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
        /**
         * D-156 — présence réelle partagée quand la mission appartient à une prestation
         * multi-salles dont les heures ont été déclarées : {id, revision, actualStartAt,
         * actualEndAt, totalMinutes, missionIds}. actualDurationMinutes est alors la PART
         * IMPUTÉE à cette mission (effectiveDurationSource = PRESENCE_SHARE).
         *
         * @var array<string,mixed>|null
         */
        public readonly ?array $presence = null,
        /** @var list<int> D-156 — missions de la prestation dynamique (seule = [missionId]). */
        public readonly array $prestationMissionIds = [],
    ) {}
}
