<?php

namespace App\Service;

use App\Dto\DocumentLineSelectionAnomaly;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\InstrumentistStatement;
use App\Entity\InstrumentistStatementLine;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialLineType;
use App\Enum\InvoiceStatus;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\StatementLineType;
use App\Exception\DocumentAlreadyIssuedException;
use App\Exception\DocumentLineSelectionException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — preview()/generate()/markSent()/
 * markPaid() ci-dessous sont le chemin LEGACY, conservés strictement inchangés : ils
 * relisent encore User.hourlyRate/consultationFee et recalculent la durée depuis
 * Mission.startAt/endAt (jamais MissionExecution ni InstrumentistRate) — c'est le seul
 * chemin utilisé par le frontend actuel. Les nouvelles méthodes en bas de fichier
 * (previewEligibleLines()/createFromEligibleLines()/cancel()) consomment exclusivement
 * des FinancialCalculationLine déjà valorisées (Lot 3) : aucun accès à User.hourlyRate/
 * consultationFee, aucune relecture de MissionExecution, aucun recalcul de durée. Les
 * deux chemins coexistent (§18 du lot), jamais mélangés au sein d'un même décompte
 * (InstrumentistStatementLine::isLegacy()).
 */
class InstrumentistStatementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FinancialCalculationService $financialCalculationService,
        private readonly AuditService $audit,
    ) {}

    /**
     * Prévisualise les lignes facturables pour un instrumentiste + mois.
     * Exclut les missions déjà incluses dans un décompte GENERATED/SENT/PAID.
     */
    public function preview(User $instrumentist, int $year, int $month): array
    {
        $missions = $this->findBillableMissions($instrumentist, $year, $month);
        $alreadyBilledIds = $this->getAlreadyBilledMissionIds($instrumentist, $year, $month);

        $lines = [];
        foreach ($missions as $mission) {
            if (in_array($mission->getId(), $alreadyBilledIds, true)) {
                continue;
            }
            $lines[] = $this->buildPreviewLine($mission, $instrumentist);
        }

        $total = array_sum(array_column($lines, 'totalAmount'));

        return [
            'instrumentist' => [
                'id' => $instrumentist->getId(),
                'displayName' => $this->buildDisplayName($instrumentist),
                'email' => $instrumentist->getEmail(),
                'hourlyRate' => $instrumentist->getHourlyRate(),
                'consultationFee' => $instrumentist->getConsultationFee(),
            ],
            'period' => ['year' => $year, 'month' => $month],
            'lines' => $lines,
            'totalAmount' => round($total, 2),
            'alreadyBilledMissionIds' => $alreadyBilledIds,
        ];
    }

    /**
     * Génère un décompte définitif (snapshot + verrouillage).
     */
    public function generate(User $instrumentist, int $year, int $month, array $selectedMissionIds): InstrumentistStatement
    {
        // Vérifie qu'il n'existe pas déjà un décompte GENERATED+ pour ce mois
        $existing = $this->findExistingGeneratedStatement($instrumentist, $year, $month);
        if ($existing !== null) {
            throw new \DomainException(sprintf(
                'Un décompte existe déjà pour %02d/%d (statut : %s).',
                $month, $year, $existing->getStatus()->value
            ));
        }

        $missions = $this->findBillableMissions($instrumentist, $year, $month);
        $alreadyBilled = $this->getAlreadyBilledMissionIds($instrumentist, $year, $month);

        $statement = new InstrumentistStatement();
        $statement->setInstrumentist($instrumentist);
        $statement->setPeriodYear($year);
        $statement->setPeriodMonth($month);
        $statement->setStatus(InvoiceStatus::GENERATED);
        $statement->setInstrumentistNameSnapshot($this->buildDisplayName($instrumentist));
        $statement->setInstrumentistEmailSnapshot($instrumentist->getEmail());

        $total = '0.00';

        foreach ($missions as $mission) {
            if (!in_array($mission->getId(), $selectedMissionIds, true)) {
                continue;
            }
            if (in_array($mission->getId(), $alreadyBilled, true)) {
                continue;
            }

            $lineData = $this->buildPreviewLine($mission, $instrumentist);

            $line = new InstrumentistStatementLine();
            $line->setMission($mission);
            $line->setLineType($lineData['lineType'] === 'BLOC' ? StatementLineType::BLOC : StatementLineType::CONSULTATION);
            $line->setDurationMinutesRaw($lineData['durationMinutesRaw']);
            $line->setDurationMinutesRounded($lineData['durationMinutesRounded']);
            $line->setRateSnapshot((string) $lineData['rateSnapshot']);
            $line->setQuantity((string) $lineData['quantity']);
            $line->setTotalAmount((string) $lineData['totalAmount']);
            $line->setSurgeonNameSnapshot($lineData['surgeonName']);
            $line->setSiteNameSnapshot($lineData['siteName']);
            $line->setMissionDateSnapshot(new \DateTimeImmutable($mission->getStartAt()->format('Y-m-d')));

            $statement->addLine($line);

            $total = (string) round((float) $total + (float) $lineData['totalAmount'], 2);
        }

        $statement->setTotalAmount($total);

        $this->em->persist($statement);
        $this->em->flush();

        return $statement;
    }

    public function markSent(InstrumentistStatement $statement): InstrumentistStatement
    {
        if ($statement->getStatus() !== InvoiceStatus::GENERATED) {
            throw new \DomainException('Le décompte doit être en statut GENERATED pour être envoyé.');
        }
        $statement->setStatus(InvoiceStatus::SENT);
        $statement->setSentAt(new \DateTimeImmutable());
        $this->em->flush();
        return $statement;
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

    /** @return Mission[] */
    private function findBillableMissions(User $instrumentist, int $year, int $month): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month));
        $end = $start->modify('last day of this month')->setTime(23, 59, 59);

        return $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->leftJoin('m.services', 's')
            ->where('m.instrumentist = :user')
            ->andWhere('m.status = :status')
            ->andWhere('m.startAt >= :start')
            ->andWhere('m.startAt <= :end')
            ->setParameter('user', $instrumentist)
            ->setParameter('status', MissionStatus::VALIDATED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('m.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function getAlreadyBilledMissionIds(User $instrumentist, int $year, int $month): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.mission) as missionId')
            ->from(InstrumentistStatementLine::class, 'l')
            ->join('l.statement', 's')
            ->where('s.instrumentist = :user')
            ->andWhere('s.periodYear = :year')
            ->andWhere('s.periodMonth = :month')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $instrumentist)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->setParameter('statuses', [InvoiceStatus::GENERATED, InvoiceStatus::SENT, InvoiceStatus::PAID])
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'missionId');
    }

    private function findExistingGeneratedStatement(User $instrumentist, int $year, int $month): ?InstrumentistStatement
    {
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(InstrumentistStatement::class, 's')
            ->where('s.instrumentist = :user')
            ->andWhere('s.periodYear = :year')
            ->andWhere('s.periodMonth = :month')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('user', $instrumentist)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->setParameter('statuses', [InvoiceStatus::GENERATED, InvoiceStatus::SENT, InvoiceStatus::PAID])
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function buildPreviewLine(Mission $mission, User $instrumentist): array
    {
        $isConsultation = $mission->getType() === MissionType::CONSULTATION;

        $surgeonName = $mission->getSurgeon()
            ? $this->buildDisplayName($mission->getSurgeon())
            : null;

        $siteName = $mission->getSite()?->getName();

        if ($isConsultation) {
            $rate = (float) ($instrumentist->getConsultationFee() ?? '0');
            return [
                'missionId' => $mission->getId(),
                'missionDate' => $mission->getStartAt()->format('Y-m-d'),
                'lineType' => 'CONSULTATION',
                'durationMinutesRaw' => null,
                'durationMinutesRounded' => null,
                'rateSnapshot' => $rate,
                'quantity' => 1.0,
                'totalAmount' => round($rate, 2),
                'surgeonName' => $surgeonName,
                'siteName' => $siteName,
            ];
        }

        // BLOC — durée à partir de l'heure de début/fin
        $raw = (int) ($mission->getEndAt()->getTimestamp() - $mission->getStartAt()->getTimestamp()) / 60;
        $raw = max(0, $raw);
        $rounded = (int) (ceil($raw / 15) * 15);
        $hours = round($rounded / 60, 4);
        $rate = (float) ($instrumentist->getHourlyRate() ?? '0');
        $total = round($hours * $rate, 2);

        return [
            'missionId' => $mission->getId(),
            'missionDate' => $mission->getStartAt()->format('Y-m-d'),
            'lineType' => 'BLOC',
            'durationMinutesRaw' => $raw,
            'durationMinutesRounded' => $rounded,
            'rateSnapshot' => $rate,
            'quantity' => $hours,
            'totalAmount' => $total,
            'surgeonName' => $surgeonName,
            'siteName' => $siteName,
        ];
    }

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

        return [
            'instrumentist' => ['id' => $instrumentist->getId(), 'displayName' => $this->buildDisplayName($instrumentist)],
            'currency' => $currency,
            'period' => ['year' => $year, 'month' => $month],
            'lines' => array_map($this->serializeEligibleLine(...), $lines),
            'totalAmount' => $this->sumLineTotals($lines),
        ];
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
