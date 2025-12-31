<?php

namespace App\Security\Voter;

use App\Entity\InstrumentistService;
use App\Entity\ServiceHoursDispute;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ServiceVoter extends Voter
{
    public const UPDATE = 'SERVICE_UPDATE';
    public const DISPUTE_CREATE = 'SERVICE_DISPUTE_CREATE';
    public const DISPUTE_MANAGE = 'SERVICE_DISPUTE_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::UPDATE, self::DISPUTE_CREATE, self::DISPUTE_MANAGE], true)
            && ($subject instanceof InstrumentistService || $subject instanceof ServiceHoursDispute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isManager = in_array('ROLE_MANAGER', $user->getRoles(), true) || in_array('ROLE_ADMIN', $user->getRoles(), true);

        return match ($attribute) {
            self::UPDATE => $subject instanceof InstrumentistService && $this->canUpdateService($subject, $user, $isManager),
            self::DISPUTE_CREATE => $subject instanceof InstrumentistService && $subject->getMission()?->getSurgeon()?->getId() === $user->getId(),
            self::DISPUTE_MANAGE => $isManager,
            default => false,
        };
    }

    private function canUpdateService(InstrumentistService $service, User $user, bool $isManager): bool
    {
        return $isManager || $service->getMission()?->getInstrumentist()?->getId() === $user->getId();
    }
}
