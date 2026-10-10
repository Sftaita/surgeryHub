<?php

namespace App\Service;

use App\Dto\FinancialCalculationAnomaly;
use App\Dto\MissionFinancialEvaluation;
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
 * Lecture seule : ne persiste jamais un calcul, n'écrit rien. Le message technique du
 * moteur n'est jamais renvoyé ; un code inconnu devient CALCULATION_FAILED (catégorie
 * TECHNICAL), jamais une anomalie métier inventée. « resolved » rejoue le MOTEUR LUI-MÊME
 * (FinancialCalculationService::evaluate(), lecture seule — D-141) sur la configuration et
 * l'encodage actuels : la cause n'existe plus, un nouveau calcul peut aboutir — il n'efface
 * rien tant qu'il n'a pas été relancé. Aucune règle tarifaire n'est ré-implémentée ici.
 */
final class FinancialCalculationAnomalyExplainer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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
            // D-138 — ordre des événements = identifiant (déterministe), jamais l'horodatage.
            ->orderBy('a.id', 'DESC')
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
     * D-141 — explication d'un échec ET de ce qu'un recalcul produirait maintenant, à partir
     * d'UNE évaluation du moteur (FinancialCalculationService::evaluate(), lecture seule) :
     *  - anomalies    : celles de l'échec audité, dans l'ordre du moteur, chacune avec
     *                   `resolved` (la cause n'existe plus) et `currentResolution` ;
     *  - newAnomalies : anomalies qu'un recalcul produirait et que l'échec audité ne contenait
     *                   pas (ex. présence du délégué devenue pertinente après l'encodage) ;
     *  - recalculation: le recalcul aboutirait-il, et avec combien d'anomalies restantes.
     * Rien n'est persisté ; l'échec audité reste l'explication de l'état ANOMALY (D-138).
     *
     * @param array<int, array> $rawAnomalies
     * @return array{anomalies: list<array<string, mixed>>, newAnomalies: list<array<string, mixed>>, recalculation: array{wouldSucceed: bool, remainingAnomalyCount: int, referenceDate: string}}
     */
    public function explainWithOutlook(array $rawAnomalies, Mission $mission, \DateTimeImmutable $effectiveAt): array
    {
        $evaluation = $this->financialCalculationService->evaluate($mission);
        $interventions = $mission->getInterventions()->toArray();
        $materials = $mission->getMaterialLines()->toArray();
        if ($rawAnomalies === []) {
            $rawAnomalies = [['code' => FirmBillingReason::CALCULATION_FAILED->value]];
        }

        $audited = [];
        foreach ($rawAnomalies as $raw) {
            $audited[self::anomalyIdentity((string) ($raw['code'] ?? ''), (array) ($raw['context'] ?? []))] = true;
        }
        $new = [];
        foreach ($evaluation->anomalies as $current) {
            if (!isset($audited[self::anomalyIdentity($current->code, $current->context)])) {
                $new[] = $this->explain($current->toArray(), $mission, $interventions, $materials, $evaluation->effectiveAt, $evaluation) + ['detectedAfterFailure' => true];
            }
        }

        return [
            'anomalies' => array_values(array_map(
                fn (array $raw) => $this->explain($raw, $mission, $interventions, $materials, $effectiveAt, $evaluation),
                $rawAnomalies,
            )),
            'newAnomalies' => $new,
            'recalculation' => [
                'wouldSucceed' => $evaluation->succeeds(),
                'remainingAnomalyCount' => count($evaluation->anomalies),
                'referenceDate' => $evaluation->effectiveAt->format('Y-m-d'),
            ],
        ];
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
        return $this->explainWithOutlook($rawAnomalies, $mission, $effectiveAt)['anomalies'];
    }

    /**
     * Identité d'une anomalie : son code et l'élément qu'elle vise. Sert à dire si la cause
     * d'une anomalie auditée existe encore dans l'évaluation actuelle (même code, même
     * élément) — jamais une comparaison de messages.
     *
     * @param array<string, mixed> $ctx
     */
    public static function anomalyIdentity(string $code, array $ctx): string
    {
        $target = match (true) {
            isset($ctx['materialLineId']) => 'MATERIAL_LINE:' . (int) $ctx['materialLineId'],
            isset($ctx['missionInterventionId']) => 'MISSION_INTERVENTION:' . (int) $ctx['missionInterventionId'],
            isset($ctx['rateType']) => 'INSTRUMENTIST:' . (string) $ctx['rateType'],
            default => 'MISSION',
        };

        return FirmBillingReason::fromEngineCode($code)->value . '|' . $target;
    }

    /**
     * @param array{code?: string, message?: string, context?: array<string, mixed>} $raw
     * @param MissionIntervention[] $interventions
     * @param MaterialLine[]        $materials
     * @return array{code: string, category: string, severity: string, title: string, explanation: string,
     *               firm: ?array{id: int, name: string}, element: ?array, action: ?array{code: string, label: string},
     *               resolved: bool, currentResolution: array, referenceDate: string, targetRules: list<array>,
     *               missionInterventionId: ?int, materialLineId: ?int, conflictingRules: list<array>, rowKey: ?string}
     */
    public function explain(array $raw, Mission $mission, array $interventions, array $materials, \DateTimeImmutable $effectiveAt, ?MissionFinancialEvaluation $evaluation = null): array
    {
        $evaluation ??= $this->financialCalculationService->evaluate($mission);
        $reason = FirmBillingReason::fromEngineCode((string) ($raw['code'] ?? ''));
        $ctx = (array) ($raw['context'] ?? []);
        $date = $effectiveAt->format('d/m/Y');

        // L'anomalie existe-t-elle encore (même code, même élément) ? Son contexte ACTUEL,
        // produit par le moteur, complète alors le contexte audité (anciens échecs sans
        // règles ni statut de facturation dans leur payload).
        $identity = self::anomalyIdentity($reason->value, $ctx);
        $current = null;
        foreach ($evaluation->anomalies as $candidate) {
            if (self::anomalyIdentity($candidate->code, $candidate->context) === $identity) {
                $current = $candidate;
                break;
            }
        }
        $explainCtx = $current !== null ? $ctx + $current->context : $ctx;

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
            $itv = null; // une anomalie de matériel vise la ligne, pas l'intervention qui la porte
        }

        $firm = $itv?->getPrimaryFirm() ?? $ml?->getItem()?->getFirm();
        if ($firm === null && isset($ctx['firmId'])) {
            $firm = $this->em->find(Firm::class, (int) $ctx['firmId']);
        }
        $element = null;
        $explanation = $reason->defaultDetail();
        /** @var list<array<string, mixed>> $conflictingRules instantané audité des règles en conflit */
        $conflictingRules = array_values(array_filter((array) ($ctx['conflictingRules'] ?? []), 'is_array'));
        /** @var list<array<string, mixed>> $targetRules règles de la cible hors date (D-141) */
        $targetRules = array_values(array_filter((array) ($explainCtx['targetRules'] ?? []), 'is_array'));
        $action = null;
        $elementKey = null;

        switch ($reason) {
            case FirmBillingReason::MISSING_FIRM_INTERVENTION_RATE:
                $type = $itv?->getInterventionType() ?? (isset($ctx['interventionTypeId']) ? $this->em->find(InterventionType::class, (int) $ctx['interventionTypeId']) : null);
                $element = ['type' => 'INTERVENTION', 'label' => $type?->getLabel() ?? $itv?->getLabel()];
                $explanation = $this->missingInterventionRateExplanation($firm, $explainCtx, $targetRules, $date);
                $action = ['code' => 'CONFIGURE_INTERVENTION_RATE', 'label' => 'Configurer le tarif'];
                $elementKey = $this->interventionKey($ctx);
                break;

            case FirmBillingReason::CONFLICTING_FIRM_INTERVENTION_RATE:
                $type = $itv?->getInterventionType() ?? (isset($ctx['interventionTypeId']) ? $this->em->find(InterventionType::class, (int) $ctx['interventionTypeId']) : null);
                $element = ['type' => 'INTERVENTION', 'label' => $type?->getLabel() ?? $itv?->getLabel()];
                $explanation = $this->conflictExplanation('cette prestation' . ($firm !== null ? ' chez ' . $firm->getName() : ''), $conflictingRules, $date);
                $action = ['code' => 'CONFIGURE_INTERVENTION_RATE', 'label' => 'Corriger les tarifs'];
                $elementKey = $this->interventionKey($ctx);
                break;

            case FirmBillingReason::MISSING_FIRM_MATERIAL_RATE:
                // Ligne supprimée depuis l'échec : l'article reste identifiable par le contexte
                // audité, mais l'anomalie est résolue (plus rien à valoriser).
                $item = $ml?->getItem() ?? (isset($ctx['materialItemId']) ? $this->em->find(MaterialItem::class, (int) $ctx['materialItemId']) : null);
                $element = ['type' => 'MATERIAL', 'label' => $item?->getLabel(), 'reference' => $item?->getReferenceCode()];
                $explanation = $this->missingMaterialRateExplanation($firm, $explainCtx, $targetRules, $date);
                $action = ['code' => 'CONFIGURE_MATERIAL_RATE', 'label' => 'Configurer le tarif'];
                $elementKey = $this->materialKey($ctx);
                break;

            case FirmBillingReason::CONFLICTING_FIRM_MATERIAL_RATE:
                $item = $ml?->getItem() ?? (isset($ctx['materialItemId']) ? $this->em->find(MaterialItem::class, (int) $ctx['materialItemId']) : null);
                $element = ['type' => 'MATERIAL', 'label' => $item?->getLabel(), 'reference' => $item?->getReferenceCode()];
                $explanation = $this->conflictExplanation('ce matériel' . ($firm !== null ? ' (' . $firm->getName() . ')' : ''), $conflictingRules, $date);
                $action = ['code' => 'CONFIGURE_MATERIAL_RATE', 'label' => 'Corriger les tarifs'];
                $elementKey = $this->materialKey($ctx);
                break;

            case FirmBillingReason::CONFLICTING_INSTRUMENTIST_RATE:
                $instrumentist = $mission->getInstrumentist();
                $rateType = InstrumentistRateType::tryFrom((string) ($ctx['rateType'] ?? '')) ?? InstrumentistRateType::HOURLY_RATE;
                $name = $instrumentist !== null ? trim(($instrumentist->getFirstname() ?? '') . ' ' . ($instrumentist->getLastname() ?? '')) : '';
                $element = ['type' => 'INSTRUMENTIST', 'label' => $name !== '' ? $name : 'Instrumentiste'];
                $explanation = $this->conflictExplanation(
                    sprintf('le tarif %s de %s', $rateType === InstrumentistRateType::CONSULTATION_FEE ? 'de consultation' : 'horaire', $name !== '' ? $name : "l'instrumentiste"),
                    $conflictingRules,
                    $date,
                ) . ' Tant que le conflit existe, aucune ligne de cette mission — firmes comprises — ne peut être calculée.';
                $firm = null;
                $action = ['code' => 'CONFIGURE_INSTRUMENTIST_RATE', 'label' => 'Corriger les tarifs'];
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
                break;

            case FirmBillingReason::MISSING_PRIMARY_FIRM:
                $element = ['type' => 'INTERVENTION', 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
                $explanation = sprintf("L'intervention « %s » n'a pas de firme : impossible de savoir à qui la facturer.", $element['label'] ?? '—');
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $elementKey = $this->interventionKey($ctx);
                break;

            case FirmBillingReason::MISSING_INTERVENTION_TYPE:
                $element = ['type' => 'INTERVENTION', 'label' => $itv?->getLabel()];
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $elementKey = $this->interventionKey($ctx);
                break;

            case FirmBillingReason::MISSING_REPRESENTATIVE_PRESENCE_ANSWER:
                $element = ['type' => 'INTERVENTION', 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
                $explanation = sprintf("L'encodage doit indiquer si le délégué %s était présent : cela détermine le forfait.", $firm?->getName() ?? 'de la firme');
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $elementKey = $this->interventionKey($ctx);
                break;

            case FirmBillingReason::MISSING_REQUIRED_CHOICE_ANSWER:
                $element = ['type' => 'INTERVENTION', 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $elementKey = $this->interventionKey($ctx);
                break;

            case FirmBillingReason::INVALID_EFFECTIVE_DURATION:
                $firm = null;
                $action = ['code' => 'OPEN_MISSION', 'label' => 'Corriger les horaires'];
                break;

            default: // CALCULATION_FAILED — code moteur inconnu, jamais le message brut
                $firm = null;
                $action = ['code' => 'OPEN_MISSION', 'label' => 'Ouvrir la mission'];
        }

        // « resolved » = le moteur, rejoué sur la configuration et l'encodage actuels, ne
        // produit plus cette anomalie pour cet élément (D-141 : jamais une ré-implémentation
        // partielle des règles). Un code inconnu n'est résolu que si plus rien ne bloque.
        $resolved = $reason === FirmBillingReason::CALCULATION_FAILED ? $evaluation->succeeds() : $current === null;

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
            'currentResolution' => $this->currentResolution($evaluation, $elementKey, $current, $resolved),
            // Date de référence de la résolution tarifaire (date effective de la mission).
            'referenceDate' => $effectiveAt->format('Y-m-d'),
            // D-141 — tarifs existants de la cible, aucun ne couvrant la date (vide si aucun n'a
            // jamais été saisi) : « hors période » ≠ « jamais configuré ».
            'targetRules' => $targetRules,
            // Localisation dans l'encodage : l'intervention concernée, ou celle qui porte la
            // ligne de matériel concernée.
            'missionInterventionId' => $itv?->getId() ?? $ml?->getMissionIntervention()?->getId()
                ?? (isset($ctx['missionInterventionId']) ? (int) $ctx['missionInterventionId'] : null),
            'materialLineId' => $ml?->getId() ?? (isset($ctx['materialLineId']) ? (int) $ctx['materialLineId'] : null),
            // D-138 — règles contradictoires (vide hors CONFLICTING_*), telles qu'auditées.
            'conflictingRules' => $conflictingRules,
            'rowKey' => $ml !== null ? 'MATERIAL_LINE:' . $ml->getId() : ($itv !== null ? 'MISSION_INTERVENTION:' . $itv->getId() : null),
        ];
    }

    /**
     * Libellé français de l'issue d'un élément dans une évaluation (D-141) — partagé par le
     * tiroir du suivi et la worklist « Facturation firmes ». Jamais un montant facturable :
     * un tarif résolu reste indicatif tant qu'aucun calcul n'a abouti.
     *
     * @param array{kind: string, rule: ?array, unitAmount: ?string, totalAmount: ?string, currency: ?string, adjustmentReason: ?string, anomalyCodes: list<string>} $outcome
     */
    public static function describeOutcome(array $outcome): string
    {
        return match ($outcome['kind']) {
            MissionFinancialEvaluation::LINE => sprintf(
                'Tarif résolu : %s (règle #%s, %s)%s.',
                self::money($outcome['unitAmount'], $outcome['currency']),
                $outcome['rule']['id'] ?? '?',
                self::period($outcome['rule']['validFrom'] ?? null, $outcome['rule']['validTo'] ?? null),
                $outcome['adjustmentReason'] !== null ? ' — ' . rtrim($outcome['adjustmentReason'], '.') : '',
            ),
            MissionFinancialEvaluation::FEE_NOT_APPLICABLE => 'Aucun forfait prévu pour cette prestation (« Pas de forfait ») : rien ne sera facturé.',
            MissionFinancialEvaluation::MATERIAL_NOT_BILLABLE => 'Matériel marqué « non facturable » : rien ne sera facturé.',
            default => 'Toujours bloquant : ' . implode(', ', array_map(
                static fn (string $code) => mb_strtolower(FirmBillingReason::fromEngineCode($code)->label()),
                $outcome['anomalyCodes'],
            )) . '.',
        };
    }

    /** @return array{kind: ?string, label: ?string, rule: ?array} */
    private function currentResolution(MissionFinancialEvaluation $evaluation, ?string $elementKey, ?FinancialCalculationAnomaly $current, bool $resolved): array
    {
        if ($elementKey !== null) {
            $outcome = $evaluation->outcome($elementKey);
            if ($outcome === null) {
                return ['kind' => 'ELEMENT_REMOVED', 'label' => "L'élément a été retiré de l'encodage depuis : plus rien à valoriser.", 'rule' => null];
            }
            return ['kind' => $outcome['kind'], 'label' => self::describeOutcome($outcome), 'rule' => $outcome['rule']];
        }
        // Anomalie de mission (instrumentiste, durée, code inconnu) : pas d'élément.
        if ($current !== null) {
            return ['kind' => MissionFinancialEvaluation::ANOMALY, 'label' => 'Toujours bloquant.', 'rule' => null];
        }

        return $resolved
            ? ['kind' => 'RESOLVED', 'label' => 'Cause corrigée dans la configuration actuelle.', 'rule' => null]
            : ['kind' => null, 'label' => null, 'rule' => null];
    }

    /** @param array<string, mixed> $ctx */
    private function interventionKey(array $ctx): ?string
    {
        return isset($ctx['missionInterventionId']) ? 'MISSION_INTERVENTION:' . (int) $ctx['missionInterventionId'] : null;
    }

    /** @param array<string, mixed> $ctx */
    private function materialKey(array $ctx): ?string
    {
        return isset($ctx['materialLineId']) ? 'MATERIAL_LINE:' . (int) $ctx['materialLineId'] : null;
    }

    /**
     * Les trois causes d'un forfait manquant, jamais confondues avec « Pas de forfait »
     * (feeApplicable=false ne produit aucune anomalie) : tarifs existants hors période,
     * prestation configurée « avec forfait » sans aucun tarif, prestation jamais configurée.
     *
     * @param array<string, mixed>       $ctx
     * @param list<array<string, mixed>> $rules
     */
    private function missingInterventionRateExplanation(?Firm $firm, array $ctx, array $rules, string $date): string
    {
        $at = $firm !== null ? ' chez ' . $firm->getName() : '';
        if ($rules !== []) {
            return sprintf(
                "Un forfait est attendu pour cette prestation%s, mais aucun de ses tarifs ne s'applique au %s (%s). Ajoutez ou prolongez une période qui couvre cette date.",
                $at, $date, $this->describeRules($rules),
            );
        }
        if (($ctx['offeringConfigured'] ?? null) === false) {
            return sprintf(
                "Cette prestation n'est pas configurée%s : un forfait est donc attendu, mais aucun tarif n'existe au %s. Saisissez son tarif, ou choisissez « Pas de forfait » si la firme n'en prévoit aucun.",
                $at, $date,
            );
        }
        if (($ctx['offeringConfigured'] ?? null) === true) {
            return sprintf(
                "La prestation est configurée « avec forfait »%s, mais aucun tarif n'a jamais été saisi (au %s). Saisissez le tarif, ou choisissez « Pas de forfait » si la firme n'en prévoit aucun.",
                $at, $date,
            );
        }

        return sprintf("Aucun tarif applicable n'est configuré pour cette prestation%s au %s.", $at, $date);
    }

    /**
     * @param array<string, mixed>       $ctx
     * @param list<array<string, mixed>> $rules
     */
    private function missingMaterialRateExplanation(?Firm $firm, array $ctx, array $rules, string $date): string
    {
        $of = $firm !== null ? ' (' . $firm->getName() . ')' : '';
        if ($rules !== []) {
            return sprintf(
                "Aucun des tarifs de ce matériel%s ne s'applique au %s (%s). Ajoutez ou prolongez une période qui couvre cette date, ou marquez-le « non facturable » s'il n'est jamais facturé.",
                $of, $date, $this->describeRules($rules),
            );
        }
        if (($ctx['billingStatus'] ?? null) === MaterialBillingStatus::UNSPECIFIED->value) {
            return sprintf(
                "Aucune décision de facturation n'a été prise pour ce matériel%s : ni tarif, ni « non facturable ». Saisissez son tarif, ou marquez-le « non facturable » s'il n'est jamais facturé (par exemple s'il est compris dans le forfait).",
                $of,
            );
        }
        if (($ctx['billingStatus'] ?? null) === MaterialBillingStatus::BILLABLE->value) {
            return sprintf("Ce matériel%s est marqué « facturable », mais aucun tarif n'a été saisi (au %s). Saisissez son tarif.", $of, $date);
        }

        return sprintf("Aucun tarif applicable n'est configuré pour ce matériel%s au %s. S'il n'est jamais facturé, marquez-le « non facturable » dans le catalogue.", $of, $date);
    }

    /** @param list<array<string, mixed>> $rules */
    private function describeRules(array $rules): string
    {
        return implode(' ; ', array_map(static fn (array $r) => sprintf(
            'règle #%s : %s, %s%s',
            $r['id'] ?? '?',
            self::money(isset($r['unitPrice']) ? (string) $r['unitPrice'] : null, $r['currency'] ?? null),
            self::period($r['validFrom'] ?? null, $r['validTo'] ?? null),
            ($r['active'] ?? true) ? '' : ', désactivée',
        ), $rules));
    }

    private static function money(?string $amount, ?string $currency): string
    {
        return $amount !== null ? trim(number_format((float) $amount, 2, ',', ' ') . ' ' . ($currency ?? '')) : '—';
    }

    /** validTo est EXCLUSIF (D-072) : le dernier jour couvert est la veille. */
    private static function period(?string $validFrom, ?string $validTo): string
    {
        $from = $validFrom !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom) : false;
        $to = $validTo !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $validTo) : false;
        if (!$from && !$to) {
            return 'sans limite de validité';
        }

        return ($from ? 'du ' . $from->format('d/m/Y') : 'sans date de début') . ($to ? ' au ' . $to->modify('-1 day')->format('d/m/Y') : ', sans date de fin');
    }

    /** @param list<array<string, mixed>> $rules */
    private function conflictExplanation(string $target, array $rules, string $date): string
    {
        $described = array_map(static function (array $r): string {
            $from = isset($r['validFrom']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $r['validFrom']) : false;
            $to = isset($r['validTo']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $r['validTo']) : false;
            return sprintf(
                'règle #%s : %s %s, %s',
                $r['id'] ?? '?',
                isset($r['unitPrice']) ? number_format((float) $r['unitPrice'], 2, ',', ' ') : '—',
                $r['currency'] ?? '',
                ($from ? 'du ' . $from->format('d/m/Y') : 'sans date de début') . ($to ? ' au ' . $to->modify('-1 day')->format('d/m/Y') : ', sans date de fin'),
            );
        }, $rules);

        return sprintf(
            "%d tarifs actifs s'appliquent en même temps à %s au %s%s. Le calcul ne choisit jamais entre eux : clôturez ou corrigez l'un d'eux.",
            max(2, count($rules)),
            $target,
            $date,
            $described !== [] ? ' (' . implode(' ; ', $described) . ')' : '',
        );
    }
}
