<?php

namespace App\Absence;

use App\Activity\Recorder\AbsenceImpactActivityRecorder;
use App\Entity\Absence;
use App\Entity\User;
use App\Enum\AbsenceImpactLevel;
use App\Message\AbsenceImpactNotificationMessage;
use App\Message\ReleasedRoomSlotsCreatedMessage;
use App\Service\AbsenceImpactReconciliationService;
use App\Service\AbsenceImpactService;
use App\Service\AbsenceImpactSummaryService;
use App\Service\AbsenceMissionReactionService;
use App\Service\BlockManagementCommunicationService;
use App\Service\InstrumentistAbsenceOccurrenceImpactService;
use App\Service\ReleasedOperatingRoomSlotService;
use App\Service\SurgeonAbsenceOccurrenceImpactService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * H4 (D-130) — the ONE sequence that processes an absence (create / update / delete), used
 * by both AbsenceController (manager) and SelfAbsenceController (self-service) — formerly
 * two hand-maintained copies.
 *
 *   1. AbsenceImpactResolver decides, BEFORE anything changes, every impacted occurrence,
 *      its level (N3 > N2 > N1 for one occurrence) and who must be informed;
 *   2. the existing services APPLY the consequences — never deciding levels themselves:
 *        N3  AbsenceMissionReactionService (cancel / release, CAS B; N3 recap + pool via H3)
 *        N2  AbsenceImpactService (PlanningAlert for the manager; deploy neutralises / blocks, D-090)
 *        N1  SurgeonAbsenceOccurrenceImpactService (occurrence exception) /
 *            InstrumentistAbsenceOccurrenceImpactService (audit only);
 *   3. ABSENCE_IMPACT_EVALUATED + USER_PLANNING_IMPACTED in activity_event;
 *   4. N1 / N2 people informed asynchronously by AbsenceImpactNotifier (one message);
 *   5. the consolidated manager summary (D-105) and the surgeon-absence communications
 *      (D-114: block management, released slots — unrelated to the person notifications
 *      above);
 *   6. D-161 — the rooms really created in step 5 are announced to the surgeons who may take
 *      them over (ReleasedRoomSlotsCreatedMessage → ReleasedRoomAvailabilityNotifier), a
 *      distinct asynchronous operation that replaces the D-114 « Libération de salle » email.
 */
class AbsenceImpactCoordinator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AbsenceImpactResolver $resolver,
        private readonly AbsenceMissionReactionService $missionReaction,
        private readonly AbsenceImpactService $alerts,
        private readonly SurgeonAbsenceOccurrenceImpactService $surgeonOccurrences,
        private readonly InstrumentistAbsenceOccurrenceImpactService $instrumentistOccurrences,
        private readonly AbsenceImpactReconciliationService $reconciliation,
        private readonly AbsenceImpactSummaryService $summary,
        private readonly BlockManagementCommunicationService $blockManagement,
        private readonly ReleasedOperatingRoomSlotService $releasedSlots,
        private readonly AbsenceImpactActivityRecorder $activity,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The Absence is already persisted and flushed. */
    public function afterCreated(Absence $absence, User $actor): AbsenceProcessingResult
    {
        return $this->process($absence, $actor, null, null);
    }

    /** The Absence's new range is already flushed; $previousStart/End are the range before the change. */
    public function afterUpdated(Absence $absence, User $actor, \DateTimeImmutable $previousStart, \DateTimeImmutable $previousEnd): AbsenceProcessingResult
    {
        return $this->process($absence, $actor, $previousStart, $previousEnd);
    }

    /**
     * Full deletion, two-phase (D-104): occurrence restoration needs the FK, mission
     * restoration needs the row gone. Deletion never produces a new impact: what it can
     * restore is restored by the existing rules, nothing is resurrected silently.
     */
    public function delete(Absence $absence, User $actor, bool $notifyBlockManagementCancellation): AbsenceProcessingResult
    {
        $this->blockManagement->onAbsenceDeleted($absence, $actor, $notifyBlockManagementCancellation);

        $absenceId = (int) $absence->getId();
        $user      = $absence->getUser();
        $dateStart = $absence->getDateStart()->format('Y-m-d');
        $dateEnd   = $absence->getDateEnd()->format('Y-m-d');

        $this->alerts->onAbsenceDeleted($absence);
        $restoredOccurrences = $this->reconciliation->beginDeletion($absence, $actor);
        $this->missionReaction->onAbsenceDeleted($absence, $actor);

        $this->em->remove($absence);
        $this->em->flush();

        $reconciliation = $this->reconciliation->completeDeletion($absence, $absenceId, $actor, $restoredOccurrences);

        $role = $user !== null ? AbsenceImpactResolver::roleOf($user) : null;
        if ($user !== null && $role !== null) {
            $this->summary->dispatch(
                absenceId: $absenceId, absentUserId: (int) $user->getId(), absentUserName: self::name($user), absentUserRole: $role,
                dateStart: $dateStart, dateEnd: $dateEnd, actor: $actor, action: 'DELETED', reconciliation: $reconciliation,
            );
        }

        return new AbsenceProcessingResult(reconciliation: $reconciliation);
    }

    private function process(Absence $absence, User $actor, ?\DateTimeImmutable $previousStart, ?\DateTimeImmutable $previousEnd): AbsenceProcessingResult
    {
        $user = $absence->getUser();
        $isUpdate = $previousStart !== null && $previousEnd !== null;

        // 1. Decide — before any mutation (the snapshot of who was planned is the truth).
        $impacts  = $this->resolver->resolve($absence);
        $previous = $isUpdate && $user !== null
            ? $this->resolver->resolveRange($user, (int) $absence->getId(), $previousStart, $previousEnd)
            : [];
        $previousKeys = array_flip(array_map(static fn (AbsenceImpact $i) => $i->occurrenceKey(), $previous));
        $currentKeys  = array_flip(array_map(static fn (AbsenceImpact $i) => $i->occurrenceKey(), $impacts));
        $newImpacts   = array_values(array_filter($impacts, static fn (AbsenceImpact $i) => !isset($previousKeys[$i->occurrenceKey()])));
        // No longer impacted after the change: habitual occurrences of an instrumentist absence
        // (D-107 "de nouveau disponible") and DRAFT occurrences (H4 correction) — a correction
        // is only sent if the initial information really reached the person (notifier).
        // Surgeon-absence N1 occurrences and published missions follow D-104's restoration.
        $recovered    = array_values(array_filter($previous, static fn (AbsenceImpact $i) =>
            !isset($currentKeys[$i->occurrenceKey()])
            && ($i->level === AbsenceImpactLevel::DRAFT
                || ($i->level === AbsenceImpactLevel::USUAL_SCHEDULE && $i->absentRole === AbsenceImpact::ROLE_INSTRUMENTIST))));

        // 2. Apply — the existing services, in their documented order (N3 reaction first, so
        //    the alert sync never asks the manager to do what was just done automatically).
        $missionSummaries = $isUpdate
            ? $this->missionReaction->onAbsenceUpdated($absence, $actor)
            : $this->missionReaction->onAbsenceCreated($absence, $actor);
        $alerts       = $isUpdate ? $this->alerts->onAbsenceUpdated($absence) : $this->alerts->onAbsenceCreated($absence);
        $neutralized  = $this->surgeonOccurrences->neutralize($absence, $actor, $impacts);
        $instrumentist = $this->instrumentistOccurrences->record($absence, $actor, $newImpacts, $recovered);
        $reconciliation = $isUpdate
            ? $this->reconciliation->reconcileForUpdate($absence, $previousStart, $previousEnd, $actor)
            : ['restoredOccurrences' => [], 'restoredMissions' => []];

        // 3. Trace.
        try {
            $this->activity->evaluated($absence, $isUpdate ? 'UPDATED' : 'CREATED', $impacts, $recovered, $actor);
        } catch (\Throwable $e) {
            $this->logger->error('AbsenceImpactCoordinator: impact trace failed', ['absenceId' => $absence->getId(), 'error' => $e->getMessage()]);
        }

        // 4. Inform N1 / N2 people (N3 people are informed by the reaction's own recap).
        $toInform = array_values(array_filter($impacts, static fn (AbsenceImpact $i) => $i->level !== AbsenceImpactLevel::PUBLISHED && $i->recipientId() !== null));
        if ($user !== null && ($toInform !== [] || $recovered !== [])) {
            $this->bus->dispatch(AbsenceImpactNotificationMessage::of(
                (int) $absence->getId(), self::name($user),
                $absence->getDateStart()->format('Y-m-d'), $absence->getDateEnd()->format('Y-m-d'),
                $toInform, $recovered,
            ));
        }

        // 5. Manager summary + surgeon-absence communications (D-105, D-114).
        if ($user !== null) {
            $this->summary->dispatch(
                absenceId: (int) $absence->getId(),
                absentUserId: (int) $user->getId(),
                absentUserName: self::name($user),
                absentUserRole: AbsenceImpactResolver::roleOf($user) ?? 'INSTRUMENTIST',
                dateStart: $absence->getDateStart()->format('Y-m-d'),
                dateEnd: $absence->getDateEnd()->format('Y-m-d'),
                actor: $actor,
                action: $isUpdate ? 'UPDATED' : 'CREATED',
                missionReactionSummaries: $missionSummaries,
                occurrenceNeutralized: $neutralized['occurrences'],
                reconciliation: $reconciliation,
                impacts: $newImpacts,
            );
        }
        if ($isUpdate) {
            $this->blockManagement->onAbsenceUpdated($absence, $actor, $previousStart, $previousEnd);
            $createdSlotIds = $this->releasedSlots->onAbsenceUpdated($absence, $actor);
        } else {
            $this->blockManagement->onAbsenceCreated($absence, $actor);
            $createdSlotIds = $this->releasedSlots->onAbsenceCreated($absence, $actor);
        }

        // 6. Announce only the rooms THIS processing really created (an existing room, a
        //    replaced post or a failed creation is never announced, D-161).
        if ($createdSlotIds !== []) {
            $this->bus->dispatch(new ReleasedRoomSlotsCreatedMessage($createdSlotIds, (int) $absence->getId()));
        }

        return new AbsenceProcessingResult(
            impacts: $impacts,
            missionSummaries: $missionSummaries,
            alertsCreated: count($alerts['created']),
            occurrencesNeutralized: $neutralized['created'],
            usualNewlyImpacted: $instrumentist['newlyImpacted'],
            usualNoLongerImpacted: $instrumentist['noLongerImpacted'],
            reconciliation: $reconciliation,
        );
    }

    private static function name(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));

        return $name !== '' ? $name : (string) $user->getEmail();
    }
}
