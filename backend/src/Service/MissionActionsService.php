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

        // REJECTED = lecture seule (contrat)
        if ($mission->getStatus() === MissionStatus::REJECTED) {
            return ['view'];
        }

        $actions = ['view'];

        // Manager/Admin
        if ($isManager) {
            return match ($mission->getStatus()) {
                MissionStatus::DRAFT => ['view', 'edit', 'publish'],
                MissionStatus::OPEN => ['view', 'view_publications', 'cancel'],
                MissionStatus::ASSIGNED => ['view', 'cancel', 'reassign', 'view_claim'],
                MissionStatus::SUBMITTED => ['view', 'validate', 'reopen'],
                MissionStatus::DECLARED => ['view', 'approve', 'reject', 'edit'],
                default => ['view'],
            };
        }

        // Instrumentiste : claim si OPEN + éligible (publication + règles EMPLOYEE/FREELANCER)
        if ($isInstr && $this->canInstrumentistClaim($mission, $viewer)) {
            $actions[] = 'claim';
        }

        // Instrumentiste assigné : encoding / submit selon statut
        // - ASSIGNED / IN_PROGRESS : encodage standard (action existante: edit_encoding)
        // - DECLARED : encodage autorisé (action contrat: encoding) + submit + edit_hours
        // Toujours sous réserve du garde-fou encodage (ex: pas avant startAt).
        if (
            $isInstr
            && $isAssignedInstr
            && $this->isEncodingAllowedNow($mission, $viewer)
        ) {
            if (in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS], true)) {
                $actions[] = 'edit_encoding';
                $actions[] = 'submit';
            }

            if ($mission->getStatus() === MissionStatus::DECLARED) {
                $actions[] = 'encoding';
                $actions[] = 'submit';
                $actions[] = 'edit_hours';
            }
        }

        // Chirurgien : uniquement view sur DECLARED (contrat)
        if ($isSurgeon && $mission->getStatus() !== MissionStatus::DECLARED) {
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