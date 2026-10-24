<?php

namespace App\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Message\PlanningAlertRaisedMessage;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Central answer to one question: "does this person have another active assignment that
 * overlaps this interval?" — reused by the generator (preview/generate), manual
 * modification (PlanningModificationService), deploy-time revalidation
 * (PlanningDraftRevalidationService), and manual reassignment eligibility
 * (PlanningAlertActionService) so the overlap math and the "which missions count" status
 * filter are never duplicated (D-091).
 *
 * Overlap rule — end-exclusive, real Europe/Brussels wall-clock instants (Mission.startAt/
 * endAt are business_datetime_immutable, D-066): two intervals conflict iff
 * `startA < endB AND endA > startB`. 08:00–10:00 and 10:00–12:00 do NOT conflict (adjacent,
 * not overlapping) — no travel-time model, see class docblock further down.
 *
 * Cross-site by construction: every query here is scoped by PERSON, never by site — a
 * conflict between two different sites is detected exactly the same way as one on the same
 * site (see docs/decisions.md D-091), with exactly ONE carve-out (D-035/D-091-amend,
 * 2026-09-13): a SURGEON running two overlapping rooms of the SAME site is a deliberate
 * "double salle" setup, never a conflict, regardless of whether the instrumentists match —
 * see $doubleRoomSite on findConflict()/hasConflictInPool(). An INSTRUMENTIST overlapping
 * two missions — same site or not — is never exempt this way; that stays a real,
 * unconditionally-detected conflict (a person cannot be in two rooms at once), for which
 * MissionConflictWaiverService still offers a narrow manager-authorized override (D-091
 * follow-up) — untouched by this carve-out and consulted here in syncAlertsForMission()/
 * syncAlertsForVersion() so a waived conflict's alert doesn't outlive the waiver that
 * unblocked its deployment. This service depends on MissionConflictWaiverService (not the
 * reverse) only for that one alert-lifecycle composition; the overlap math itself is never
 * duplicated there — see that service's own docblock.
 */
class PlanningConflictDetectionService
{
    /**
     * Missions that represent a real, still-relevant commitment of the person's time.
     * Excludes REJECTED (never happened), CANCELLED (explicitly called off) and CLOSED
     * (fully archived/settled) — these must never produce a false conflict. Everything
     * else counts, including DRAFT: once a Mission row exists, it represents an intended
     * commitment even before publication (same reasoning as
     * PlanningAlertActionService::hasConflict()'s pre-existing DRAFT inclusion).
     */
    public const ACTIVE_STATUSES = [
        MissionStatus::DRAFT,
        MissionStatus::OPEN,
        MissionStatus::DECLARED,
        MissionStatus::ASSIGNED,
        MissionStatus::SUBMITTED,
        MissionStatus::VALIDATED,
        MissionStatus::IN_PROGRESS,
        MissionStatus::ENCODING_IN_PROGRESS,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanningAlertService $alertService,
        private readonly UserRepository $userRepository,
        private readonly MessageBusInterface $bus,
        private readonly MissionConflictWaiverService $conflictWaiver,
    ) {}

    // ── Pure detection ───────────────────────────────────────────────────────

    /**
     * Real-time, single-person check. Returns the first other active mission of $person
     * that overlaps [$start, $end), or null. Never filtered by site for the person as a
     * whole — this IS the cross-site check — EXCEPT for the one carve-out $doubleRoomSite
     * exists for (D-035/D-091-amend, 2026-09-13): a surgeon running two overlapping rooms
     * of the exact same site is a deliberate, valid "double salle" setup, never a conflict,
     * regardless of whether the instrumentists match (see D-035's own canonical example —
     * two DIFFERENT instrumentists is the normal case, not the exception). Pass the
     * checked mission's site as $doubleRoomSite ONLY when $person is being checked in their
     * SURGEON role for that mission; pass null for the INSTRUMENTIST role (an instrumentist
     * cannot physically be in two rooms at once, same site or not — that stays a real,
     * unconditionally blocking SCHEDULE_CONFLICT, see D-091 follow-up which still allows a
     * narrow manager waiver for exactly that shape via MissionConflictWaiverService,
     * untouched by this carve-out). Use for one-off validations (deploy revalidation,
     * manual modification, manual reassignment) where a handful of real-time queries is
     * acceptable; for a batch (generation), use preloadActiveMissionsForPeriod() +
     * hasConflictInPool() instead to stay within the project's fixed-query-budget
     * discipline (see PlanningGeneratorServiceV2).
     */
    public function findConflict(User $person, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $excludeMissionId = null, ?Hospital $doubleRoomSite = null): ?Mission
    {
        return $this->conflictQuery($person, $start, $end, $excludeMissionId, $doubleRoomSite)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * D-167 — every other active mission of $person overlapping [$start, $end), not just the
     * first: each conflicting pair is its own alert. Same rules as findConflict().
     *
     * @return Mission[]
     */
    public function findConflicts(User $person, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $excludeMissionId = null, ?Hospital $doubleRoomSite = null): array
    {
        return $this->conflictQuery($person, $start, $end, $excludeMissionId, $doubleRoomSite)
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function conflictQuery(User $person, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $excludeMissionId, ?Hospital $doubleRoomSite): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('(m.surgeon = :person OR m.instrumentist = :person)')
            ->andWhere('m.startAt < :end')
            ->andWhere('m.endAt > :start')
            ->andWhere('m.status IN (:statuses)')
            ->setParameter('person', $person)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('statuses', self::ACTIVE_STATUSES)
            ->orderBy('m.startAt', 'ASC');

        if ($excludeMissionId !== null) {
            $qb->andWhere('m.id != :excludeId')->setParameter('excludeId', $excludeMissionId);
        }

        if ($doubleRoomSite !== null) {
            $qb->andWhere('NOT (m.surgeon = :doubleRoomPerson AND m.site = :doubleRoomSite)')
                ->setParameter('doubleRoomPerson', $person)
                ->setParameter('doubleRoomSite', $doubleRoomSite);
        }

        return $qb;
    }

    /**
     * Checks both people of a mission (surgeon + instrumentist) against the current DB
     * state. Used by deploy-time revalidation (D-090's pattern, extended to conflicts).
     *
     * @return array{surgeon: ?Mission, instrumentist: ?Mission}
     */
    public function findConflictsForMission(Mission $mission): array
    {
        $surgeon       = $mission->getSurgeon();
        $instrumentist = $mission->getInstrumentist();
        $start         = $mission->getStartAt();
        $end           = $mission->getEndAt();

        return [
            'surgeon' => $surgeon !== null
                ? $this->findConflict($surgeon, $start, $end, $mission->getId(), $mission->getSite())
                : null,
            'instrumentist' => $instrumentist !== null
                ? $this->findConflict($instrumentist, $start, $end, $mission->getId())
                : null,
        ];
    }

    /**
     * Bulk preload for the generator's batch path — ONE query for every ACTIVE mission
     * overlapping the period, indexed by person id (surgeon and/or instrumentist), never
     * filtered by site. Pair with hasConflictInPool() to check each newly-built line
     * in-memory, avoiding N+1 queries over a full month of occurrences.
     *
     * @return array<int, Mission[]>
     */
    public function preloadActiveMissionsForPeriod(string $from, string $to): array
    {
        $fromDt = (new \DateTimeImmutable($from))->setTime(0, 0, 0);
        $toDt   = (new \DateTimeImmutable($to))->setTime(23, 59, 59);

        /** @var Mission[] $missions */
        $missions = $this->em->createQuery(
            'SELECT m FROM App\Entity\Mission m
             WHERE m.startAt <= :to AND m.endAt >= :from
               AND m.status IN (:statuses)'
        )
            ->setParameter('from', $fromDt, Types::DATETIME_IMMUTABLE)
            ->setParameter('to', $toDt, Types::DATETIME_IMMUTABLE)
            ->setParameter('statuses', self::ACTIVE_STATUSES)
            ->getResult();

        $index = [];
        foreach ($missions as $mission) {
            if ($mission->getSurgeon() !== null) {
                $index[$mission->getSurgeon()->getId()][] = $mission;
            }
            if ($mission->getInstrumentist() !== null) {
                $index[$mission->getInstrumentist()->getId()][] = $mission;
            }
        }
        return $index;
    }

    /**
     * In-memory check against a pool built by preloadActiveMissionsForPeriod(). Same
     * $doubleRoomSite carve-out as findConflict() — pass the checked mission's site only
     * when $personId is being checked in their SURGEON role, null for INSTRUMENTIST.
     */
    public function hasConflictInPool(int $personId, \DateTimeImmutable $start, \DateTimeImmutable $end, array $pool, ?int $excludeMissionId = null, ?Hospital $doubleRoomSite = null): ?Mission
    {
        return $this->findConflictsInPool($personId, $start, $end, $pool, $excludeMissionId, $doubleRoomSite)[0] ?? null;
    }

    /**
     * D-167 — every overlapping mission of the pool, in pool order (see findConflicts()).
     *
     * @return Mission[]
     */
    public function findConflictsInPool(int $personId, \DateTimeImmutable $start, \DateTimeImmutable $end, array $pool, ?int $excludeMissionId = null, ?Hospital $doubleRoomSite = null): array
    {
        $conflicts = [];
        foreach ($pool[$personId] ?? [] as $mission) {
            if ($excludeMissionId !== null && $mission->getId() === $excludeMissionId) {
                continue;
            }
            if ($doubleRoomSite !== null
                && $mission->getSurgeon()?->getId() === $personId
                && $mission->getSite()?->getId() === $doubleRoomSite->getId()
            ) {
                continue;
            }
            if ($mission->getStartAt() < $end && $mission->getEndAt() > $start) {
                $conflicts[] = $mission;
            }
        }
        return $conflicts;
    }

    // ── Alert lifecycle (sync — create/refresh/resolve, never delete) ─────────────
    //
    // D-167 — one alert per conflicting PAIR (anchor mission, other mission, person, type).
    // It is held by the LOWER-id mission of the pair, so A vs B (or B vs A, whichever is
    // synced first/again) always resolves to exactly one active PlanningAlert row — never a
    // duplicate A→B and B→A pair. The pair is read from snapshotJson (`conflictingMissionId`,
    // `personId`), which every conflict alert has carried since D-091: no schema change.
    //
    // Every sync ends by re-evaluating EVERY active conflict alert that involves the synced
    // mission on either side — never only the alerts it happens to hold — against the
    // persisted state of BOTH missions. Before D-167, a sync of the higher-id mission only
    // looked for an alert on itself, so the alert held by the lower-id mission outlived the
    // conflict it described (case of 2026-10-23: IN_PROGRESS mission, the other one moved).

    /**
     * Detects and syncs SURGEON_CONFLICT/INSTRUMENTIST_CONFLICT alerts for one mission
     * against the CURRENT real-time DB state. Idempotent — safe to call repeatedly (e.g.
     * once per touched mission after every apply()). Never mutates the Mission itself.
     * Flushes its own alert changes (D-167): a caller is never trusted to flush for it —
     * PlanningModificationService::apply() never did, and every automatic resolution made
     * on the save path was silently lost.
     *
     * A manager-authorized MissionConflictWaiver (D-091 follow-up) is consulted here too,
     * not just at deploy-time revalidation — otherwise a waived INSTRUMENTIST_CONFLICT
     * would keep its alert badge open forever even after the manager explicitly authorized
     * it and deployed (found during this same audit, 2026-09-13). Never re-derives the
     * waiver's own eligibility rule here — findActiveWaiver() already owns that.
     *
     * A mission that no longer counts (CANCELLED, CLOSED…) raises nothing; its existing
     * conflict alerts are only re-evaluated, and resolved since the conflict is gone.
     *
     * @return array{created: PlanningAlert[], resolved: PlanningAlert[]}
     */
    public function syncAlertsForMission(Mission $mission): array
    {
        $created = [];

        if (in_array($mission->getStatus(), self::ACTIVE_STATUSES, true)) {
            foreach ($this->personChecks($mission) as [$person, $type]) {
                $doubleRoomSite = $type === PlanningAlertType::SURGEON_CONFLICT ? $mission->getSite() : null;
                foreach ($this->findConflicts($person, $mission->getStartAt(), $mission->getEndAt(), $mission->getId(), $doubleRoomSite) as $conflict) {
                    if ($this->conflictWaiver->findActiveWaiver($mission, $conflict) === null) {
                        $this->raiseOrRefresh($mission, $person, $type, $conflict, $created);
                    }
                }
            }
        }

        $resolved = $this->reconcileConflictAlertsInvolving([(int) $mission->getId()]);

        return ['created' => $created, 'resolved' => $resolved];
    }

    /**
     * Bulk variant for PlanningGeneratorServiceV2::generate() — syncs conflict alerts for
     * every DRAFT mission of a freshly-generated PlanningVersion using ONE preloaded
     * cross-site pool instead of per-mission real-time queries.
     *
     * @return array{created: PlanningAlert[], resolved: PlanningAlert[]}
     */
    public function syncAlertsForVersion(PlanningVersion $version): array
    {
        /** @var Mission[] $missions */
        $missions = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Mission::class, 'm')
            ->where('m.planningVersion = :version')
            ->andWhere('m.status = :draft')
            ->setParameter('version', $version)
            ->setParameter('draft', MissionStatus::DRAFT)
            ->getQuery()
            ->getResult();

        if (empty($missions)) {
            return ['created' => [], 'resolved' => []];
        }

        $pool = $this->preloadActiveMissionsForPeriod(
            $version->getPeriodStart()->format('Y-m-d'),
            $version->getPeriodEnd()->format('Y-m-d'),
        );

        $created = [];
        foreach ($missions as $mission) {
            foreach ($this->personChecks($mission) as [$person, $type]) {
                $doubleRoomSite = $type === PlanningAlertType::SURGEON_CONFLICT ? $mission->getSite() : null;
                foreach ($this->findConflictsInPool($person->getId(), $mission->getStartAt(), $mission->getEndAt(), $pool, $mission->getId(), $doubleRoomSite) as $conflict) {
                    if ($this->conflictWaiver->findActiveWaiver($mission, $conflict) === null) {
                        $this->raiseOrRefresh($mission, $person, $type, $conflict, $created);
                    }
                }
            }
        }

        $resolved = $this->reconcileConflictAlertsInvolving(array_map(static fn (Mission $m) => (int) $m->getId(), $missions));

        return ['created' => $created, 'resolved' => $resolved];
    }

    /**
     * D-167 — re-evaluates every OPEN/ACKNOWLEDGED conflict alert involving one of these
     * missions (as the alert holder OR as its conflicting mission) against the persisted
     * state of both missions, and resolves — never deletes — the ones whose conflict no
     * longer exists. A still-real conflict is left untouched (never closed, never
     * duplicated). RESOLVED/IGNORED alerts are history: never read back here, never reopened.
     * Used by every sync above and by « Vérifier les conflits » (PlanningVersionAuditService),
     * which reconciles the whole version this way. Flushes its own changes.
     *
     * @param int[] $missionIds
     * @return PlanningAlert[] the alerts resolved by this call
     */
    public function reconcileConflictAlertsInvolving(array $missionIds): array
    {
        $resolved = [];
        foreach ($this->alertService->findActiveConflictAlertsInvolving($missionIds) as $alert) {
            // The identity map may hold an alert already resolved earlier in this same unit
            // of work (not flushed yet): never resolve — or count — it twice.
            if (!$alert->isOpenOrAcknowledged()) {
                continue;
            }
            $reason = $this->obsolescenceReason($alert);
            if ($reason !== null) {
                $alert->resolve(null, $reason);
                $resolved[] = $alert;
            }
        }
        $this->em->flush();

        return $resolved;
    }

    /** @return list<array{0: User, 1: PlanningAlertType}> */
    private function personChecks(Mission $mission): array
    {
        $checks = [];
        if ($mission->getSurgeon() !== null) {
            $checks[] = [$mission->getSurgeon(), PlanningAlertType::SURGEON_CONFLICT];
        }
        if ($mission->getInstrumentist() !== null) {
            $checks[] = [$mission->getInstrumentist(), PlanningAlertType::INSTRUMENTIST_CONFLICT];
        }
        return $checks;
    }

    /**
     * Shared create/refresh decision for one real conflict, used by both the real-time and
     * pool-based paths. The alert is always held by the lower-id mission of the pair,
     * regardless of WHICH of the two missions triggered this sync call.
     *
     *  - An active alert already covers this pair → kept; its snapshot is refreshed when
     *    the schedules changed (the overlap was moved or reduced, but still exists).
     *  - The manager IGNORED this exact conflict and neither mission's schedule changed
     *    since → nothing is raised again (an ignored alert is never reopened arbitrarily).
     *  - Otherwise → a new alert, and its notification.
     */
    private function raiseOrRefresh(Mission $mission, User $person, PlanningAlertType $type, Mission $conflict, array &$created): void
    {
        $anchor = $mission->getId() < $conflict->getId() ? $mission : $conflict;
        $other  = $anchor === $mission ? $conflict : $mission;

        // Concurrency guard (D-109, found via a live two-simultaneous-scans test): the
        // lookup-then-create below is a plain check-then-create — without serialization,
        // two callers racing on the exact same pair (e.g. two "Vérifier les conflits"
        // scans fired at once) can both read "no active alert yet" before either commits
        // its INSERT, producing duplicate alerts. Locking the anchor mission's row for the
        // duration of the check-then-create — the same PESSIMISTIC_WRITE convention
        // already used everywhere else in this codebase for Mission-adjacent mutations
        // (see MissionPostDeployService) — makes the second caller block until the first
        // commits, so it then correctly finds the alert already created.
        $alert = $this->em->wrapInTransaction(function () use ($anchor, $type, $other, $person): ?PlanningAlert {
            $this->em->lock($anchor, LockMode::PESSIMISTIC_WRITE);
            $snapshot = $this->buildSnapshot($type, $anchor, $other, $person);
            $existing = $this->alertService->findConflictAlertsForPair($anchor, $type, (int) $other->getId(), (int) $person->getId());

            foreach ($existing as $alert) {
                if ($alert->isOpenOrAcknowledged()) {
                    if ($alert->getSnapshotJson() !== $snapshot) {
                        $alert->setSnapshotJson($snapshot);
                    }
                    return null;
                }
            }
            foreach ($existing as $alert) {
                if ($alert->getStatus() === PlanningAlertStatus::IGNORED && self::sameSchedule($alert->getSnapshotJson(), $snapshot)) {
                    return null;
                }
            }

            $alert = new PlanningAlert();
            $alert->setMission($anchor);
            $alert->setType($type);
            $alert->setSnapshotJson($snapshot);
            $this->em->persist($alert);
            return $alert;
        });

        if ($alert !== null) {
            $created[] = $alert;
            $this->dispatchNotification($alert, $anchor, $other, $type);
        }
    }

    /**
     * Why this conflict alert no longer describes reality — null while the conflict is still
     * real. Reads both missions as persisted; re-runs the exact detection rule of
     * findConflict() (overlap math, statuses, double-room carve-out) from either side of the
     * pair, so the result never depends on which mission holds the alert or has the lower id.
     */
    private function obsolescenceReason(PlanningAlert $alert): ?string
    {
        $snapshot = $alert->getSnapshotJson();
        $anchor   = $alert->getMission();
        $other    = isset($snapshot['conflictingMissionId']) ? $this->em->find(Mission::class, (int) $snapshot['conflictingMissionId']) : null;
        $person   = isset($snapshot['personId']) ? $this->em->find(User::class, (int) $snapshot['personId']) : null;
        $prefix   = 'Conflit horaire résolu automatiquement après modification du planning';

        if ($anchor === null || $other === null || $person === null) {
            return $prefix . " : la mission ou la personne en conflit n'existe plus.";
        }
        foreach ([$anchor, $other] as $m) {
            if (!in_array($m->getStatus(), self::ACTIVE_STATUSES, true)) {
                return sprintf("%s : la mission #%d n'est plus active (%s).", $prefix, $m->getId(), $m->getStatus()->value);
            }
        }
        if (!self::involves($anchor, $person) || !self::involves($other, $person)) {
            return sprintf("%s : %s n'est plus sur les missions #%d et #%d à la fois.", $prefix, self::displayName($person), $anchor->getId(), $other->getId());
        }

        $type = $alert->getType();
        if (!$this->pairConflicts($anchor, $other, $person, $type) && !$this->pairConflicts($other, $anchor, $person, $type)) {
            return sprintf('%s : les missions #%d et #%d ne se chevauchent plus.', $prefix, $anchor->getId(), $other->getId());
        }
        if ($this->conflictWaiver->findActiveWaiver($anchor, $other) !== null) {
            return sprintf('Conflit horaire couvert par une dérogation du manager (missions #%d et #%d).', $anchor->getId(), $other->getId());
        }

        return null;
    }

    /**
     * Would syncing $checked report $against as a $type conflict for $person? Same rule as
     * syncAlertsForMission(): $person holds the $type role on $checked, and $against —
     * where $person is surgeon or instrumentist — overlaps it (hasConflictInPool(), so the
     * overlap math and the double-room carve-out are never duplicated).
     */
    private function pairConflicts(Mission $checked, Mission $against, User $person, PlanningAlertType $type): bool
    {
        $role = $type === PlanningAlertType::SURGEON_CONFLICT ? $checked->getSurgeon() : $checked->getInstrumentist();
        if ($role?->getId() !== $person->getId()) {
            return false;
        }
        $doubleRoomSite = $type === PlanningAlertType::SURGEON_CONFLICT ? $checked->getSite() : null;

        return $this->hasConflictInPool(
            (int) $person->getId(), $checked->getStartAt(), $checked->getEndAt(),
            [(int) $person->getId() => [$against]], $checked->getId(), $doubleRoomSite,
        ) !== null;
    }

    private static function involves(Mission $mission, User $person): bool
    {
        return $mission->getSurgeon()?->getId() === $person->getId()
            || $mission->getInstrumentist()?->getId() === $person->getId();
    }

    private static function sameSchedule(array $a, array $b): bool
    {
        foreach (['missionStartAt', 'missionEndAt', 'conflictingStartAt', 'conflictingEndAt'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private function buildSnapshot(PlanningAlertType $type, Mission $anchor, Mission $other, User $person): array
    {
        $anchorSite = $anchor->getSite();
        $otherSite  = $other->getSite();

        return [
            'type'                 => $type->value,
            'personId'             => $person->getId(),
            'personName'           => self::displayName($person),
            'missionId'            => $anchor->getId(),
            'missionSiteId'        => $anchorSite?->getId(),
            'missionSiteName'      => $anchorSite?->getName(),
            'missionStartAt'       => $anchor->getStartAt()->format(\DateTimeInterface::ATOM),
            'missionEndAt'         => $anchor->getEndAt()->format(\DateTimeInterface::ATOM),
            'conflictingMissionId' => $other->getId(),
            'conflictingSiteId'    => $otherSite?->getId(),
            'conflictingSiteName'  => $otherSite?->getName(),
            'conflictingStartAt'   => $other->getStartAt()->format(\DateTimeInterface::ATOM),
            'conflictingEndAt'     => $other->getEndAt()->format(\DateTimeInterface::ATOM),
            'crossSite'            => $anchorSite?->getId() !== $otherSite?->getId(),
        ];
    }

    /**
     * Recipients: every operational manager/admin (D-149), same as AbsenceImpactService — no
     * surgeon/instrumentist-facing notification UX exists yet for planning alerts, so the
     * conflicting person themself is deliberately not notified here either.
     */
    private function dispatchNotification(PlanningAlert $alert, Mission $anchor, Mission $other, PlanningAlertType $type): void
    {
        $recipients = [];
        foreach ($this->userRepository->findOperationalManagers() as $manager) {
            $recipients[$manager->getId()] = true;
        }

        $site = $anchor->getSite();

        $this->bus->dispatch(new PlanningAlertRaisedMessage(
            alertId: $alert->getId() ?? 0,
            alertType: $type->value,
            missionId: $anchor->getId(),
            siteId: $site?->getId(),
            siteName: $site?->getName(),
            missionDate: $anchor->getStartAt()->format('Y-m-d'),
            absenceId: null,
            surgeonId: $anchor->getSurgeon()->getId(),
            instrumentistId: $anchor->getInstrumentist()?->getId(),
            recipientUserIds: array_keys($recipients),
            detectedAt: $alert->getDetectedAt()->format(\DateTimeInterface::ATOM),
        ));
    }

    private static function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : ($user->getEmail() ?? '');
    }
}
