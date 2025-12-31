<?php

namespace App\Security\Voter;

use App\Entity\Mission;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class RatingVoter extends Voter
{
    public const RATE_INSTRUMENTIST = 'RATE_INSTRUMENTIST';
    public const RATE_SURGEON = 'RATE_SURGEON';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::RATE_INSTRUMENTIST, self::RATE_SURGEON], true) && $subject instanceof Mission;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $mission = $subject;

        return match ($attribute) {
            self::RATE_INSTRUMENTIST => $mission->getSurgeon()?->getId() === $user->getId(),
            self::RATE_SURGEON => $mission->getInstrumentist()?->getId() === $user->getId(),
            default => false,
        };
    }
}
