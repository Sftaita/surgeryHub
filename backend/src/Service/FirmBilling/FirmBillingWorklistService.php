<?php

namespace App\Service\FirmBilling;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\FirmBillingLineEvent;
use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\FirmInvoiceLine;
use App\Entity\InterventionType;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Enum\AuditEventType;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialDocumentType;
use App\Enum\FirmBillingReason;
use App\Enum\FirmBillingStatus;
use App\Enum\InstrumentistRateType;
use App\Enum\InvoiceStatus;
use App\Enum\MaterialBillingStatus;
use App\Enum\MissionStatus;
use App\Repository\EncodingTrackingRepository;
use App\Service\FinancialCalculationService;
use App\Service\InstrumentistRateResolver;
use App\Service\MissionExecutionService;
use App\Service\PricingRuleResolver;
use App\Service\RepresentativePolicyResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-133 — worklist « Facturation firmes » : projection de LECTURE qui part de l'activité
 * validée (interventions + matériel des missions VALIDATED) et l'enrichit de son état
 * financier. Aucun calcul tarifaire, aucun montant estimé : les montants viennent
 * exclusivement des FinancialCalculationLine du calcul ACTIF (CALCULATED/APPROVED/LOCKED —
 * jamais SUPERSEDED ni CANCELLED) ou de la FirmInvoiceLine qui les a facturées.
 *
 * Granularité : une ligne par élément source. Le moteur produit au plus UNE ligne FIRM par
 * MissionIntervention (firme principale) et par MaterialLine (firme de l'article) — la
 * paire source × firme est donc la source elle-même. Une ligne FIRM du calcul actif dont la
 * source n'est plus encodée reste visible (clé FINANCIAL_LINE:id) : rien ne disparaît.
 *
 * Exclusions (« non facturable ») : lues aux MÊMES endroits que le moteur —
 * MaterialItem.billingStatus et RepresentativePolicyResolver (FirmServiceOffering), dans le
 * même ordre de priorité que FinancialCalculationService::resolveFirmInterventionLine().
 * Extension en lecture seule de l'exception D-092 (voir D-133).
 *
 * Anomalies : celles du DERNIER échec audité (FINANCIAL_CALCULATION_FAILED), traduites en
 * français ; le message technique du moteur n'est jamais renvoyé. « resolved » indique que
 * la cause n'existe plus dans la configuration actuelle (même résolveurs que le moteur) :
 * la mission peut être recalculée.
 */
final class FirmBillingWorklistService
{
    public const TYPE_INTERVENTION = 'INTERVENTION';
    public const TYPE_MATERIAL = 'MATERIAL';

    private const ACTIVE_STATUSES = [
        FinancialCalculationStatus::CALCULATED,
        FinancialCalculationStatus::APPROVED,
        FinancialCalculationStatus::LOCKED,
    ];

    /** D-134 — état documentaire courant d'une ligne. */
    public const INVOICE_STATE_LABELS = [
        'FREE' => 'Libre',
        'IN_DRAFT' => 'Dans un brouillon',
        'GENERATED' => 'Facture générée',
        'SENT' => 'Envoyée',
        'PAID' => 'Payée',
    ];

    /** @var array<string, \App\Dto\RepresentativePolicy> */
    private array $policyCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EncodingTrackingRepository $encodingTrackingRepository,
        private readonly RepresentativePolicyResolver $representativePolicyResolver,
        private readonly PricingRuleResolver $pricingRuleResolver,
        private readonly InstrumentistRateResolver $instrumentistRateResolver,
        private readonly MissionExecutionService $missionExecutionService,
        private readonly FinancialCalculationService $financialCalculationService,
    ) {}

    /**
     * @param int[]       $firmIds  vide = toutes les firmes ; sinon OU logique
     * @param string|null $type     INTERVENTION | MATERIAL | null
     * @param FirmBillingStatus|null $status filtre des lignes (pas des tuiles)
     * @return array<string, mixed>
     */
    public function build(\DateTimeImmutable $fromDay, \DateTimeImmutable $toDay, array $firmIds = [], ?string $type = null, ?FirmBillingStatus $status = null): array
    {
        $from = $fromDay->setTime(0, 0, 0);
        $to = $toDay->setTime(23, 59, 59);
        $firmFilter = array_fill_keys(array_map('intval', $firmIds), true);

        [$rows, $anomalies, $missionFirms] = $this->project($from, $to);
        [$rows, $anomalies] = $this->withStaleDraftMembership($rows, $anomalies);

        // Filtre firme (OU) puis type : appliqué aux tuiles ET aux lignes.
        $rows = array_values(array_filter($rows, static function (array $row) use ($firmFilter, $type): bool {
            if ($firmFilter !== [] && !isset($firmFilter[$row['firm']['id'] ?? 0])) {
                return false;
            }
            return $type === null || $row['sourceType'] === $type;
        }));

        // Une anomalie suit sa firme ; une anomalie de mission (tarif instrumentiste, calcul
        // à effectuer…) suit les firmes de la mission.
        $anomalies = array_values(array_filter($anomalies, static function (array $a) use ($firmFilter, $missionFirms): bool {
            if ($firmFilter === []) {
                return true;
            }
            if ($a['firm'] !== null) {
                return isset($firmFilter[$a['firm']['id']]);
            }
            foreach ($missionFirms[$a['mission']['id']] ?? [] as $firmId => $_) {
                if (isset($firmFilter[$firmId])) {
                    return true;
                }
            }
            return false;
        }));

        $summary = $this->summary($rows, $anomalies, $from, $to, $firmFilter);

        if ($status !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r) => $r['billingStatus'] === $status->value));
        }
        $rows = $this->withHistoryFlags($rows);

        return [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'summary' => $summary,
            'rows' => $rows,
            'anomalies' => $anomalies,
            'bulkActions' => $this->bulkActions($anomalies),
        ];
    }

    /**
     * D-137 — prestations ajoutables à un brouillon : la projection worklist elle-même,
     * restreinte à la firme et à la période du brouillon, aux lignes `canInvoice` (libres,
     * facturables, calcul approuvé, ni obsolètes, ni dans un autre document) et à sa devise.
     * Aucune règle propre : le filtre est celui que la worklist expose déjà.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candidatesForDraft(FirmInvoice $draft): array
    {
        $rows = $this->build($draft->getPeriodStart(), $draft->getPeriodEnd(), [(int) $draft->getFirm()->getId()])['rows'];

        return array_values(array_filter(
            $rows,
            static fn (array $r) => $r['canInvoice'] === true && $r['currency'] === $draft->getCurrency(),
        ));
    }

    /**
     * Lignes exactement sélectionnées (clés), dans l'ordre de la projection, sans doublon.
     *
     * @param string[] $keys
     * @return array{rows: array<int, array<string, mixed>>, unknownKeys: string[]}
     */
    public function selectRows(\DateTimeImmutable $fromDay, \DateTimeImmutable $toDay, array $firmIds, array $keys): array
    {
        $wanted = array_fill_keys(array_map('strval', $keys), true);
        $all = $this->build($fromDay, $toDay, $firmIds)['rows'];

        $rows = [];
        foreach ($all as $row) {
            if (isset($wanted[$row['key']])) {
                $rows[] = $row;
                unset($wanted[$row['key']]);
            }
        }

        return ['rows' => $rows, 'unknownKeys' => array_keys($wanted)];
    }

    // ── Projection ──────────────────────────────────────────────────────────

    /** @return array{0: array<int, array>, 1: array<int, array>, 2: array<int, array<int, true>>} */
    private function project(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $missionIds = $this->missionIdsInPeriod($from, $to);
        if ($missionIds === []) {
            return [[], [], []];
        }

        $missions = $this->loadMissions($missionIds);
        $interventionsByMission = $this->loadInterventions($missionIds);
        $materialsByMission = $this->loadMaterialLines($missionIds);
        $calculationsByMission = $this->loadActiveCalculations($missionIds);
        $firmLinesByCalculation = $this->loadFirmLines(array_map(static fn (FinancialCalculation $c) => $c->getId(), $calculationsByMission));
        $failedMissions = $this->encodingTrackingRepository->findMissionsWithFailedCalculation($missionIds);
        $failures = $this->latestFailures(array_keys($failedMissions));

        $rows = [];
        $anomalies = [];
        $missionFirms = [];

        foreach ($missions as $mission) {
            $missionId = (int) $mission->getId();
            $calculation = $calculationsByMission[$missionId] ?? null;
            $firmLines = $calculation !== null ? ($firmLinesByCalculation[$calculation->getId()] ?? []) : [];
            $failure = $failures[$missionId] ?? null;
            $effectiveAt = $calculation?->getEffectiveAt() ?? $this->financialCalculationService->resolveEffectiveAt($mission);
            $context = $this->missionContext($mission, $effectiveAt);

            $missionRows = [];
            $missionAnomalies = [];

            // Anomalies du dernier échec audité, traduites et rattachées à leur élément.
            $anomalyByIntervention = [];
            $anomalyByMaterial = [];
            if ($failure !== null) {
                foreach ($failure['anomalies'] as $i => $raw) {
                    $item = $this->translateAnomaly($raw, $mission, $context, $interventionsByMission[$missionId] ?? [], $materialsByMission[$missionId] ?? [], $failure['effectiveAt'] ?? $effectiveAt, $calculation, $i);
                    $missionAnomalies[] = $item;
                    if (isset($raw['context']['missionInterventionId'])) {
                        $anomalyByIntervention[(int) $raw['context']['missionInterventionId']] ??= $item;
                    }
                    if (isset($raw['context']['materialLineId'])) {
                        $anomalyByMaterial[(int) $raw['context']['materialLineId']] ??= $item;
                    }
                }
            }

            // Lignes FIRM du calcul actif, indexées par source.
            $lineByIntervention = [];
            $lineByMaterial = [];
            $orphanLines = [];
            foreach ($firmLines as $line) {
                if ($line->getMissionIntervention() !== null) {
                    $lineByIntervention[(int) $line->getMissionIntervention()->getId()] = $line;
                } elseif ($line->getMaterialLine() !== null) {
                    $lineByMaterial[(int) $line->getMaterialLine()->getId()] = $line;
                } else {
                    $orphanLines[] = $line;
                }
            }

            $partiallyInvoiced = false;
            foreach ($firmLines as $line) {
                if ($line->getFirmInvoiceLine() !== null) {
                    $partiallyInvoiced = true;
                    break;
                }
            }

            $validated = $mission->getStatus() === MissionStatus::VALIDATED;
            $seenLineIds = [];

            if ($validated) {
                foreach ($interventionsByMission[$missionId] ?? [] as $itv) {
                    $line = $lineByIntervention[$itv->getId()] ?? null;
                    if ($line !== null) {
                        $seenLineIds[$line->getId()] = true;
                    }
                    $missionRows[] = $this->interventionRow($itv, $context, $calculation, $line, $failure !== null, $anomalyByIntervention[$itv->getId()] ?? null, $missionAnomalies, $partiallyInvoiced);
                }
                foreach ($materialsByMission[$missionId] ?? [] as $ml) {
                    $line = $lineByMaterial[(int) $ml->getId()] ?? null;
                    if ($line !== null) {
                        $seenLineIds[$line->getId()] = true;
                    } elseif ((float) $ml->getQuantity() <= 0) {
                        continue; // matériel retiré (quantité 0, D-122) et jamais valorisé
                    }
                    $missionRows[] = $this->materialRow($ml, $context, $calculation, $line, $failure !== null, $anomalyByMaterial[(int) $ml->getId()] ?? null, $missionAnomalies, $partiallyInvoiced);
                }
            }

            // Toute ligne FIRM du calcul actif non couverte ci-dessus (mission rouverte,
            // source supprimée, matériel ramené à 0) reste visible.
            foreach ($firmLines as $line) {
                if (!isset($seenLineIds[$line->getId()])) {
                    $missionRows[] = $this->financialLineRow($line, $context, $calculation, $validated, $partiallyInvoiced);
                }
            }

            if ($missionRows === []) {
                continue; // aucune activité firme (ni intervention, ni matériel)
            }

            foreach ($missionRows as $row) {
                if ($row['firm'] !== null) {
                    $missionFirms[$missionId][$row['firm']['id']] = true;
                }
            }

            // Anomalies de workflow (une par mission ou par élément concerné).
            if ($calculation === null && $failure === null && $validated) {
                $missionAnomalies[] = $this->workflowAnomaly(FirmBillingReason::CALCULATION_REQUIRED, $context, null, null, null, 'CALCULATE', 'Calculer');
            }
            if ($calculation !== null && $calculation->getStatus() === FinancialCalculationStatus::CALCULATED) {
                $missionAnomalies[] = $this->workflowAnomaly(
                    FirmBillingReason::CALCULATION_PENDING_APPROVAL, $context, $calculation, null, null, 'APPROVE', 'Approuver',
                    sprintf('Les montants sont calculés (%s) mais doivent être approuvés avant facturation.', $this->formatTotals($firmLines)),
                );
            }
            foreach ($missionRows as $row) {
                if ($row['reasonCode'] === FirmBillingReason::RECALCULATION_REQUIRED->value) {
                    $locked = $calculation?->getStatus() === FinancialCalculationStatus::LOCKED;
                    $missionAnomalies[] = $this->workflowAnomaly(
                        FirmBillingReason::RECALCULATION_REQUIRED, $context, $calculation, $row['firm'], ['type' => $row['sourceType'], 'label' => $row['label']],
                        $locked ? null : 'RECALCULATE', $locked ? null : 'Recalculer',
                        $locked
                            ? sprintf('« %s » a été encodé après le calcul, mais celui-ci est verrouillé par une facture : une correction (note de crédit/débit) sera nécessaire.', $row['label'])
                            : sprintf('« %s » a été encodé après le dernier calcul : relancez le calcul pour le valoriser.', $row['label']),
                        $row['key'],
                    );
                }
            }
            $anomalyRowKeys = array_fill_keys(array_filter(array_column($missionAnomalies, 'rowKey')), true);
            foreach ($missionRows as $row) {
                if ($row['reasonCode'] === FirmBillingReason::MISSING_PRIMARY_FIRM->value && !isset($anomalyRowKeys[$row['key']])) {
                    $missionAnomalies[] = $this->workflowAnomaly(
                        FirmBillingReason::MISSING_PRIMARY_FIRM, $context, $calculation, null, ['type' => $row['sourceType'], 'label' => $row['label']],
                        'OPEN_MISSION', "Compléter l'encodage",
                        sprintf("L'intervention « %s » n'a pas de firme : impossible de savoir à qui la facturer. Le calcul de la mission échouera tant qu'elle manque.", $row['label']),
                        $row['key'],
                    );
                }
            }
            if (!$validated && array_filter($missionRows, static fn (array $r) => $r['reasonCode'] === FirmBillingReason::ENCODING_REOPENED->value) !== []) {
                $missionAnomalies[] = $this->workflowAnomaly(FirmBillingReason::ENCODING_REOPENED, $context, $calculation, null, null, 'OPEN_MISSION', 'Ouvrir la mission');
            }

            array_push($rows, ...$missionRows);
            array_push($anomalies, ...$missionAnomalies);
        }

        return [$rows, $anomalies, $missionFirms];
    }

    private function interventionRow(MissionIntervention $itv, array $context, ?FinancialCalculation $calculation, ?FinancialCalculationLine $line, bool $failed, ?array $ownAnomaly, array $missionAnomalies, bool $partiallyInvoiced): array
    {
        $firm = $itv->getPrimaryFirm();
        $base = [
            'key' => 'MISSION_INTERVENTION:' . $itv->getId(),
            'sourceType' => self::TYPE_INTERVENTION,
            'sourceId' => $itv->getId(),
            'mission' => $context,
            'firm' => $firm !== null ? ['id' => $firm->getId(), 'name' => $firm->getName()] : null,
            'label' => $itv->getLabel() ?? $itv->getInterventionType()?->getLabel(),
            'reference' => null,
            'quantity' => '1',
        ];

        if ($line !== null) {
            return $base + $this->classifyLine($line, $calculation, true, $partiallyInvoiced);
        }

        $exclusion = $this->interventionExclusion($itv);

        return $base + $this->classifyWithoutLine($calculation, $failed, $ownAnomaly, $missionAnomalies, $exclusion, $itv->getPrimaryFirm() === null);
    }

    private function materialRow(MaterialLine $ml, array $context, ?FinancialCalculation $calculation, ?FinancialCalculationLine $line, bool $failed, ?array $ownAnomaly, array $missionAnomalies, bool $partiallyInvoiced): array
    {
        $item = $ml->getItem();
        $firm = $item?->getFirm();
        $base = [
            'key' => 'MATERIAL_LINE:' . $ml->getId(),
            'sourceType' => self::TYPE_MATERIAL,
            'sourceId' => $ml->getId(),
            'mission' => $context,
            'firm' => $firm !== null ? ['id' => $firm->getId(), 'name' => $firm->getName()] : null,
            'label' => $item?->getLabel(),
            'reference' => $item?->getReferenceCode(),
            'quantity' => $this->formatQuantity($ml->getQuantity()),
        ];

        if ($line !== null) {
            return $base + $this->classifyLine($line, $calculation, true, $partiallyInvoiced);
        }

        $exclusion = $item !== null && $item->getBillingStatus() === MaterialBillingStatus::NOT_BILLABLE
            ? [FirmBillingReason::MATERIAL_NOT_BILLABLE, sprintf('« %s » est marqué « non facturable » dans le catalogue %s : aucun tarif ne s\'applique.', $item->getLabel(), $firm?->getName() ?? '')]
            : null;

        return $base + $this->classifyWithoutLine($calculation, $failed, $ownAnomaly, $missionAnomalies, $exclusion, false);
    }

    /** Ligne FIRM du calcul actif sans source encodée correspondante. */
    private function financialLineRow(FinancialCalculationLine $line, array $context, FinancialCalculation $calculation, bool $missionValidated, bool $partiallyInvoiced): array
    {
        $material = $line->getMaterialLine();
        $item = $material?->getItem();
        $intervention = $line->getMissionIntervention();
        $isMaterial = $line->getLineType() === \App\Enum\FinancialLineType::FIRM_MATERIAL_FEE;
        $snapshot = $line->getSnapshot();

        $base = [
            'key' => $material !== null ? 'MATERIAL_LINE:' . $material->getId() : ($intervention !== null ? 'MISSION_INTERVENTION:' . $intervention->getId() : 'FINANCIAL_LINE:' . $line->getId()),
            'sourceType' => $isMaterial ? self::TYPE_MATERIAL : self::TYPE_INTERVENTION,
            'sourceId' => $material?->getId() ?? $intervention?->getId(),
            'mission' => $context,
            'firm' => ['id' => $line->getBeneficiaryFirm()?->getId(), 'name' => $line->getBeneficiaryFirm()?->getName()],
            'label' => $isMaterial
                ? ($item?->getLabel() ?? ($snapshot['materialNameSnapshot'] ?? $line->getDescriptionSnapshot()))
                : ($intervention?->getInterventionType()?->getLabel() ?? ($snapshot['interventionLabelSnapshot'] ?? $line->getDescriptionSnapshot())),
            'reference' => $item?->getReferenceCode(),
            'quantity' => $this->formatQuantity($line->getQuantity()),
        ];

        return $base + $this->classifyLine($line, $calculation, $missionValidated, $partiallyInvoiced);
    }

    /** Classement d'un élément qui a une ligne FIRM dans le calcul actif. */
    private function classifyLine(FinancialCalculationLine $line, ?FinancialCalculation $calculation, bool $missionValidated, bool $partiallyInvoiced): array
    {
        $invoiceLine = $line->getFirmInvoiceLine();
        if ($invoiceLine !== null && $invoiceLine->getInvoice()->getStatus() === InvoiceStatus::DRAFT) {
            $draft = $invoiceLine->getInvoice();
            return $this->state(FirmBillingReason::IN_DRAFT, sprintf(
                'Dans le brouillon %s #%d, pas encore généré. Pour la placer dans un autre brouillon, utilisez « Déplacer vers… ».',
                $draft->getFirm()?->getName() ?? '', $draft->getId(),
            ), $line, $line->getTotalAmount(), $draft);
        }
        if ($invoiceLine !== null) {
            $invoice = $invoiceLine->getInvoice();
            return $this->state(FirmBillingReason::INVOICED, sprintf('Facturé sur la facture %s (%s).', $invoice->getNumber() ?? '#' . $invoice->getId(), $this->invoiceStatusLabel($invoice->getStatus())), $line, $invoiceLine->getTotalAmount(), $invoice);
        }

        if (!$missionValidated) {
            return $this->state(FirmBillingReason::ENCODING_REOPENED, null, $line, $line->getTotalAmount());
        }

        if ($calculation?->getStatus() === FinancialCalculationStatus::CALCULATED) {
            return $this->state(FirmBillingReason::CALCULATION_PENDING_APPROVAL, null, $line, $line->getTotalAmount());
        }

        if ((float) $line->getTotalAmount() == 0.0) {
            $adjustment = $line->getSnapshot()['adjustmentReasonSnapshot'] ?? null;
            if (is_string($adjustment) && $adjustment !== '') {
                return $this->state(FirmBillingReason::REPRESENTATIVE_PRESENT, $adjustment, $line, $line->getTotalAmount());
            }
            return $this->state(FirmBillingReason::ZERO_AMOUNT, null, $line, $line->getTotalAmount());
        }

        $detail = $partiallyInvoiced
            ? 'Facturable. Calcul verrouillé : une autre partie de cette mission est déjà facturée.'
            : null;

        return $this->state(FirmBillingReason::BILLABLE, $detail, $line, $line->getTotalAmount(), null, canInvoice: true);
    }

    /**
     * Classement d'un élément SANS ligne FIRM dans le calcul actif (ou sans calcul).
     *
     * @param array{0: FirmBillingReason, 1: string}|null $exclusion
     */
    private function classifyWithoutLine(?FinancialCalculation $calculation, bool $failed, ?array $ownAnomaly, array $missionAnomalies, ?array $exclusion, bool $missingFirm): array
    {
        if ($ownAnomaly !== null && $calculation === null) {
            return $this->state(FirmBillingReason::from($ownAnomaly['code']), $ownAnomaly['explanation']);
        }
        if ($exclusion !== null) {
            return $this->state($exclusion[0], $exclusion[1]);
        }
        if ($missingFirm) {
            return $this->state(FirmBillingReason::MISSING_PRIMARY_FIRM, null);
        }
        if ($calculation !== null) {
            return $this->state(FirmBillingReason::RECALCULATION_REQUIRED, null);
        }
        if ($failed) {
            $titles = array_values(array_unique(array_map(static fn (array $a) => $a['title'], $missionAnomalies)));
            return $this->state(FirmBillingReason::CALCULATION_BLOCKED, sprintf(
                'Cet élément est correct, mais le calcul de la mission est bloqué par : %s.',
                $titles !== [] ? implode(', ', array_map('mb_strtolower', $titles)) : 'une anomalie',
            ));
        }

        return $this->state(FirmBillingReason::CALCULATION_REQUIRED, null);
    }

    /**
     * Même ordre que FinancialCalculationService::resolveFirmInterventionLine() : firme et
     * prestation renseignées, présence du délégué répondue si pertinente, puis
     * feeApplicable. Toute autre situation n'est pas une exclusion.
     *
     * @return array{0: FirmBillingReason, 1: string}|null
     */
    private function interventionExclusion(MissionIntervention $itv): ?array
    {
        $firm = $itv->getPrimaryFirm();
        $type = $itv->getInterventionType();
        if ($firm === null || $type === null) {
            return null;
        }
        $policy = $this->policy($firm, $type);
        if ($policy->representativePresenceRelevant && $itv->getRepresentativePresent() === null) {
            return null;
        }
        if (!$policy->feeApplicable) {
            return [FirmBillingReason::FEE_NOT_APPLICABLE, sprintf('%s ne prévoit aucun forfait pour « %s » (décision commerciale) : rien n\'est facturé.', $firm->getName(), $type->getLabel())];
        }

        return null;
    }

    private function state(FirmBillingReason $reason, ?string $detail, ?FinancialCalculationLine $line = null, ?string $amount = null, ?FirmInvoice $invoice = null, bool $canInvoice = false): array
    {
        return [
            'billingStatus' => $reason->status()->value,
            'billingStatusLabel' => $reason->status()->label(),
            'reasonCode' => $reason->value,
            'reasonLabel' => $reason->label(),
            'reasonDetail' => $detail ?? $reason->defaultDetail(),
            'amount' => $amount,
            'currency' => $line?->getCurrency(),
            // D-134 — appartenance ACTUELLE à un document (l'historique est servi à part).
            'currentInvoice' => $invoice !== null ? [
                'id' => $invoice->getId(),
                'number' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'statusLabel' => $this->invoiceStatusLabel($invoice->getStatus()),
                'firmName' => $invoice->getFirm()?->getName(),
                'editable' => $invoice->getStatus() === InvoiceStatus::DRAFT,
            ] : null,
            'invoiceState' => $this->invoiceState($invoice),
            'invoiceStateLabel' => self::INVOICE_STATE_LABELS[$this->invoiceState($invoice)],
            'financialLineId' => $line?->getId(),
            'calculationId' => $line?->getFinancialCalculation()?->getId(),
            'canInvoice' => $canInvoice,
            // D-135 — ligne dans un brouillon : jamais ajoutée ailleurs, seulement déplacée.
            'canMoveToDraft' => $reason === FirmBillingReason::IN_DRAFT,
        ];
    }

    // ── Anomalies (« À corriger ») ──────────────────────────────────────────

    /**
     * @param array{code?: string, message?: string, context?: array<string, mixed>} $raw
     * @param MissionIntervention[] $interventions
     * @param MaterialLine[]        $materials
     */
    private function translateAnomaly(array $raw, Mission $mission, array $context, array $interventions, array $materials, \DateTimeImmutable $failedAt, ?FinancialCalculation $calculation, int $index): array
    {
        $reason = FirmBillingReason::fromEngineCode((string) ($raw['code'] ?? ''));
        $ctx = (array) ($raw['context'] ?? []);
        $date = $failedAt->format('d/m/Y');
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
                $element = ['type' => self::TYPE_INTERVENTION, 'label' => $type?->getLabel() ?? $itv?->getLabel()];
                $explanation = sprintf("Aucun tarif applicable n'est configuré pour cette prestation%s au %s.", $firm !== null ? ' chez ' . $firm->getName() : '', $date);
                $action = ['code' => 'CONFIGURE_INTERVENTION_RATE', 'label' => 'Configurer le tarif'];
                $resolved = $itv === null || ($itv->getPrimaryFirm() !== null && $itv->getInterventionType() !== null
                    && $this->pricingRuleResolver->resolveInterventionFee($itv->getPrimaryFirm(), $itv->getInterventionType(), $nextEffectiveAt, $itv->getSelectedChoiceOption()) !== null);
                break;

            case FirmBillingReason::MISSING_FIRM_MATERIAL_RATE:
                $item = $ml?->getItem();
                $element = ['type' => self::TYPE_MATERIAL, 'label' => $item?->getLabel(), 'reference' => $item?->getReferenceCode()];
                $explanation = sprintf("Aucun tarif applicable n'est configuré pour ce matériel%s au %s. S'il n'est jamais facturé, marquez-le « non facturable » dans le catalogue.", $firm !== null ? ' (' . $firm->getName() . ')' : '', $date);
                $action = ['code' => 'CONFIGURE_MATERIAL_RATE', 'label' => 'Configurer le tarif'];
                $resolved = $item === null || $item->getBillingStatus() === MaterialBillingStatus::NOT_BILLABLE
                    || $this->pricingRuleResolver->resolveMaterialFee($item, $nextEffectiveAt) !== null;
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
                $element = ['type' => self::TYPE_INTERVENTION, 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
                $explanation = sprintf("L'intervention « %s » n'a pas de firme : impossible de savoir à qui la facturer.", $element['label'] ?? '—');
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $resolved = $itv === null || $itv->getPrimaryFirm() !== null;
                break;

            case FirmBillingReason::MISSING_INTERVENTION_TYPE:
                $element = ['type' => self::TYPE_INTERVENTION, 'label' => $itv?->getLabel()];
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $resolved = $itv === null || $itv->getInterventionType() !== null;
                break;

            case FirmBillingReason::MISSING_REPRESENTATIVE_PRESENCE_ANSWER:
                $element = ['type' => self::TYPE_INTERVENTION, 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
                $explanation = sprintf("L'encodage doit indiquer si le délégué %s était présent : cela détermine le forfait.", $firm?->getName() ?? 'de la firme');
                $action = ['code' => 'OPEN_MISSION', 'label' => "Compléter l'encodage"];
                $resolved = $itv === null || $itv->getRepresentativePresent() !== null;
                break;

            case FirmBillingReason::MISSING_REQUIRED_CHOICE_ANSWER:
                $element = ['type' => self::TYPE_INTERVENTION, 'label' => $itv?->getInterventionType()?->getLabel() ?? $itv?->getLabel()];
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
            'key' => sprintf('%s:%d:%d', $reason->value, $mission->getId(), $index),
            'code' => $reason->value,
            'title' => $reason->label(),
            'explanation' => $explanation,
            'mission' => $context,
            'firm' => $firm !== null ? ['id' => $firm->getId(), 'name' => $firm->getName()] : null,
            'element' => $element,
            'action' => $action,
            'resolved' => $resolved,
            'calculationId' => $calculation?->getId(),
            'rowKey' => $itv !== null ? 'MISSION_INTERVENTION:' . $itv->getId() : ($ml !== null ? 'MATERIAL_LINE:' . $ml->getId() : null),
            'calculationLocked' => $calculation?->getStatus() === FinancialCalculationStatus::LOCKED,
        ];
    }

    private function workflowAnomaly(FirmBillingReason $reason, array $context, ?FinancialCalculation $calculation, ?array $firm, ?array $element, ?string $actionCode, ?string $actionLabel, ?string $explanation = null, ?string $rowKey = null): array
    {
        return [
            'key' => sprintf('%s:%d:%s', $reason->value, $context['id'], $rowKey ?? 'mission'),
            'code' => $reason->value,
            'title' => $reason->label(),
            'explanation' => $explanation ?? $reason->defaultDetail(),
            'mission' => $context,
            'firm' => $firm,
            'element' => $element,
            'action' => $actionCode !== null ? ['code' => $actionCode, 'label' => $actionLabel] : null,
            'resolved' => false,
            'calculationId' => $calculation?->getId(),
            'rowKey' => $rowKey,
            'calculationLocked' => $calculation?->getStatus() === FinancialCalculationStatus::LOCKED,
        ];
    }

    /**
     * Actions groupées proposées par « À corriger » :
     *  - recalculateFixed : missions en échec (ou dont le recalcul a échoué) dont TOUTES les
     *    anomalies sont résolues dans la configuration actuelle ;
     *  - calculatePending : missions validées jamais valorisées, sans échec.
     *
     * @return array{recalculateFixed: int[], calculatePending: int[]}
     */
    private function bulkActions(array $anomalies): array
    {
        $engine = [];
        $pending = [];
        foreach ($anomalies as $a) {
            $missionId = $a['mission']['id'];
            if (FirmBillingReason::from($a['code'])->isEngineAnomaly()) {
                $engine[$missionId] = ($engine[$missionId] ?? true) && $a['resolved'] && !$a['calculationLocked'];
            } elseif ($a['code'] === FirmBillingReason::CALCULATION_REQUIRED->value) {
                $pending[$missionId] = true;
            }
        }

        return [
            'recalculateFixed' => array_keys(array_filter($engine)),
            'calculatePending' => array_keys($pending),
        ];
    }

    // ── Tuiles ──────────────────────────────────────────────────────────────

    private function summary(array $rows, array $anomalies, \DateTimeImmutable $from, \DateTimeImmutable $to, array $firmFilter): array
    {
        $counts = array_fill_keys(array_map(static fn (FirmBillingStatus $s) => $s->value, FirmBillingStatus::cases()), 0);
        $billableAmounts = [];
        $invoicedAmounts = [];
        foreach ($rows as $row) {
            $counts[$row['billingStatus']]++;
            if ($row['billingStatus'] === FirmBillingStatus::BILLABLE->value) {
                $billableAmounts[$row['currency']] = $this->add($billableAmounts[$row['currency']] ?? '0.00', $row['amount']);
            }
            if ($row['billingStatus'] === FirmBillingStatus::INVOICED->value) {
                $invoicedAmounts[$row['currency']] = $this->add($invoicedAmounts[$row['currency']] ?? '0.00', $row['amount']);
            }
        }

        return [
            'lineCount' => count($rows),
            'billable' => ['lineCount' => $counts[FirmBillingStatus::BILLABLE->value], 'amounts' => $this->amountList($billableAmounts)],
            'notBillable' => ['lineCount' => $counts[FirmBillingStatus::NOT_BILLABLE->value]],
            'toReview' => ['lineCount' => $counts[FirmBillingStatus::TO_REVIEW->value]],
            'invoiced' => ['lineCount' => $counts[FirmBillingStatus::INVOICED->value], 'amounts' => $this->amountList($invoicedAmounts)],
            'anomalyCount' => count($anomalies),
            'pendingValidationMissionCount' => $this->pendingValidationCount($from, $to),
            'invoices' => $this->invoiceCounts($from, $to, array_keys($firmFilter)),
        ];
    }

    private function pendingValidationCount(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT m.id)')
            ->from(Mission::class, 'm')
            ->leftJoin('m.execution', 'exec')
            ->where('m.status = :submitted')
            ->andWhere('COALESCE(exec.actualStartAt, m.startAt) BETWEEN :from AND :to')
            ->setParameter('submitted', MissionStatus::SUBMITTED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param int[] $firmIds */
    private function invoiceCounts(\DateTimeImmutable $from, \DateTimeImmutable $to, array $firmIds): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('i.status AS status', 'COUNT(i.id) AS cnt')
            ->from(FirmInvoice::class, 'i')
            ->where('i.documentType = :standard')
            ->andWhere('i.periodStart BETWEEN :from AND :to')
            ->setParameter('standard', FinancialDocumentType::STANDARD)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('i.status');
        if ($firmIds !== []) {
            $qb->andWhere('IDENTITY(i.firm) IN (:firms)')->setParameter('firms', $firmIds);
        }
        $byStatus = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof InvoiceStatus ? $row['status']->value : (string) $row['status'];
            $byStatus[$status] = (int) $row['cnt'];
        }

        return [
            'draft' => $byStatus[InvoiceStatus::DRAFT->value] ?? 0,
            'generated' => $byStatus[InvoiceStatus::GENERATED->value] ?? 0,
            'sent' => $byStatus[InvoiceStatus::SENT->value] ?? 0,
            'paid' => $byStatus[InvoiceStatus::PAID->value] ?? 0,
            'cancelled' => $byStatus[InvoiceStatus::CANCELLED->value] ?? 0,
            // D-137 — compté à part, jamais comme une facture annulée.
            'abandoned' => $byStatus[InvoiceStatus::ABANDONED->value] ?? 0,
        ];
    }

    // ── Chargement ──────────────────────────────────────────────────────────

    /**
     * Missions VALIDATED dont la date effective (réelle sinon planifiée — même règle que
     * FinancialCalculationService::resolveEffectiveAt()) tombe dans la période, plus toute
     * mission ayant une ligne FIRM active datée de la période (mission rouverte depuis).
     *
     * @return int[]
     */
    private function missionIdsInPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $validated = $this->em->createQueryBuilder()
            ->select('m.id')
            ->from(Mission::class, 'm')
            ->leftJoin('m.execution', 'exec')
            ->where('m.status = :validated')
            ->andWhere('COALESCE(exec.actualStartAt, m.startAt) BETWEEN :from AND :to')
            ->setParameter('validated', MissionStatus::VALIDATED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleColumnResult();

        $withLines = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(fc.mission)')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.financialCalculation', 'fc')
            ->where('l.beneficiaryType = :firm')
            ->andWhere('fc.status IN (:active)')
            ->andWhere('l.effectiveAt BETWEEN :from AND :to')
            ->setParameter('firm', FinancialBeneficiaryType::FIRM)
            ->setParameter('active', self::ACTIVE_STATUSES)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_unique(array_map('intval', [...$validated, ...$withLines])));
    }

    /** @return Mission[] triées par date effective puis id */
    private function loadMissions(array $ids): array
    {
        /** @var Mission[] $missions */
        $missions = $this->em->createQueryBuilder()
            ->select('m', 'site', 'surgeon', 'exec', 'instr')
            ->from(Mission::class, 'm')
            ->leftJoin('m.site', 'site')
            ->leftJoin('m.surgeon', 'surgeon')
            ->leftJoin('m.execution', 'exec')
            ->leftJoin('m.instrumentist', 'instr')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        usort($missions, fn (Mission $a, Mission $b) => [$this->financialCalculationService->resolveEffectiveAt($a), $a->getId()] <=> [$this->financialCalculationService->resolveEffectiveAt($b), $b->getId()]);

        return $missions;
    }

    /** @return array<int, MissionIntervention[]> */
    private function loadInterventions(array $missionIds): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('mi', 'it', 'pf', 'choice')
            ->from(MissionIntervention::class, 'mi')
            ->leftJoin('mi.interventionType', 'it')
            ->leftJoin('mi.primaryFirm', 'pf')
            ->leftJoin('mi.selectedChoiceOption', 'choice')
            ->where('IDENTITY(mi.mission) IN (:ids)')
            ->setParameter('ids', $missionIds)
            ->orderBy('mi.orderIndex', 'ASC')
            ->addOrderBy('mi.id', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $mi) {
            $out[(int) $mi->getMission()->getId()][] = $mi;
        }
        return $out;
    }

    /** @return array<int, MaterialLine[]> */
    private function loadMaterialLines(array $missionIds): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('ml', 'item', 'itemFirm')
            ->from(MaterialLine::class, 'ml')
            ->join('ml.item', 'item')
            ->leftJoin('item.firm', 'itemFirm')
            ->where('IDENTITY(ml.mission) IN (:ids)')
            ->setParameter('ids', $missionIds)
            ->orderBy('ml.id', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $ml) {
            $out[(int) $ml->getMission()->getId()][] = $ml;
        }
        return $out;
    }

    /** @return array<int, FinancialCalculation> calcul actif (version la plus haute) par mission */
    private function loadActiveCalculations(array $missionIds): array
    {
        $calcs = $this->em->createQueryBuilder()
            ->select('fc')
            ->from(FinancialCalculation::class, 'fc')
            ->where('IDENTITY(fc.mission) IN (:ids)')
            ->andWhere('fc.status IN (:active)')
            ->setParameter('ids', $missionIds)
            ->setParameter('active', self::ACTIVE_STATUSES)
            ->orderBy('fc.version', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($calcs as $calc) {
            $out[(int) $calc->getMission()->getId()] = $calc; // la plus haute version l'emporte
        }
        return $out;
    }

    /** @return array<int, FinancialCalculationLine[]> lignes FIRM par calcul */
    private function loadFirmLines(array $calculationIds): array
    {
        if ($calculationIds === []) {
            return [];
        }
        $lines = $this->em->createQueryBuilder()
            ->select('l', 'bf', 'fil', 'inv', 'mi', 'it', 'ml', 'item')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.beneficiaryFirm', 'bf')
            ->leftJoin('l.firmInvoiceLine', 'fil')
            ->leftJoin('fil.invoice', 'inv')
            ->leftJoin('l.missionIntervention', 'mi')
            ->leftJoin('mi.interventionType', 'it')
            ->leftJoin('l.materialLine', 'ml')
            ->leftJoin('ml.item', 'item')
            ->where('IDENTITY(l.financialCalculation) IN (:ids)')
            ->andWhere('l.beneficiaryType = :firm')
            ->setParameter('ids', array_values($calculationIds))
            ->setParameter('firm', FinancialBeneficiaryType::FIRM)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($lines as $line) {
            $out[(int) $line->getFinancialCalculation()->getId()][] = $line;
        }
        return $out;
    }

    /**
     * Dernier échec audité par mission (payload écrit par FinancialCalculationService).
     *
     * @param int[] $missionIds
     * @return array<int, array{anomalies: array<int, array>, effectiveAt: ?\DateTimeImmutable}>
     */
    private function latestFailures(array $missionIds): array
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
            ];
        }
        return $out;
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    private function policy(Firm $firm, InterventionType $type): \App\Dto\RepresentativePolicy
    {
        return $this->policyCache[$firm->getId() . '|' . $type->getId()] ??= $this->representativePolicyResolver->resolve($firm, $type);
    }

    /** Contexte métier d'une mission — aucune donnée patient. */
    private function missionContext(Mission $mission, \DateTimeInterface $effectiveAt): array
    {
        return [
            'id' => (int) $mission->getId(),
            'date' => $effectiveAt->format('Y-m-d'),
            'status' => $mission->getStatus()->value,
            'site' => $mission->getSite()?->getName(),
            'surgeon' => $mission->getSurgeon()?->getDrName(),
        ];
    }

    /**
     * D-135 — une ligne sans document via sa ligne financière ACTIVE peut encore figurer dans
     * un brouillon par une version périmée (calcul recalculé depuis l'ajout). Elle n'est
     * alors ni libre ni facturable : elle est signalée et une anomalie mène au brouillon.
     *
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    private function withStaleDraftMembership(array $rows, array $anomalies): array
    {
        $candidates = ['material' => [], 'intervention' => []];
        foreach ($rows as $row) {
            if ($row['currentInvoice'] === null && $row['sourceId'] !== null) {
                $candidates[$row['sourceType'] === self::TYPE_MATERIAL ? 'material' : 'intervention'][] = (int) $row['sourceId'];
            }
        }
        if ($candidates['material'] === [] && $candidates['intervention'] === []) {
            return [$rows, $anomalies];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('fil', 'i', 'f')
            ->from(FirmInvoiceLine::class, 'fil')
            ->join('fil.invoice', 'i')
            ->join('i.firm', 'f')
            ->where('i.status = :draft')
            ->setParameter('draft', InvoiceStatus::DRAFT);
        $or = [];
        if ($candidates['material'] !== []) {
            $or[] = 'IDENTITY(fil.materialLine) IN (:materials)';
            $qb->setParameter('materials', $candidates['material']);
        }
        if ($candidates['intervention'] !== []) {
            $or[] = '(fil.materialLine IS NULL AND IDENTITY(fil.missionIntervention) IN (:interventions))';
            $qb->setParameter('interventions', $candidates['intervention']);
        }
        $qb->andWhere(implode(' OR ', $or));

        $bySource = [];
        foreach ($qb->getQuery()->getResult() as $fil) {
            $key = $fil->getMaterialLine() !== null ? self::TYPE_MATERIAL . ':' . $fil->getMaterialLine()->getId() : self::TYPE_INTERVENTION . ':' . $fil->getMissionIntervention()?->getId();
            $bySource[$key] = $fil->getInvoice();
        }
        if ($bySource === []) {
            return [$rows, $anomalies];
        }

        foreach ($rows as &$row) {
            $draft = $row['currentInvoice'] === null && $row['sourceId'] !== null ? ($bySource[$row['sourceType'] . ':' . $row['sourceId']] ?? null) : null;
            if ($draft === null) {
                continue;
            }
            // montant et ligne financière ACTIVE conservés ; seuls l'état et le motif changent.
            $row = array_merge($row, array_diff_key($this->state(FirmBillingReason::DRAFT_LINE_STALE, null, null, null, $draft), array_flip(['amount', 'currency', 'financialLineId', 'calculationId'])));
            $anomalies[] = $this->workflowAnomaly(
                FirmBillingReason::DRAFT_LINE_STALE, $row['mission'], null, $row['firm'], ['type' => $row['sourceType'], 'label' => $row['label']],
                'OPEN_DRAFT', 'Ouvrir le brouillon',
                sprintf('« %s » figure dans le brouillon %s #%d avec un ancien calcul : retirez-la du brouillon puis ajoutez la ligne à jour.', $row['label'], $draft->getFirm()?->getName() ?? '', $draft->getId()),
                $row['key'],
            ) + ['invoiceId' => $draft->getId(), 'focusLine' => $row['sourceType'] . ':' . $row['sourceId']];
        }
        unset($row);

        return [$rows, $anomalies];
    }

    private function invoiceState(?FirmInvoice $invoice): string
    {
        return match ($invoice?->getStatus()) {
            null, InvoiceStatus::CANCELLED, InvoiceStatus::ABANDONED => 'FREE',
            InvoiceStatus::DRAFT => 'IN_DRAFT',
            InvoiceStatus::GENERATED => 'GENERATED',
            InvoiceStatus::SENT => 'SENT',
            InvoiceStatus::PAID => 'PAID',
        };
    }

    /**
     * D-134 — `sourceKey` (INTERVENTION:12 / MATERIAL:34, clé du journal et des deep-links)
     * et `hasHistory` : la ligne a déjà un passé documentaire, même si elle est libre.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function withHistoryFlags(array $rows): array
    {
        $ids = [FirmBillingLineEvent::SOURCE_INTERVENTION => [], FirmBillingLineEvent::SOURCE_MATERIAL => []];
        foreach ($rows as $row) {
            if ($row['sourceId'] !== null) {
                $ids[$row['sourceType']][] = (int) $row['sourceId'];
            }
        }
        $withHistory = [];
        foreach ($ids as $sourceType => $sourceIds) {
            if ($sourceIds === []) {
                continue;
            }
            $found = $this->em->createQueryBuilder()
                ->select('DISTINCT e.sourceId')
                ->from(FirmBillingLineEvent::class, 'e')
                ->where('e.sourceType = :type')
                ->andWhere('e.sourceId IN (:ids)')
                ->setParameter('type', $sourceType)
                ->setParameter('ids', array_values(array_unique($sourceIds)))
                ->getQuery()
                ->getSingleColumnResult();
            foreach ($found as $id) {
                $withHistory[$sourceType . ':' . (int) $id] = true;
            }
        }

        foreach ($rows as &$row) {
            $row['sourceKey'] = $row['sourceId'] !== null ? $row['sourceType'] . ':' . $row['sourceId'] : null;
            $row['hasHistory'] = $row['sourceKey'] !== null && isset($withHistory[$row['sourceKey']]);
        }
        unset($row);

        return $rows;
    }

    private function invoiceStatusLabel(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::DRAFT => 'brouillon',
            InvoiceStatus::GENERATED => 'générée',
            InvoiceStatus::SENT => 'envoyée',
            InvoiceStatus::PAID => 'payée',
            InvoiceStatus::CANCELLED => 'facture annulée',
            InvoiceStatus::ABANDONED => 'brouillon abandonné',
        };
    }

    /** @param FinancialCalculationLine[] $lines */
    private function formatTotals(array $lines): string
    {
        $totals = [];
        foreach ($lines as $line) {
            $totals[$line->getCurrency()] = $this->add($totals[$line->getCurrency()] ?? '0.00', $line->getTotalAmount());
        }
        if ($totals === []) {
            return '0,00 €';
        }
        $parts = [];
        foreach ($totals as $currency => $amount) {
            $parts[] = number_format((float) $amount, 2, ',', ' ') . ' ' . ($currency === 'EUR' ? '€' : $currency);
        }
        return implode(' + ', $parts);
    }

    private function formatQuantity(?string $quantity): string
    {
        $q = rtrim(rtrim(number_format((float) $quantity, 4, '.', ''), '0'), '.');
        return $q === '' ? '0' : $q;
    }

    /** @param array<string, string> $amounts */
    private function amountList(array $amounts): array
    {
        ksort($amounts);
        return array_map(static fn (string $c, string $a) => ['currency' => $c, 'amount' => $a], array_keys($amounts), array_values($amounts));
    }

    private function add(string $a, ?string $b): string
    {
        return number_format(round((float) $a + (float) $b, 2), 2, '.', '');
    }
}
