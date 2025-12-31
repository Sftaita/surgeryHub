<?php

namespace App\Security\Voter;

use App\Entity\Mission;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class MissionVoter extends Voter
{
    public const VIEW = 'MISSION_VIEW';
    public const CREATE = 'MISSION_CREATE';
    public const PUBLISH = 'MISSION_PUBLISH';
    public const CLAIM = 'MISSION_CLAIM';
    public const SUBMIT = 'MISSION_SUBMIT';
    public const EDIT_ENCODING = 'MISSION_EDIT_ENCODING';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::CREATE, self::PUBLISH, self::CLAIM, self::SUBMIT, self::EDIT_ENCODING], true)
            && $subject instanceof Mission;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $mission = $subject;
        $isManager = $this->isManager($user);
        $isAdmin = $this->isAdmin($user);

        return match ($attribute) {
            self::VIEW => $this->canView($mission, $user, $isManager || $isAdmin),
            self::CREATE, self::PUBLISH => $isManager || $isAdmin,
            self::CLAIM => $this->canClaim($mission, $user),
            self::SUBMIT => $this->canSubmit($mission, $user, $isManager || $isAdmin),
            self::EDIT_ENCODING => $this->canEditEncoding($mission, $user, $isManager || $isAdmin),
            default => false,
        };
    }

    private function canView(Mission $mission, User $user, bool $managerContext): bool
    {
        return $managerContext
            || $mission->getSurgeon()?->getId() === $user->getId()
            || $mission->getInstrumentist()?->getId() === $user->getId();
    }

    private function canClaim(Mission $mission, User $user): bool
    {
        return in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true);
    }

    private function canSubmit(Mission $mission, User $user, bool $managerContext): bool
    {
        return $managerContext || $mission->getInstrumentist()?->getId() === $user->getId();
    }

    private function canEditEncoding(Mission $mission, User $user, bool $managerContext): bool
    {
        return $managerContext
            || $mission->getInstrumentist()?->getId() === $user->getId()
            || $mission->getSurgeon()?->getId() === $user->getId();
    }

    private function isManager(User $user): bool
    {
        return in_array('ROLE_MANAGER', $user->getRoles(), true);
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
