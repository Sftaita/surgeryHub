<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\Firm;
use App\Entity\FirmInvoice;
use App\Entity\Mission;
use App\Enum\AuditEventType;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialDocumentType;
use App\Enum\FinancialLineType;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Repository\EncodingTrackingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-123 — cockpit « Facturation firmes ». Lecture seule, aucun calcul tarifaire : ne fait
 * que CLASSER des données déjà persistées. Source de vérité : FinancialCalculationLine
 * (bénéficiaire FIRM) d'un calcul actif, jamais les interventions/le matériel bruts.
 *
 * Invariant : chaque FinancialCalculationLine FIRM de la période tombe dans EXACTEMENT une
 * catégorie —
 *  - « facturée »   : une FirmInvoiceLine la référence (contrainte UNIQUE) ;
 *  - « à facturer » : calcul APPROVED ou LOCKED, aucune FirmInvoiceLine ;
 *  - « à vérifier » : calcul encore CALCULATED (approbation requise).
 * Et chaque mission de la période qui concerne une firme mais n'a AUCUN calcul actif
 * apparaît dans « à vérifier » avec sa raison (encodage non validé, calcul requis, calcul
 * en échec + anomalies auditées) — jamais une disparition silencieuse.
 *
 * Période : dates métier inclusives (Europe/Brussels) sur la date effective du calcul
 * (FinancialCalculationLine.effectiveAt = horaires réels sinon planifiés,
 * FinancialCalculationService::resolveEffectiveAt()) ; même règle
 * COALESCE(actual_start_at, start_at) pour les missions pas encore valorisées.
 */
final class FirmBillingCockpitService
{
    public const REASON_ENCODING_NOT_VALIDATED = 'ENCODING_NOT_VALIDATED';
    public const REASON_CALCULATION_REQUIRED = 'CALCULATION_REQUIRED';
    public const REASON_CALCULATION_FAILED = 'CALCULATION_FAILED';
    public const REASON_CALCULATION_PENDING_APPROVAL = 'CALCULATION_PENDING_APPROVAL';

    private const REASON_LABELS = [
        self::REASON_ENCODING_NOT_VALIDATED => "Encodage soumis mais pas encore validé : la mission ne peut pas encore être valorisée.",
        self::REASON_CALCULATION_REQUIRED => 'Calcul financier requis : la mission est validée mais n\'a pas encore été valorisée.',
        self::REASON_CALCULATION_FAILED => 'Le dernier calcul financier a échoué : corrigez les points ci-dessous puis relancez le calcul.',
        self::REASON_CALCULATION_PENDING_APPROVAL => 'Calcul à approuver : les montants sont calculés mais pas encore approuvés.',
    ];

    public const LOCKED_NOTICE = 'Calcul financier verrouillé : une partie de cette mission a déjà été facturée. Le calcul ne peut plus être modifié.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EncodingTrackingRepository $encodingTrackingRepository,
    ) {}

    /** @return array<string, mixed> */
    public function build(\DateTimeImmutable $fromDay, \DateTimeImmutable $toDay, ?Firm $firm): array
    {
        $from = $fromDay->setTime(0, 0, 0);
        $to = $toDay->setTime(23, 59, 59);

        $lines = $this->fetchFirmLines($from, $to, $firm);
        $partiallyInvoicedCalculations = $this->calculationsWithInvoicedFirmLines($lines);

        $toInvoiceGroups = [];
        $invoiced = [];
        $pendingApproval = [];

        foreach ($lines as $line) {
            $calculation = $line->getFinancialCalculation();
            $invoiceLine = $line->getFirmInvoiceLine();

            if ($invoiceLine !== null) {
                $invoice = $invoiceLine->getInvoice();
                $invoiced[] = $this->serializeLine($line) + [
                    'invoice' => [
                        'id' => $invoice->getId(),
                        'number' => $invoice->getNumber(),
                        'status' => $invoice->getStatus()->value,
                        'generatedAt' => $invoice->getGeneratedAt()?->format(\DateTimeInterface::ATOM),
                        'invoicedAmount' => $invoiceLine->getTotalAmount(),
                    ],
                ];
                continue;
            }

            if ($calculation->getStatus() === FinancialCalculationStatus::CALCULATED) {
                $pendingApproval[$calculation->getId()][] = $line;
                continue;
            }

            // APPROVED ou LOCKED, jamais rattachée : à facturer.
            $firmEntity = $line->getBeneficiaryFirm();
            $key = $firmEntity->getId() . '|' . $line->getCurrency();
            $toInvoiceGroups[$key] ??= [
                'firm' => ['id' => $firmEntity->getId(), 'name' => $firmEntity->getName()],
                'currency' => $line->getCurrency(),
                'lineCount' => 0,
                'totalAmount' => '0.00',
                'lines' => [],
            ];
            $locked = isset($partiallyInvoicedCalculations[$calculation->getId()]);
            $toInvoiceGroups[$key]['lines'][] = $this->serializeLine($line) + [
                'calculationLocked' => $calculation->getStatus() === FinancialCalculationStatus::LOCKED,
                'missionPartiallyInvoiced' => $locked,
                'notice' => $locked ? self::LOCKED_NOTICE : null,
            ];
            $toInvoiceGroups[$key]['lineCount']++;
            $toInvoiceGroups[$key]['totalAmount'] = $this->add($toInvoiceGroups[$key]['totalAmount'], $line->getTotalAmount());
        }

        $toVerify = [];
        foreach ($pendingApproval as $lines) {
            $toVerify[] = $this->pendingApprovalItem($lines);
        }
        foreach ($this->missionsWithoutActiveCalculation($from, $to, $firm) as $item) {
            $toVerify[] = $item;
        }
        usort($toVerify, static fn (array $a, array $b) => [$a['mission']['date'], $a['mission']['id']] <=> [$b['mission']['date'], $b['mission']['id']]);

        $groups = array_values($toInvoiceGroups);
        usort($groups, static fn (array $a, array $b) => [$a['firm']['name'], $a['currency']] <=> [$b['firm']['name'], $b['currency']]);

        return [
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'kpis' => $this->kpis($groups, $toVerify, $invoiced, $from, $to, $firm),
            'toInvoice' => $groups,
            'toVerify' => $toVerify,
            'invoiced' => $invoiced,
        ];
    }

    /** @return FinancialCalculationLine[] */
    private function fetchFirmLines(\DateTimeImmutable $from, \DateTimeImmutable $to, ?Firm $firm): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('l', 'fc', 'm', 'site', 'surgeon', 'bf', 'fil', 'inv', 'mi', 'ml', 'item')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.financialCalculation', 'fc')
            ->join('fc.mission', 'm')
            ->leftJoin('m.site', 'site')
            ->leftJoin('m.surgeon', 'surgeon')
            ->join('l.beneficiaryFirm', 'bf')
            ->leftJoin('l.firmInvoiceLine', 'fil')
            ->leftJoin('fil.invoice', 'inv')
            ->leftJoin('l.missionIntervention', 'mi')
            ->leftJoin('l.materialLine', 'ml')
            ->leftJoin('ml.item', 'item')
            ->where('l.beneficiaryType = :firmType')
            ->andWhere('fc.status IN (:active)')
            ->andWhere('l.effectiveAt >= :from')
            ->andWhere('l.effectiveAt <= :to')
            ->setParameter('firmType', FinancialBeneficiaryType::FIRM)
            ->setParameter('active', [
                FinancialCalculationStatus::CALCULATED,
                FinancialCalculationStatus::APPROVED,
                FinancialCalculationStatus::LOCKED,
            ])
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('l.effectiveAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->addOrderBy('l.id', 'ASC');

        if ($firm !== null) {
            $qb->andWhere('l.beneficiaryFirm = :firm')->setParameter('firm', $firm);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Calculs dont au moins une ligne FIRM est déjà facturée — leur calcul est LOCKED
     * (FirmInvoiceService::createFromEligibleLines()) et ne peut plus être recalculé :
     * les lignes restantes sont signalées, jamais masquées. Requête dédiée : le filtre
     * firme/période de la liste ne doit pas cacher une facture d'une autre firme.
     *
     * @param FinancialCalculationLine[] $lines
     * @return array<int, true>
     */
    private function calculationsWithInvoicedFirmLines(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            $ids[$line->getFinancialCalculation()->getId()] = true;
        }
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(l.financialCalculation) AS calcId')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.firmInvoiceLine', 'fil')
            ->where('IDENTITY(l.financialCalculation) IN (:ids)')
            ->setParameter('ids', array_keys($ids))
            ->getQuery()
            ->getArrayResult();

        return array_fill_keys(array_map(static fn (array $r) => (int) $r['calcId'], $rows), true);
    }

    /** @param FinancialCalculationLine[] $lines */
    private function pendingApprovalItem(array $lines): array
    {
        $calculation = $lines[0]->getFinancialCalculation();
        $total = '0.00';
        $firms = [];
        foreach ($lines as $line) {
            $total = $this->add($total, $line->getTotalAmount());
            $firms[$line->getBeneficiaryFirm()->getId()] = $line->getBeneficiaryFirm()->getName();
        }

        return [
            'reason' => self::REASON_CALCULATION_PENDING_APPROVAL,
            'reasonLabel' => self::REASON_LABELS[self::REASON_CALCULATION_PENDING_APPROVAL],
            'mission' => $this->missionContext($calculation->getMission(), $lines[0]->getEffectiveAt()),
            'firms' => $this->firmList($firms),
            'calculationId' => $calculation->getId(),
            'anomalies' => [],
            'lines' => array_map($this->serializeLine(...), $lines),
            'totalAmount' => $total,
            'currency' => $lines[0]->getCurrency(),
            'allowedActions' => ['approve'],
        ];
    }

    /**
     * Missions de la période concernant une firme (intervention à firme principale ou
     * matériel d'une firme — mêmes relations que FinancialCalculationService) sans aucun
     * calcul actif. Aucun montant n'existe avant le calcul : jamais estimé ici.
     *
     * @return array<int, array<string, mixed>>
     */
    private function missionsWithoutActiveCalculation(\DateTimeImmutable $from, \DateTimeImmutable $to, ?Firm $firm): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('DISTINCT m.id')
            ->from(Mission::class, 'm')
            ->leftJoin('m.execution', 'exec')
            ->leftJoin('m.interventions', 'itv')
            ->leftJoin('m.materialLines', 'ml')
            ->leftJoin('ml.item', 'item')
            ->where('m.status IN (:statuses)')
            ->andWhere('COALESCE(exec.actualStartAt, m.startAt) >= :from')
            ->andWhere('COALESCE(exec.actualStartAt, m.startAt) <= :to')
            ->andWhere('NOT EXISTS (
                SELECT fc.id FROM ' . FinancialCalculation::class . ' fc
                WHERE fc.mission = m AND fc.status IN (:active)
            )')
            ->setParameter('statuses', [MissionStatus::SUBMITTED, MissionStatus::VALIDATED])
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('active', [
                FinancialCalculationStatus::CALCULATED,
                FinancialCalculationStatus::APPROVED,
                FinancialCalculationStatus::LOCKED,
            ]);

        if ($firm !== null) {
            $qb->andWhere('(itv.primaryFirm = :firm OR (item.firm = :firm AND ml.quantity > 0))')->setParameter('firm', $firm);
        } else {
            $qb->andWhere('(itv.primaryFirm IS NOT NULL OR (item.firm IS NOT NULL AND ml.quantity > 0))');
        }

        $ids = array_map('intval', array_column($qb->getQuery()->getArrayResult(), 'id'));
        if ($ids === []) {
            return [];
        }

        /** @var Mission[] $missions */
        $missions = $this->em->createQueryBuilder()
            ->select('m', 'site', 'surgeon', 'exec', 'itv', 'pf', 'ml', 'item', 'itemFirm')
            ->from(Mission::class, 'm')
            ->leftJoin('m.site', 'site')
            ->leftJoin('m.surgeon', 'surgeon')
            ->leftJoin('m.execution', 'exec')
            ->leftJoin('m.interventions', 'itv')
            ->leftJoin('itv.primaryFirm', 'pf')
            ->leftJoin('m.materialLines', 'ml')
            ->leftJoin('ml.item', 'item')
            ->leftJoin('item.firm', 'itemFirm')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $failed = $this->encodingTrackingRepository->findMissionsWithFailedCalculation($ids);
        $anomaliesByMission = $this->latestFailureAnomalies(array_keys($failed));

        $items = [];
        foreach ($missions as $mission) {
            $firms = [];
            foreach ($mission->getInterventions() as $itv) {
                if ($itv->getPrimaryFirm() !== null) {
                    $firms[$itv->getPrimaryFirm()->getId()] = $itv->getPrimaryFirm()->getName();
                }
            }
            foreach ($mission->getMaterialLines() as $ml) {
                $itemFirm = $ml->getItem()?->getFirm();
                if ($itemFirm !== null && (float) $ml->getQuantity() > 0) {
                    $firms[$itemFirm->getId()] = $itemFirm->getName();
                }
            }

            if ($mission->getStatus() === MissionStatus::SUBMITTED) {
                $reason = self::REASON_ENCODING_NOT_VALIDATED;
                $actions = [];
            } elseif (isset($failed[(int) $mission->getId()])) {
                $reason = self::REASON_CALCULATION_FAILED;
                $actions = ['calculate'];
            } else {
                $reason = self::REASON_CALCULATION_REQUIRED;
                $actions = ['calculate'];
            }

            $effective = $mission->getExecution()?->getActualStartAt() ?? $mission->getStartAt();
            $items[] = [
                'reason' => $reason,
                'reasonLabel' => self::REASON_LABELS[$reason],
                'mission' => $this->missionContext($mission, $effective),
                'firms' => $this->firmList($firms),
                'calculationId' => null,
                'anomalies' => $reason === self::REASON_CALCULATION_FAILED ? ($anomaliesByMission[(int) $mission->getId()] ?? []) : [],
                'lines' => [],
                'totalAmount' => null,
                'currency' => null,
                'allowedActions' => $actions,
            ];
        }

        return $items;
    }

    /**
     * Anomalies du DERNIER échec audité (FINANCIAL_CALCULATION_FAILED, écrit par
     * FinancialCalculationService::buildAndPersist()) — jamais recalculées ici.
     *
     * @param int[] $missionIds
     * @return array<int, array<int, array{code: string, message: string}>>
     */
    private function latestFailureAnomalies(array $missionIds): array
    {
        if ($missionIds === []) {
            return [];
        }

        /** @var AuditEvent[] $events */
        $events = $this->em->createQueryBuilder()
            ->select('a')
            ->from(AuditEvent::class, 'a')
            ->where('a.mission IN (:ids)')
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
            $out[$missionId] = array_map(
                static fn (array $a) => ['code' => (string) ($a['code'] ?? ''), 'message' => (string) ($a['message'] ?? '')],
                (array) ($event->getPayload()['anomalies'] ?? []),
            );
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function kpis(array $groups, array $toVerify, array $invoiced, \DateTimeImmutable $from, \DateTimeImmutable $to, ?Firm $firm): array
    {
        $amounts = [];
        $lineCount = 0;
        foreach ($groups as $group) {
            $lineCount += $group['lineCount'];
            $amounts[$group['currency']] = $this->add($amounts[$group['currency']] ?? '0.00', $group['totalAmount']);
        }

        $qb = $this->em->createQueryBuilder()
            ->select('i.status AS status', 'COUNT(i.id) AS cnt')
            ->from(FirmInvoice::class, 'i')
            ->where('i.documentType = :standard')
            ->andWhere('i.periodStart >= :from')
            ->andWhere('i.periodStart <= :to')
            ->setParameter('standard', FinancialDocumentType::STANDARD)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('i.status');
        if ($firm !== null) {
            $qb->andWhere('i.firm = :firm')->setParameter('firm', $firm);
        }
        $byStatus = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $status = $row['status'] instanceof InvoiceStatus ? $row['status']->value : (string) $row['status'];
            $byStatus[$status] = (int) $row['cnt'];
        }

        ksort($amounts);

        return [
            'toInvoiceLineCount' => $lineCount,
            'toInvoiceAmounts' => array_map(static fn (string $c, string $a) => ['currency' => $c, 'amount' => $a], array_keys($amounts), array_values($amounts)),
            'toVerifyCount' => count($toVerify),
            'invoicedLineCount' => count($invoiced),
            'invoices' => [
                'generated' => $byStatus[InvoiceStatus::GENERATED->value] ?? 0,
                'sent' => $byStatus[InvoiceStatus::SENT->value] ?? 0,
                'paid' => $byStatus[InvoiceStatus::PAID->value] ?? 0,
                'cancelled' => $byStatus[InvoiceStatus::CANCELLED->value] ?? 0,
            ],
        ];
    }

    /** Contexte métier d'une ligne — aucune donnée patient (jamais nom, identifiant ni motif). */
    private function serializeLine(FinancialCalculationLine $line): array
    {
        $calculation = $line->getFinancialCalculation();
        $mission = $calculation->getMission();
        $intervention = $line->getMissionIntervention();
        $material = $line->getMaterialLine();
        $item = $material?->getItem();

        return [
            'id' => $line->getId(),
            'calculationId' => $calculation->getId(),
            'calculationStatus' => $calculation->getStatus()->value,
            'mission' => $this->missionContext($mission, $line->getEffectiveAt()),
            'firm' => ['id' => $line->getBeneficiaryFirm()?->getId(), 'name' => $line->getBeneficiaryFirm()?->getName()],
            'lineType' => $line->getLineType()->value,
            'lineTypeLabel' => $line->getLineType() === FinancialLineType::FIRM_MATERIAL_FEE ? 'Matériel' : 'Intervention',
            'description' => $line->getDescriptionSnapshot(),
            'intervention' => $intervention !== null ? ['id' => $intervention->getId(), 'label' => $intervention->getLabel()] : null,
            'material' => $item !== null ? ['id' => $material->getId(), 'label' => $item->getLabel(), 'referenceCode' => $item->getReferenceCode()] : null,
            'quantity' => $line->getQuantity(),
            'unitAmount' => $line->getUnitAmount(),
            'totalAmount' => $line->getTotalAmount(),
            'currency' => $line->getCurrency(),
        ];
    }

    private function missionContext(Mission $mission, ?\DateTimeInterface $effectiveAt): array
    {
        return [
            'id' => $mission->getId(),
            'date' => ($effectiveAt ?? $mission->getStartAt())?->format('Y-m-d'),
            'status' => $mission->getStatus()->value,
            'site' => $mission->getSite()?->getName(),
            'surgeon' => $mission->getSurgeon()?->getDrName(),
        ];
    }

    /** @param array<int, string> $firms */
    private function firmList(array $firms): array
    {
        asort($firms);
        return array_map(static fn (int $id, string $name) => ['id' => $id, 'name' => $name], array_keys($firms), array_values($firms));
    }

    private function add(string $a, ?string $b): string
    {
        return number_format((float) $a + (float) $b, 2, '.', '');
    }
}
