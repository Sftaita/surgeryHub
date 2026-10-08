<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Enum\AuditEventType;
use App\Enum\FirmBillingReason;
use App\Enum\InstrumentistRateType;
use App\Enum\MaterialBillingStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-138 — SEULE traduction des anomalies du moteur financier (payload de l'AuditEvent
 * FINANCIAL_CALCULATION_FAILED écrit par FinancialCalculationService::buildAndPersist())
 * en explications françaises localisées. Extraite de FirmBillingWorklistService (D-133)
 * pour que « Facturation firmes » et « Suivi des encodages » expliquent une même anomalie
 * avec les mêmes mots — jamais deux traducteurs.
 *
 * Lecture seule : ne relance jamais le moteur, n'écrit rien. Le message technique du
 * moteur n'est jamais renvoyé ; un code inconnu devient CALCULATION_FAILED (catégorie
 * TECHNICAL), jamais une anomalie métier inventée. « resolved » rejoue les MÊMES
 * résolveurs que le moteur sur la configuration actuelle : la cause n'existe plus, un
 * nouveau calcul peut aboutir — il n'efface rien tant qu'il n'a pas été relancé.
 */
final class FinancialCalculationAnomalyExplainer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PricingRuleResolver $pricingRuleResolver,
        private readonly InstrumentistRateResolver $instrumentistRateResolver,
        private readonly MissionExecutionService $missionExecutionService,
        private readonly FinancialCalculationService $financialCalculationService,
    ) {}

    /**
     * Dernier échec audité par mission. Le statut ANOMALY (EncodingTrackingRepository::
     * findMissionsWithFailedCalculation()) existe ssi le DERNIER échec n'est suivi d'aucun
     * calcul actif : c'est donc toujours ce dernier échec qui explique l'anomalie.
     *
     * @param int[] $missionIds
     * @return array<int, array{anomalies: array<int, array>, effectiveAt: ?\DateTimeImmutable, failedAt: ?\DateTimeImmutable}>
     */
    public function latestFailures(array $missionIds): array
    {
        if ($missionIds === []) {
            return [];
        }
        /** @var AuditEvent[] $events */
        $events = $this->em->createQueryBuilder()
            ->select('a')
            ->from(AuditEvent::class, 'a')
            ->where('IDENTITY(a.mission) IN (:ids)')
            ->andWhere('a.eventType = :type')
            ->setParameter('ids', $missionIds)
            ->setParameter('type', AuditEventType::FINANCIAL_CALCULATION_FAILED)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($events as $event) {
            $missionId = (int) $event->getMission()?->getId();
            if (isset($out[$missionId])) {
                continue;
            }
            $payload = $event->getPayload() ?? [];
            $effective = isset($payload['effectiveAt']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $payload['effectiveAt']) : false;
            $out[$missionId] = [
                'anomalies' => array_values(array_filter((array) ($payload['anomalies'] ?? []), 'is_array')),
                'effectiveAt' => $effective !== false ? $effective : null,
                'failedAt' => $event->getCreatedAt(),
            ];
        }
        return $out;
    }

    /**
     * Ventilation par motif, sans charger aucune entité — pour la liste du suivi. Un échec
     * sans anomalie exploitable compte comme un CALCULATION_FAILED : une mission en
     * anomalie a toujours au moins un motif affichable.
     *
     * @param array<int, array> $rawAnomalies
     * @return list<array{code: string, label: string, count: int}>
     */
    public function summarize(array $rawAnomalies): array
    {
        $counts = [];
        foreach ($rawAnomalies as $raw) {
            $reason = FirmBillingReason::fromEngineCode((string) ($raw['code'] ?? ''));
            $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;
        }
        if ($counts === []) {
            $counts[FirmBillingReason::CALCULATION_FAILED->value] = 1;
        }
        arsort($counts);

        $out = [];
        foreach ($counts as $code => $count) {
            $out[] = ['code' => $code, 'label' => FirmBillingReason::from($code)->label(), 'count' => $count];
        }
        return $out;
    }

    /**
     * Toutes les anomalies d'un échec, dans l'ordre du moteur. Un échec sans anomalie
     * exploitable (payload vide ou corrompu) donne une seule anomalie CALCULATION_FAILED :
     * jamais une mission « Anomalie » sans explication.
     *
     * @param array<int, array> $rawAnomalies
     * @return list<array<string, mixed>>
     */
    public function explainAll(array $rawAnomalies, Mission $mission, \DateTimeImmutable $effectiveAt): array
    {
        $interventions = $mission->getInterventions()->toArray();
        $materials = $mission->getMaterialLines()->toArray();
        if ($rawAnomalies === []) {
            $rawAnomalies = [['code' => FirmBillingReason::CALCULATION_FAILED->value]];
        }

        return array_values(array_map(
            fn (array $raw) => $this->explain($raw, $mission, $interventions, $materials, $effectiveAt),
            $rawAnomalies,
        ));
    }

    /**
     * @param array{code?: string, message?: string, context?: array<string, mixed>} $raw
     * @param MissionIntervention[] $interventions
     * @param MaterialLine[]        $materials
     * @return array{code: string, category: string, severity: string, title: string, explanation: string,
     *               firm: ?array{id: int, name: string}, element: ?array, action: ?array{code: string, label: string},
     *               resolved: bool, missionInterventionId: ?int, materialLineId: ?int, rowKey: ?string}
     */
    public function explain(array $raw, Mission $mission, array $interventions, array $materials, \DateTimeImmutable $effectiveAt): array
    {
        $reason = FirmBillingReason::fromEngineCode((string) ($raw['code'] ?? ''));
        $ctx = (array) ($raw['context'] ?? []);
        $date = $effectiveAt->format('d/m/Y');
        $nextEffectiveAt = $this->financialCalculationService->resolveEffectiveAt($mission);

        $itv = null;
        if (isset($ctx['missionInterventionId'])) {
            foreach ($interventions as $candidate) {
                if ($candidate->getId() === (int) $ctx['missionInterventionId']) {
                    $itv = $candidate;
                }
            }
        }
        $ml = null;
        if (isset($ctx['materialLineId'])) {
            foreach ($materials as $candidate) {
                if ((int) $candidate->getId() === (int) $ctx['materialLineId']) {
                    $ml = $candidate;
                }
            }
        }

        $firm = $itv?->getPrimaryFirm() ?? $ml?->getItem()?->getFirm();
        if ($firm === null && isset($ctx['firmId'])) {
            $firm = $this->em->find(Firm::class, (int) $ctx['firmId']);
        }
        $element = null;
        $explanation = $reason->defaultDetail();
        $action = null;
        $resolved = false;

        switch ($reason) {
            case FirmBillingReason::MISSING_FIRM_INTERVENTION_RATE:
                $type = $itv?->getInterventionType() ?? (isset($ctx['interventionTypeId']) ? $this->em->find(InterventionType::class, (int) $ctx['interventionTypeId']) : null);
                $element = ['type' => 'INTERVENTION', 'label' => $type?->getLabel() ?? $itv?->getLabel()];
                $explanation = sprintf("Aucun tarif applicable n'est configuré pour cette prestation%s au %s.", $firm !== null ? ' chez ' . $firm->getName() : '', $date);
                $action = ['code' => 'CONFIGURE_INTERVENTION_RATE', 'label' => 'Configurer le tarif'];
                $resolved = $itv === null || ($itv->getPrimaryFirm() !== null && $itv->getInterventionType() !== null
                    && $this->pricingRuleResolver->resolveInterventionFee($itv->getPrimaryFirm(), $itv->getInterventionType(), $nextEffectiveAt, $itv->getSelectedChoiceOption()) !== null);
                break;

            case FirmBillingReason::MISSING_FIRM_MATERIAL_RATE:
                // Ligne supprimée depuis l'échec : l'article reste identifiable par le contexte
                // audité, mais l'anomalie est résolue (plus rien à valoriser).
                $item = $ml?->getItem() ?? (isset($ctx['materialItemId']) ? $this->em->find(MaterialItem::class, (int) $ctx['materialItemId']) : null);
                $element = ['type' => 'MATERIAL', 'label' => $item?->getLabel(), 'reference' => $item?->getReferenceCode()];
                $explanation = sprintf("Aucun tarif applicable n'est configuré pour ce matériel%s au %s. S'il n'est jamais facturé, marquez-le « non facturable » dans le catalogue.", $firm !== null ? ' (' . $firm->getName() . ')' : '', $date);
                $action = ['code' => 'CONFIGURE_MATERIAL_RATE', 'label' => 'Configurer le tarif'];
                $resolved = $ml === null || $ml->getItem()->getBillingStatus() === MaterialBillingStatus::NOT_BILLABLE
                    || $this->pricingRuleResolver->resolveMaterialFee($ml->getItem(), $nextEffectiveAt) !== null;
                break;

            case FirmBillingReason::MISSING_INSTRUMENTIST_RATE:
                $instrumentist = $mission->getInstrumentist();
                $rateType = InstrumentistRateType::tryFrom((string) ($ctx['rateType'] ?? '')) ?? InstrumentistRateType::HOURLY_RATE;
                $name = $instrumentist !== null ? trim(($instrumentist->getFirstname() ?? '') . ' ' . ($instrumentist->getLastname() ?? '')) : '';
                $element = ['type' => 'INSTRUMENTIST', 'label' => $name !== '' ? $name : 'Instrumentiste'];
                $explanation = sprintf(
                    "Aucun tarif %s actif n'est configuré pour %s au %s. Tant qu'il manque, aucune ligne de cette mission — firmes comprises — ne peut être calculée.",
                    $rateType === InstrumentistRateType::CONSULTATION_FEE ? 'de consultation' : 'horaire',
                    $name !== '' ? $name : "l'instrumentiste",
                    $date,
                );
                $firm = null; // anomalie de mission : elle bloque toutes les firmes
                $action = ['code' => 'CONFIGURE_INSTRUMENTIST_RATE', 'label' => 'Configurer le tarif'];
                $resolved = $instrumentist !== null && $this->instrumentistRateResolver->resolve($instrumentist, $rateType, $nextEffectiveAt) !== null;
                break;

            case FirmBillingReason::MISSING_PRIMARY_FIRM:
                $element = ['type' => 'INTERVENTION', 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
                $explanation = sprintf("L'intervention « %s » n'a pas de firme : impossible de savoir à qui la facturer.", $element['label'] ?? '—');
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $resolved = $itv === null || $itv->getPrimaryFirm() !== null;
                break;

            case FirmBillingReason::MISSING_INTERVENTION_TYPE:
                $element = ['type' => 'INTERVENTION', 'label' => $itv?->getLabel()];
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $resolved = $itv === null || $itv->getInterventionType() !== null;
                break;

            case FirmBillingReason::MISSING_REPRESENTATIVE_PRESENCE_ANSWER:
                $element = ['type' => 'INTERVENTION', 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
                $explanation = sprintf("L'encodage doit indiquer si le délégué %s était présent : cela détermine le forfait.", $firm?->getName() ?? 'de la firme');
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $resolved = $itv === null || $itv->getRepresentativePresent() !== null;
                break;

            case FirmBillingReason::MISSING_REQUIRED_CHOICE_ANSWER:
                $element = ['type' => 'INTERVENTION', 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $resolved = $itv === null || $itv->getSelectedChoiceOption() !== null;
                break;

            case FirmBillingReason::INVALID_EFFECTIVE_DURATION:
                $firm = null;
                $action = ['code' => 'OPEN_MISSION', 'label' => 'Corriger les horaires'];
                $resolved = $this->missionExecutionService->resolveEffectiveDuration($mission)->minutes > 0;
                break;

            default: // CALCULATION_FAILED — code moteur inconnu, jamais le message brut
                $firm = null;
                $action = ['code' => 'OPEN_MISSION', 'label' => 'Ouvrir la mission'];
        }

        return [
            'code' => $reason->value,
            'category' => $reason->anomalyCategory() ?? 'TECHNICAL',
            // Toute anomalie du moteur bloque le calcul ENTIER (§14 D-073, aucun calcul partiel).
            'severity' => 'BLOCKING',
            'title' => $reason->label(),
            'explanation' => $explanation,
            'firm' => $firm !== null ? ['id' => $firm->getId(), 'name' => $firm->getName()] : null,
            'element' => $element,
            'action' => $action,
            'resolved' => $resolved,
            // Localisation dans l'encodage : l'intervention concernée, ou celle qui porte la
            // ligne de matériel concernée.
            'missionInterventionId' => $itv?->getId() ?? $ml?->getMissionIntervention()?->getId()
                ?? (isset($ctx['missionInterventionId']) ? (int) $ctx['missionInterventionId'] : null),
            'materialLineId' => $ml?->getId() ?? (isset($ctx['materialLineId']) ? (int) $ctx['materialLineId'] : null),
            'rowKey' => $itv !== null ? 'MISSION_INTERVENTION:' . $itv->getId() : ($ml !== null ? 'MATERIAL_LINE:' . $ml->getId() : null),
        ];
    }
}
