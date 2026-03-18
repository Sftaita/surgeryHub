<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class PlanningVoter extends Voter
{
    public const PLANNING_MANAGE = 'PLANNING_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::PLANNING_MANAGE;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();
        return in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
    }
}
