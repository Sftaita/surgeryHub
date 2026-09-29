<?php

namespace App\Service;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\ReleasedRoomSlotStatus;
use App\Enum\ShiftPeriod;
use App\Exception\ReleasedRoomSlotConflictException;
use App\Message\MissionLifecycleChangedMessage;
use App\Security\Voter\ReleasedRoomSlotVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * D-124 — « Reprendre une salle libérée ». Seul point d'écriture du cycle
 * AVAILABLE ⇄ CLAIMED d'un `ReleasedOperatingRoomSlot`.
 *
 * **Unicité de la reprise (garantie DB).** La ligne `released_operating_room_slot` est
 * l'identité unique du créneau (contrainte `(site_id, post_id, occurrence_date)`, Lot D). La
 * reprise prend un verrou `PESSIMISTIC_WRITE` (SELECT … FOR UPDATE) sur CETTE ligne, relit
 * son état (`refresh()` — un `lock()` seul ne recharge pas une entité déjà en mémoire, qui
 * pourrait être périmée si elle a été lue avant que le gagnant ne commit), revérifie
 * AVAILABLE, puis crée la Mission et passe le créneau à CLAIMED dans la MÊME transaction. Le
 * second chirurgien reste bloqué sur le verrou jusqu'au commit du premier, relit CLAIMED et
 * reçoit `ROOM_SLOT_ALREADY_TAKEN` — aucune Mission n'est jamais créée pour lui. Même
 * technique que `MissionPostDeployService::claim()` / `SurgeonMissionRequestService::accept()`.
 *
 * **Missions.** Aucune mutation de Mission ici : création via
 * `MissionPostDeployService::createPostDeploy()` (OPEN, sans instrumentiste, audit
 * MISSION_ADDED_POST_DEPLOY), annulation via `MissionPostDeployService::cancel()`. La Mission
 * d'origine du chirurgien absent (déjà CANCELLED par AbsenceMissionReactionService) et le
 * `SurgeonSchedulePost` ne sont jamais modifiés (invariant Planning V2).
 *
 * **Transactions.** Connexion gérée à la main plutôt que `wrapInTransaction()` : celui-ci
 * ferme l'EntityManager sur toute exception, alors qu'un refus 409 est un résultat métier
 * ordinaire (même raisonnement que AbsenceMissionReactionService::tryAssignCandidate()).
 * Tous les refus sont levés AVANT la première écriture, donc un rollback de connexion suffit.
 *
 * **Notifications.** Toujours dispatchées APRÈS commit (R-07), via le pipeline existant
 * `MissionLifecycleChangedMessage` → `MissionLifecycleChangedMessageHandler` → préférences.
 * Jamais un second système.
 */
class ReleasedRoomSlotTakeoverService
{
    /** Mission de reprise annulable par un désistement (ensuite : réalité opérationnelle, intouchable). */
    private const CANCELLABLE_STATUSES = [MissionStatus::OPEN, MissionStatus::ASSIGNED];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MissionPostDeployService $postDeploy,
        private readonly MissionEligibilityService $eligibility,
        private readonly PlanningConflictDetectionService $conflicts,
        private readonly AuditService $audit,
        private readonly MessageBusInterface $bus,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    /**
     * Actions exposed to the current viewer (`allowedActions` of the API payload) — the only
     * thing the frontend reads to show a CTA. Combines the Voter (who) with the slot's current
     * state (whether it is takeable right now); never re-derived client-side.
     *
     * @return array{takeOver: bool, release: bool}
     */
    public function allowedActions(ReleasedOperatingRoomSlot $slot): array
    {
        if ($this->authorization === null) {
            return ['takeOver' => false, 'release' => false];
        }

        return [
            'takeOver' => $slot->getStatus() === ReleasedRoomSlotStatus::AVAILABLE
                && $this->authorization->isGranted(ReleasedRoomSlotVoter::TAKE_OVER, $slot),
            'release' => $this->authorization->isGranted(ReleasedRoomSlotVoter::RELEASE, $slot),
        ];
    }

    /**
     * AVAILABLE → CLAIMED + nouvelle Mission OPEN pour $surgeon, atomiquement.
     * Le Voter (TAKE_OVER) a déjà validé rôle/affiliation/date ; tout ce qui dépend d'une
     * lecture fraîche est revérifié ici, sous verrou.
     */
    public function takeOver(ReleasedOperatingRoomSlot $slot, User $surgeon): ReleasedOperatingRoomSlot
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $this->em->lock($slot, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($slot);

            if ($slot->getStatus() !== ReleasedRoomSlotStatus::AVAILABLE) {
                $takenBy = $slot->getClaimedBy();
                throw new ReleasedRoomSlotConflictException(
                    ReleasedRoomSlotConflictException::ALREADY_TAKEN,
                    $takenBy !== null
                        ? sprintf('Cette salle vient d\'être reprise par %s.', $takenBy->getDrName())
                        : 'Cette salle vient d\'être reprise.',
                    ['takenBy' => $takenBy !== null ? ['id' => $takenBy->getId(), 'name' => $takenBy->getDrName()] : null],
                );
            }

            $site = $slot->getSite();
            if ($site === null || $slot->getOccurrenceDate() < new \DateTimeImmutable('today')) {
                throw new ReleasedRoomSlotConflictException(ReleasedRoomSlotConflictException::NOT_AVAILABLE, 'Cette salle n\'est plus disponible.');
            }

            // Lot D ne retire jamais un créneau déjà publié (non-rétractation) : si l'absence du
            // chirurgien libérant a été supprimée/réduite depuis, sa Mission a pu être restaurée
            // (D-104) — la salle n'est alors plus réellement libre, jamais une double occupation.
            $sourceSurgeon = $slot->getSurgeon();
            if ($sourceSurgeon !== null && $this->eligibility->findBlockingAbsence($sourceSurgeon, $slot->getOccurrenceDate()) === null) {
                throw new ReleasedRoomSlotConflictException(
                    ReleasedRoomSlotConflictException::NOT_AVAILABLE,
                    sprintf('%s n\'est plus absent ce jour-là : la salle n\'est plus disponible.', $sourceSurgeon->getDrName()),
                );
            }

            $originalMission = $slot->getOriginalMission() ?? $this->findOriginalMission($slot);
            $schedule = $this->resolveSchedule($slot, $originalMission);
            if ($schedule === null) {
                throw new ReleasedRoomSlotConflictException(
                    ReleasedRoomSlotConflictException::SCHEDULE_UNKNOWN,
                    'Aucun horaire n\'est configuré pour ce créneau : contactez le gestionnaire du planning.',
                );
            }
            [$startAt, $endAt] = $schedule;

            if ($this->eligibility->findBlockingAbsence($surgeon, $startAt) !== null) {
                throw new ReleasedRoomSlotConflictException(ReleasedRoomSlotConflictException::SURGEON_ABSENT, 'Vous êtes absent ce jour-là.');
            }

            // D-119 : deux salles du MÊME site en parallèle restent une « double salle »
            // légitime, jamais un conflit — même moteur que le reste du planning.
            $conflict = $this->conflicts->findConflict($surgeon, $startAt, $endAt, null, $site);
            if ($conflict !== null) {
                throw new ReleasedRoomSlotConflictException(
                    ReleasedRoomSlotConflictException::SURGEON_CONFLICT,
                    sprintf('Vous avez déjà une mission à %s sur ce créneau.', $conflict->getSite()?->getName() ?? 'un autre site'),
                    ['conflictMissionId' => $conflict->getId()],
                );
            }

            $initialInstrumentist = $this->resolveInitialInstrumentist($slot, $originalMission);
            $type = $slot->getSchedulePost()?->getType() ?? $originalMission?->getType() ?? MissionType::BLOCK;

            $mission = $this->postDeploy->createPostDeploy(
                planningVersion: $originalMission?->getPlanningVersion(),
                actor: $surgeon,
                site: $site,
                surgeon: $surgeon,
                instrumentist: null,
                type: $type,
                startAt: $startAt,
                endAt: $endAt,
                notify: false, // dispatched below as ROOM_TAKEN_OVER, after commit
            );

            $now = new \DateTimeImmutable();
            $slot->setOriginalMission($originalMission);
            $slot->markClaimed($surgeon, $mission, $now);

            $payload = array_merge($this->slotSnapshot($slot), [
                'takenById'                => $surgeon->getId(),
                'takenByName'              => $surgeon->getDrName(),
                'takenAt'                  => $now->format(\DateTimeInterface::ATOM),
                'originalMissionId'        => $originalMission?->getId(),
                'initialInstrumentistId'   => $initialInstrumentist?->getId(),
                'initialInstrumentistName' => $initialInstrumentist !== null ? self::displayName($initialInstrumentist) : null,
                'actorId'                  => $surgeon->getId(),
                'actorName'                => $surgeon->getDrName(),
            ]);
            $this->audit->record($mission, $surgeon, AuditEventType::ROOM_SLOT_TAKEN_OVER, $payload);

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $this->bus->dispatch(new MissionLifecycleChangedMessage(
            missionId: $mission->getId(),
            changeType: MissionChangeType::ROOM_TAKEN_OVER,
            actorId: $surgeon->getId(),
            payload: [
                'roomSlotId'               => $slot->getId(),
                'originalSurgeonName'      => $payload['originalSurgeonName'],
                'takenByName'              => $surgeon->getDrName(),
                'initialInstrumentistId'   => $payload['initialInstrumentistId'],
                'initialInstrumentistName' => $payload['initialInstrumentistName'],
            ],
            occurredAt: new \DateTimeImmutable(),
        ));

        return $slot;
    }

    /**
     * CLAIMED → AVAILABLE (désistement du repreneur, ou manager). La Mission de reprise est
     * annulée via MissionPostDeployService::cancel() tant qu'elle est OPEN/ASSIGNED ; si elle
     * a déjà été annulée par ailleurs, la salle est simplement rendue (jamais laissée bloquée).
     * Une instrumentiste déjà assignée est prévenue par le pipeline CANCELLED existant
     * (payload `roomSlotId` + `fromInstrumentistId`, voir le handler).
     */
    public function release(ReleasedOperatingRoomSlot $slot, User $actor): ReleasedOperatingRoomSlot
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $cancelPayload = null;
        $mission = null;

        try {
            $this->em->lock($slot, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($slot);

            if ($slot->getStatus() !== ReleasedRoomSlotStatus::CLAIMED || $slot->getOccurrenceDate() < new \DateTimeImmutable('today')) {
                throw new ReleasedRoomSlotConflictException(ReleasedRoomSlotConflictException::NOT_RELEASABLE, 'Cette salle ne peut plus être libérée.');
            }

            $taker = $slot->getClaimedBy();
            $mission = $slot->getTakeoverMission();

            if ($mission !== null) {
                // Même ordre de verrous que takeOver() (créneau puis Mission) — jamais d'interblocage.
                $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($mission);

                if (in_array($mission->getStatus(), self::CANCELLABLE_STATUSES, true)) {
                    $fromInstrumentist = $mission->getInstrumentist();
                    $reason = sprintf('Salle libérée par %s', $taker?->getDrName() ?? self::displayName($actor));
                    $this->postDeploy->cancel($mission, $actor, reason: $reason, notify: false);
                    $cancelPayload = [
                        'reason'                => $reason,
                        'roomSlotId'            => $slot->getId(),
                        'fromInstrumentistId'   => $fromInstrumentist?->getId(),
                        'fromInstrumentistName' => $fromInstrumentist !== null ? self::displayName($fromInstrumentist) : null,
                        'actorId'               => $actor->getId(),
                        'actorName'             => self::displayName($actor),
                    ];
                } elseif ($mission->getStatus() !== MissionStatus::CANCELLED) {
                    throw new ReleasedRoomSlotConflictException(
                        ReleasedRoomSlotConflictException::NOT_RELEASABLE,
                        'La mission de cette salle a déjà commencé : elle ne peut plus être libérée.',
                    );
                }
            }

            $this->reopen($slot, $actor, $taker, $mission, 'TAKER_RELEASED');

            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        if ($cancelPayload !== null) {
            $this->bus->dispatch(new MissionLifecycleChangedMessage(
                missionId: $mission->getId(),
                changeType: MissionChangeType::CANCELLED,
                actorId: $actor->getId(),
                payload: $cancelPayload,
                occurredAt: new \DateTimeImmutable(),
            ));
        }

        return $slot;
    }

    /**
     * Absence ultérieure du repreneur : AbsenceMissionReactionService (appelé AVANT, D-062) a
     * déjà annulé sa Mission de reprise ; sans ceci, le créneau resterait CLAIMED sur une
     * Mission CANCELLED — salle artificiellement bloquée. Rouvre chaque créneau concerné.
     * Appelé par ReleasedOperatingRoomSlotService::onAbsenceCreated()/onAbsenceUpdated().
     *
     * @return int nombre de créneaux rouverts
     */
    public function reopenSlotsCancelledByTakerAbsence(Absence $absence, User $actor): int
    {
        $taker = $absence->getUser();
        if ($taker === null) {
            return 0;
        }

        /** @var list<ReleasedOperatingRoomSlot> $candidates */
        $candidates = $this->em->createQueryBuilder()
            ->select('s')->from(ReleasedOperatingRoomSlot::class, 's')
            ->join('s.takeoverMission', 'm')
            ->where('s.claimedBy = :taker')
            ->andWhere('s.status = :claimed')
            ->andWhere('m.status = :cancelled')
            ->andWhere('s.occurrenceDate >= :start')
            ->andWhere('s.occurrenceDate <= :end')
            ->setParameter('taker', $taker)
            ->setParameter('claimed', ReleasedRoomSlotStatus::CLAIMED)
            ->setParameter('cancelled', MissionStatus::CANCELLED)
            ->setParameter('start', $absence->getDateStart(), Types::DATE_IMMUTABLE)
            ->setParameter('end', $absence->getDateEnd(), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        $reopened = 0;
        foreach ($candidates as $slot) {
            $connection = $this->em->getConnection();
            $connection->beginTransaction();
            try {
                $this->em->lock($slot, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($slot);

                $mission = $slot->getTakeoverMission();
                if ($slot->getStatus() !== ReleasedRoomSlotStatus::CLAIMED
                    || $slot->getClaimedBy()?->getId() !== $taker->getId()
                    || $mission?->getStatus() !== MissionStatus::CANCELLED) {
                    $connection->commit(); // handled concurrently — nothing to do
                    continue;
                }

                $this->reopen($slot, $actor, $taker, $mission, 'TAKER_ABSENT', $absence->getId());
                $this->em->flush();
                $connection->commit();
                $reopened++;
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        }

        return $reopened;
    }

    /**
     * D-104 guard — true while a take-over is in progress for this Mission's released slot.
     * AbsenceImpactReconciliationService must then never restore the absent surgeon's Mission,
     * or the room would have two surgeons.
     */
    public function isOriginalMissionTakenOver(Mission $mission): bool
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')->from(ReleasedOperatingRoomSlot::class, 's')
            ->where('s.originalMission = :m')
            ->andWhere('s.status = :claimed')
            ->setParameter('m', $mission)
            ->setParameter('claimed', ReleasedRoomSlotStatus::CLAIMED)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** D-104 guard, pre-generation variant — same rule for a neutralized Post occurrence (D-103). */
    public function isOccurrenceTakenOver(int $postId, \DateTimeImmutable $occurrenceDate): bool
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')->from(ReleasedOperatingRoomSlot::class, 's')
            ->where('s.postId = :postId')
            ->andWhere('s.occurrenceDate = :date')
            ->andWhere('s.status = :claimed')
            ->setParameter('postId', $postId)
            ->setParameter('date', $occurrenceDate, Types::DATE_IMMUTABLE)
            ->setParameter('claimed', ReleasedRoomSlotStatus::CLAIMED)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function reopen(ReleasedOperatingRoomSlot $slot, User $actor, ?User $taker, ?Mission $mission, string $reason, ?int $absenceId = null): void
    {
        $payload = array_merge($this->slotSnapshot($slot), [
            'reason'      => $reason,
            'absenceId'   => $absenceId,
            'previousTakenById'   => $taker?->getId(),
            'previousTakenByName' => $taker?->getDrName(),
            'takeoverMissionId'   => $mission?->getId(),
            'actorId'     => $actor->getId(),
            'actorName'   => self::displayName($actor),
        ]);

        $slot->markAvailableAgain();

        if ($mission !== null) {
            $this->audit->record($mission, $actor, AuditEventType::ROOM_SLOT_REOPENED, $payload);
        } else {
            $this->audit->recordGlobal($actor, AuditEventType::ROOM_SLOT_REOPENED, $payload);
        }
    }

    /**
     * The absent surgeon's own (already CANCELLED) Mission for this occurrence, if the planning
     * had been generated. Mission carries no Post FK, so the occurrence is matched the same way
     * the rest of the absence engine does: same surgeon, same site, same (Brussels) day, and a
     * start hour consistent with the slot's period. Most recent first.
     */
    private function findOriginalMission(ReleasedOperatingRoomSlot $slot): ?Mission
    {
        $surgeon = $slot->getSurgeon();
        $site = $slot->getSite();
        if ($surgeon === null || $site === null) {
            return null;
        }

        $day = $this->businessDay($slot->getOccurrenceDate());

        /** @var list<Mission> $candidates */
        $candidates = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.surgeon = :surgeon')
            ->andWhere('m.site = :site')
            ->andWhere('m.status = :cancelled')
            ->andWhere('m.startAt >= :dayStart')
            ->andWhere('m.startAt <= :dayEnd')
            ->setParameter('surgeon', $surgeon)
            ->setParameter('site', $site)
            ->setParameter('cancelled', MissionStatus::CANCELLED)
            ->setParameter('dayStart', $day->setTime(0, 0, 0))
            ->setParameter('dayEnd', $day->setTime(23, 59, 59))
            ->orderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $mission) {
            $hour = (int) $mission->getStartAt()?->format('G');
            $matches = match ($slot->getPeriod()) {
                ShiftPeriod::MATIN => $hour < 12,
                ShiftPeriod::APRES_MIDI => $hour >= 12,
                default => true,
            };
            if ($matches) {
                return $mission;
            }
        }

        return null;
    }

    /**
     * Times, in priority order: the slot's own snapshot (ShiftPeriodConfig at release time,
     * Lot D), then the absent surgeon's original Mission, then the site's current
     * ShiftPeriodConfig. Never invented — null when none is known.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    private function resolveSchedule(ReleasedOperatingRoomSlot $slot, ?Mission $originalMission): ?array
    {
        $day = $this->businessDay($slot->getOccurrenceDate());

        $start = $slot->getStartTime();
        $end = $slot->getEndTime();

        if (($start === null || $end === null) && $originalMission?->getStartAt() !== null && $originalMission->getEndAt() !== null) {
            return [$originalMission->getStartAt(), $originalMission->getEndAt()];
        }

        if ($start === null || $end === null) {
            $config = $slot->getSite() !== null
                ? $this->em->getRepository(ShiftPeriodConfig::class)->findOneBy(['site' => $slot->getSite(), 'period' => $slot->getPeriod()])
                : null;
            if ($config === null || !$config->isActive()) {
                return null;
            }
            $start = $config->getStartTime();
            $end = $config->getEndTime();
        }

        $startAt = $day->setTime((int) $start->format('H'), (int) $start->format('i'));
        $endAt = $day->setTime((int) $end->format('H'), (int) $end->format('i'));

        return $endAt > $startAt ? [$startAt, $endAt] : null;
    }

    /**
     * The instrumentist who was planned on this room before the absence — only ever used as
     * the target of an informational priority notice, never assigned. Source of truth, in
     * order: who was actually on the absent surgeon's Mission when it was cancelled (its
     * MISSION_CANCELLED_POST_DEPLOY snapshot — cancel() already cleared the FK), otherwise the
     * Post's theoretical partner (D-107 — planning not generated yet).
     */
    private function resolveInitialInstrumentist(ReleasedOperatingRoomSlot $slot, ?Mission $originalMission): ?User
    {
        if ($originalMission !== null) {
            /** @var AuditEvent|null $cancelEvent */
            $cancelEvent = $this->em->createQueryBuilder()
                ->select('e')->from(AuditEvent::class, 'e')
                ->where('e.mission = :m')
                ->andWhere('e.eventType = :type')
                ->setParameter('m', $originalMission)
                ->setParameter('type', AuditEventType::MISSION_CANCELLED_POST_DEPLOY)
                ->orderBy('e.id', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            $instrumentistId = $cancelEvent?->getPayload()['fromInstrumentistId'] ?? null;

            return $instrumentistId !== null ? $this->em->find(User::class, (int) $instrumentistId) : null;
        }

        return $slot->getSchedulePost()?->getInstrumentist();
    }

    /** @return array<string, mixed> */
    private function slotSnapshot(ReleasedOperatingRoomSlot $slot): array
    {
        $surgeon = $slot->getSurgeon();

        return [
            'roomSlotId'          => $slot->getId(),
            'postId'              => $slot->getPostId(),
            'occurrenceDate'      => $slot->getOccurrenceDate()->format('Y-m-d'),
            'period'              => $slot->getPeriod()->value,
            'startTime'           => $slot->getStartTime()?->format('H:i'),
            'endTime'             => $slot->getEndTime()?->format('H:i'),
            'siteId'              => $slot->getSite()?->getId(),
            'siteName'            => $slot->getSite()?->getName(),
            'originalSurgeonId'   => $surgeon?->getId(),
            'originalSurgeonName' => $surgeon?->getDrName(),
        ];
    }

    /** D-066 — Mission datetimes must be built from a Brussels-labeled day, never container UTC. */
    private function businessDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE));
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
