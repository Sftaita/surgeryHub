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
}
