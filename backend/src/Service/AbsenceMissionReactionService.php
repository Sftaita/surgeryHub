<?php

namespace App\Service;

use App\Entity\Absence;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Enum\PublicationChannel;
use App\Message\AbsenceMissionsReactedMessage;
use App\Message\MissionLifecycleChangedMessage;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reacts to an absence (surgeon or instrumentist) by auto-mutating already-generated,
 * operational Mission occurrences that overlap the absence period — never the recurring
 * SurgeonSchedulePost definition (that describes future organization; an absence only
 * concerns the concrete occurrences already materialized for this period).
 *
 * Deliberately a separate collaborator from AbsenceImpactService, which keeps its own
 * documented "never mutates a Mission" contract completely unchanged — AbsenceController
 * calls this service IN ADDITION, for exactly the subset of missions safe to auto-correct
 * without a manager decision.
 *
 * Scope (see docs/decisions.md for the full status-by-status rationale):
 *   - Instrumentist absence: ASSIGNED missions where this user is the instrumentist
 *     → MissionPostDeployService::release() (ASSIGNED → OPEN, instrumentist cleared).
 *   - Surgeon absence: OPEN|ASSIGNED missions where this user is the surgeon
 *     → MissionPostDeployService::cancel() (→ CANCELLED, instrumentist cleared if any).
 *   - DRAFT (not yet part of a deployed/published plan), SUBMITTED, VALIDATED, IN_PROGRESS,
 *     DECLARED missions are deliberately NEVER touched here — a mission already declared,
 *     validated, in progress, or not yet deployed represents a business record (or a plan
 *     not yet live) that must not be silently mutated. AbsenceImpactService already raises
 *     a PlanningAlert for the alertable subset of these (DRAFT/SUBMITTED/VALIDATED/
 *     IN_PROGRESS) so a manager can decide by hand — nothing new needed there. CLOSED,
 *     REJECTED, CANCELLED are terminal and excluded by definition (not in either actionable
 *     list below).
 *
 * Ordering requirement: AbsenceController MUST call this service BEFORE
 * AbsenceImpactService::onAbsenceCreated()/onAbsenceUpdated(). This is not just a style
 * choice — AbsenceImpactService's own overlap query matches
 * "(m.surgeon = :user OR m.instrumentist = :user) AND m.status IN (alertable statuses)".
 * Once this service releases/cancels a mission, that mission naturally falls OUT of that
 * query (instrumentist is now null, or status is now CANCELLED — never one of the alertable
 * statuses) — so AbsenceImpactService, completely unmodified, never raises a
 * REASSIGNMENT_REQUIRED/SURGEON_ABSENCE alert for a mission this service already handled.
 * No stale-alert logic had to be written anywhere; the two services simply compose
 * correctly given the right call order.
 *
 * Idempotency: every mutation is gated by MissionPostDeployService's own status guard
 * (release() requires ASSIGNED, cancel() requires OPEN|ASSIGNED) AND by this service's own
 * overlap query only ever matching missions still in a mutable state for this user/absence.
 * Once mutated, a mission's FK/status no longer matches the query, so re-running
 * onAbsenceCreated()/onAbsenceUpdated() — for the same absence, an updated one, or a second
 * absence for the same person — naturally finds nothing left to redo for it. No explicit
 * "already processed" tracking table is needed. This is also why period reduction/shift on
 * update never needs to "undo" anything: a mission that no longer overlaps the new range
 * simply isn't in the query result, and this service has no reversal code path at all.
 *
 * Concurrency: handled the same way MissionPostDeployService::claim() already does (the one
 * existing high-contention case in this codebase) — a pessimistic write lock acquired inside
 * a transaction, the status guard re-checked under that lock, and MissionLifecycleChangedMessage
 * dispatched only AFTER the transaction commits (never from inside it — dispatching while the
 * transaction is still open would let an async worker observe the message before the mutation
 * is durably visible to other connections).
 *
 * onAbsenceDeleted() is deliberately a no-op here (never restores a released/cancelled
 * mission) — see the class-level note on AbsenceController for where the manager
 * "reassess manually" notice on delete lives.
 */
class AbsenceMissionReactionService
{
    /** Instrumentist absence is only ever actionable against a mission that is ASSIGNED. */
    private const INSTRUMENTIST_ACTIONABLE_STATUSES = [MissionStatus::ASSIGNED];

    /** Surgeon absence is actionable against a mission that is OPEN or already ASSIGNED. */
    private const SURGEON_ACTIONABLE_STATUSES = [MissionStatus::OPEN, MissionStatus::ASSIGNED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionPostDeployService $missionPostDeployService,
        private readonly MessageBusInterface $bus,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function onAbsenceCreated(Absence $absence, User $actor): void
    {
        $this->react($absence, $actor);
    }

    public function onAbsenceUpdated(Absence $absence, User $actor): void
    {
        $this->react($absence, $actor);
    }

    /**
     * Never restores a released/cancelled mission (§5 "Suppression d'une absence" — a
     * released mission may already have been claimed by someone else; a cancelled mission
     * has already generated its own notifications; reconstructing the previous state would
     * silently overwrite whatever happened in between). Instead, always leaves a lightweight
     * in-app notice for managers/admins that missions possibly affected by this absence must
     * be reassessed by hand — deliberately generic (no attempt to determine exactly which
     * missions this specific absence had mutated; there is no durable link from Absence to
     * the missions it once triggered a mutation for, and reconstructing one would risk being
     * wrong in either direction). A no-op for absences on any role other than
     * surgeon/instrumentist, consistent with react()'s own scope.
     */
    public function onAbsenceDeleted(Absence $absence, User $actor): void
    {
        $user = $absence->getUser();
        if ($user === null || self::roleOf($user) === null) {
            return;
        }

        $managers = $this->userRepository->findManagersAndAdmins(true);
        if (empty($managers)) {
            return;
        }

        $payload = [
            'absenceId'        => $absence->getId(),
            'absentUserId'     => $user->getId(),
            'absentUserName'   => self::displayName($user),
            'absenceDateStart' => $absence->getDateStart()->format('Y-m-d'),
            'absenceDateEnd'   => $absence->getDateEnd()->format('Y-m-d'),
            'deletedById'      => $actor->getId(),
            'message'          => sprintf(
                "L'absence de %s (%s → %s) a été supprimée. Les missions éventuellement "
                . 'libérées ou annulées suite à cette absence ne sont jamais restaurées '
                . 'automatiquement — à réévaluer manuellement si nécessaire.',
                self::displayName($user),
                $absence->getDateStart()->format('d/m/Y'),
                $absence->getDateEnd()->format('d/m/Y'),
            ),
        ];

        foreach ($managers as $manager) {
            $evt = (new NotificationEvent())
                ->setUser($manager)
                ->setEventType(NotificationType::PLANNING_ALERT->value)
                ->setChannel(PublicationChannel::IN_APP)
                ->setSentAt(new \DateTimeImmutable())
                ->setPayload($payload);
            $this->em->persist($evt);
        }

        $this->em->flush();
    }

    private function react(Absence $absence, User $actor): void
    {
        $user = $absence->getUser();
        if ($user === null) {
            return;
        }

        $role = self::roleOf($user);
        if ($role === null) {
            return; // absences only ever concern surgeons/instrumentists in practice
        }

        $missions = $role === 'INSTRUMENTIST'
            ? $this->findOverlapping($user, $absence, true, self::INSTRUMENTIST_ACTIONABLE_STATUSES)
            : $this->findOverlapping($user, $absence, false, self::SURGEON_ACTIONABLE_STATUSES);

        if (empty($missions)) {
            return;
        }

        $summaries = [];
        foreach ($missions as $mission) {
            $summary = $role === 'INSTRUMENTIST'
                ? $this->processInstrumentistAbsence($mission, $actor, $absence)
                : $this->processSurgeonAbsence($mission, $actor, $absence);

            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        if (empty($summaries)) {
            // Every candidate mission had already moved out of the actionable status
            // between the query above and processing (concurrent claim/reassign/cancel) —
            // nothing left to report.
            return;
        }

        $this->bus->dispatch(new AbsenceMissionsReactedMessage(
            absenceId: $absence->getId(),
            absentUserId: $user->getId(),
            absentUserRole: $role,
            actorId: $actor->getId(),
            missions: $summaries,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /** @return array<string, mixed>|null null if the mission was no longer ASSIGNED under lock */
    private function processInstrumentistAbsence(Mission $mission, User $actor, Absence $absence): ?array
    {
        $reason = sprintf(
            'Absence instrumentiste enregistrée (%s → %s)',
            $absence->getDateStart()->format('d/m/Y'),
            $absence->getDateEnd()->format('d/m/Y'),
        );

        $captured = null;
        $summary  = null;

        $this->em->wrapInTransaction(function () use ($mission, $actor, $reason, &$captured, &$summary): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);

            if ($mission->getStatus() !== MissionStatus::ASSIGNED) {
                return; // handled concurrently between the overlap query and now
            }

            $instrumentist = $mission->getInstrumentist();
            $captured = [
                'fromInstrumentistId'   => $instrumentist?->getId(),
                'fromInstrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
            ];
            $summary = $this->buildMissionSummary($mission, 'RELEASED');

            $this->missionPostDeployService->release($mission, $actor, notify: false, reason: $reason);
        });

        if ($summary === null) {
            return null;
        }

        // Dispatched here, AFTER the transaction above committed — mirrors what
        // MissionPostDeployService::release() would itself have dispatched with notify=true,
        // just correctly timed. Preserves the existing free notification pipeline
        // (SURGEON_POST_UNCOVERED in-app+push to the surgeon, OPEN_MISSION_AVAILABLE fan-out
        // to eligible instrumentists) — this service never re-implements that, only adds the
        // absence-specific recap email on top via AbsenceMissionsReactedMessage.
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId: $mission->getId(),
            changeType: MissionChangeType::RELEASED,
            actorId: $actor->getId(),
            payload: [
                'fromInstrumentistId'   => $captured['fromInstrumentistId'],
                'fromInstrumentistName' => $captured['fromInstrumentistName'],
                'reason'                => $reason,
                'actorId'               => $actor->getId(),
                'actorName'             => self::displayName($actor),
            ],
            occurredAt: new \DateTimeImmutable(),
        ));

        return $summary;
    }

    /** @return array<string, mixed>|null null if the mission was no longer OPEN|ASSIGNED under lock */
    private function processSurgeonAbsence(Mission $mission, User $actor, Absence $absence): ?array
    {
        $reason = sprintf(
            'Absence chirurgien enregistrée (%s → %s)',
            $absence->getDateStart()->format('d/m/Y'),
            $absence->getDateEnd()->format('d/m/Y'),
        );

        $captured = null;
        $summary  = null;

        $this->em->wrapInTransaction(function () use ($mission, $actor, $reason, &$captured, &$summary): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);

            if (!in_array($mission->getStatus(), self::SURGEON_ACTIONABLE_STATUSES, true)) {
                return; // handled concurrently between the overlap query and now
            }

            $instrumentist = $mission->getInstrumentist();
            $captured = [
                'fromInstrumentistId'   => $instrumentist?->getId(),
                'fromInstrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
            ];
            $summary = $this->buildMissionSummary($mission, 'CANCELLED');

            $this->missionPostDeployService->cancel($mission, $actor, reason: $reason, notify: false);
        });

        if ($summary === null) {
            return null;
        }

        // See processInstrumentistAbsence() — same reasoning, dispatched after commit.
        // Preserves PLANNING_MISSION_CANCELLED in-app+push to the surgeon (existing free
        // pipeline); the instrumentist-facing "defensive" branch in
        // MissionLifecycleChangedMessageHandler stays a no-op since cancel() already cleared
        // the instrumentist — AbsenceMissionsReactedMessage covers that recipient instead.
        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId: $mission->getId(),
            changeType: MissionChangeType::CANCELLED,
            actorId: $actor->getId(),
            payload: [
                'reason'                => $reason,
                'fromInstrumentistId'   => $captured['fromInstrumentistId'],
                'fromInstrumentistName' => $captured['fromInstrumentistName'],
                'actorId'               => $actor->getId(),
                'actorName'             => self::displayName($actor),
            ],
            occurredAt: new \DateTimeImmutable(),
        ));

        return $summary;
    }

    /**
     * Snapshot for the batched absence recap email — captured BEFORE the mutation (still
     * has the real instrumentist/surgeon on the mission), never re-derived from a FK at
     * handler read-time later (R-12-style discipline, consistent with the rest of the app).
     */
    private function buildMissionSummary(Mission $mission, string $changeType): array
    {
        $surgeon       = $mission->getSurgeon();
        $instrumentist = $mission->getInstrumentist();
        $startAt       = $mission->getStartAt();
        $endAt         = $mission->getEndAt();

        return [
            'missionId'         => $mission->getId(),
            'changeType'        => $changeType,
            'date'              => $startAt?->format('d/m/Y') ?? '',
            'moment'            => $startAt !== null ? (((int) $startAt->format('G')) < 12 ? 'Matin' : 'Après-midi') : null,
            'horaire'           => $startAt !== null && $endAt !== null
                ? $startAt->format('H:i') . '–' . $endAt->format('H:i')
                : null,
            'siteName'          => $mission->getSite()?->getName(),
            'surgeonId'         => $surgeon?->getId(),
            'surgeonName'       => $surgeon !== null ? self::displayName($surgeon) : null,
            'instrumentistId'   => $instrumentist?->getId(),
            'instrumentistName' => $instrumentist !== null ? self::displayName($instrumentist) : null,
        ];
    }

    /** @return Mission[] */
    private function findOverlapping(User $user, Absence $absence, bool $byInstrumentist, array $statuses): array
    {
        $absenceStart = $absence->getDateStart()->setTime(0, 0, 0);
        $absenceEnd   = $absence->getDateEnd()->setTime(23, 59, 59);
        $field        = $byInstrumentist ? 'm.instrumentist' : 'm.surgeon';

        return $this->em->createQuery(
            "SELECT m FROM App\Entity\Mission m
             WHERE {$field} = :user
               AND m.startAt <= :absenceEnd
               AND m.endAt >= :absenceStart
               AND m.status IN (:statuses)"
        )
            ->setParameter('user', $user)
            ->setParameter('absenceStart', $absenceStart, Types::DATETIME_IMMUTABLE)
            ->setParameter('absenceEnd', $absenceEnd, Types::DATETIME_IMMUTABLE)
            ->setParameter('statuses', $statuses)
            ->getResult();
    }

    private static function roleOf(User $user): ?string
    {
        $roles = $user->getRoles();
        if (in_array('ROLE_INSTRUMENTIST', $roles, true)) {
            return 'INSTRUMENTIST';
        }
        if (in_array('ROLE_SURGEON', $roles, true)) {
            return 'SURGEON';
        }
        return null;
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
