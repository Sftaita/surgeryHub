<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningAlertType;
use App\Message\PlanningAlertRaisedMessage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Detects the impact of an absence (surgeon or instrumentist) on already-generated
 * Missions, and raises/resolves PlanningAlert rows accordingly.
 *
 * Hard rule: this service NEVER mutates a Mission. Regardless of status (DRAFT, OPEN,
 * ASSIGNED, SUBMITTED, VALIDATED, IN_PROGRESS), it only ever creates or resolves
 * PlanningAlert rows and prepares notification payloads. Manager-driven resolution
 * actions (reassign/cancel/open) are a future batch, once the UI/endpoints exist to
 * make that decision deliberately rather than automatically.
 *
 * Missions in a terminal-or-out-of-band state (CLOSED, REJECTED, DECLARED) are excluded
 * from impact detection — there is no actionable manager decision left to make about them.
 */
class AbsenceImpactService
{
    private const ALERTABLE_STATUSES = [
        MissionStatus::DRAFT,
        MissionStatus::OPEN,
        MissionStatus::ASSIGNED,
        MissionStatus::SUBMITTED,
        MissionStatus::VALIDATED,
        MissionStatus::IN_PROGRESS,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningAlertService $alertService,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * Re-synchronizes alerts for the absence's CURRENT date range against currently
     * existing missions. Used for both "absence created" and "absence modified" — in
     * both cases the correct behavior is "what should be true right now, given this
     * absence's current range", which naturally resolves alerts that no longer apply
     * and creates only the ones that don't already exist (idempotent either way).
     *
     * @return array{created: \App\Entity\PlanningAlert[], resolved: \App\Entity\PlanningAlert[], notifications: PlanningAlertRaisedMessage[]}
     */
    public function onAbsenceCreated(Absence $absence): array
    {
        return $this->sync($absence);
    }

    /** @return array{created: \App\Entity\PlanningAlert[], resolved: \App\Entity\PlanningAlert[], notifications: PlanningAlertRaisedMessage[]} */
    public function onAbsenceUpdated(Absence $absence): array
    {
        return $this->sync($absence);
    }

    /**
     * Call BEFORE the Absence row is removed from the database — resolves every alert
     * still tied to it. Alert rows are never deleted; PlanningAlert.absence is
     * ON DELETE SET NULL so history survives even after the absence itself is gone.
     *
     * @return \App\Entity\PlanningAlert[] the alerts that were resolved
     */
    public function onAbsenceDeleted(Absence $absence): array
    {
        $resolved = $this->alertService->resolveAllForAbsence($absence, 'Absence supprimée.');
        $this->em->flush();
        return $resolved;
    }

    /** @return array{created: \App\Entity\PlanningAlert[], resolved: \App\Entity\PlanningAlert[], notifications: PlanningAlertRaisedMessage[]} */
    private function sync(Absence $absence): array
    {
        $user             = $absence->getUser();
        $overlapping      = $this->findOverlappingMissions($user, $absence->getDateStart(), $absence->getDateEnd());
        $overlappingIds   = array_map(static fn (Mission $m) => $m->getId(), $overlapping);

        $existingAlerts = $this->alertService->findActiveAlertsForAbsence($absence);
        $resolved       = [];
        foreach ($existingAlerts as $alert) {
            if (!in_array($alert->getMission()->getId(), $overlappingIds, true)) {
                $alert->resolve(null, 'Absence modifiée : ne chevauche plus cette mission.');
                $resolved[] = $alert;
            }
        }

        $created       = [];
        $createdContext = [];
        foreach ($overlapping as $mission) {
            $type = $this->classify($mission, $user);

            $result = $this->alertService->createIfNotDuplicate($mission, $type, $absence, $this->snapshot($mission, $absence, $type));
            if ($result['created']) {
                $created[]        = $result['alert'];
                $createdContext[] = $mission;
            }
        }

        // Flush first so newly-created alerts get a real ID before building notification payloads.
        $this->em->flush();

        // Dispatch exactly one message per newly-created alert — never for idempotent
        // no-ops (the $created array above already excludes those) and never twice for
        // the same alert (sync() re-running finds it via createIfNotDuplicate and skips it).
        $notifications = [];
        foreach ($created as $i => $alert) {
            $notification = $this->buildNotification($alert, $createdContext[$i], $absence, $user);
            $notifications[] = $notification;
            $this->bus->dispatch($notification);
        }

        return ['created' => $created, 'resolved' => $resolved, 'notifications' => $notifications];
    }

    /**
     * SURGEON_ABSENCE   — the absent person is the mission's surgeon.
     * REASSIGNMENT_REQUIRED — the absent person is the instrumentist AND the mission was
     *                         already ASSIGNED (someone was depending on coverage; a
     *                         concrete reassignment decision is now required).
     * INSTRUMENTIST_ABSENCE — the absent person is the instrumentist on a mission that
     *                         was not yet ASSIGNED (DRAFT/OPEN) or already past assignment
     *                         in the workflow (SUBMITTED/VALIDATED/IN_PROGRESS) — informational,
     *                         since "reassignment" isn't the right framing for those states.
     */
    private function classify(Mission $mission, User $absentUser): PlanningAlertType
    {
        if ($mission->getSurgeon()?->getId() === $absentUser->getId()) {
            return PlanningAlertType::SURGEON_ABSENCE;
        }

        return $mission->getStatus() === MissionStatus::ASSIGNED
            ? PlanningAlertType::REASSIGNMENT_REQUIRED
            : PlanningAlertType::INSTRUMENTIST_ABSENCE;
    }

    /** @return Mission[] */
    private function findOverlappingMissions(User $user, \DateTimeImmutable $dateStart, \DateTimeImmutable $dateEnd): array
    {
        $absenceStart = $dateStart->setTime(0, 0, 0);
        $absenceEnd   = $dateEnd->setTime(23, 59, 59);

        return $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE (m.surgeon = :absentUser OR m.instrumentist = :absentUser)
               AND m.startAt <= :absenceEnd
               AND m.endAt >= :absenceStart
               AND m.status IN (:alertableStatuses)'
        )
            ->setParameter('absentUser', $user)
            ->setParameter('absenceStart', $absenceStart, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('absenceEnd', $absenceEnd, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('alertableStatuses', self::ALERTABLE_STATUSES)
            ->getResult();
    }

    private function snapshot(Mission $mission, Absence $absence, PlanningAlertType $type): array
    {
        return [
            'type'             => $type->value,
            'missionId'        => $mission->getId(),
            'missionStatus'    => $mission->getStatus()->value,
            'missionStartAt'   => $mission->getStartAt()->format(\DateTimeInterface::ATOM),
            'missionEndAt'     => $mission->getEndAt()->format(\DateTimeInterface::ATOM),
            'surgeonId'        => $mission->getSurgeon()?->getId(),
            'instrumentistId'  => $mission->getInstrumentist()?->getId(),
            'absenceId'        => $absence->getId(),
            'absenceUserId'    => $absence->getUser()?->getId(),
            'absenceDateStart' => $absence->getDateStart()->format('Y-m-d'),
            'absenceDateEnd'   => $absence->getDateEnd()->format('Y-m-d'),
            'absenceReason'    => $absence->getReason(),
        ];
    }

    /**
     * Recipients per Batch 7 rules:
     *   - every active manager/admin, for every alert type;
     *   - SURGEON_ABSENCE: the mission's assigned instrumentist (if any) — their work
     *     risks being cancelled/changed even though they aren't the absent person;
     *   - REASSIGNMENT_REQUIRED / INSTRUMENTIST_ABSENCE: the absent instrumentist
     *     themself, so they know their absence is already impacting a real mission;
     *   - surgeons are intentionally never notified yet — no surgeon-facing
     *     notification UX exists in the product today, so it would be silent noise.
     */
    private function buildNotification(\App\Entity\PlanningAlert $alert, Mission $mission, Absence $absence, User $absentUser): PlanningAlertRaisedMessage
    {
        $recipients = [];
        foreach ($this->userRepository->findManagersAndAdmins(true) as $manager) {
            $recipients[$manager->getId()] = true;
        }

        if ($alert->getType() === PlanningAlertType::SURGEON_ABSENCE) {
            if ($mission->getInstrumentist() !== null) {
                $recipients[$mission->getInstrumentist()->getId()] = true;
            }
        } elseif (in_array($alert->getType(), [PlanningAlertType::REASSIGNMENT_REQUIRED, PlanningAlertType::INSTRUMENTIST_ABSENCE], true)) {
            $recipients[$absentUser->getId()] = true;
        }

        $site = $mission->getSite();

        return new PlanningAlertRaisedMessage(
            alertId: $alert->getId() ?? 0,
            alertType: $alert->getType()->value,
            missionId: $mission->getId(),
            siteId: $site?->getId(),
            siteName: $site?->getName(),
            missionDate: $mission->getStartAt()->format('Y-m-d'),
            absenceId: $absence->getId(),
            surgeonId: $mission->getSurgeon()->getId(),
            instrumentistId: $mission->getInstrumentist()?->getId(),
            recipientUserIds: array_keys($recipients),
            detectedAt: $alert->getDetectedAt()->format(\DateTimeInterface::ATOM),
        );
    }
}
