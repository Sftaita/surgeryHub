<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\MissionAssignment;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;
use App\Exception\AssignmentRefusalException;

/**
 * D-151 — the single rule deciding whether the surgeon of a mission may refuse its current
 * affectation right now. Read by MissionPostDeployService::refuseAssignment() (under the
 * mission lock), by MissionActionsService (allowedActions) and by MissionMapper (deadline
 * shown to the surgeon): never re-derived anywhere else, never in the frontend.
 *
 * - Only a voluntary acceptance (POOL_CLAIM / OFFER_ACCEPTED) can be refused — no approval is
 *   ever required: an affectation nobody refuses simply stays.
 * - Refusal window: until min(assignedAt + 24 h, mission start) — assignedAt is the
 *   affectation's own timestamp (D-150), never the mission's creation date.
 * - Less than 12 h before the start, a refusal never unassigns automatically: the surgeon
 *   asks the managers to intervene instead (REFUSAL_REQUIRES_MANAGER).
 * - Defence in depth: as soon as anything encoding- or finance-related exists on the mission,
 *   a refusal goes through the managers too.
 *
 * Returns null when the refusal is possible, otherwise an AssignmentRefusalException code.
 */
final class MissionAssignmentRefusalPolicy
{
    public const WINDOW_HOURS   = 24;
    public const IMMINENT_HOURS = 12;

    public static function deadline(MissionAssignment $assignment, Mission $mission): \DateTimeImmutable
    {
        $windowEnd = $assignment->getAssignedAt()->modify(sprintf('+%d hours', self::WINDOW_HOURS));
        $startAt   = $mission->getStartAt();

        return $startAt !== null && $startAt < $windowEnd ? $startAt : $windowEnd;
    }

    public static function blockedReason(Mission $mission, ?MissionAssignment $assignment, \DateTimeImmutable $now): ?string
    {
        if ($assignment === null || !$assignment->isActive()
            || $mission->getInstrumentist()?->getId() !== $assignment->getInstrumentist()->getId()) {
            return AssignmentRefusalException::ASSIGNMENT_ALREADY_ENDED;
        }

        if (!$assignment->getSource()->isVoluntaryAcceptance()) {
            return AssignmentRefusalException::NOT_REFUSABLE;
        }

        if ($now >= self::deadline($assignment, $mission)) {
            return AssignmentRefusalException::WINDOW_EXPIRED;
        }

        // Started (IN_PROGRESS…) is already past the deadline above; any other status is not
        // an affectation a refusal may undo on its own.
        if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
            return AssignmentRefusalException::REQUIRES_MANAGER;
        }

        $startAt = $mission->getStartAt();
        if ($startAt !== null && $startAt < $now->modify(sprintf('+%d hours', self::IMMINENT_HOURS))) {
            return AssignmentRefusalException::REQUIRES_MANAGER;
        }

        if (self::hasEncodingOrFinancialData($mission)) {
            return AssignmentRefusalException::REQUIRES_MANAGER;
        }

        return null;
    }

    private static function hasEncodingOrFinancialData(Mission $mission): bool
    {
        if ($mission->isEncodingLocked() || $mission->getEncodingStartedAt() !== null || $mission->getSubmittedAt() !== null) {
            return true;
        }

        $execution = $mission->getExecution();
        if ($execution !== null && ($execution->getActualStartAt() !== null || $execution->getActualEndAt() !== null)) {
            return true;
        }

        if (!$mission->getInterventions()->isEmpty() || !$mission->getMaterialLines()->isEmpty()) {
            return true;
        }

        foreach ($mission->getFinancialCalculations() as $calculation) {
            if (!in_array($calculation->getStatus(), [FinancialCalculationStatus::SUPERSEDED, FinancialCalculationStatus::CANCELLED], true)) {
                return true;
            }
        }

        return false;
    }
}
