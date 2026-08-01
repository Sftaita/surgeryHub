<?php

namespace App\Service;

use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\PlanningAlertType;
use App\Message\PlanningAlertRaisedMessage;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
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
 * site (see docs/decisions.md D-091 for why "same site, two missions" is deliberately not
 * a special case).
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
    ) {}

    // ── Pure detection ───────────────────────────────────────────────────────

    /**
     * Real-time, single-person check. Returns the first other active mission of $person
     * that overlaps [$start, $end), or null. Never filtered by site — this IS the
     * cross-site check. Use for one-off validations (deploy revalidation, manual
     * modification, manual reassignment) where a handful of real-time queries is
     * acceptable; for a batch (generation), use preloadActiveMissionsForPeriod() +
     * hasConflictInPool() instead to stay within the project's fixed-query-budget
     * discipline (see PlanningGeneratorServiceV2).
     */
    public function findConflict(User $person, \DateTimeImmutable $start, \DateTimeImmutable $end, ?int $excludeMissionId = null): ?Mission
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
            ->orderBy('m.startAt', 'ASC')
            ->setMaxResults(1);

        if ($excludeMissionId !== null) {
            $qb->andWhere('m.id != :excludeId')->setParameter('excludeId', $excludeMissionId);
        }

        return $qb->getQuery()->getOneOrNullResult();
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
                ? $this->findConflict($surgeon, $start, $end, $mission->getId())
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

    /** In-memory check against a pool built by preloadActiveMissionsForPeriod(). */
    public function hasConflictInPool(int $personId, \DateTimeImmutable $start, \DateTimeImmutable $end, array $pool, ?int $excludeMissionId = null): ?Mission
    {
        foreach ($pool[$personId] ?? [] as $mission) {
            if ($excludeMissionId !== null && $mission->getId() === $excludeMissionId) {
                continue;
            }
            if ($mission->getStartAt() < $end && $mission->getEndAt() > $start) {
                return $mission;
            }
        }
        return null;
    }

    // ── Alert lifecycle (sync — create/resolve, never delete) ──────────────────
    //
    // A conflict alert is always anchored on the LOWER-id mission of the conflicting pair,
    // so mission A vs mission B (or B vs A, whichever is synced first/again) always
    // resolves to exactly one PlanningAlert row — never a duplicate A→B and B→A pair.

    /**
     * Detects and syncs SURGEON_CONFLICT/INSTRUMENTIST_CONFLICT alerts for one mission
     * against the CURRENT real-time DB state. Idempotent — safe to call repeatedly (e.g.
     * once per touched mission after every apply()). Never mutates the Mission itself.
     *
     * @return array{created: PlanningAlert[], resolved: PlanningAlert[]}
     */
    public function syncAlertsForMission(Mission $mission): array
    {
        $created  = [];
        $resolved = [];

        foreach ($this->personChecks($mission) as [$person, $type]) {
            $conflict = $this->findConflict($person, $mission->getStartAt(), $mission->getEndAt(), $mission->getId());
            $this->applySync($mission, $person, $type, $conflict, $created, $resolved);
        }

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

        $created  = [];
        $resolved = [];
        foreach ($missions as $mission) {
            foreach ($this->personChecks($mission) as [$person, $type]) {
                $conflict = $this->hasConflictInPool($person->getId(), $mission->getStartAt(), $mission->getEndAt(), $pool, $mission->getId());
                $this->applySync($mission, $person, $type, $conflict, $created, $resolved);
            }
        }

        return ['created' => $created, 'resolved' => $resolved];
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
     * Shared create/resolve decision, used by both the real-time and pool-based paths.
     * Only the mission with the LOWER id ever HOLDS the active alert for a given pair —
     * that is what guarantees "never A→B and B→A". Critically, the alert is always
     * created on that anchor regardless of WHICH of the two missions triggered this sync
     * call: callers only ever sync the mission(s) they actually touched (e.g.
     * PlanningModificationService::apply() only syncs the edited mission), so if the
     * touched mission happens to be the higher-id side, nothing else would ever visit
     * the lower-id side to raise the alert there. createIfNotDuplicate() still guards
     * against re-creating it if an earlier sync (from either side) already did.
     */
    private function applySync(Mission $mission, User $person, PlanningAlertType $type, ?Mission $conflict, array &$created, array &$resolved): void
    {
        if ($conflict !== null) {
            $anchor = $mission->getId() < $conflict->getId() ? $mission : $conflict;
            $other  = $anchor === $mission ? $conflict : $mission;

            $result = $this->alertService->createIfNotDuplicate(
                $anchor, $type, null, $this->buildSnapshot($type, $anchor, $other, $person),
            );
            if ($result['created']) {
                $created[] = $result['alert'];
                $this->em->flush(); // populate the alert's id before it's referenced by the message
                $this->dispatchNotification($result['alert'], $anchor, $other, $type);
            }

            // $mission itself is never the alert holder when it isn't the anchor — if it
            // was carrying a now-stale row (e.g. it used to be the lower-id side of a
            // different pairing), that row no longer reflects reality either.
            if ($mission !== $anchor) {
                $staleHere = $this->alertService->findActiveAlert($mission, $type, null);
                if ($staleHere !== null) {
                    $staleHere->resolve(null, 'Conflit résolu : ancré désormais sur une autre mission.');
                    $resolved[] = $staleHere;
                }
            }
            return;
        }

        $existingHere = $this->alertService->findActiveAlert($mission, $type, null);
        if ($existingHere !== null) {
            $existingHere->resolve(null, 'Conflit résolu : plus de chevauchement actif.');
            $resolved[] = $existingHere;
        }
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
     * Recipients: every active manager/admin, same as AbsenceImpactService — no
     * surgeon/instrumentist-facing notification UX exists yet for planning alerts, so the
     * conflicting person themself is deliberately not notified here either.
     */
    private function dispatchNotification(PlanningAlert $alert, Mission $anchor, Mission $other, PlanningAlertType $type): void
    {
        $recipients = [];
        foreach ($this->userRepository->findManagersAndAdmins(true) as $manager) {
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
