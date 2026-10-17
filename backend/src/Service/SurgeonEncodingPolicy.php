<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\FinancialCalculationStatus;
use App\Enum\MissionStatus;

/**
 * D-154 — the mission's surgeon encodes interventions and material (never the hours, never a
 * workflow transition) on HIS OWN mission. Single rule for MissionVoter::EDIT_ENCODING
 * (surgeon branch) and the 'edit_encoding' action: a direct API call is refused exactly when
 * the action is not offered.
 *
 * Same frontier as the instrumentist's encoding (MissionVoter::canEditEncoding()): statuses
 * DECLARED → SUBMITTED, never before the start, never on a locked / invoiced encoding, never
 * with a LOCKED financial calculation (a VALIDATED mission is reopened by a manager first).
 *
 * L5 covers missions WITH an instrumentist. L6 (missions executed without instrumentist)
 * extends hasEncodableActivity() only — the screen, endpoints and checks are reused as-is.
 */
final class SurgeonEncodingPolicy
{
    public const EDITABLE_STATUSES = [
        MissionStatus::DECLARED,
        MissionStatus::ASSIGNED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
        MissionStatus::SUBMITTED,
    ];

    /** The surgeon of THIS mission (a colleague's mission is never his to encode). */
    public static function isMissionSurgeon(Mission $mission, User $user): bool
    {
        return in_array('ROLE_SURGEON', $user->getRoles(), true)
            && $mission->getSurgeon()?->getId() !== null
            && $mission->getSurgeon()?->getId() === $user->getId();
    }

    public static function canEditEncoding(Mission $mission, User $user, \DateTimeImmutable $now): bool
    {
        if (!self::isMissionSurgeon($mission, $user) || !self::hasEncodableActivity($mission)) {
            return false;
        }

        if (!in_array($mission->getStatus(), self::EDITABLE_STATUSES, true) || $mission->isEncodingLocked()) {
            return false;
        }

        $startAt = $mission->getStartAt();
        if ($startAt === null || $now < $startAt) {
            return false;
        }

        foreach ($mission->getFinancialCalculations() as $calculation) {
            if ($calculation->getStatus() === FinancialCalculationStatus::LOCKED) {
                return false;
            }
        }

        return true;
    }

    /** L5 — an instrumentist is assigned (missions executed without one: L6). */
    private static function hasEncodableActivity(Mission $mission): bool
    {
        return $mission->getInstrumentist() !== null;
    }
}
