<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class InstrumentistVoter extends Voter
{
    public const LIST = 'INSTRUMENTIST_LIST';
    public const LIST_WITH_RATES = 'INSTRUMENTIST_LIST_WITH_RATES';
    public const CREATE = 'INSTRUMENTIST_CREATE';
    public const UPDATE_RATES = 'INSTRUMENTIST_UPDATE_RATES';
    public const SUSPEND = 'INSTRUMENTIST_SUSPEND';
    public const ACTIVATE = 'INSTRUMENTIST_ACTIVATE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [
            self::LIST,
            self::LIST_WITH_RATES,
            self::CREATE,
            self::UPDATE_RATES,
            self::SUSPEND,
            self::ACTIVATE,
        ], true)) {
            return false;
        }

        return $subject === null || $subject === User::class;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);

        return match ($attribute) {
            self::LIST => $isManager,
            self::LIST_WITH_RATES => $isManager,
            self::CREATE => $isManager,
            self::UPDATE_RATES => $isManager,
            self::SUSPEND => $isManager,
            self::ACTIVATE => $isManager,
            default => false,
        };
    }
}