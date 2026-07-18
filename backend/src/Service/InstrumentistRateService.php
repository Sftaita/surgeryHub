<?php

namespace App\Service;

use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\InstrumentistRateType;
use App\Exception\InstrumentistRateImmutableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — seul point d'entrée métier pour toute
 * mutation d'InstrumentistRate. Miroir exact de PricingRuleVersioningService — mêmes
 * garanties (append-only, atomicité du remplacement, immutabilité historique), même
 * découpage createInitialRate/scheduleRate/replaceCurrentRateFrom/updateFutureRate/
 * cancelFutureRate/resolveAt.
 */
final class InstrumentistRateService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InstrumentistRateWriteService $writeService,
        private readonly InstrumentistRateResolver $resolver,
        private readonly AuditService $audit,
    ) {}

    public function createInitialRate(
        User $instrumentist,
        InstrumentistRateType $rateType,
        string $amount,
        string $currency,
        \DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
        User $actor,
    ): InstrumentistRate {
        $rate = $this->persistNewRate($instrumentist, $rateType, $amount, $currency, $validFrom, $validTo);

        $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_RATE_CREATED, $this->scopePayload($rate) + [
            'amount' => $amount, 'currency' => $rate->getCurrency(),
            'validFrom' => $validFrom->format('Y-m-d'), 'validTo' => $validTo?->format('Y-m-d'),
        ]);
        $this->em->flush();

        return $rate;
    }

    public function scheduleRate(
        User $instrumentist,
        InstrumentistRateType $rateType,
        string $amount,
        string $currency,
        \DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
        User $actor,
    ): InstrumentistRate {
        if ($validFrom <= new \DateTimeImmutable('today')) {
            throw new InstrumentistRateImmutableException('scheduleRate() exige une date de début strictement future — utilisez createInitialRate() sinon.');
        }

        $rate = $this->persistNewRate($instrumentist, $rateType, $amount, $currency, $validFrom, $validTo);

        $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_RATE_SCHEDULED, $this->scopePayload($rate) + [
            'amount' => $amount, 'currency' => $rate->getCurrency(),
            'validFrom' => $validFrom->format('Y-m-d'), 'validTo' => $validTo?->format('Y-m-d'),
        ]);
        $this->em->flush();

        return $rate;
    }

    /** Ferme le tarif actuel + ouvre le nouveau, atomique — voir PricingRuleVersioningService::replaceCurrentRuleFrom(). */
    public function replaceCurrentRateFrom(
        InstrumentistRate $currentRate,
        string $newAmount,
        string $newCurrency,
        \DateTimeImmutable $effectiveFrom,
        User $actor,
    ): InstrumentistRate {
        $this->assertCurrentlyActive($currentRate);
        $this->assertPositiveAmount($newAmount);
        $this->assertValidCurrency($newCurrency);

        $today = new \DateTimeImmutable('today');
        if ($effectiveFrom < $today) {
            throw new InstrumentistRateImmutableException("La date d'effet d'un remplacement ne peut jamais être dans le passé.");
        }
        if ($effectiveFrom <= $currentRate->getValidFrom()) {
            throw new InstrumentistRateImmutableException("La date d'effet doit être strictement postérieure au début du tarif actuel.");
        }

        $oldAmount = $currentRate->getAmount();
        $oldCurrency = $currentRate->getCurrency();

        $newRate = new InstrumentistRate();
        $newRate->setInstrumentist($currentRate->getInstrumentist());
        $newRate->setRateType($currentRate->getRateType());
        $newRate->setAmount($newAmount);
        $newRate->setCurrency($newCurrency);
        $newRate->setValidFrom($effectiveFrom);
        $newRate->setValidTo(null);

        $created = null;
        $this->em->wrapInTransaction(function () use (&$created, $currentRate, $newRate, $effectiveFrom): void {
            $currentRate->setValidTo($effectiveFrom);
            $this->writeService->update($currentRate);
            $created = $this->writeService->create($newRate);
        });

        $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_RATE_REPLACED, $this->scopePayload($currentRate) + [
            'previousInstrumentistRateId' => $currentRate->getId(),
            'newInstrumentistRateId' => $created->getId(),
            'oldAmount' => $oldAmount, 'newAmount' => $newAmount,
            'oldCurrency' => $oldCurrency, 'newCurrency' => $created->getCurrency(),
            'effectiveFrom' => $effectiveFrom->format('Y-m-d'),
        ]);
        $this->em->flush();

        return $created;
    }

    public function updateFutureRate(
        InstrumentistRate $rate,
        ?string $amount,
        ?string $currency,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
        User $actor,
    ): InstrumentistRate {
        $this->assertNotYetApplicable($rate);

        $old = [
            'amount' => $rate->getAmount(), 'currency' => $rate->getCurrency(),
            'validFrom' => $rate->getValidFrom()->format('Y-m-d'), 'validTo' => $rate->getValidTo()?->format('Y-m-d'),
        ];

        if ($amount !== null) {
            $this->assertPositiveAmount($amount);
            $rate->setAmount($amount);
        }
        if ($currency !== null) {
            $this->assertValidCurrency($currency);
            $rate->setCurrency($currency);
        }
        if ($validFrom !== null) {
            if ($validFrom <= new \DateTimeImmutable('today')) {
                throw new InstrumentistRateImmutableException('Un tarif futur ne peut pas être déplacé à une date déjà applicable.');
            }
            $rate->setValidFrom($validFrom);
        }
        if ($validTo !== null) {
            $rate->setValidTo($validTo);
        }

        $updated = $this->writeService->update($rate);

        $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_RATE_FUTURE_UPDATED, $this->scopePayload($rate) + [
            'old' => $old,
            'new' => [
                'amount' => $rate->getAmount(), 'currency' => $rate->getCurrency(),
                'validFrom' => $rate->getValidFrom()->format('Y-m-d'), 'validTo' => $rate->getValidTo()?->format('Y-m-d'),
            ],
        ]);
        $this->em->flush();

        return $updated;
    }

    public function cancelFutureRate(InstrumentistRate $rate, User $actor): void
    {
        $this->assertNotYetApplicable($rate);

        $payload = $this->scopePayload($rate) + [
            'amount' => $rate->getAmount(), 'currency' => $rate->getCurrency(),
            'validFrom' => $rate->getValidFrom()->format('Y-m-d'), 'validTo' => $rate->getValidTo()?->format('Y-m-d'),
        ];

        $this->writeService->delete($rate);

        $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_RATE_FUTURE_CANCELLED, $payload);
        $this->em->flush();
    }

    /** Date explicite obligatoire — jamais now() implicite (§8 du lot). */
    public function resolveAt(User $instrumentist, InstrumentistRateType $rateType, \DateTimeImmutable $effectiveAt): ?InstrumentistRate
    {
        return $this->resolver->resolve($instrumentist, $rateType, $effectiveAt);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function persistNewRate(
        User $instrumentist,
        InstrumentistRateType $rateType,
        string $amount,
        string $currency,
        \DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
    ): InstrumentistRate {
        $this->assertPositiveAmount($amount);
        $this->assertValidCurrency($currency);

        if ($validTo !== null && $validTo <= $validFrom) {
            throw new InstrumentistRateImmutableException('validTo doit être strictement postérieure à validFrom.');
        }

        $rate = new InstrumentistRate();
        $rate->setInstrumentist($instrumentist);
        $rate->setRateType($rateType);
        $rate->setAmount($amount);
        $rate->setCurrency($currency);
        $rate->setValidFrom($validFrom);
        $rate->setValidTo($validTo);

        return $this->writeService->create($rate);
    }

    private function assertCurrentlyActive(InstrumentistRate $rate): void
    {
        $today = new \DateTimeImmutable('today');
        if ($rate->getValidFrom() > $today) {
            throw new InstrumentistRateImmutableException('Ce tarif est futur, pas encore applicable — utilisez updateFutureRate() ou cancelFutureRate().');
        }
        if ($rate->getValidTo() !== null && $rate->getValidTo() <= $today) {
            throw new InstrumentistRateImmutableException('Ce tarif est déjà terminé — seul un tarif actuellement actif peut être remplacé.');
        }
    }

    private function assertNotYetApplicable(InstrumentistRate $rate): void
    {
        if ($rate->getValidFrom() <= new \DateTimeImmutable('today')) {
            throw new InstrumentistRateImmutableException('Seul un tarif futur (validFrom strictement postérieure à aujourd\'hui) peut être modifié ou annulé directement.');
        }
    }

    private function assertPositiveAmount(string $amount): void
    {
        if ((float) $amount < 0) {
            throw new \InvalidArgumentException('Le montant doit être positif ou nul.');
        }
    }

    private function assertValidCurrency(string $currency): void
    {
        if (!preg_match('/^[A-Z]{3}$/', strtoupper($currency))) {
            throw new \InvalidArgumentException('Devise invalide (code ISO 4217 à 3 lettres attendu).');
        }
    }

    /** @return array<string, mixed> */
    private function scopePayload(InstrumentistRate $rate): array
    {
        $instrumentist = $rate->getInstrumentist();
        return [
            'instrumentistRateId' => $rate->getId(),
            'instrumentistId' => $instrumentist?->getId(),
            'instrumentistName' => trim(($instrumentist?->getFirstname() ?? '') . ' ' . ($instrumentist?->getLastname() ?? '')),
            'rateType' => $rate->getRateType()?->value,
        ];
    }
}
