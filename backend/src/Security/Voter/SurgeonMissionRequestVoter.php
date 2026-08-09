<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Lot 5 (D-099) — SELF_ACCESS (rôle SURGEON, sans sujet) gate la création/liste
 * self-scopée par le chirurgien. MANAGE (rôle MANAGER/ADMIN, sans sujet) gate la revue
 * manager (liste/accept/reject) — dédié, jamais BillingVoter::MANAGE (domaine
 * planning/mission, pas catalogue/facturation).
 */
final class SurgeonMissionRequestVoter extends Voter
{
    public const SELF_ACCESS = 'SURGEON_MISSION_REQUEST_SELF_ACCESS';
    public const MANAGE = 'SURGEON_MISSION_REQUEST_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::SELF_ACCESS, self::MANAGE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();

        if ($attribute === self::SELF_ACCESS) {
            return in_array('ROLE_SURGEON', $roles, true);
        }

        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }
}
