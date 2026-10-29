<?php

namespace App\Service;

use App\Entity\InstrumentistRate;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\InstrumentistRateType;
use App\Exception\InstrumentistRateImmutableException;
use App\Exception\InstrumentistRateLockedPeriodException;
use Doctrine\DBAL\LockMode;
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
        private readonly InstrumentistRateStalenessService $staleness,
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

        $effectiveFrom = $effectiveFrom->setTime(0, 0);
        if ($effectiveFrom <= $currentRate->getValidFrom()) {
            throw new InstrumentistRateImmutableException("La date d'effet doit être strictement postérieure au début du tarif actuel.");
        }
        // D-172 — une date d'effet passée est permise : les missions réalisées depuis cette
        // date relèvent du nouveau tarif, sauf si l'une d'elles est déjà verrouillée.
        $stale = [];
        if ($effectiveFrom < new \DateTimeImmutable('today')) {
            $instrumentist = $currentRate->getInstrumentist();
            $locked = $this->staleness->calculationsInWindow($instrumentist, $currentRate->getRateType(), $effectiveFrom, $currentRate->getValidTo(), true);
            if ($locked !== []) {
                throw new InstrumentistRateLockedPeriodException(self::lockedPeriodMessage($locked));
            }
            $stale = $this->staleness->calculationsInWindow($instrumentist, $currentRate->getRateType(), $effectiveFrom, $currentRate->getValidTo(), false);
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
            'staleCalculationIds' => array_column($stale, 'calculationId'),
        ]);
        $this->em->flush();

        return $created;
    }

    /**
     * D-172 — date de prise d'effet d'un tarif, rétroactive comprise. Déplace la frontière
     * entre ce tarif et le précédent (qui s'arrête désormais à $newValidFrom) sans supprimer
     * aucune version : l'historique reste continu. Refusé en entier si la période dont le
     * tarif change contient un calcul verrouillé ; les calculs non verrouillés de cette période
     * sont listés (ils deviennent obsolètes, InstrumentistRateStalenessService) et ne sont
     * jamais recalculés ici.
     *
     * $dryRun : mêmes contrôles et même impact, rien n'est écrit.
     *
     * @return array{rate: InstrumentistRate, previousRate: ?InstrumentistRate, oldValidFrom: string, newValidFrom: string,
     *     periodFrom: string, periodTo: string, lockedCalculations: list<array<string, mixed>>, staleCalculations: list<array<string, mixed>>}
     */
    public function changeEffectiveDate(InstrumentistRate $rate, \DateTimeImmutable $newValidFrom, User $actor, bool $dryRun = false): array
    {
        $newValidFrom = $newValidFrom->setTime(0, 0);
        $result = null;

        $this->em->wrapInTransaction(function () use (&$result, $rate, $newValidFrom, $actor, $dryRun): void {
            $instrumentist = $rate->getInstrumentist();
            $this->em->lock($instrumentist, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($rate);

            $oldValidFrom = $rate->getValidFrom();
            if ($newValidFrom == $oldValidFrom) {
                throw new InstrumentistRateImmutableException('Ce tarif prend déjà effet à cette date.');
            }
            if ($rate->getValidTo() !== null && $newValidFrom >= $rate->getValidTo()) {
                throw new InstrumentistRateImmutableException(sprintf(
                    'La date de prise d\'effet doit précéder la fin de ce tarif (%s).', $rate->getValidTo()->format('d/m/Y'),
                ));
            }

            $previous = $this->em->getRepository(InstrumentistRate::class)->findOneBy([
                'instrumentist' => $instrumentist, 'rateType' => $rate->getRateType(), 'validTo' => $oldValidFrom,
            ]);
            if ($previous !== null && $newValidFrom <= $previous->getValidFrom()) {
                throw new InstrumentistRateImmutableException(sprintf(
                    'La date de prise d\'effet doit suivre le début du tarif précédent (%s %s depuis le %s) : il ne peut pas disparaître de l\'historique.',
                    $previous->getAmount(), $previous->getCurrency(), $previous->getValidFrom()->format('d/m/Y'),
                ));
            }

            $periodFrom = min($oldValidFrom, $newValidFrom);
            $periodTo = max($oldValidFrom, $newValidFrom);
            $locked = $this->staleness->calculationsInWindow($instrumentist, $rate->getRateType(), $periodFrom, $periodTo, true);
            $stale = $this->staleness->calculationsInWindow($instrumentist, $rate->getRateType(), $periodFrom, $periodTo, false);

            $result = [
                'rate' => $rate, 'previousRate' => $previous,
                'oldValidFrom' => $oldValidFrom->format('Y-m-d'), 'newValidFrom' => $newValidFrom->format('Y-m-d'),
                'periodFrom' => $periodFrom->format('Y-m-d'), 'periodTo' => $periodTo->format('Y-m-d'),
                'lockedCalculations' => $locked, 'staleCalculations' => $stale,
            ];
            if ($dryRun) {
                return;
            }
            if ($locked !== []) {
                throw new InstrumentistRateLockedPeriodException(self::lockedPeriodMessage($locked));
            }

            $previousOldValidTo = $previous?->getValidTo();
            // Ordre sans chevauchement transitoire : on raccourcit d'abord la version qui cède
            // du terrain, puis on étend l'autre.
            if ($newValidFrom < $oldValidFrom) {
                $previous?->setValidTo($newValidFrom);
                if ($previous !== null) {
                    $this->writeService->update($previous);
                }
                $rate->setValidFrom($newValidFrom);
                $this->writeService->update($rate);
            } else {
                $rate->setValidFrom($newValidFrom);
                $this->writeService->update($rate);
                $previous?->setValidTo($newValidFrom);
                if ($previous !== null) {
                    $this->writeService->update($previous);
                }
            }

            $this->audit->recordGlobal($actor, AuditEventType::INSTRUMENTIST_RATE_EFFECTIVE_DATE_CHANGED, $this->scopePayload($rate) + [
                'amount' => $rate->getAmount(), 'currency' => $rate->getCurrency(),
                'oldValidFrom' => $oldValidFrom->format('Y-m-d'), 'newValidFrom' => $newValidFrom->format('Y-m-d'),
                'previousInstrumentistRateId' => $previous?->getId(),
                'previousOldValidTo' => $previousOldValidTo?->format('Y-m-d'),
                'previousNewValidTo' => $previous?->getValidTo()?->format('Y-m-d'),
                'staleCalculationIds' => array_column($stale, 'calculationId'),
            ]);
            $this->em->flush();
        });

        return $result;
    }

    /** @param list<array{missionId: int, missionDate: string}> $locked */
    private static function lockedPeriodMessage(array $locked): string
    {
        $missions = array_map(
            static fn (array $c) => sprintf('#%d (%s)', $c['missionId'], (new \DateTimeImmutable($c['missionDate']))->format('d/m/Y')),
            $locked,
        );

        return sprintf(
            'Impossible de modifier cette période tarifaire : %d calcul(s) financier(s) verrouillé(s) par une facture ou un relevé l\'utilisent (missions %s). Leurs montants ne sont jamais modifiés ; une correction passe par la procédure de correction des documents émis.',
            count($locked), implode(', ', array_unique($missions)),
        );
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
