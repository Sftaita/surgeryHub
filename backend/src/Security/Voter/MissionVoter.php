<?php

namespace App\Security\Voter;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PublicationScope;
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
        if (!in_array($attribute, [self::VIEW, self::CREATE, self::PUBLISH, self::CLAIM, self::SUBMIT, self::EDIT_ENCODING], true)) {
            return false;
        }

        // CREATE peut être évalué sur la classe
        if ($attribute === self::CREATE) {
            return $subject === Mission::class || $subject instanceof Mission;
        }

        return $subject instanceof Mission;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $roles = $user->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);

        // CREATE ne dépend pas d'une mission
        if ($attribute === self::CREATE) {
            return $isManager;
        }

        /** @var Mission $mission */
        $mission = $subject;

        return match ($attribute) {
            self::VIEW => $this->canView($mission, $user, $isManager),
            self::PUBLISH => $isManager,
            self::CLAIM => $this->canClaim($mission, $user),
            self::SUBMIT => $this->canSubmit($mission, $user, $isManager),
            self::EDIT_ENCODING => $this->canEditEncoding($mission, $user, $isManager),
            default => false,
        };
    }

    private function canView(Mission $mission, User $user, bool $managerContext): bool
    {
        if ($managerContext) return true;

        if ($mission->getSurgeon()?->getId() === $user->getId()) return true;
        if ($mission->getInstrumentist()?->getId() === $user->getId()) return true;

        // instrumentiste peut voir les missions OPEN publiées (POOL/TARGETED)
        if (in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true) && $mission->getStatus() === MissionStatus::OPEN) {
            foreach ($mission->getPublications() as $pub) {
                if ($pub->getScope() === PublicationScope::POOL) return true;
                if ($pub->getScope() === PublicationScope::TARGETED && $pub->getTargetInstrumentist()?->getId() === $user->getId()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function canClaim(Mission $mission, User $user): bool
    {
        if (!in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true)) {
            return false;
        }

        if ($mission->getStatus() !== MissionStatus::OPEN) {
            return false;
        }

        if ($mission->getInstrumentist() !== null) {
            return false;
        }

        // eligible by publication
        foreach ($mission->getPublications() as $pub) {
            if ($pub->getScope() === PublicationScope::POOL) return true;
            if ($pub->getScope() === PublicationScope::TARGETED && $pub->getTargetInstrumentist()?->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    private function canSubmit(Mission $mission, User $user, bool $managerContext): bool
    {
        return $managerContext || $mission->getInstrumentist()?->getId() === $user->getId();
    }

    private function canEditEncoding(Mission $mission, User $user, bool $managerContext): bool
    {
        // chirurgien lecture seule : pas d'édition encodage
        return $managerContext || $mission->getInstrumentist()?->getId() === $user->getId();
    }
}
