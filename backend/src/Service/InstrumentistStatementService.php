<?php

namespace App\Service;

use App\Dto\DocumentLineSelectionAnomaly;
use App\Dto\EligibleLinesDiagnostic;
use App\Repository\EncodingTrackingRepository;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\InstrumentistStatement;
use App\Entity\InstrumentistStatementLine;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialDocumentType;
use App\Enum\FinancialLineType;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\StatementLineType;
use App\Exception\DocumentAlreadyIssuedException;
use App\Exception\DocumentLineSelectionException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) puis nettoyage architectural (D-121) —
 * ce service ne consomme plus que des FinancialCalculationLine déjà valorisées et
 * figées (Lot 3) : jamais d'accès à User.hourlyRate/consultationFee, jamais de
 * relecture de MissionExecution, aucun recalcul de durée au moment de la génération.
 * Le chemin LEGACY (preview()/generate()) a été supprimé — aucun décompte n'avait
 * jamais été produit par ce chemin en production (voir D-121).
 */
class InstrumentistStatementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinancialCalculationService $financialCalculationService,
        private readonly AuditService $audit,
        private readonly EncodingTrackingRepository $encodingTrackingRepository,
    ) {}

    /**
     * Conservé pour compatibilité (POST /{id}/send, existant) — délègue à issue()
     * (Lot 5, D-075).
     */
    public function markSent(InstrumentistStatement $statement, User $actor): InstrumentistStatement
    {
        return $this->issue($statement, $actor);
    }

    /**
     * EPIC Exécution & Valorisation, Lot 5 (D-075) — §12 du lot, miroir exact de
     * FirmInvoiceService::issue(). Pas de numérotation ici : InstrumentistStatement
     * n'a jamais eu de champ `number` (contrairement à FirmInvoice), rien à attribuer.
     */
    public function issue(InstrumentistStatement $statement, User $actor): InstrumentistStatement
    {
        if ($statement->getStatus() !== InvoiceStatus::GENERATED) {
            throw new \DomainException('Le décompte doit être en statut GENERATED pour être envoyé.');
        }

        // EPIC Exécution & Valorisation, Lot 6 (D-076) — §19 du lot : un décompte
        // STANDARD reste sans numéro (comportement inchangé) ; seule une note de
        // crédit/débit (correction) en reçoit un à l'émission.
        if ($statement->getDocumentType() !== FinancialDocumentType::STANDARD && $statement->getNumber() === null) {
            $statement->setNumber($this->generateNumber($statement->getDocumentType()));
        }

        $statement->setStatus(InvoiceStatus::SENT);
        $statement->setSentAt(new \DateTimeImmutable());

        $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_STATEMENT_ISSUED, [
            'instrumentistStatementId' => $statement->getId(),
            'instrumentistId' => $statement->getInstrumentist()?->getId(),
            'number' => $statement->getNumber(),
            'previousStatus' => InvoiceStatus::GENERATED->value,
            'newStatus' => InvoiceStatus::SENT->value,
        ]);

        $this->em->flush();
        return $statement;
    }

    /** EPIC Exécution & Valorisation, Lot 6 (D-076) — §19 du lot : réservé aux notes de crédit/débit (voir issue()). */
    private function generateNumber(FinancialDocumentType $type): string
    {
        $year = (int) (new \DateTimeImmutable())->format('Y');
        $prefix = match ($type) {
            FinancialDocumentType::CREDIT_NOTE => 'STMT-CN',
            FinancialDocumentType::DEBIT_NOTE => 'STMT-DN',
            FinancialDocumentType::STANDARD => throw new \LogicException('Un décompte STANDARD ne doit jamais recevoir de numéro.'),
        };

        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(InstrumentistStatement::class, 's')
            ->where('s.number LIKE :pattern')
            ->setParameter('pattern', sprintf('%s-%d-%%', $prefix, $year))
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('%s-%d-%03d', $prefix, $year, $count + 1);
    }

    public function markPaid(InstrumentistStatement $statement): InstrumentistStatement
    {
        if ($statement->getStatus() === InvoiceStatus::PAID) {
            return $statement;
        }
        $statement->setStatus(InvoiceStatus::PAID);
        $statement->setPaidAt(new \DateTimeImmutable());
        $this->em->flush();
        return $statement;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function buildDisplayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : $user->getEmail();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // EPIC Exécution & Valorisation, Lot 4 (D-074) — chemin NOUVEAU, consomme
    // exclusivement des FinancialCalculationLine déjà valorisées (Lot 3).
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * §7.1/§7.2 du lot — lecture seule. INSTRUMENTIST_HOURLY/INSTRUMENTIST_CONSULTATION_FEE
     * uniquement, bénéficiaire = $instrumentist, calcul APPROVED ou LOCKED, devise =
     * $currency. Période = mois calendaire (year/month), même granularité que le chemin
     * legacy ; filtrée sur FinancialCalculationLine.effectiveAt — jamais createdAt, la
     * date de génération, ou la date du jour (§7.2, convention centralisée).
     */
    public function previewEligibleLines(User $instrumentist, string $currency, int $year, int $month): array
    {
        [$start, $end] = $this->periodBounds($year, $month);
        $lines = $this->findEligibleInstrumentistLines($instrumentist, $currency, $start, $end);

        $result = [
            'instrumentist' => ['id' => $instrumentist->getId(), 'displayName' => $this->buildDisplayName($instrumentist)],
            'currency' => $currency,
            'period' => ['year' => $year, 'month' => $month],
            'lines' => array_map($this->serializeEligibleLine(...), $lines),
            'totalAmount' => $this->sumLineTotals($lines),
        ];

        if (count($lines) === 0) {
            $result['diagnostic'] = $this->buildDiagnostic($instrumentist, $currency, $start, $end)->toArray();
        }

        return $result;
    }

    /** Diagnostic explicatif (D-121, §6) — miroir exact de FirmInvoiceService::buildDiagnostic(). */
    private function buildDiagnostic(User $instrumentist, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): EligibleLinesDiagnostic
    {
        $missionIds = $this->findValidatedMissionIdsForInstrumentist($instrumentist, $periodStart, $periodEnd);
        $validatedMissionCount = count($missionIds);

        $byStatus = $this->countCalculationsByStatusForMissions($missionIds);
        $calculatedCount = $byStatus[FinancialCalculationStatus::CALCULATED->value] ?? 0;
        $approvedCount = $byStatus[FinancialCalculationStatus::APPROVED->value] ?? 0;
        $lockedCount = $byStatus[FinancialCalculationStatus::LOCKED->value] ?? 0;
        $calculationCount = $calculatedCount + $approvedCount + $lockedCount;

        $missingPricingCount = $missionIds === [] ? 0 : $this->countMissionsWithCalculationFailure($missionIds);
        $currencyMismatchCount = $this->countInstrumentistLines($instrumentist, $periodStart, $periodEnd, currency: null, excludeCurrency: strtoupper($currency));
        $alreadyInvoicedCount = $this->countInstrumentistLines($instrumentist, $periodStart, $periodEnd, currency: strtoupper($currency), excludeCurrency: null, onlyAssigned: true);

        $reasons = [];
        if ($validatedMissionCount === 0) {
            $reasons[] = 'NO_VALIDATED_MISSIONS';
        } elseif ($calculationCount === 0) {
            $reasons[] = 'NO_FINANCIAL_CALCULATIONS';
            if ($missingPricingCount > 0) {
                $reasons[] = 'MISSING_PRICING';
            }
        } else {
            if ($calculatedCount > 0) {
                $reasons[] = 'CALCULATIONS_PENDING_APPROVAL';
            }
            if ($missingPricingCount > 0) {
                $reasons[] = 'MISSING_PRICING';
            }
            if ($currencyMismatchCount > 0) {
                $reasons[] = 'CURRENCY_MISMATCH';
            }
            if ($alreadyInvoicedCount > 0) {
                $reasons[] = 'ALREADY_INVOICED';
            }
            if (($approvedCount + $lockedCount) > 0 && $reasons === []) {
                $reasons[] = 'NO_LINES_FOR_BENEFICIARY';
            }
        }

        return new EligibleLinesDiagnostic(
            validatedMissionCount: $validatedMissionCount,
            calculationCount: $calculationCount,
            calculatedCount: $calculatedCount,
            approvedCount: $approvedCount,
            lockedCount: $lockedCount,
            missingPricingCount: $missingPricingCount,
            currencyMismatchCount: $currencyMismatchCount,
            alreadyInvoicedCount: $alreadyInvoicedCount,
            reasons: $reasons,
        );
    }

    /** @return int[] */
    private function findValidatedMissionIdsForInstrumentist(User $instrumentist, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('m.id')
            ->from(Mission::class, 'm')
            ->leftJoin('m.execution', 'exec')
            ->where('m.instrumentist = :instrumentist')
            ->andWhere('m.status = :status')
            ->andWhere('COALESCE(exec.actualStartAt, m.startAt) >= :start')
            ->andWhere('COALESCE(exec.actualStartAt, m.startAt) <= :end')
            ->setParameter('instrumentist', $instrumentist)
            ->setParameter('status', MissionStatus::VALIDATED)
            ->setParameter('start', $periodStart)
            ->setParameter('end', $periodEnd)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'id');
    }

    /** @param int[] $missionIds @return array<string, int> statut => nombre de calculs actifs */
    private function countCalculationsByStatusForMissions(array $missionIds): array
    {
        if ($missionIds === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('fc.status as status', 'COUNT(fc.id) as cnt')
            ->from(FinancialCalculation::class, 'fc')
            ->where('IDENTITY(fc.mission) IN (:missionIds)')
            ->andWhere('fc.status IN (:statuses)')
            ->setParameter('missionIds', $missionIds)
            ->setParameter('statuses', [
                FinancialCalculationStatus::CALCULATED,
                FinancialCalculationStatus::APPROVED,
                FinancialCalculationStatus::LOCKED,
            ])
            ->groupBy('fc.status')
            ->getQuery()
            ->getArrayResult();

        $byStatus = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof FinancialCalculationStatus ? $row['status']->value : $row['status'];
            $byStatus[$status] = (int) $row['cnt'];
        }
        return $byStatus;
    }

    /**
     * @param int[] $missionIds
     * Miroir exact de FirmInvoiceService::countMissionsWithCalculationFailure() — voir son
     * docblock.
     */
    private function countMissionsWithCalculationFailure(array $missionIds): int
    {
        return count($this->encodingTrackingRepository->findMissionsWithFailedCalculation($missionIds));
    }

    private function countInstrumentistLines(
        User $instrumentist,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        ?string $currency,
        ?string $excludeCurrency,
        bool $onlyAssigned = false,
    ): int {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.financialCalculation', 'fc')
            ->leftJoin('l.instrumentistStatementLine', 'sl')
            ->where('l.beneficiaryType = :beneficiaryType')
            ->andWhere('l.beneficiaryInstrumentist = :instrumentist')
            ->andWhere('fc.status IN (:statuses)')
            ->andWhere('l.effectiveAt >= :start')
            ->andWhere('l.effectiveAt <= :end')
            ->setParameter('beneficiaryType', FinancialBeneficiaryType::INSTRUMENTIST)
            ->setParameter('instrumentist', $instrumentist)
            ->setParameter('statuses', [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED])
            ->setParameter('start', $periodStart)
            ->setParameter('end', $periodEnd);

        if ($currency !== null) {
            $qb->andWhere('l.currency = :currency')->setParameter('currency', $currency);
        }
        if ($excludeCurrency !== null) {
            $qb->andWhere('l.currency != :excludeCurrency')->setParameter('excludeCurrency', $excludeCurrency);
        }
        if ($onlyAssigned) {
            $qb->andWhere('sl.id IS NOT NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * §16 du lot — miroir exact de FirmInvoiceService::createFromEligibleLines() : ne
     * fait jamais confiance à previewEligibleLines(), reverrouille chaque
     * FinancialCalculation référencé (ordre croissant d'id) et revérifie individuellement
     * chaque ligne sélectionnée. Aucune lecture de User.hourlyRate/consultationFee, aucun
     * MissionExecution — uniquement les montants et snapshots déjà figés (§7.1).
     */
    public function createFromEligibleLines(User $instrumentist, string $currency, int $year, int $month, array $selectedFinancialCalculationLineIds, User $actor): InstrumentistStatement
    {
        $result = null;
        [$start, $end] = $this->periodBounds($year, $month);

        $this->em->wrapInTransaction(function () use (&$result, $instrumentist, $currency, $year, $month, $start, $end, $selectedFinancialCalculationLineIds, $actor): void {
            ['lines' => $lines, 'missingIds' => $missingIds] = $this->lockAndReloadSelectedLines($selectedFinancialCalculationLineIds);

            $anomalies = $this->validateInstrumentistLineSelection($lines, $instrumentist, $currency, $start, $end);
            foreach ($missingIds as $missingId) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_NOT_ELIGIBLE', sprintf('La ligne #%d est introuvable.', $missingId), ['financialCalculationLineId' => $missingId]);
            }
            if (count($anomalies) > 0) {
                throw new DocumentLineSelectionException($anomalies);
            }

            $statement = new InstrumentistStatement();
            $statement->setInstrumentist($instrumentist);
            $statement->setCurrency($currency);
            $statement->setPeriodYear($year);
            $statement->setPeriodMonth($month);
            $statement->setStatus(InvoiceStatus::GENERATED);
            $statement->setLegacySource(false);
            $statement->setInstrumentistNameSnapshot($this->buildDisplayName($instrumentist));
            $statement->setInstrumentistEmailSnapshot($instrumentist->getEmail());
            // Persisté AVANT la boucle — voir FirmInvoiceService::createFromEligibleLines()
            // pour la raison exacte (lock() flush() en interne à chaque itération).
            $this->em->persist($statement);

            $total = '0.00';
            $lockedCalculationIds = [];

            foreach ($lines as $line) {
                $statementLine = $this->hydrateFromFinancialLine($line);
                $statement->addLine($statementLine);
                $this->em->persist($statementLine);
                $total = number_format((float) $total + (float) $line->getTotalAmount(), 2, '.', '');

                $calculation = $line->getFinancialCalculation();
                if ($calculation->getStatus() !== FinancialCalculationStatus::LOCKED) {
                    $this->financialCalculationService->lock($calculation, $actor);
                }
                $lockedCalculationIds[$calculation->getId()] = true;
            }

            $statement->setTotalAmount($total);
            $this->em->flush();

            $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_STATEMENT_CREATED_FROM_CALCULATION, [
                'instrumentistStatementId' => $statement->getId(),
                'instrumentistId' => $instrumentist->getId(),
                'currency' => $currency,
                'periodYear' => $year,
                'periodMonth' => $month,
                'financialCalculationLineIds' => array_map(static fn (FinancialCalculationLine $l) => $l->getId(), $lines),
                'financialCalculationIds' => array_keys($lockedCalculationIds),
                'totalAmount' => $total,
            ]);
            $this->em->flush();

            $result = $statement;
        });

        return $result;
    }

    /** §12/§13 du lot — miroir exact de FirmInvoiceService::cancel(), voir son docblock. */
    public function cancel(InstrumentistStatement $statement, User $actor, ?string $reason = null): InstrumentistStatement
    {
        if ($statement->getStatus() !== InvoiceStatus::GENERATED) {
            throw new DocumentAlreadyIssuedException(sprintf(
                'Seul un décompte GENERATED peut être annulé (statut actuel : %s).',
                $statement->getStatus()->value,
            ));
        }

        $releasedLineIds = [];
        foreach ($statement->getLines() as $line) {
            $financialLine = $line->getFinancialCalculationLine();
            if ($financialLine !== null) {
                $releasedLineIds[] = $financialLine->getId();
            }
            $this->em->remove($line);
        }
        $statement->getLines()->clear();
        $statement->setStatus(InvoiceStatus::CANCELLED);

        $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_STATEMENT_CANCELLED, [
            'instrumentistStatementId' => $statement->getId(),
            'instrumentistId' => $statement->getInstrumentist()?->getId(),
            'reason' => $reason,
            'releasedFinancialCalculationLineIds' => $releasedLineIds,
        ]);
        $this->em->flush();

        return $statement;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function periodBounds(int $year, int $month): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');
        return [$start, $end];
    }

    /** @param int[] $lineIds @return FinancialCalculationLine[] */
    /**
     * @param int[] $lineIds
     * @return array{lines: FinancialCalculationLine[], missingIds: int[]}
     */
    private function lockAndReloadSelectedLines(array $lineIds): array
    {
        $lines = [];
        $missingIds = [];
        foreach (array_unique($lineIds) as $id) {
            $line = $this->em->find(FinancialCalculationLine::class, $id);
            if ($line !== null) {
                $lines[] = $line;
            } else {
                $missingIds[] = $id;
            }
        }

        $calculationIds = [];
        foreach ($lines as $line) {
            $calculationIds[$line->getFinancialCalculation()->getId()] = true;
        }
        $sortedCalculationIds = array_keys($calculationIds);
        sort($sortedCalculationIds);

        foreach ($sortedCalculationIds as $calculationId) {
            $calculation = $this->em->find(FinancialCalculation::class, $calculationId);
            $this->em->lock($calculation, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($calculation);
        }

        return ['lines' => $lines, 'missingIds' => $missingIds];
    }

    /**
     * @param FinancialCalculationLine[] $lines
     * @return DocumentLineSelectionAnomaly[]
     */
    private function validateInstrumentistLineSelection(array $lines, User $instrumentist, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $anomalies = [];

        foreach ($lines as $line) {
            $context = ['financialCalculationLineId' => $line->getId()];

            if ($line->getBeneficiaryType() !== FinancialBeneficiaryType::INSTRUMENTIST || $line->getBeneficiaryInstrumentist()?->getId() !== $instrumentist->getId()) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_BENEFICIARY_MISMATCH', sprintf('La ligne #%d ne concerne pas l\'instrumentiste %d.', $line->getId(), $instrumentist->getId()), $context);
                continue;
            }
            if ($line->getCurrency() !== strtoupper($currency)) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_CURRENCY_MISMATCH', sprintf('La ligne #%d est en %s, pas %s.', $line->getId(), $line->getCurrency(), $currency), $context);
                continue;
            }
            if ($line->getEffectiveAt() < $periodStart || $line->getEffectiveAt() > $periodEnd) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_NOT_ELIGIBLE', sprintf('La ligne #%d est hors période.', $line->getId()), $context);
                continue;
            }
            if (!in_array($line->getFinancialCalculation()->getStatus(), [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED], true)) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_CALCULATION_NOT_APPROVED', sprintf('Le calcul de la ligne #%d n\'est ni APPROVED ni LOCKED.', $line->getId()), $context);
                continue;
            }
            if ($this->isLineAlreadyAssigned($line)) {
                $anomalies[] = new DocumentLineSelectionAnomaly('FINANCIAL_LINE_ALREADY_ASSIGNED', sprintf('La ligne #%d est déjà rattachée à un document.', $line->getId()), $context);
            }
        }

        return $anomalies;
    }

    private function isLineAlreadyAssigned(FinancialCalculationLine $line): bool
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(sl.id)')
            ->from(InstrumentistStatementLine::class, 'sl')
            ->where('sl.financialCalculationLine = :line')
            ->setParameter('line', $line)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /** @return FinancialCalculationLine[] */
    private function findEligibleInstrumentistLines(User $instrumentist, string $currency, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        return $this->em->createQueryBuilder()
            ->select('l')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.financialCalculation', 'fc')
            ->leftJoin('l.instrumentistStatementLine', 'sl')
            ->where('l.beneficiaryType = :beneficiaryType')
            ->andWhere('l.beneficiaryInstrumentist = :instrumentist')
            ->andWhere('l.currency = :currency')
            ->andWhere('fc.status IN (:statuses)')
            ->andWhere('sl.id IS NULL')
            ->andWhere('l.effectiveAt >= :start')
            ->andWhere('l.effectiveAt <= :end')
            ->setParameter('beneficiaryType', FinancialBeneficiaryType::INSTRUMENTIST)
            ->setParameter('instrumentist', $instrumentist)
            ->setParameter('currency', strtoupper($currency))
            ->setParameter('statuses', [FinancialCalculationStatus::APPROVED, FinancialCalculationStatus::LOCKED])
            ->setParameter('start', $periodStart)
            ->setParameter('end', $periodEnd)
            ->orderBy('l.effectiveAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function hydrateFromFinancialLine(FinancialCalculationLine $line): InstrumentistStatementLine
    {
        $mission = $line->getFinancialCalculation()->getMission();

        $statementLine = new InstrumentistStatementLine();
        $statementLine->setMission($mission);
        $statementLine->setFinancialCalculationLine($line);
        $statementLine->setLineType($line->getLineType() === FinancialLineType::INSTRUMENTIST_HOURLY ? StatementLineType::BLOC : StatementLineType::CONSULTATION);
        $statementLine->setDurationMinutesRaw($line->getDurationMinutes());
        $statementLine->setDurationMinutesRounded($line->getDurationMinutes());
        $statementLine->setRateSnapshot($line->getUnitAmount());
        $statementLine->setQuantity($line->getQuantity());
        $statementLine->setTotalAmount($line->getTotalAmount());
        $statementLine->setCurrency($line->getCurrency());
        $statementLine->setUnitSnapshot($line->getLineType() === FinancialLineType::INSTRUMENTIST_HOURLY ? 'heure' : 'consultation');
        $statementLine->setSourceSnapshot($line->getSnapshot());
        $statementLine->setSurgeonNameSnapshot($mission->getSurgeon() ? $this->buildDisplayName($mission->getSurgeon()) : null);
        $statementLine->setSiteNameSnapshot($mission->getSite()?->getName());
        $statementLine->setMissionDateSnapshot(new \DateTimeImmutable($line->getEffectiveAt()->format('Y-m-d')));
        return $statementLine;
    }

    /** @param FinancialCalculationLine[] $lines */
    private function sumLineTotals(array $lines): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $total = number_format((float) $total + (float) $line->getTotalAmount(), 2, '.', '');
        }
        return $total;
    }

    /** @return array<string, mixed> */
    private function serializeEligibleLine(FinancialCalculationLine $line): array
    {
        return [
            'id' => $line->getId(),
            'financialCalculationId' => $line->getFinancialCalculation()->getId(),
            'financialCalculationVersion' => $line->getFinancialCalculation()->getVersion(),
            'missionId' => $line->getFinancialCalculation()->getMission()->getId(),
            'lineType' => $line->getLineType()->value,
            'descriptionSnapshot' => $line->getDescriptionSnapshot(),
            'durationMinutes' => $line->getDurationMinutes(),
            'quantity' => $line->getQuantity(),
            'unitAmount' => $line->getUnitAmount(),
            'totalAmount' => $line->getTotalAmount(),
            'currency' => $line->getCurrency(),
            'effectiveAt' => $line->getEffectiveAt()?->format('Y-m-d'),
        ];
    }
}
