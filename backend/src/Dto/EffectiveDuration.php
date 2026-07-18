<?php

namespace App\Dto;

use App\Enum\EffectiveDurationSource;

/**
 * Lot 1 (Exécution & Valorisation) — résultat de
 * MissionExecutionService::resolveEffectiveDuration(). Le futur moteur financier
 * (FinancialCalculation) est le consommateur visé : $minutes est la seule donnée dont
 * il a besoin, $source documente pourquoi (auditabilité), jamais une donnée qu'il doit
 * lui-même interpréter.
 */
final readonly class EffectiveDuration
{
    public function __construct(
        public int $minutes,
        public EffectiveDurationSource $source,
    ) {}
}
