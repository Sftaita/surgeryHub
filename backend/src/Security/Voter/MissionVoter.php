<?php

namespace App\Security\Voter;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EmploymentType;
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

    // PATCH "planning" (site / startAt / endAt / type / schedulePrecision)
    public const EDIT = 'MISSION_EDIT';

    // Encodage (ex: instrumentiste)
    public const EDIT_ENCODING = 'MISSION_EDIT_ENCODING';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [
            self::VIEW,
            self::CREATE,
            self::PUBLISH,
            self::CLAIM,
            self::SUBMIT,
            self::EDIT,
            self::EDIT_ENCODING,
        ], true)) {
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
            self::EDIT => $this->canEdit($mission, $user, $isManager),
            self::EDIT_ENCODING => $this->canEditEncoding($mission, $user, $isManager),
            default => false,
        };
    }

    private function canView(Mission $mission, User $user, bool $managerContext): bool
    {
        if ($managerContext) {
            return true;
        }

        if ($mission->getSurgeon()?->getId() === $user->getId()) {
            return true;
        }

        if ($mission->getInstrumentist()?->getId() === $user->getId()) {
            return true;
        }

        // instrumentiste peut voir les missions OPEN publiées (POOL/TARGETED) selon règles EMPLOYEE/FREELANCER
        if (in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true) && $mission->getStatus() === MissionStatus::OPEN) {
            return $this->isEligibleInstrumentistForOpenMission($mission, $user);
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

        // sécurité: pas claimable si déjà affectée
        if ($mission->getInstrumentist() !== null) {
            return false;
        }

        return $this->isEligibleInstrumentistForOpenMission($mission, $user);
    }

    private function canSubmit(Mission $mission, User $user, bool $managerContext): bool
    {
        // manager/admin: autorisé (support / correction)
        if ($managerContext) {
            return true;
        }

        // SUBMIT = action instrumentiste
        if (!in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true)) {
            return false;
        }

        if ($mission->getInstrumentist()?->getId() !== $user->getId()) {
            return false;
        }

        // RÈGLE CLÉ : pas d'encodage / submit avant le début
        if (!$this->hasMissionStarted($mission)) {
            return false;
        }

        // ✅ SUBMITTED autorisé (idempotent + liberté instrumentiste)
        return in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS, MissionStatus::SUBMITTED], true);
    }

    private function canEdit(Mission $mission, User $user, bool $managerContext): bool
    {
        // Édition "planning" réservée aux managers/admin.
        if (!$managerContext) {
            return false;
        }

        return in_array($mission->getStatus(), [MissionStatus::DRAFT], true);
    }

    private function canEditEncoding(Mission $mission, User $user, bool $managerContext): bool
    {
        // manager/admin: OK (support / correction)
        if ($managerContext) {
            return true;
        }

        // instrumentiste assigné seulement
        if (!in_array('ROLE_INSTRUMENTIST', $user->getRoles(), true)) {
            return false;
        }

        if ($mission->getInstrumentist()?->getId() !== $user->getId()) {
            return false;
        }

        // RÈGLE CLÉ : pas d'encodage avant le début
        if (!$this->hasMissionStarted($mission)) {
            return false;
        }

        // ✅ SUBMITTED autorisé (liberté instrumentiste)
        return in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS, MissionStatus::SUBMITTED], true);
    }

    private function isEligibleInstrumentistForOpenMission(Mission $mission, User $instrumentist): bool
    {
        // TARGETED => ok si target = moi
        // POOL => ok si FREELANCER, sinon EMPLOYEE nécessite membership sur le site

        $isFreelancer = ($instrumentist->getEmploymentType() === EmploymentType::FREELANCER);

        $hasMembershipForSite = false;
        if (!$isFreelancer) {
            $missionSiteId = $mission->getSite()?->getId();
            if ($missionSiteId !== null) {
                foreach ($instrumentist->getSiteMemberships() as $sm) {
                    if ($sm->getSite()?->getId() === $missionSiteId) {
                        $hasMembershipForSite = true;
                        break;
                    }
                }
            }
        }

        foreach ($mission->getPublications() as $pub) {
            if ($pub->getScope() === PublicationScope::TARGETED) {
                if ($pub->getTargetInstrumentist()?->getId() === $instrumentist->getId()) {
                    return true;
                }
                continue;
            }

            if ($pub->getScope() === PublicationScope::POOL) {
                return $isFreelancer ? true : $hasMembershipForSite;
            }
        }

        return false;
    }

    private function hasMissionStarted(Mission $mission): bool
    {
        $startAt = $mission->getStartAt();
        if ($startAt === null) {
            // Si mission mal formée sans startAt, on refuse l'encodage (sécurité)
            return false;
        }

        // On compare en UTC pour éviter les surprises de timezone serveur.
        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $nowUtc >= $startAt;
    }
}
