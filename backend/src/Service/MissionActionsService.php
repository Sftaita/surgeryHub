<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\PublicationScope;
use Symfony\Component\Clock\Clock;

final class MissionActionsService
{
    public function __construct(
        private readonly MissionEncodingGuard $encodingGuard,
        // D-151 — optional only so plain unit tests can build the service; always autowired.
        private readonly ?MissionAssignmentService $assignments = null,
        private readonly ?MissionEligibilityService $eligibility = null,
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
            // D-120 / D-166 — même règle exactement que MissionVoter::ENCODING_REMIND
            // (EncodingReminderPolicy) : jamais de relance sans instrumentiste affectée. Le
            // filtrage par statut reste porté par le match ci-dessous (mêmes statuts).
            $remind = EncodingReminderPolicy::canSendManualReminder($mission) ? ['remind'] : [];

            $actions = match ($mission->getStatus()) {
                // 'cancel' couvre aussi DRAFT depuis D-090 (MissionPostDeployService::cancel()
                // accepte déjà DRAFT|OPEN|ASSIGNED) — un manager doit pouvoir abandonner un
                // brouillon jamais publié, jamais uniquement 'edit'/'publish' sans issue.
                MissionStatus::DRAFT => ['view', 'edit', 'publish', 'cancel'],
                // D-125 — 'dispatch' (pool / request / direct assignment) is offered again on
                // an OPEN mission nobody holds and no request is pending for — typically
                // after its target declined (MissionDispatchService::lockDispatchable()).
                MissionStatus::OPEN => $mission->getInstrumentist() === null && MissionDispatchService::pendingOffer($mission) === null
                    ? ['view', 'view_publications', 'cancel', 'dispatch']
                    : ['view', 'view_publications', 'cancel'],
                MissionStatus::ASSIGNED => [...['view', 'cancel', 'reassign', 'view_claim'], ...$remind],
                MissionStatus::IN_PROGRESS, MissionStatus::ENCODING_IN_PROGRESS => [...['view'], ...$remind],
                // Lot 7 (D-070) : corrigé — 'reopen' n'est valide que depuis VALIDATED
                // (une mission SUBMITTED n'a jamais été validée, donc rien à "rouvrir").
                MissionStatus::SUBMITTED => ['view', 'validate', 'reject'],
                // D-148 — jamais proposé quand reopen() le refuserait (calcul LOCKED).
                MissionStatus::VALIDATED => MissionEncodingCorrectionPolicy::reopenBlockedReason($mission) === null
                    ? ['view', 'reopen']
                    : ['view'],
                MissionStatus::DECLARED => [...['view', 'approve', 'reject', 'edit'], ...$remind],
                default => ['view'],
            };

            // D-165 — horaires prévus corrigeables depuis le planning (MissionPostDeployService::
            // updateSchedule()) : OPEN/ASSIGNED, et IN_PROGRESS pour ses seules heures du jour.
            if (in_array($mission->getStatus(), MissionPostDeployService::RESCHEDULABLE_STATUSES, true)) {
                $actions[] = 'reschedule';
            }

            // D-136 — rappel des heures réelles manquantes, transverse aux statuts ci-dessus :
            // même condition exactement que MissionVoter::HOURS_REMIND.
            if (MissionHoursReminderPolicy::isReminderRelevant($mission)) {
                $actions[] = 'remind_hours';
            }

            // D-153 — corrections directes du manager : mêmes règles exactement que
            // MissionVoter::EDIT_ENCODING et MissionExecutionVoter::UPDATE (une mission
            // validée se rouvre d'abord, jamais une mission clôturée ou verrouillée).
            if (MissionEncodingCorrectionPolicy::managerCanEditEncoding($mission)) {
                $actions[] = 'edit_encoding';
            }
            if (MissionEncodingCorrectionPolicy::managerCanEditHours($mission)) {
                $actions[] = 'edit_hours';
            }

            // D-155 — same rules exactly as MissionVoter::DECLARE_ / CANCEL_EXECUTED_WITHOUT_INSTRUMENTIST.
            if (ExecutedWithoutInstrumentistPolicy::canDeclare($mission, $viewer, Clock::get()->now())) {
                $actions[] = 'declare_executed_without_instrumentist';
            }
            if (ExecutedWithoutInstrumentistPolicy::canCancel($mission, $viewer)) {
                $actions[] = 'cancel_executed_without_instrumentist';
            }
            // D-155 — the manager may submit a declared activity himself (MissionVoter::SUBMIT
            // already grants it); offered only in this mode, where no instrumentist will.
            if ($mission->isExecutedWithoutInstrumentist() && $mission->getStatus() === MissionStatus::ENCODING_IN_PROGRESS) {
                $actions[] = 'submit';
            }
            // D-171 — finish and validate an open encoding himself: same rule exactly as
            // MissionVoter::ENCODING_COMPLETE_AND_VALIDATE.
            if (MissionEncodingCorrectionPolicy::completeAndValidateBlockedReason($mission, Clock::get()->now()) === null) {
                $actions[] = 'complete_and_validate';
            }

            return $actions;
        }

        // Instrumentiste : claim si OPEN + éligible (publication + règles EMPLOYEE/FREELANCER)
        if ($isInstr && $this->canInstrumentistClaim($mission, $viewer)) {
            $actions[] = 'claim';
        }

        // D-125 — the target of a pending nominative request may refuse it ('claim' = accept).
        if ($isInstr && MissionDispatchService::pendingOffer($mission)?->getTargetInstrumentist()?->getId() === $viewer->getId()) {
            $actions[] = 'decline_offer';
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

        // D-146 bis — instrumentiste assignée : consultation (lecture seule) de son encodage
        // une fois la mission validée ou clôturée — même règle que MissionVoter::canViewEncoding().
        if ($isInstr && $isAssignedInstr && in_array($mission->getStatus(), [MissionStatus::VALIDATED, MissionStatus::CLOSED], true)) {
            $actions[] = 'view_encoding';
        }

        // D-151 — the mission's surgeon: refuse the instrumentist who accepted it while the
        // policy allows it, otherwise ask the managers to intervene (once per affectation).
        if ($isSurgeon && in_array('ROLE_SURGEON', $roles, true)
            && in_array($mission->getStatus(), [MissionStatus::ASSIGNED, MissionStatus::IN_PROGRESS], true)) {
            $current = $this->assignments?->findCurrent($mission);
            if ($current !== null) {
                if (MissionAssignmentRefusalPolicy::blockedReason($mission, $current, Clock::get()->now()) === null) {
                    $actions[] = 'refuse_assignment';
                } elseif (!$current->isReviewRequested()) {
                    $actions[] = 'request_assignment_review';
                }
            }
        }

        // D-154 — the mission's surgeon encodes interventions and material (never the hours,
        // never start / submit): same rule exactly as MissionVoter::EDIT_ENCODING.
        if (SurgeonEncodingPolicy::canEditEncoding($mission, $viewer, Clock::get()->now())) {
            $actions[] = 'edit_encoding';
        }
        // D-155 — an activity he declared executed without instrumentist: he also submits it.
        if (ExecutedWithoutInstrumentistPolicy::surgeonCanSubmit($mission, $viewer, Clock::get()->now())) {
            $actions[] = 'submit';
        }
        // D-155 — declare his own started, uncovered OPEN mission executed without instrumentist.
        if (ExecutedWithoutInstrumentistPolicy::canDeclare($mission, $viewer, Clock::get()->now())) {
            $actions[] = 'declare_executed_without_instrumentist';
        }

        // Chirurgien : uniquement view sur DECLARED (contrat)
        if ($isSurgeon && $mission->getStatus() !== MissionStatus::DECLARED) {
            $actions[] = 'rate_instrumentist';
            $actions[] = 'dispute_hours';
        }

        // Lot 6 (D-100) — lecture seule de l'encodage, jamais un droit d'écriture (voir
        // MissionVoter::canViewEncoding(), même liste de statuts exactement).
        if ($isSurgeon && in_array($mission->getStatus(), [
            MissionStatus::DECLARED,
            MissionStatus::ASSIGNED,
            MissionStatus::IN_PROGRESS,
            MissionStatus::ENCODING_IN_PROGRESS,
            MissionStatus::SUBMITTED,
            MissionStatus::VALIDATED,
            MissionStatus::CLOSED,
        ], true)) {
            $actions[] = 'view_encoding';
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

        // D-151 / D-152 — same gate as claim(): never once started, never for someone its
        // surgeon refused on it or is incompatible with.
        if (MissionEligibilityService::hasStarted($mission, Clock::get()->now())) {
            return false;
        }
        if ($this->eligibility !== null && $this->eligibility->exclusionReasons($mission, $instrumentist) !== []) {
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
            // D-125 — a declined TARGETED request no longer grants its target anything.
            if (!$pub->isActive()) {
                continue;
            }
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