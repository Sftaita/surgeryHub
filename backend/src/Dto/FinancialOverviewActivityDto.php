<?php

namespace App\Dto;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §2/§7 du lot : bloc "activité opérationnelle"
 * de l'overview, source Mission/MissionExecution exclusivement. Volontairement SANS
 * devise (une mission n'a pas de devise propre — seules ses lignes financières en ont
 * une) : ce bloc n'est jamais dupliqué par devise, contrairement à
 * FinancialOverviewCurrencyDto.
 */
final readonly class FinancialOverviewActivityDto
{
    public function __construct(
        public int $missionCount,
        public int $executedMissionCount,
        public int $validatedMissionCount,
        public int $averageExecutionDurationMinutes,
    ) {}
}
