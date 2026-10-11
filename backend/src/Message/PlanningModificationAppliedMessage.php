<?php

namespace App\Message;

/**
 * H3 (D-129) — a Planning V2 Modification batch has COMMITTED. Dispatched by
 * PlanningModificationService::apply() strictly after the commit (never for a failed
 * batch); its handler produces every notification of the batch under the batch's
 * correlationId:
 *   - the per-person recap (PLANNING_CHANGE_SUMMARY) of the people whose planning changed;
 *   - the pool for the missions the batch opened (OpenMissionPoolNotifier, BATCH mode).
 *
 * $diff: the before/after diff computed inside the batch transaction (plain arrays).
 * $openedEpisodes: OpenEpisode::toArray() of the batch's openings (contextual exclusions
 * of the instrumentists a manager removed included).
 */
final class PlanningModificationAppliedMessage
{
    /**
     * @param array{added: array<array>, removed: array<array>, modified: array<array>} $diff
     * @param list<array<string, mixed>> $openedEpisodes
     */
    public function __construct(
        public readonly int $planningVersionId,
        public readonly string $batchKey,
        public readonly array $diff,
        public readonly array $openedEpisodes,
        public readonly int $actorId,
    ) {
    }
}
