<?php

namespace App\Dto\Request\Response;

/**
 * Lot 1 (Exécution & Valorisation) — réponse de GET/PATCH /api/missions/{id}/execution.
 * Toujours renvoyée, même si aucune MissionExecution n'existe encore en base (§3.1) :
 * dans ce cas `hasExecutionRecord = false` et effectiveDurationMinutes/Source
 * reflètent le repli sur le planifié — jamais une absence de réponse.
 */
final class MissionExecutionDto
{
    /** @param MissionExecutionDisputeDto[] $disputes */
    public function __construct(
        public readonly int $missionId,
        public readonly bool $hasExecutionRecord,
        public readonly ?string $actualStartAt,
        public readonly ?string $actualEndAt,
        public readonly ?int $actualDurationMinutes,
        public readonly ?string $hoursSource,
        public readonly int $effectiveDurationMinutes,
        public readonly string $effectiveDurationSource,
        public readonly array $disputes,
    ) {}
}
