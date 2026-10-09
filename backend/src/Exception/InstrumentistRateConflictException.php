<?php

namespace App\Exception;

use App\Entity\InstrumentistRate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * D-138 — pendant de PricingRuleConflictException pour InstrumentistRate : plusieurs
 * tarifs actifs du même type couvrent la même date (impossible via
 * InstrumentistRateWriteService, possible seulement par écriture hors application).
 * Jamais un choix arbitraire, jamais un 500 : anomalie CONFLICTING_INSTRUMENTIST_RATE
 * dans le moteur, 409 INSTRUMENTIST_RATE_CONFLICT ailleurs.
 */
class InstrumentistRateConflictException extends ConflictHttpException
{
    /** @param InstrumentistRate[] $rates */
    public function __construct(private readonly array $rates, private readonly \DateTimeImmutable $date)
    {
        parent::__construct(sprintf(
            '%d tarifs instrumentiste actifs se chevauchent au %s (tarifs %s) : aucun tarif n\'est appliqué tant que le conflit n\'est pas corrigé.',
            count($rates),
            $date->format('d/m/Y'),
            implode(', ', array_map(static fn (InstrumentistRate $r) => '#' . $r->getId(), $rates)),
        ));
    }

    /** @return InstrumentistRate[] */
    public function getRates(): array
    {
        return $this->rates;
    }

    /** @return list<array{id: ?int, unitPrice: ?string, currency: ?string, validFrom: ?string, validTo: ?string}> */
    public function ratesSnapshot(): array
    {
        return array_map(static fn (InstrumentistRate $r) => [
            'id' => $r->getId(),
            'unitPrice' => $r->getAmount(),
            'currency' => $r->getCurrency(),
            'validFrom' => $r->getValidFrom()?->format('Y-m-d'),
            'validTo' => $r->getValidTo()?->format('Y-m-d'),
        ], array_values($this->rates));
    }
}
