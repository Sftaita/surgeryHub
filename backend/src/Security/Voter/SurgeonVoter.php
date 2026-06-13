<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class SurgeonVoter extends Voter
{
    public const LIST = 'SURGEON_LIST';
    public const CREATE = 'SURGEON_CREATE';
    public const VIEW = 'SURGEON_VIEW';
    public const ADD_SITE_MEMBERSHIP = 'SURGEON_ADD_SITE_MEMBERSHIP';
    public const DELETE_SITE_MEMBERSHIP = 'SURGEON_DELETE_SITE_MEMBERSHIP';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::LIST, self::CREATE, self::VIEW,
            self::ADD_SITE_MEMBERSHIP, self::DELETE_SITE_MEMBERSHIP,
        ], true) && ($subject === null || $subject === User::class);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
        $isInstrumentist = in_array('ROLE_INSTRUMENTIST', $roles, true);

        return match ($attribute) {
            self::LIST => $isManager || $isInstrumentist,
            default => $isManager,
        };
    }
}
