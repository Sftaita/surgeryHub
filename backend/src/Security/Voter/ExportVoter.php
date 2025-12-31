<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ExportVoter extends Voter
{
    public const SURGEON_ACTIVITY = 'EXPORT_SURGEON_ACTIVITY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::SURGEON_ACTIVITY && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isManager = in_array('ROLE_MANAGER', $user->getRoles(), true) || in_array('ROLE_ADMIN', $user->getRoles(), true);

        return $subject->getId() === $user->getId() || $isManager;
    }
}
