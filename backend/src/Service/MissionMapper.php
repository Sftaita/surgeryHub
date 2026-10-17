<?php

namespace App\Service;

use App\Dto\Request\Response\HospitalSlimDto;
use App\Dto\Request\Response\MissionDetailDto;
use App\Dto\Request\Response\MissionListDto;
use App\Dto\Request\Response\UserSlimDto;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use Symfony\Component\Clock\Clock;

final class MissionMapper
{
    public function __construct(
        private readonly MissionActionsService $actions,
        private readonly PlanningCoverageService $coverage,
        private readonly EncodingReminderService $reminders,
        // D-151 — optional only so plain unit tests can build the mapper; always autowired.
        private readonly ?MissionAssignmentService $assignments = null,
    ) {}

    public function toListDto(Mission $m, User $viewer): MissionListDto
    {
        return new MissionListDto(
            id: (int) $m->getId(),
            site: $this->toHospitalSlim($m->getSite()),
            // D-066: Mission.startAt/endAt use business_datetime_immutable — already
            // correctly Europe/Brussels-labeled at hydration, no relabel needed here.
            startAt: $m->getStartAt()?->format(\DateTimeInterface::ATOM),
            endAt: $m->getEndAt()?->format(\DateTimeInterface::ATOM),
            schedulePrecision: (string) $m->getSchedulePrecision()?->value,
            type: (string) $m->getType()?->value,
            status: (string) $m->getStatus()?->value,
            surgeon: $this->toUserSlim($m->getSurgeon()),
            instrumentist: $m->getInstrumentist() ? $this->toUserSlim($m->getInstrumentist()) : null,
            allowedActions: $this->actions->allowedActions($m, $viewer),
            covered: $this->coverage->isCovered($m),
            targetedOffer: $this->targetedOffer($m),
        );
    }

    public function toDetailDto(Mission $m, User $viewer): MissionDetailDto
    {
        $lastManualReminder = $this->reminders->lastManualReminder($m);
        $isManagerViewer = in_array('ROLE_MANAGER', $viewer->getRoles(), true) || in_array('ROLE_ADMIN', $viewer->getRoles(), true);
        // D-143 — historique des relances automatiques (J+1/J+2/J+4), manager uniquement.
        $automaticReminders = $isManagerViewer ? $this->reminders->reminderHistory($m) : [];
        $lastAutomatic = $automaticReminders !== [] ? $automaticReminders[array_key_last($automaticReminders)] : null;

        return new MissionDetailDto(
            id: (int) $m->getId(),
            site: $this->toHospitalSlim($m->getSite()),
            // D-066: Mission.startAt/endAt use business_datetime_immutable — already
            // correctly Europe/Brussels-labeled at hydration, no relabel needed here.
            startAt: $m->getStartAt()?->format(\DateTimeInterface::ATOM),
            endAt: $m->getEndAt()?->format(\DateTimeInterface::ATOM),
            schedulePrecision: (string) $m->getSchedulePrecision()?->value,
            type: (string) $m->getType()?->value,
            status: (string) $m->getStatus()?->value,
            surgeon: $this->toUserSlim($m->getSurgeon()),
            instrumentist: $m->getInstrumentist() ? $this->toUserSlim($m->getInstrumentist()) : null,
            allowedActions: $this->actions->allowedActions($m, $viewer),
            noMaterialComment: $m->getNoMaterialComment(),
            submittedWithoutMaterial: $m->isSubmittedWithoutMaterial(),
            covered: $this->coverage->isCovered($m),
            // D-143 — dernière relance automatique (historique mission_encoding_reminder) ;
            // repli sur l'ancien marqueur D-083 pour une mission relancée avant D-143.
            automaticReminderSentAt: $lastAutomatic['sentAt'] ?? $m->getEncodingReminderSentAt()?->format(\DateTimeInterface::ATOM),
            nextAutomaticReminderAt: $this->reminders->nextAutomaticReminderAt($m),
            lastManualReminderAt: $lastManualReminder !== null ? $lastManualReminder['at'] : null,
            lastManualReminderByName: $lastManualReminder !== null ? $lastManualReminder['byName'] : null,
            lastManualReminderChannel: $lastManualReminder !== null ? $lastManualReminder['channel'] : null,
            automaticReminders: $automaticReminders,
            submittedAt: $m->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
            targetedOffer: $this->targetedOffer($m),
            encodingCorrectionBlockedReason: $isManagerViewer ? MissionEncodingCorrectionPolicy::reopenBlockedReason($m) : null,
            currentAssignment: $isManagerViewer || $m->getSurgeon()?->getId() === $viewer->getId() ? $this->currentAssignment($m) : null,
            myAssignmentStatus: $this->myAssignmentStatus($m, $viewer),
            encodingCorrectionFinancialNotice: $isManagerViewer ? MissionEncodingCorrectionPolicy::financialImpactNotice($m) : null,
        );
    }

    /**
     * D-151 — what an instrumentist whose affectation ENDED may still see of the mission
     * (MissionVoter::VIEW_FORMER_ASSIGNMENT): date, time, site, type, surgeon — nobody else's
     * name, no action, no reason ("Affectation annulée").
     */
    public function toFormerAssigneeDetailDto(Mission $m): MissionDetailDto
    {
        return new MissionDetailDto(
            id: (int) $m->getId(),
            site: $this->toHospitalSlim($m->getSite()),
            startAt: $m->getStartAt()?->format(\DateTimeInterface::ATOM),
            endAt: $m->getEndAt()?->format(\DateTimeInterface::ATOM),
            schedulePrecision: (string) $m->getSchedulePrecision()?->value,
            type: (string) $m->getType()?->value,
            status: (string) $m->getStatus()?->value,
            surgeon: $this->toUserSlim($m->getSurgeon()),
            instrumentist: null,
            allowedActions: ['view'],
            noMaterialComment: null,
            submittedWithoutMaterial: null,
            covered: false,
            automaticReminderSentAt: null,
            nextAutomaticReminderAt: null,
            lastManualReminderAt: null,
            lastManualReminderByName: null,
            lastManualReminderChannel: null,
            automaticReminders: [],
            submittedAt: null,
            myAssignmentStatus: 'ENDED',
        );
    }

    /** @return array<string,mixed>|null */
    private function currentAssignment(Mission $m): ?array
    {
        $current = $this->assignments?->findCurrent($m);
        if ($current === null) {
            return null;
        }

        $instrumentist = $current->getInstrumentist();
        $name          = trim(($instrumentist->getFirstname() ?? '') . ' ' . ($instrumentist->getLastname() ?? ''));
        $blocked       = MissionAssignmentRefusalPolicy::blockedReason($m, $current, Clock::get()->now());

        return [
            'id'                   => $current->getId(),
            'instrumentist'        => ['id' => $instrumentist->getId(), 'name' => $name !== '' ? $name : $instrumentist->getEmail()],
            'source'               => $current->getSource()->value,
            'assignedAt'           => $current->getAssignedAt()->format(\DateTimeInterface::ATOM),
            'refusalDeadlineAt'    => $current->getSource()->isVoluntaryAcceptance()
                ? MissionAssignmentRefusalPolicy::deadline($current, $m)->format(\DateTimeInterface::ATOM)
                : null,
            'refusable'            => $blocked === null,
            'refusalBlockedReason' => $blocked,
            'reviewRequestedAt'    => $current->getReviewRequestedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function myAssignmentStatus(Mission $m, User $viewer): ?string
    {
        if (!in_array('ROLE_INSTRUMENTIST', $viewer->getRoles(), true) || $m->getInstrumentist()?->getId() === $viewer->getId()) {
            return null;
        }

        return $this->assignments?->hasEndedAffectationOf($m, $viewer) ? 'ENDED' : null;
    }

    /**
     * D-125 — see MissionListDto::$targetedOffer. Touches the publications collection only
     * for OPEN unassigned missions (pendingOffer()/lastDeclinedOffer() short-circuit first).
     *
     * @return array<string,mixed>|null
     */
    private function targetedOffer(Mission $m): ?array
    {
        $pending     = MissionDispatchService::pendingOffer($m);
        $publication = $pending ?? MissionDispatchService::lastDeclinedOffer($m);
        if ($publication === null) {
            return null;
        }

        $target = $publication->getTargetInstrumentist();
        $name   = $target !== null ? trim(($target->getFirstname() ?? '') . ' ' . ($target->getLastname() ?? '')) : '';

        return [
            'status'        => $pending !== null ? 'PENDING' : 'DECLINED',
            'instrumentist' => $target !== null ? ['id' => $target->getId(), 'name' => $name !== '' ? $name : $target->getEmail()] : null,
            'offeredAt'     => $publication->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'declinedAt'    => $publication->getDeclinedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function toHospitalSlim(?Hospital $h): HospitalSlimDto
    {
        if (!$h) {
            // ne devrait jamais arriver
            return new HospitalSlimDto(0, 'Unknown', Hospital::DEFAULT_TIMEZONE);
        }

        return new HospitalSlimDto(
            id: (int) $h->getId(),
            name: (string) $h->getName(),
            timezone: $h->getTimezone(), // getter fallback Europe/Brussels
            address: $h->getAddress(),
            photoPath: $h->getPhotoPath(),
        );
    }

    private function toUserSlim(?User $u): UserSlimDto
    {
        if (!$u) {
            // ne devrait jamais arriver pour surgeon
            return new UserSlimDto(0, 'unknown', null, null);
        }

        return new UserSlimDto(
            id: (int) $u->getId(),
            email: (string) $u->getEmail(),
            firstname: $u->getFirstname(),
            lastname: $u->getLastname(),
            active: $u->isActive(),
            employmentType: $u->getEmploymentType()?->value,
            specialties: $u->getSpecialties(),
            profilePicturePath: $u->getProfilePicturePath(),
        );
    }
}