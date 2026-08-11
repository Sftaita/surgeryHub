<?php

namespace App\Service;

use App\Entity\User;
use App\Message\AbsenceImpactSummaryMessage;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-105 (Lot 5) — the single seam that consolidates every collaborator's impact result into
 * ONE manager notification per absence-processing run (create, update, or delete).
 *
 * AbsenceController/SelfAbsenceController already call AbsenceMissionReactionService,
 * SurgeonAbsenceOccurrenceImpactService, and (on update/delete)
 * AbsenceImpactReconciliationService in sequence for a single request — this service is the
 * only place that sees all of their results together, so it is the natural seam for a single
 * combined dispatch, without inventing a new aggregation/outbox mechanism (see docs/decisions.md
 * D-105 for the alternatives considered).
 *
 * Never dispatches when every impact bucket is empty (§4 of the spec: no email without real
 * impact) or when there is no active manager/admin to receive it.
 */
class AbsenceImpactSummaryService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $missionReactionSummaries AbsenceMissionReactionService::onAbsenceCreated()/onAbsenceUpdated()'s return — each entry has a 'changeType' of RELEASED|CANCELLED
     * @param array<int, array<string, mixed>> $occurrenceNeutralized SurgeonAbsenceOccurrenceImpactService::onSurgeonAbsenceCreated()/onSurgeonAbsenceUpdated()'s 'occurrences' key
     * @param array{restoredOccurrences: array<int, array<string, mixed>>, restoredMissions: array<int, array<string, mixed>>} $reconciliation AbsenceImpactReconciliationService::completeDeletion()/reconcileForUpdate()'s return
     */
    public function dispatch(
        int $absenceId,
        int $absentUserId,
        string $absentUserName,
        string $absentUserRole,
        string $dateStart,
        string $dateEnd,
        User $actor,
        string $action,
        array $missionReactionSummaries = [],
        array $occurrenceNeutralized = [],
        array $reconciliation = [],
    ): void {
        $missionsCancelled = array_values(array_filter(
            $missionReactionSummaries,
            static fn (array $m) => ($m['changeType'] ?? null) === 'CANCELLED',
        ));
        $missionsReleased = array_values(array_filter(
            $missionReactionSummaries,
            static fn (array $m) => ($m['changeType'] ?? null) === 'RELEASED',
        ));

        $restoredMissions = $reconciliation['restoredMissions'] ?? [];
        $missionsRestoredAssigned = array_values(array_filter(
            $restoredMissions,
            static fn (array $m) => ($m['restoredStatus'] ?? null) === 'ASSIGNED',
        ));
        $missionsRestoredOpen = array_values(array_filter(
            $restoredMissions,
            static fn (array $m) => ($m['restoredStatus'] ?? null) === 'OPEN',
        ));

        $futureOccurrencesRestored = $reconciliation['restoredOccurrences'] ?? [];

        if (empty($missionsCancelled) && empty($missionsReleased) && empty($missionsRestoredAssigned)
            && empty($missionsRestoredOpen) && empty($occurrenceNeutralized) && empty($futureOccurrencesRestored)
        ) {
            return;
        }

        $managerIds = array_map(
            static fn (User $m) => $m->getId(),
            $this->userRepository->findManagersAndAdmins(true),
        );
        if (empty($managerIds)) {
            return;
        }

        $this->bus->dispatch(new AbsenceImpactSummaryMessage(
            absenceId: $absenceId,
            absentUserId: $absentUserId,
            absentUserName: $absentUserName,
            absentUserRole: $absentUserRole,
            action: $action,
            dateStart: $dateStart,
            dateEnd: $dateEnd,
            actorId: $actor->getId(),
            missionsCancelled: $missionsCancelled,
            missionsReleased: $missionsReleased,
            missionsRestoredAssigned: $missionsRestoredAssigned,
            missionsRestoredOpen: $missionsRestoredOpen,
            futureOccurrencesCancelled: $occurrenceNeutralized,
            futureOccurrencesRestored: $futureOccurrencesRestored,
            recipientManagerIds: $managerIds,
            occurredAt: new \DateTimeImmutable(),
        ));
    }
}
