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
                // 'cancel' couvre aussi DRAFT depuis D-090 (MissionPostDeployService::cancel()
                // accepte déjà DRAFT|OPEN|ASSIGNED) — un manager doit pouvoir abandonner un
                // brouillon jamais publié, jamais uniquement 'edit'/'publish' sans issue.
                MissionStatus::DRAFT => ['view', 'edit', 'publish', 'cancel'],
                MissionStatus::OPEN => ['view', 'view_publications', 'cancel'],
                MissionStatus::ASSIGNED => ['view', 'cancel', 'reassign', 'view_claim'],
                // Lot 7 (D-070) : corrigé — 'reopen' n'est valide que depuis VALIDATED
                // (une mission SUBMITTED n'a jamais été validée, donc rien à "rouvrir").
                MissionStatus::SUBMITTED => ['view', 'validate', 'reject'],
                MissionStatus::VALIDATED => ['view', 'reopen'],
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
                // Lot 7 (D-070) : 'start_encoding' est une invite optionnelle — 'submit'
                // (= "complete") reste directement atteignable sans être passé par start()
                // (comportement préexistant conservé, voir MissionEncodingWorkflowService::complete()).
                $actions[] = 'start_encoding';
                $actions[] = 'submit';
                // Corrigé : 'edit_hours' était réservé à DECLARED depuis D-013, jamais étendu
                // quand Lot 7 a introduit ce bloc de statuts. PATCH /api/missions/{id}/service
                // (ServiceController + MissionExecutionVoter::UPDATE) n'a aucune restriction de
                // statut — l'instrumentiste assigné peut déjà éditer les heures ici côté backend,
                // seul allowedActions ne le reflétait pas.
                $actions[] = 'edit_hours';
            }

            // Lot 7 (D-070) : encodage explicitement démarré — mêmes actions que ci-dessus
            // moins 'start_encoding' (déjà fait), 'submit' = "terminer l'encodage".
            if ($mission->getStatus() === MissionStatus::ENCODING_IN_PROGRESS) {
                $actions[] = 'edit_encoding';
                $actions[] = 'submit';
                $actions[] = 'edit_hours'; // même correction que ci-dessus
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