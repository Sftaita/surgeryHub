<?php

namespace App\Tests\Unit\Service;

use App\Dto\EligibilityResult;
use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EligibilityReason;
use App\Enum\MissionStatus;
use App\Service\MissionEligibilityService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MissionEligibilityServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MissionEligibilityService $service;

    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->service = new MissionEligibilityService($this->em);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function setId(object $entity, int $id): void
    {
        $rp = new \ReflectionProperty($entity, 'id');
        $rp->setAccessible(true);
        $rp->setValue($entity, $id);
    }

    private function makeUser(bool $active = true, array $roles = ['ROLE_INSTRUMENTIST']): User
    {
        $u = new User();
        $u->setEmail('u' . self::$nextId . '@test.com');
        $u->setActive($active);
        $u->setRoles($roles);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeSite(): Hospital
    {
        $s = new Hospital();
        $s->setName('Site ' . self::$nextId);
        $this->setId($s, self::$nextId++);
        return $s;
    }

    private function makeMission(
        MissionStatus $status = MissionStatus::OPEN,
        ?User $instrumentist = null,
        ?Hospital $site = null,
    ): Mission {
        $m = new Mission();
        $m->setStatus($status);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site ?? $this->makeSite());
        $m->setStartAt(new \DateTimeImmutable('2026-07-15 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-07-15 13:00:00'));
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function makeAbsence(User $user, string $start = '2026-07-15', string $end = '2026-07-15'): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($start));
        $a->setDateEnd(new \DateTimeImmutable($end));
        $a->setCreatedBy($user);
        $this->setId($a, self::$nextId++);
        return $a;
    }

    private function makeConflictMission(User $instrumentist, ?Mission $excludeId = null): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setInstrumentist($instrumentist);
        $m->setStartAt(new \DateTimeImmutable('2026-07-15 09:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-07-15 12:00:00'));
        $this->setId($m, self::$nextId++);
        return $m;
    }

    /**
     * Set up em->createQuery for evaluate() calls.
     * Q1 = membership count, Q2 = absence count, Q3 = conflict count.
     */
    private function setupEvaluateQueries(int $memberCount, int $absenceCount, int $conflictCount): void
    {
        $call = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$call, $memberCount, $absenceCount, $conflictCount): Query {
                $call++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getSingleScalarResult')->willReturn(match ($call) {
                    1 => $memberCount,
                    2 => $absenceCount,
                    3 => $conflictCount,
                    default => 0,
                });
                return $q;
            });
    }

    // ── evaluate() — single candidate ─────────────────────────────────────────

    public function test_evaluate_returns_eligible_when_all_checks_pass(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser();

        $this->setupEvaluateQueries(memberCount: 1, absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertTrue($result->eligible);
        $this->assertEmpty($result->reasons);
        $this->assertSame($candidate, $result->candidate);
    }

    public function test_evaluate_inactive_user_returns_inactive_reason(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser(active: false);

        $this->setupEvaluateQueries(memberCount: 1, absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::INACTIVE, $result->reasons);
    }

    public function test_evaluate_no_site_membership_returns_reason(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser();

        $this->setupEvaluateQueries(memberCount: 0, absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::NO_SITE_MEMBERSHIP, $result->reasons);
    }

    /**
     * RC1-C: evaluate() must bypass the site-membership check for FREELANCER candidates,
     * mirroring findEligible() and MissionVoter::isEligibleInstrumentistForOpenMission()
     * (D-057). Without this, a freelancer without a formal SiteMembership row could never
     * claim any OPEN pool mission — defeating the point of being freelance.
     */
    public function test_evaluate_freelancer_bypasses_site_membership_check(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::FREELANCER);

        // Only 2 queries expected (absence, conflict) — the membership query (Q1) is skipped.
        $call = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$call): Query {
                $call++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getSingleScalarResult')->willReturn(0);
                return $q;
            });

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertTrue($result->eligible);
        $this->assertNotContains(EligibilityReason::NO_SITE_MEMBERSHIP, $result->reasons);
        $this->assertSame(2, $call, 'freelancer must skip the site-membership query entirely');
    }

    public function test_evaluate_non_freelancer_without_membership_still_ineligible(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::EMPLOYEE);

        $this->setupEvaluateQueries(memberCount: 0, absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::NO_SITE_MEMBERSHIP, $result->reasons);
    }

    public function test_evaluate_absent_candidate_returns_absent_reason(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser();

        $this->setupEvaluateQueries(memberCount: 1, absenceCount: 1, conflictCount: 0);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::ABSENT, $result->reasons);
    }

    public function test_evaluate_conflicting_mission_returns_schedule_conflict(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser();

        $this->setupEvaluateQueries(memberCount: 1, absenceCount: 0, conflictCount: 1);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::SCHEDULE_CONFLICT, $result->reasons);
    }

    public function test_evaluate_assigned_mission_returns_already_assigned(): void
    {
        $assigned  = $this->makeUser();
        $mission   = $this->makeMission(status: MissionStatus::ASSIGNED, instrumentist: $assigned);
        $candidate = $this->makeUser();

        $this->setupEvaluateQueries(memberCount: 1, absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::ALREADY_ASSIGNED, $result->reasons);
    }

    public function test_evaluate_non_open_mission_returns_incompatible_status(): void
    {
        $mission   = $this->makeMission(status: MissionStatus::CANCELLED);
        $candidate = $this->makeUser();

        $this->setupEvaluateQueries(memberCount: 1, absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::INCOMPATIBLE_STATUS, $result->reasons);
    }

    public function test_evaluate_accumulates_multiple_reasons(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser();

        $this->setupEvaluateQueries(memberCount: 1, absenceCount: 1, conflictCount: 1);

        $result = $this->service->evaluate($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::ABSENT, $result->reasons);
        $this->assertContains(EligibilityReason::SCHEDULE_CONFLICT, $result->reasons);
    }

    public function test_evaluate_uses_exactly_3_db_queries(): void
    {
        $mission   = $this->makeMission();
        $candidate = $this->makeUser();

        $queryCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$queryCount): Query {
                $queryCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getSingleScalarResult')->willReturn(0);
                return $q;
            });

        $this->service->evaluate($mission, $candidate);

        $this->assertSame(3, $queryCount, 'evaluate() must run exactly 3 DB queries.');
    }

    // ── evaluateAllCandidates() ────────────────────────────────────────────────

    public function test_evaluate_all_candidates_returns_eligible_and_ineligible(): void
    {
        $site      = $this->makeSite();
        $mission   = $this->makeMission(site: $site);
        $eligible  = $this->makeUser();
        $absent    = $this->makeUser();
        $absence   = $this->makeAbsence($absent);

        $callCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$callCount, $eligible, $absent, $absence): Query {
                $callCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match ($callCount) {
                    1 => [$eligible, $absent],  // Q1: candidates
                    2 => [$absence],             // Q2: absences
                    3 => [],                     // Q3: no conflicts
                    default => [],
                });
                return $q;
            });

        $results = $this->service->evaluateAllCandidates($mission);

        $this->assertCount(2, $results);

        $eligibleResult = array_values(array_filter($results, fn ($r) => $r->candidate === $eligible))[0];
        $this->assertTrue($eligibleResult->eligible);

        $absentResult = array_values(array_filter($results, fn ($r) => $r->candidate === $absent))[0];
        $this->assertFalse($absentResult->eligible);
        $this->assertContains(EligibilityReason::ABSENT, $absentResult->reasons);
    }

    /**
     * D-102 (Lot 2) — cas Christine Vanmessem (§3 de la spec) : un instrumentiste
     * inactif du site doit rester dans la liste, marqué INACTIVE, jamais silencieusement
     * exclu de la requête Q1.
     */
    public function test_evaluate_all_candidates_includes_inactive_site_member_as_ghost(): void
    {
        $site     = $this->makeSite();
        $mission  = $this->makeMission(site: $site);
        $inactive = $this->makeUser(active: false);

        $callCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$callCount, $inactive): Query {
                $callCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match ($callCount) {
                    1 => [$inactive],
                    default => [],
                });
                return $q;
            });

        $results = $this->service->evaluateAllCandidates($mission);

        $this->assertCount(1, $results, 'inactive candidate must still appear in the list');
        $this->assertFalse($results[0]->eligible);
        $this->assertContains(EligibilityReason::INACTIVE, $results[0]->reasons);
    }

    /**
     * D-102 (Lot 2) — cas Sophie Collette : le résultat doit porter l'Absence réelle
     * (pas juste le code ABSENT) pour qu'une UI affiche "Absente — 01/08 → 16/08".
     */
    public function test_evaluate_all_candidates_populates_absence_detail(): void
    {
        $site    = $this->makeSite();
        $mission = $this->makeMission(site: $site, status: MissionStatus::ASSIGNED);
        $sophie  = $this->makeUser();
        $absence = $this->makeAbsence($sophie, '2026-08-01', '2026-08-16');
        $mission->setStartAt(new \DateTimeImmutable('2026-08-14 08:00:00'));
        $mission->setEndAt(new \DateTimeImmutable('2026-08-14 18:00:00'));

        $callCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$callCount, $sophie, $absence): Query {
                $callCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match ($callCount) {
                    1 => [$sophie],
                    2 => [$absence],
                    default => [],
                });
                return $q;
            });

        $results = $this->service->evaluateAllCandidates($mission);

        $this->assertCount(1, $results);
        $this->assertSame($absence, $results[0]->absence);
        $this->assertSame('2026-08-01', $results[0]->absence->getDateStart()->format('Y-m-d'));
        $this->assertSame('2026-08-16', $results[0]->absence->getDateEnd()->format('Y-m-d'));
    }

    /** D-102 (Lot 2) — same for SCHEDULE_CONFLICT: the conflicting Mission is carried too. */
    public function test_evaluate_all_candidates_populates_conflicting_mission_detail(): void
    {
        $site      = $this->makeSite();
        $mission   = $this->makeMission(site: $site, status: MissionStatus::ASSIGNED);
        $candidate = $this->makeUser();
        $conflict  = $this->makeConflictMission($candidate);

        $callCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$callCount, $candidate, $conflict): Query {
                $callCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match ($callCount) {
                    1 => [$candidate],
                    3 => [$conflict],
                    default => [],
                });
                return $q;
            });

        $results = $this->service->evaluateAllCandidates($mission);

        $this->assertCount(1, $results);
        $this->assertSame($conflict, $results[0]->conflictingMission);
    }

    public function test_evaluate_all_candidates_returns_empty_when_no_site(): void
    {
        $mission = new Mission();
        $mission->setStatus(MissionStatus::OPEN);
        $this->setId($mission, self::$nextId++);

        $this->em->expects($this->never())->method('createQuery');

        $results = $this->service->evaluateAllCandidates($mission);

        $this->assertEmpty($results);
    }

    public function test_evaluate_all_candidates_uses_exactly_3_db_queries(): void
    {
        $site      = $this->makeSite();
        $mission   = $this->makeMission(site: $site);
        $candidate = $this->makeUser();

        $queryCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$queryCount, $candidate): Query {
                $queryCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                // Q1 must return at least one candidate so Q2 and Q3 are executed
                $q->method('getResult')->willReturn($queryCount === 1 ? [$candidate] : []);
                return $q;
            });

        $this->service->evaluateAllCandidates($mission);

        $this->assertSame(3, $queryCount, 'evaluateAllCandidates() must run exactly 3 DB queries.');
    }

    // ── findEligible() ────────────────────────────────────────────────────────

    public function test_find_eligible_returns_eligible_users_by_site(): void
    {
        $site      = $this->makeSite();
        $mission   = $this->makeMission(site: $site);
        $candidate = $this->makeUser();

        $callCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$callCount, $candidate, $site): Query {
                $callCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match ($callCount) {
                    1 => [['user' => $candidate, 'siteId' => $site->getId()]],  // Q1: candidates
                    2 => [],     // Q2: no absences
                    3 => [],     // Q3: no conflicts
                    default => [],
                });
                return $q;
            });

        $result = $this->service->findEligible([$mission]);

        $siteId = $site->getId();
        $this->assertArrayHasKey($siteId, $result);
        $this->assertContains($candidate, $result[$siteId]);
    }

    public function test_find_eligible_excludes_absent_candidate(): void
    {
        $site      = $this->makeSite();
        $mission   = $this->makeMission(site: $site);
        $candidate = $this->makeUser();
        $absence   = $this->makeAbsence($candidate);

        $callCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$callCount, $candidate, $site, $absence): Query {
                $callCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match ($callCount) {
                    1 => [['user' => $candidate, 'siteId' => $site->getId()]],  // Q1: candidates
                    2 => [$absence],  // Q2: candidate is absent
                    3 => [],          // Q3: no conflicts
                    default => [],
                });
                return $q;
            });

        $result = $this->service->findEligible([$mission]);

        $this->assertEmpty($result[$site->getId()] ?? []);
    }

    public function test_find_eligible_excludes_candidate_with_schedule_conflict(): void
    {
        $site      = $this->makeSite();
        $mission   = $this->makeMission(site: $site);
        $candidate = $this->makeUser();
        $conflict  = $this->makeConflictMission($candidate);

        $callCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$callCount, $candidate, $site, $conflict): Query {
                $callCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match ($callCount) {
                    1 => [['user' => $candidate, 'siteId' => $site->getId()]],  // Q1: candidates
                    2 => [],           // Q2: no absences
                    3 => [$conflict],  // Q3: conflicting mission
                    default => [],
                });
                return $q;
            });

        $result = $this->service->findEligible([$mission]);

        $this->assertEmpty($result[$site->getId()] ?? []);
    }

    public function test_find_eligible_returns_empty_for_empty_input(): void
    {
        $this->em->expects($this->never())->method('createQuery');

        $result = $this->service->findEligible([]);

        $this->assertEmpty($result);
    }

    public function test_find_eligible_uses_exactly_3_db_queries_for_multiple_missions(): void
    {
        $site      = $this->makeSite();
        $candidate = $this->makeUser();
        $m1        = $this->makeMission(site: $site);
        $m2        = $this->makeMission(site: $site);
        $m3        = $this->makeMission(site: $site);

        $queryCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$queryCount, $candidate, $site): Query {
                $queryCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                // Q1 must return at least one candidate so Q2 and Q3 are executed
                $q->method('getResult')->willReturn(
                    $queryCount === 1 ? [['user' => $candidate, 'siteId' => $site->getId()]] : []
                );
                return $q;
            });

        $this->service->findEligible([$m1, $m2, $m3]);

        $this->assertSame(3, $queryCount,
            'findEligible() must run exactly 3 DB queries regardless of mission count (D-036).'
        );
    }

    public function test_find_eligible_freelancer_without_membership_is_included(): void
    {
        $site       = $this->makeSite();
        $mission    = $this->makeMission(site: $site);
        $freelancer = $this->makeUser();
        $freelancer->setEmploymentType(\App\Enum\EmploymentType::FREELANCER);

        $callCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$callCount, $freelancer): Query {
                $callCount++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match ($callCount) {
                    // Q1: freelancer row with siteId = null — no matching membership.
                    1 => [['user' => $freelancer, 'siteId' => null]],
                    2 => [],
                    3 => [],
                    default => [],
                });
                return $q;
            });

        $result = $this->service->findEligible([$mission]);

        $this->assertContains($freelancer, $result[$site->getId()] ?? []);
    }

    // ── evaluateForReassignment() — D-101, single canonical source of truth ────
    //
    // Only 2 queries (absence, conflict) — unlike evaluate()'s 3 — since
    // NO_SITE_MEMBERSHIP is deliberately NOT checked here (see the method's own
    // docblock): a manager assign/reassign has never required formal site affiliation
    // in this codebase, only claim() self-service does.

    /** Set up em->createQuery for evaluateForReassignment() calls: Q1 = absence, Q2 = conflict. */
    private function setupReassignmentQueries(int $absenceCount, int $conflictCount): void
    {
        $call = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function () use (&$call, $absenceCount, $conflictCount): Query {
                $call++;
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getSingleScalarResult')->willReturn(match ($call) {
                    1 => $absenceCount,
                    2 => $conflictCount,
                    default => 0,
                });
                return $q;
            });
    }

    public function test_evaluate_for_reassignment_returns_eligible_when_all_checks_pass(): void
    {
        $mission   = $this->makeMission(status: MissionStatus::ASSIGNED, instrumentist: $this->makeUser());
        $candidate = $this->makeUser();

        $this->setupReassignmentQueries(absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluateForReassignment($mission, $candidate);

        $this->assertTrue($result->eligible);
    }

    /**
     * D-101 — cas Sophie Collette : evaluateForReassignment() ne doit JAMAIS ignorer une
     * absence sous prétexte que la mission est déjà ASSIGNED (contrairement à evaluate(),
     * qui est réservé au flux claim() sur mission OPEN).
     */
    public function test_evaluate_for_reassignment_absent_candidate_returns_absent_reason(): void
    {
        $mission   = $this->makeMission(status: MissionStatus::ASSIGNED);
        $candidate = $this->makeUser();

        $this->setupReassignmentQueries(absenceCount: 1, conflictCount: 0);

        $result = $this->service->evaluateForReassignment($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::ABSENT, $result->reasons);
    }

    public function test_evaluate_for_reassignment_conflicting_candidate_returns_schedule_conflict(): void
    {
        $mission   = $this->makeMission(status: MissionStatus::ASSIGNED);
        $candidate = $this->makeUser();

        $this->setupReassignmentQueries(absenceCount: 0, conflictCount: 1);

        $result = $this->service->evaluateForReassignment($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::SCHEDULE_CONFLICT, $result->reasons);
    }

    public function test_evaluate_for_reassignment_does_not_flag_already_assigned_or_incompatible_status(): void
    {
        $existing  = $this->makeUser();
        $mission   = $this->makeMission(status: MissionStatus::ASSIGNED, instrumentist: $existing);
        $candidate = $this->makeUser();

        $this->setupReassignmentQueries(absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluateForReassignment($mission, $candidate);

        $this->assertTrue($result->eligible, 'reassignment onto a non-OPEN, already-assigned mission is the normal case');
        $this->assertNotContains(EligibilityReason::ALREADY_ASSIGNED, $result->reasons);
        $this->assertNotContains(EligibilityReason::INCOMPATIBLE_STATUS, $result->reasons);
    }

    /**
     * Explicit regression: confirms the deliberate scope boundary decided for this lot —
     * a manager assign/reassign is never blocked for lack of site affiliation, even when
     * the candidate genuinely has none (unlike evaluate()/claim(), see the method's own
     * docblock). This is a documented, intentional carve-out — not an oversight.
     */
    public function test_evaluate_for_reassignment_never_checks_site_membership(): void
    {
        $mission   = $this->makeMission(status: MissionStatus::ASSIGNED);
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::EMPLOYEE);

        // No SiteMembership query configured at all — if evaluateForReassignment() ever
        // queried it, the unconfigured mock would return null, and (int) null === 0 would
        // silently look like "eligible" here too, defeating the point of this test. Assert
        // directly on the query count instead: exactly 2 (absence, conflict), never 3.
        $queryCount = 0;
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use (&$queryCount): Query {
                $queryCount++;
                self::assertStringNotContainsString('SiteMembership', $dql, 'evaluateForReassignment() must never query SiteMembership');
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getSingleScalarResult')->willReturn(0);
                return $q;
            });

        $result = $this->service->evaluateForReassignment($mission, $candidate);

        $this->assertTrue($result->eligible);
        $this->assertSame(2, $queryCount, 'evaluateForReassignment() must run exactly 2 queries (absence, conflict) — never a site-membership check');
    }

    public function test_evaluate_for_reassignment_inactive_candidate_returns_inactive_reason(): void
    {
        $mission   = $this->makeMission(status: MissionStatus::ASSIGNED);
        $candidate = $this->makeUser(active: false);

        $this->setupReassignmentQueries(absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluateForReassignment($mission, $candidate);

        $this->assertFalse($result->eligible);
        $this->assertContains(EligibilityReason::INACTIVE, $result->reasons);
    }

    /** Adjacent slots (end-exclusive) must never be reported as a conflict. */
    public function test_evaluate_for_reassignment_adjacent_slots_are_not_a_conflict(): void
    {
        $mission   = $this->makeMission(status: MissionStatus::ASSIGNED);
        $candidate = $this->makeUser();

        // conflictCount=0 simulates the real DQL (m.startAt < :endAt AND m.endAt > :startAt)
        // correctly excluding a mission ending exactly when this one starts (08:00–10:00 vs
        // 10:00–12:00) — the query itself is exercised end-to-end in the functional test.
        $this->setupReassignmentQueries(absenceCount: 0, conflictCount: 0);

        $result = $this->service->evaluateForReassignment($mission, $candidate);

        $this->assertTrue($result->eligible);
    }

    public function test_evaluate_for_reassignment_excludes_own_mission_id_by_default(): void
    {
        $instrumentist = $this->makeUser();
        $mission       = $this->makeMission(status: MissionStatus::ASSIGNED, instrumentist: $instrumentist);

        $excludedId = null;
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use (&$excludedId, $mission): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnCallback(function (string $name, $value) use (&$excludedId, $q) {
                    if ($name === 'missionId') {
                        $excludedId = $value;
                    }
                    return $q;
                });
                $q->method('getSingleScalarResult')->willReturn(0);
                return $q;
            });

        $this->service->evaluateForReassignment($mission, $instrumentist);

        $this->assertSame($mission->getId(), $excludedId, 'a mission must never conflict against itself');
    }

    // ── EligibilityResult::selectableUnder() — D-102 (Lot 2) ────────────────────

    public function test_selectable_under_strict_assignment_blocks_absent(): void
    {
        $result = new \App\Dto\EligibilityResult($this->makeUser(), [EligibilityReason::ABSENT]);

        $this->assertFalse($result->selectableUnder(\App\Enum\EligibilityEnforcementPolicy::STRICT_ASSIGNMENT));
    }

    public function test_selectable_under_planning_modification_blocks_absent(): void
    {
        $result = new \App\Dto\EligibilityResult($this->makeUser(), [EligibilityReason::ABSENT]);

        $this->assertFalse($result->selectableUnder(\App\Enum\EligibilityEnforcementPolicy::PLANNING_MODIFICATION));
    }

    public function test_selectable_under_planning_modification_blocks_inactive(): void
    {
        $result = new \App\Dto\EligibilityResult($this->makeUser(), [EligibilityReason::INACTIVE]);

        $this->assertFalse($result->selectableUnder(\App\Enum\EligibilityEnforcementPolicy::PLANNING_MODIFICATION));
    }

    /**
     * D-101/D-102 — the whole point of the PLANNING_MODIFICATION policy: a
     * SCHEDULE_CONFLICT-only candidate stays selectable (D-091/D-052), even though the
     * raw $eligible fact is false.
     */
    public function test_selectable_under_planning_modification_allows_schedule_conflict_only(): void
    {
        $result = new \App\Dto\EligibilityResult($this->makeUser(), [EligibilityReason::SCHEDULE_CONFLICT]);

        $this->assertFalse($result->eligible, 'raw eligibility is still false — reasons[] is never hidden');
        $this->assertTrue($result->selectableUnder(\App\Enum\EligibilityEnforcementPolicy::PLANNING_MODIFICATION));
    }

    public function test_selectable_under_strict_assignment_blocks_schedule_conflict(): void
    {
        $result = new \App\Dto\EligibilityResult($this->makeUser(), [EligibilityReason::SCHEDULE_CONFLICT]);

        $this->assertFalse($result->selectableUnder(\App\Enum\EligibilityEnforcementPolicy::STRICT_ASSIGNMENT));
    }

    public function test_selectable_true_when_no_reasons(): void
    {
        $result = new \App\Dto\EligibilityResult($this->makeUser(), []);

        $this->assertTrue($result->selectableUnder(\App\Enum\EligibilityEnforcementPolicy::STRICT_ASSIGNMENT));
        $this->assertTrue($result->selectableUnder(\App\Enum\EligibilityEnforcementPolicy::PLANNING_MODIFICATION));
    }

    // ── serializeCandidate() — D-102 (Lot 2) ─────────────────────────────────────

    public function test_serialize_candidate_includes_unavailability_detail_for_absent(): void
    {
        $user = $this->makeUser();
        $user->setFirstname('Sophie');
        $user->setLastname('Collette');
        $absence = $this->makeAbsence($user, '2026-08-01', '2026-08-16');
        $result  = new \App\Dto\EligibilityResult($user, [EligibilityReason::ABSENT], $absence, null);

        $entry = $this->service->serializeCandidate($result, \App\Enum\EligibilityEnforcementPolicy::STRICT_ASSIGNMENT);

        $this->assertSame('Sophie Collette', $entry['name']);
        $this->assertFalse($entry['eligible']);
        $this->assertFalse($entry['selectable']);
        $this->assertSame(['ABSENT'], $entry['reasons']);
        $this->assertSame(['type' => 'ABSENCE', 'dateStart' => '2026-08-01', 'dateEnd' => '2026-08-16'], $entry['unavailability']);
        $this->assertNull($entry['conflict']);
    }

    public function test_serialize_candidate_schedule_conflict_selectable_under_planning_modification(): void
    {
        $user     = $this->makeUser();
        $conflict = $this->makeConflictMission($user);
        $result   = new \App\Dto\EligibilityResult($user, [EligibilityReason::SCHEDULE_CONFLICT], null, $conflict);

        $entry = $this->service->serializeCandidate($result, \App\Enum\EligibilityEnforcementPolicy::PLANNING_MODIFICATION);

        $this->assertFalse($entry['eligible']);
        $this->assertTrue($entry['selectable'], 'PLANNING_MODIFICATION: SCHEDULE_CONFLICT never blocks');
        $this->assertSame(['SCHEDULE_CONFLICT'], $entry['reasons']);
        $this->assertNotNull($entry['conflict']);
        $this->assertSame($conflict->getId(), $entry['conflict']['missionId']);
    }

    public function test_serialize_candidate_eligible_candidate_has_no_detail(): void
    {
        $result = new \App\Dto\EligibilityResult($this->makeUser(), []);

        $entry = $this->service->serializeCandidate($result, \App\Enum\EligibilityEnforcementPolicy::STRICT_ASSIGNMENT);

        $this->assertTrue($entry['eligible']);
        $this->assertTrue($entry['selectable']);
        $this->assertSame([], $entry['reasons']);
        $this->assertNull($entry['unavailability']);
        $this->assertNull($entry['conflict']);
    }

    // ── evaluateRoster() — D-102 (Lot 2), Preview Editor missionless slots ───────

    /**
     * evaluateRoster()'s Q2 (membership) is conditional — skipped entirely when every
     * candidate is FREELANCER — so mapping by call-count would misalign; branch on DQL
     * content instead (same pattern already used across this file's other query-content
     * mocks and the PlanningGeneratorServiceV2 test suites).
     */
    private function setupRosterQueries(array $roster, array $absenceRows, array $conflictRows, array $membershipRows = []): void
    {
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use ($roster, $absenceRows, $conflictRows, $membershipRows): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturn(match (true) {
                    str_contains($dql, 'FROM App\Entity\User u') => $roster,
                    str_contains($dql, 'SiteMembership sm') => $membershipRows,
                    str_contains($dql, 'FROM App\Entity\Absence a') => $absenceRows,
                    str_contains($dql, 'FROM App\Entity\Mission m') => $conflictRows,
                    default => [],
                });
                return $q;
            });
    }

    public function test_evaluate_roster_absent_candidate_is_flagged(): void
    {
        $site      = $this->makeSite();
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::FREELANCER); // skip membership query content
        $absence   = $this->makeAbsence($candidate, '2026-08-01', '2026-08-16');

        $this->setupRosterQueries([$candidate], [$absence], []);

        $results = $this->service->evaluateRoster(
            $site,
            new \DateTimeImmutable('2026-08-14 08:00:00'),
            new \DateTimeImmutable('2026-08-14 18:00:00'),
        );

        $this->assertCount(1, $results);
        $this->assertContains(EligibilityReason::ABSENT, $results[0]->reasons);
    }

    public function test_evaluate_roster_non_freelancer_without_membership_flagged_no_site_membership(): void
    {
        $site      = $this->makeSite();
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::EMPLOYEE);

        $this->setupRosterQueries([$candidate], [], [], []);

        $results = $this->service->evaluateRoster(
            $site,
            new \DateTimeImmutable('2026-08-14 08:00:00'),
            new \DateTimeImmutable('2026-08-14 18:00:00'),
        );

        $this->assertCount(1, $results);
        $this->assertContains(EligibilityReason::NO_SITE_MEMBERSHIP, $results[0]->reasons);
    }

    public function test_evaluate_roster_freelancer_bypasses_site_membership(): void
    {
        $site      = $this->makeSite();
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::FREELANCER);

        $this->setupRosterQueries([$candidate], [], []);

        $results = $this->service->evaluateRoster(
            $site,
            new \DateTimeImmutable('2026-08-14 08:00:00'),
            new \DateTimeImmutable('2026-08-14 18:00:00'),
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->eligible);
        $this->assertNotContains(EligibilityReason::NO_SITE_MEMBERSHIP, $results[0]->reasons);
    }

    public function test_evaluate_roster_no_site_given_skips_membership_check(): void
    {
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::EMPLOYEE);

        $this->setupRosterQueries([$candidate], [], []);

        $results = $this->service->evaluateRoster(
            null,
            new \DateTimeImmutable('2026-08-14 08:00:00'),
            new \DateTimeImmutable('2026-08-14 18:00:00'),
        );

        $this->assertTrue($results[0]->eligible);
    }

    public function test_evaluate_roster_conflicting_candidate_is_flagged(): void
    {
        $site      = $this->makeSite();
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::FREELANCER);
        $conflict  = $this->makeConflictMission($candidate);

        $this->setupRosterQueries([$candidate], [], [$conflict]);

        $results = $this->service->evaluateRoster(
            $site,
            new \DateTimeImmutable('2026-07-15 08:00:00'),
            new \DateTimeImmutable('2026-07-15 13:00:00'),
        );

        $this->assertContains(EligibilityReason::SCHEDULE_CONFLICT, $results[0]->reasons);
    }

    public function test_evaluate_roster_excludes_own_mission_id(): void
    {
        $site      = $this->makeSite();
        $candidate = $this->makeUser();
        $candidate->setEmploymentType(\App\Enum\EmploymentType::FREELANCER);

        $excludedId = null;
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql) use (&$excludedId, $candidate): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnCallback(function (string $name, $value) use (&$excludedId, $q) {
                    if ($name === 'missionId') {
                        $excludedId = $value;
                    }
                    return $q;
                });
                $q->method('getResult')->willReturnCallback(fn () => str_contains($dql, 'App\Entity\User u') ? [$candidate] : []);
                return $q;
            });

        $this->service->evaluateRoster(
            $site,
            new \DateTimeImmutable('2026-08-14 08:00:00'),
            new \DateTimeImmutable('2026-08-14 18:00:00'),
            42,
        );

        $this->assertSame(42, $excludedId);
    }
}
