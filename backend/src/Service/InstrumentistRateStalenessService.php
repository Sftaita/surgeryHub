<?php

namespace App\Service;

use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\User;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialLineType;
use App\Enum\InstrumentistRateType;
use App\Exception\InstrumentistRateConflictException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D-172 — un calcul non verrouillé devient obsolète quand le tarif instrumentiste applicable
 * à la date de sa mission n'est plus celui qu'il a figé (date d'effet modifiée, rétroactive
 * comprise). Seule définition de cette obsolescence : rejoue InstrumentistRateResolver à la
 * date de chaque ligne et compare au tarif, au montant et à la devise de la ligne.
 *
 * Ne modifie jamais rien : un calcul LOCKED garde ses montants (il n'est jamais signalé),
 * un calcul non verrouillé se recalcule uniquement à la demande du manager.
 */
final class InstrumentistRateStalenessService
{
    private const LINE_TYPES = [
        InstrumentistRateType::HOURLY_RATE->value      => FinancialLineType::INSTRUMENTIST_HOURLY,
        InstrumentistRateType::CONSULTATION_FEE->value => FinancialLineType::INSTRUMENTIST_CONSULTATION_FEE,
    ];

    private const OPEN_STATUSES = [FinancialCalculationStatus::CALCULATED, FinancialCalculationStatus::APPROVED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstrumentistRateResolver $resolver,
    ) {}

    public static function lineTypeFor(InstrumentistRateType $rateType): FinancialLineType
    {
        return self::LINE_TYPES[$rateType->value];
    }

    /** Message destiné au manager, ou null si la ligne reflète toujours le tarif applicable. */
    public function staleReasonOf(FinancialCalculationLine $line): ?string
    {
        $rateType = match ($line->getLineType()) {
            FinancialLineType::INSTRUMENTIST_HOURLY           => InstrumentistRateType::HOURLY_RATE,
            FinancialLineType::INSTRUMENTIST_CONSULTATION_FEE => InstrumentistRateType::CONSULTATION_FEE,
            default                                           => null,
        };
        $instrumentist = $line->getBeneficiaryInstrumentist();
        $effectiveAt = $line->getEffectiveAt();
        if ($rateType === null || $instrumentist === null || $effectiveAt === null) {
            return null;
        }

        $date = $effectiveAt->format('d/m/Y');
        try {
            $current = $this->resolver->resolve($instrumentist, $rateType, $effectiveAt);
        } catch (InstrumentistRateConflictException) {
            return sprintf('Plusieurs tarifs instrumentiste couvrent désormais le %s : corrigez les périodes tarifaires, puis recalculez.', $date);
        }

        if ($current === null) {
            return sprintf("Plus aucun tarif instrumentiste n'est applicable au %s depuis ce calcul : il doit être recalculé.", $date);
        }

        $sameRate = $current->getId() === $line->getInstrumentistRate()?->getId();
        $sameAmount = round((float) $current->getAmount(), 2) === round((float) $line->getUnitAmount(), 2)
            && $current->getCurrency() === $line->getCurrency();
        if ($sameRate && $sameAmount) {
            return null;
        }

        return sprintf(
            'Le tarif instrumentiste applicable au %s a changé depuis ce calcul (%s %s → %s %s) : il doit être recalculé avant approbation ou verrouillage.',
            $date, $line->getUnitAmount(), $line->getCurrency(), $current->getAmount(), $current->getCurrency(),
        );
    }

    /** Première raison d'obsolescence tarifaire d'un calcul non verrouillé, sinon null. */
    public function staleReasonOfCalculation(FinancialCalculation $calculation): ?string
    {
        if (!in_array($calculation->getStatus(), self::OPEN_STATUSES, true)) {
            return null;
        }
        foreach ($calculation->getLines() as $line) {
            if ($line->getBeneficiaryType() === FinancialBeneficiaryType::INSTRUMENTIST && ($reason = $this->staleReasonOf($line)) !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Calculs (principaux ou complémentaires, ni remplacés ni annulés) portant une ligne de
     * l'instrumentiste pour ce type de tarif dans [$from, $to). $to null = sans fin.
     *
     * @return list<array{calculationId: int, missionId: int, missionDate: string, status: string}>
     */
    public function calculationsInWindow(User $instrumentist, InstrumentistRateType $rateType, \DateTimeImmutable $from, ?\DateTimeImmutable $to, bool $locked): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('DISTINCT c.id AS calculationId, IDENTITY(c.mission) AS missionId, l.effectiveAt AS effectiveAt, c.status AS status')
            ->from(FinancialCalculationLine::class, 'l')
            ->join('l.financialCalculation', 'c')
            ->where('l.beneficiaryInstrumentist = :instrumentist')
            ->andWhere('l.lineType = :lineType')
            ->andWhere('l.effectiveAt >= :from')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('instrumentist', $instrumentist)
            ->setParameter('lineType', self::lineTypeFor($rateType))
            ->setParameter('from', $from)
            ->setParameter('statuses', $locked ? [FinancialCalculationStatus::LOCKED] : self::OPEN_STATUSES)
            ->orderBy('l.effectiveAt', 'ASC');
        if ($to !== null) {
            $qb->andWhere('l.effectiveAt < :to')->setParameter('to', $to);
        }

        $rows = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $status = $row['status'];
            $rows[(int) $row['calculationId']] = [
                'calculationId' => (int) $row['calculationId'],
                'missionId'     => (int) $row['missionId'],
                'missionDate'   => $row['effectiveAt']->format('Y-m-d'),
                'status'        => $status instanceof FinancialCalculationStatus ? $status->value : (string) $status,
            ];
        }

        return array_values($rows);
    }
}
