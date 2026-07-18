<?php

namespace App\Service;

use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\InstrumentistRateType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — miroir de PricingRuleResolver pour
 * InstrumentistRate. Résolution déterministe par date explicite (jamais now()) : lève
 * plutôt que de deviner si l'anti-chevauchement a été contourné.
 */
final class InstrumentistRateResolver
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function resolve(User $instrumentist, InstrumentistRateType $rateType, \DateTimeImmutable $effectiveAt): ?InstrumentistRate
    {
        $matching = $this->matchingRates($instrumentist, $rateType, $effectiveAt);

        if (count($matching) > 1) {
            throw new \LogicException(sprintf(
                'Plusieurs InstrumentistRate %s actifs se chevauchent pour instrumentist=%d à la date %s.',
                $rateType->value, $instrumentist->getId(), $effectiveAt->format('Y-m-d'),
            ));
        }

        return $matching[0] ?? null;
    }

    /**
     * Vrai si $candidate chevauche, en date, une autre InstrumentistRate déjà posée sur
     * le même (instrumentist, rateType). Appelé sous verrou par InstrumentistRateWriteService.
     */
    public function hasOverlap(InstrumentistRate $candidate): bool
    {
        $qb = $this->em->getRepository(InstrumentistRate::class)->createQueryBuilder('r')
            ->andWhere('r.instrumentist = :instrumentist')
            ->andWhere('r.rateType = :rateType')
            ->setParameter('instrumentist', $candidate->getInstrumentist())
            ->setParameter('rateType', $candidate->getRateType());

        if ($candidate->getId() !== null) {
            $qb->andWhere('r.id != :selfId')->setParameter('selfId', $candidate->getId());
        }

        /** @var InstrumentistRate[] $others */
        $others = $qb->getQuery()->getResult();

        foreach ($others as $other) {
            if ($candidate->overlapsWith($other)) {
                return true;
            }
        }

        return false;
    }

    /** @return InstrumentistRate[] */
    private function matchingRates(User $instrumentist, InstrumentistRateType $rateType, \DateTimeImmutable $date): array
    {
        $candidates = $this->em->getRepository(InstrumentistRate::class)->createQueryBuilder('r')
            ->andWhere('r.instrumentist = :instrumentist')
            ->andWhere('r.rateType = :rateType')
            ->setParameter('instrumentist', $instrumentist)
            ->setParameter('rateType', $rateType)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($candidates, static fn (InstrumentistRate $r) => $r->coversDate($date)));
    }
}
