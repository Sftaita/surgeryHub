<?php

namespace App\Service;

use App\Entity\InstrumentistRate;
use App\Exception\InstrumentistRateImmutableException;
use App\Exception\InstrumentistRatePeriodOverlapException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — miroir de PricingRuleWriteService pour
 * InstrumentistRate. Seul point d'écriture bas niveau (verrouillage pessimiste +
 * anti-chevauchement) ; la politique métier (quand une mutation est légitime) vit dans
 * InstrumentistRateService, au-dessus.
 *
 * Cible du verrou : l'instrumentiste lui-même (au lieu de Firm+InterventionType/
 * MaterialItem côté PricingRule) — c'est la seule entité toujours présente avant
 * qu'une InstrumentistRate ne puisse exister (contrainte FK), donc elle sérialise
 * réellement deux écritures concurrentes sur le même instrumentiste, y compris quand
 * 0 InstrumentistRate n'existe encore.
 */
final class InstrumentistRateWriteService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstrumentistRateResolver $resolver,
    ) {}

    /** @throws InstrumentistRatePeriodOverlapException */
    public function create(InstrumentistRate $rate): InstrumentistRate
    {
        $this->em->wrapInTransaction(function () use ($rate): void {
            $this->em->lock($rate->getInstrumentist(), LockMode::PESSIMISTIC_WRITE);

            if ($this->resolver->hasOverlap($rate)) {
                throw new InstrumentistRatePeriodOverlapException(
                    'Un autre tarif actif existe déjà pour cet instrumentiste et ce type sur une période qui se chevauche.',
                );
            }

            $this->em->persist($rate);
            $this->em->flush();
        });

        return $rate;
    }

    /**
     * $rate est déjà managée et mutée en mémoire par l'appelant. La cible (instrumentist/
     * rateType) est immuable après création, verrouiller sur son état courant est donc
     * toujours correct.
     *
     * @throws InstrumentistRatePeriodOverlapException
     */
    public function update(InstrumentistRate $rate): InstrumentistRate
    {
        $this->em->wrapInTransaction(function () use ($rate): void {
            $this->em->lock($rate->getInstrumentist(), LockMode::PESSIMISTIC_WRITE);

            if ($this->resolver->hasOverlap($rate)) {
                throw new InstrumentistRatePeriodOverlapException(
                    'Un autre tarif actif existe déjà pour cet instrumentiste et ce type sur une période qui se chevauche.',
                );
            }

            $this->em->flush();
        });

        return $rate;
    }

    /** Garde-fou défensif identique à PricingRuleWriteService::delete() — voir D-072. */
    public function delete(InstrumentistRate $rate): void
    {
        if ($rate->getValidFrom() <= new \DateTimeImmutable('today')) {
            throw new InstrumentistRateImmutableException(
                'Un tarif déjà applicable ne peut pas être supprimé physiquement.',
            );
        }

        $this->em->wrapInTransaction(function () use ($rate): void {
            $this->em->remove($rate);
            $this->em->flush();
        });
    }
}
