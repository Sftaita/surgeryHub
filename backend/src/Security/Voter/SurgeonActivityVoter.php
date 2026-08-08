<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Self-scoped surgeon activity access (Lot 4, D-098) — role-only check, no subject: the
 * endpoint never accepts a surgeonId, the backend always resolves the current authenticated
 * user (see SurgeonActivityController). Mirrors AbsenceVoter::SELF_ACCESS (Lot 3, D-097).
 */
final class SurgeonActivityVoter extends Voter
{
    public const SELF_ACCESS = 'SURGEON_ACTIVITY_SELF_ACCESS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::SELF_ACCESS;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof User && in_array('ROLE_SURGEON', $user->getRoles(), true);
    }
}
