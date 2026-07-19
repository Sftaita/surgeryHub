<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §3/§6 du lot : filtres communs à tous les
 * endpoints de statistiques financières. `from` inclusif, `to` exclusif (§3). Un filtre
 * absent signifie "tous" (§6) — jamais une valeur par défaut devinée pour un id.
 *
 * `from`/`to` sont déjà résolus en instants absolus par
 * FinancialStatisticsFilterService (timezone métier D-066 appliquée à l'entrée) — ce
 * DTO ne fait plus aucune interprétation de timezone lui-même.
 */
final readonly class FinancialStatisticsFilter
{
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?int $siteId = null,
        public ?int $surgeonId = null,
        public ?int $instrumentistId = null,
        public ?int $firmId = null,
        public ?int $interventionTypeId = null,
        public ?string $currency = null,
    ) {}
}
