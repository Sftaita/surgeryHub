<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class InstrumentistVoter extends Voter
{
    public const LIST = 'INSTRUMENTIST_LIST';
    public const LIST_WITH_RATES = 'INSTRUMENTIST_LIST_WITH_RATES';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [
            self::LIST,
            self::LIST_WITH_RATES,
        ], true)) {
            return false;
        }

        // Ces attributs sont évalués sans sujet métier (ou sur la classe User)
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
            default => false,
        };
    }
}
