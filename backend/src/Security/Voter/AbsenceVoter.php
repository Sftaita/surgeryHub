<?php

namespace App\Security\Voter;

use App\Entity\Absence;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Self-service absence access (Lot 3, D-097) — separate from PlanningVoter::PLANNING_MANAGE,
 * which remains the manager/admin-only gate on AbsenceController (untouched by this lot).
 *
 * SELF_ACCESS: role-only check (SURGEON or INSTRUMENTIST), no subject — used for endpoints
 * not tied to one specific Absence row (list, create, impact preview).
 *
 * SELF_MANAGE: ownership + editability, evaluated against a specific Absence instance — used
 * for update/delete. Ownership is `absence.user === current user`, never negotiable by the
 * client (the controller never reads a client-supplied userId for self-service — see
 * SelfAbsenceController). Editability follows the a priori rule from the Lot 3 spec (no
 * existing AbsenceController rule to preserve here, since the manager-facing endpoints impose
 * no such restriction — this is a new guard specific to self-service): an absence whose
 * dateEnd has not yet fully passed (today or later) remains editable/deletable; a fully past
 * absence is read-only.
 */
final class AbsenceVoter extends Voter
{
    public const SELF_ACCESS = 'ABSENCE_SELF_ACCESS';
    public const SELF_MANAGE = 'ABSENCE_SELF_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::SELF_ACCESS) {
            return true;
        }

        return $attribute === self::SELF_MANAGE && $subject instanceof Absence;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !self::isSelfServiceEligible($user)) {
            return false;
        }

        if ($attribute === self::SELF_ACCESS) {
            return true;
        }

        /** @var Absence $absence */
        $absence = $subject;
        if ($absence->getUser()?->getId() !== $user->getId()) {
            return false;
        }

        return self::isStillEditable($absence);
    }

    private static function isSelfServiceEligible(User $user): bool
    {
        $roles = $user->getRoles();
        return in_array('ROLE_SURGEON', $roles, true) || in_array('ROLE_INSTRUMENTIST', $roles, true);
    }

    private static function isStillEditable(Absence $absence): bool
    {
        $today = new \DateTimeImmutable('today');
        return $absence->getDateEnd() >= $today;
    }
}
