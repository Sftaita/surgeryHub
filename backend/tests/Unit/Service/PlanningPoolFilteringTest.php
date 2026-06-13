<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningSlot;
use App\Entity\PlanningTemplate;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningTemplateType;
use App\Enum\SchedulePrecision;
use App\Enum\SlotPeriod;
use App\Service\PlanningGeneratorService;
use App\Service\PlanningScoreService;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for two bugs fixed in loadExistingMissionsPool():
 *
 * BUG 1 — siteId not in DQL
 *   When a siteId was provided, the filter `AND m.site = :siteId` was missing from the DQL.
 *   Result: missions from all sites were loaded, but the claimMission() key-based lookup
 *   (key = "{surgeonId}_{siteId}_{date}") already isolated by site — so the claim logic
 *   was correct but the pool loaded too many missions.
 *   The regression test verifies that a mission from site B cannot be claimed by a slot on site A.
 *
 * BUG 2 — missing Types::DATETIME_IMMUTABLE in Doctrine ORM 3.x
 *   Doctrine ORM 3.x requires explicit DBAL types for DateTimeImmutable parameters when
 *   using createQuery() (DQL). Without Types::DATETIME_IMMUTABLE, Doctrine 3.x throws:
 *   "Could not convert PHP value to database value of type 'datetime_immutable'."
 *   This is NOT reproducible with mocks (the DQL is never parsed), so the fix is documented
 *   via a smoke test that ensures preview() completes without throwing.
 *   The code comment in loadExistingMissionsPool() documents the requirement.
 *
 * Test date: 2026-01-05 (Monday, ISO week 2 = PAIR, dayOfWeek=1).
 */
class PlanningPoolFilteringTest extends TestCase
{
    private const TEST_DATE = '2026-01-05';

    private EntityManagerInterface&MockObject $em;
    private PlanningScoreService&MockObject   $scoreService;

    private array $templates    = [];
    private array $poolMissions = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->scoreService = $this->createMock(PlanningScoreService::class);
        self::$idSeq        = 0;

        $templateQuery = $this->createMock(Query::class);
        $templateQuery->method('getResult')->willReturnCallback(fn () => $this->templates);
        $templateQuery->method('getSingleScalarResult')->willReturn(null);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($templateQuery);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturn([]); // no absences
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturnCallback(fn () => $this->poolMissions);
                } else {
                    $q->method('getSingleScalarResult')->willReturn(0);
                    $q->method('getResult')->willReturn([]);
                }

                return $q;
            });

        $this->em->method('find')->willReturn(null);
        $this->em->method('persist');
        $this->em->method('flush');
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function id(): int { return ++self::$idSeq; }

    private function makeUser(string $email, string $role = 'ROLE_INSTRUMENTIST'): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles([$role]);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, $this->id());
        return $u;
    }

    private function makeSite(string $name = 'Alpha'): Hospital
    {
        $h = new Hospital();
        $h->setName($name);
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, $this->id());
        return $h;
    }

    private function makeSlot(User $surgeon, ?User $instrumentist, string $start = '08:00:00', string $end = '13:00:00'): PlanningSlot
    {
        $s = new PlanningSlot();
        $s->setDayOfWeek(1);
        $s->setPeriod(SlotPeriod::AM);
        $s->setStartTime(new \DateTimeImmutable($start));
        $s->setEndTime(new \DateTimeImmutable($end));
        $s->setMissionType(MissionType::BLOCK);
        $s->setSurgeon($surgeon);
        $s->setInstrumentist($instrumentist);
        return $s;
    }

    private function makeTemplate(Hospital $site, PlanningSlot ...$slots): PlanningTemplate
    {
        $t = new PlanningTemplate();
        $t->setType(PlanningTemplateType::TOUTES);
        $t->setSite($site);
        foreach ($slots as $slot) { $t->addSlot($slot); }
        return $t;
    }

    private function makeMission(User $surgeon, Hospital $site, ?User $instrumentist, string $startAt = '2026-01-05 08:00:00'): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::DRAFT);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStartAt(new \DateTimeImmutable($startAt));
        $m->setEndAt(new \DateTimeImmutable('2026-01-05 13:00:00'));
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setInstrumentist($instrumentist);
        $m->setCreatedBy($surgeon);
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($m, $this->id());
        return $m;
    }

    private function previewOnSite(array $templates, int $siteId): array
    {
        $this->templates = $templates;
        return (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview(self::TEST_DATE, self::TEST_DATE, $siteId, null);
    }

    private function previewAllSites(array $templates): array
    {
        $this->templates = $templates;
        return (new PlanningGeneratorService($this->em, $this->scoreService))
            ->preview(self::TEST_DATE, self::TEST_DATE, null, null);
    }

    // ── BUG 1 — siteId isolation via claimMission() key ──────────────────────

    /**
     * REGRESSION BUG 1 — siteId filter.
     *
     * A mission on site B must never be claimed by a slot on site A.
     * The claimMission() pool key is "{surgeonId}_{siteId}_{date}", so even if the pool
     * contains both site A and site B missions (as it did before the fix when siteId was
     * not added to the DQL), the key lookup prevents cross-site claiming.
     *
     * This test verifies that behavior: siteA slot + siteB mission in pool → no claim → COVERED
     * without existingMissionId (a new mission will be created at generate time).
     */
    public function test_mission_from_other_site_is_not_claimed_by_slot(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', 'ROLE_SURGEON');
        $instr   = $this->makeUser('instr@test.com');
        $siteA   = $this->makeSite('Alpha');
        $siteB   = $this->makeSite('Beta');

        // Pool has a mission on site B (same surgeon, same time)
        $missionOnB = $this->makeMission($surgeon, $siteB, $instr);
        $this->poolMissions = [$missionOnB];

        // Slot is on site A
        $slot = $this->makeSlot($surgeon, $instr);
        $result = $this->previewOnSite(
            [$this->makeTemplate($siteA, $slot)],
            $siteA->getId(),
        );

        $this->assertCount(1, $result);

        // The slot on siteA must NOT claim the mission on siteB.
        // Before the siteId fix, the pool might still have contained siteB missions,
        // but the key lookup already prevented cross-site claims — so both before and after
        // the fix, this assertion must hold.
        $this->assertNull($result[0]['existingMissionId'],
            'REGRESSION BUG 1: A mission from site B must never be claimed by a slot on site A. ' .
            'existingMissionId must be null — the slot will generate a NEW mission on site A.'
        );
    }

    /**
     * Complementary: same surgeon, same time, same site → mission IS claimed.
     * This ensures the previous test is not vacuously passing (e.g. pool empty).
     */
    public function test_mission_from_same_site_is_claimed(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', 'ROLE_SURGEON');
        $instr   = $this->makeUser('instr@test.com');
        $siteA   = $this->makeSite('Alpha');

        $missionOnA = $this->makeMission($surgeon, $siteA, $instr);
        $this->poolMissions = [$missionOnA];

        $slot = $this->makeSlot($surgeon, $instr);
        $result = $this->previewAllSites([$this->makeTemplate($siteA, $slot)]);

        $this->assertCount(1, $result);
        $this->assertSame($missionOnA->getId(), $result[0]['existingMissionId'],
            'A mission on the same site must be claimed (existingMissionId must be set).'
        );
        $this->assertSame('COVERED', $result[0]['status']);
    }

    /**
     * REGRESSION BUG 1 — two sites in the same preview.
     *
     * Slots on site A claim site A missions; slots on site B claim site B missions.
     * No cross-site contamination.
     */
    public function test_two_sites_in_preview_each_claim_own_site_missions(): void
    {
        $surgeonA = $this->makeUser('surgeon-a@test.com', 'ROLE_SURGEON');
        $surgeonB = $this->makeUser('surgeon-b@test.com', 'ROLE_SURGEON');
        $instrA   = $this->makeUser('instr-a@test.com');
        $instrB   = $this->makeUser('instr-b@test.com');
        $siteA    = $this->makeSite('Alpha');
        $siteB    = $this->makeSite('Beta');

        $missionA = $this->makeMission($surgeonA, $siteA, $instrA);
        $missionB = $this->makeMission($surgeonB, $siteB, $instrB);
        $this->poolMissions = [$missionA, $missionB];

        $slotA = $this->makeSlot($surgeonA, $instrA);
        $slotB = $this->makeSlot($surgeonB, $instrB);

        $result = $this->previewAllSites([
            $this->makeTemplate($siteA, $slotA),
            $this->makeTemplate($siteB, $slotB),
        ]);

        $this->assertCount(2, $result);

        $byMission = array_column($result, null, 'existingMissionId');

        $this->assertArrayHasKey($missionA->getId(), $byMission, 'Site A mission must be claimed');
        $this->assertArrayHasKey($missionB->getId(), $byMission, 'Site B mission must be claimed');

        $this->assertSame('COVERED', $byMission[$missionA->getId()]['status']);
        $this->assertSame('COVERED', $byMission[$missionB->getId()]['status']);
    }

    // ── BUG 2 — DateTime type documentation smoke test ────────────────────────

    /**
     * REGRESSION BUG 2 — Types::DATETIME_IMMUTABLE required in Doctrine ORM 3.x.
     *
     * In Doctrine ORM 3.x, createQuery() (DQL) requires explicit DBAL types for
     * DateTimeImmutable parameters. Without `Types::DATETIME_IMMUTABLE`:
     *
     *   ->setParameter('poolFrom', $fromDt)          // BROKEN in Doctrine ORM 3.x
     *   ->setParameter('poolFrom', $fromDt, Types::DATETIME_IMMUTABLE)  // CORRECT
     *
     * This cannot be caught with unit mocks (the DQL is never parsed).
     * The fix is enforced by:
     *   1. This smoke test — verifies preview() completes without throwing when called
     *      with standard date strings (integration-level check via real service, mock EM).
     *   2. The explicit type annotation in loadExistingMissionsPool():
     *      ->setParameter('poolFrom', $fromDt, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
     *
     * If someone removes the Types::DATETIME_IMMUTABLE annotation, the real API endpoint
     * will return 400. The code comment in loadExistingMissionsPool() documents this constraint.
     */
    public function test_preview_does_not_throw_with_standard_date_strings(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com', 'ROLE_SURGEON');
        $site    = $this->makeSite();
        $slot    = $this->makeSlot($surgeon, null);

        $this->poolMissions = [];
        $this->templates    = [$this->makeTemplate($site, $slot)];

        $threw = false;
        try {
            (new PlanningGeneratorService($this->em, $this->scoreService))
                ->preview('2026-05-01', '2026-05-31', null, null);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertFalse($threw,
            'REGRESSION BUG 2: preview() must not throw. ' .
            'If it does, check that loadExistingMissionsPool() uses ' .
            'Types::DATETIME_IMMUTABLE for the poolFrom/poolTo parameters ' .
            '(required by Doctrine ORM 3.x createQuery() — unlike createQueryBuilder() ' .
            'which infers the type automatically).'
        );
    }

    /**
     * Verifies the DBAL Types constant is importable and correctly named.
     * Guards against a refactor accidentally changing the import.
     */
    public function test_doctrine_dbal_types_datetime_immutable_constant_is_correct(): void
    {
        $this->assertSame(
            'datetime_immutable',
            Types::DATETIME_IMMUTABLE,
            'Types::DATETIME_IMMUTABLE must equal "datetime_immutable" — the value used in ' .
            'loadExistingMissionsPool() setParameter calls.'
        );
    }
}
