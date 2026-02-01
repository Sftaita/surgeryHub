<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\PublicationScope;

final class MissionActionsService
{
    public function __construct(
        private readonly MissionEncodingGuard $encodingGuard,
    ) {}

    /**
     * @return string[]
     */
    public function allowedActions(Mission $mission, User $viewer): array
    {
        $roles = $viewer->getRoles();
        $isManager = in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true);
        $isInstr = in_array('ROLE_INSTRUMENTIST', $roles, true);
        $isSurgeon = $mission->getSurgeon()?->getId() === $viewer->getId();
        $isAssignedInstr = $mission->getInstrumentist()?->getId() === $viewer->getId();

        $actions = ['view'];

        if ($isManager) {
            return match ($mission->getStatus()) {
                MissionStatus::DRAFT => ['view', 'edit', 'publish'],
                MissionStatus::OPEN => ['view', 'view_publications', 'cancel'],
                MissionStatus::ASSIGNED => ['view', 'cancel', 'reassign', 'view_claim'],
                MissionStatus::SUBMITTED => ['view', 'validate', 'reopen'],
                default => ['view'],
            };
        }

        // Instrumentiste : claim si OPEN + éligible (publication + règles EMPLOYEE/FREELANCER)
        if ($isInstr && $this->canInstrumentistClaim($mission, $viewer)) {
            $actions[] = 'claim';
        }

        // Instrumentiste assigné : submit / edit_encoding uniquement si ASSIGNED ou IN_PROGRESS
        // ET seulement si l'encodage est autorisé (pas avant startAt).
        if (
            $isInstr
            && $isAssignedInstr
            && in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS], true)
            && $this->isEncodingAllowedNow($mission, $viewer)
        ) {
            $actions[] = 'edit_encoding';
            $actions[] = 'submit';
        }

        // Chirurgien : placeholders (tu pourras durcir plus tard selon statuts/feature flags)
        if ($isSurgeon) {
            $actions[] = 'rate_instrumentist';
            $actions[] = 'dispute_hours';
        }

        return array_values(array_unique($actions));
    }

    private function isEncodingAllowedNow(Mission $mission, User $actor): bool
    {
        try {
            $this->encodingGuard->assertEncodingAllowed($mission, $actor);
            return true;
        } catch (\Throwable) {
            // On cache l'action si le garde-fou métier bloquerait de toute façon (ex: avant startAt).
            return false;
        }
    }

    private function canInstrumentistClaim(Mission $mission, User $instrumentist): bool
    {
        // Conditions de base
        if (!in_array('ROLE_INSTRUMENTIST', $instrumentist->getRoles(), true)) {
            return false;
        }

        if ($mission->getStatus() !== MissionStatus::OPEN) {
            return false;
        }

        // Filet de sécurité: si un instrumentiste est déjà posé sur la mission, on n'affiche pas claim
        if ($mission->getInstrumentist() !== null) {
            return false;
        }

        // Vérifie l'éligibilité via publications:
        // - TARGETED vers moi => OK
        // - POOL => FREELANCER OK partout, EMPLOYEE nécessite membership sur le site de la mission
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
}
