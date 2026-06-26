<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\RecurrenceRule;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Enum\RecurrenceFrequency;
use App\Enum\SchedulePrecision;
use App\Enum\ShiftPeriod;
use App\Service\PlanningGeneratorServiceV2;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests PlanningGeneratorServiceV2::generate() — PlanningVersion creation and Mission
 * compatibility. PlanningVersion and Mission are reused from the existing planning
 * module completely unchanged; this test proves V2-generated missions are
 * indistinguishable, structurally, from V1-generated ones.
 */
class PlanningGeneratorServiceV2GenerateTest extends TestCase
{
    private const MONTH = '2026-01';

    private EntityManagerInterface&MockObject $em;

    private array $posts            = [];
    private array $absenceRows      = [];
    private array $existingMissions = [];
    private array $shiftConfigRows  = [];
    private array $persisted        = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em               = $this->createMock(EntityManagerInterface::class);
        $this->posts             = [];
        $this->absenceRows       = [];
        $this->existingMissions  = [];
        $this->shiftConfigRows   = [];
        $this->persisted         = [];

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $versionNumberQuery = $this->createMock(Query::class);
        $versionNumberQuery->method('getSingleScalarResult')->willReturn(null);
        $qb->method('getQuery')->willReturn($versionNumberQuery);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();

                if (str_contains($dql, 'postsFrom')) {
                    $q->method('getResult')->willReturnCallback(fn () => $this->posts);
                } elseif (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->absenceRows);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturnCallback(fn () => $this->existingMissions);
                } elseif (str_contains($dql, 'shiftConfigSites')) {
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->shiftConfigRows);
                } elseif (str_contains($dql, 'exceptionPostIds')) {
                    $q->method('getResult')->willReturn([]);
                }

                return $q;
            });

        $this->em->method('persist')
            ->willReturnCallback(function (object $entity): void {
                $this->persisted[] = $entity;
            });
        $this->em->method('flush');
    }

    // ── Factories ────────────────────────────────────────────────────────────

    private function makeService(): PlanningGeneratorServiceV2
    {
        return new PlanningGeneratorServiceV2($this->em);
    }

    private function makeSite(string $name = 'Alpha'): Hospital
    {
        $h = new Hospital();
        $h->setName($name);
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$idSeq);
        return $h;
    }

    private function makeUser(string $email = 'user@test.com'): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_MANAGER']);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        return $u;
    }

    private function makeRecurrence(int $interval, array $weekdays, \DateTimeImmutable $anchorDate): RecurrenceRule
    {
        $r = new RecurrenceRule();
        $r->setFrequency(RecurrenceFrequency::WEEKLY);
        $r->setInterval($interval);
        $r->setWeekdays($weekdays);
        $r->setAnchorDate($anchorDate);
        return $r;
    }

    private function makeMonthlyRecurrence(array $weekdays, array $monthWeeks, \DateTimeImmutable $anchorDate): RecurrenceRule
    {
        $r = new RecurrenceRule();
        $r->setFrequency(RecurrenceFrequency::MONTHLY);
        $r->setInterval(1);
        $r->setWeekdays($weekdays);
        $r->setMonthWeeks($monthWeeks);
        $r->setAnchorDate($anchorDate);
        return $r;
    }

    private function makePost(User $surgeon, Hospital $site, RecurrenceRule $recurrence, ?User $instrumentist, string $startDate, string $endDate): SurgeonSchedulePost
    {
        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType(MissionType::BLOCK);
        $p->setPeriod(ShiftPeriod::MATIN);
        $p->setRecurrence($recurrence);
        $p->setInstrumentist($instrumentist);
        $p->setStartDate(new \DateTimeImmutable($startDate));
        $p->setEndDate(new \DateTimeImmutable($endDate));
        $p->setCreatedBy($surgeon);
        $rp = new \ReflectionProperty(SurgeonSchedulePost::class, 'id');
        $rp->setValue($p, ++self::$idSeq);
        return $p;
    }

    private function addShiftConfig(Hospital $site, string $start, string $end): void
    {
        $this->shiftConfigRows[] = [
            'siteId'    => $site->getId(),
            'period'    => ShiftPeriod::MATIN->value,
            'startTime' => new \DateTimeImmutable($start),
            'endTime'   => new \DateTimeImmutable($end),
        ];
    }

    private function withFind(Hospital $site, ?SurgeonSchedulePost $post, User $manager): void
    {
        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                Hospital::class            => $site,
                SurgeonSchedulePost::class => $post,
                User::class                => $this->makeUser((string) $id . '@test.com'),
                Mission::class             => null,
                default                    => null,
            });
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_generate_always_creates_a_planning_version(): void
    {
        $manager = $this->makeUser('manager@test.com');
        $site    = $this->makeSite();
        $this->withFind($site, null, $manager);

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager);

        $this->assertArrayHasKey('versionId', $result);

        $versions = array_filter($this->persisted, fn ($e) => $e instanceof PlanningVersion);
        $this->assertCount(1, $versions);

        /** @var PlanningVersion $version */
        $version = array_values($versions)[0];
        $this->assertSame(PlanningVersionStatus::DRAFT, $version->getStatus());
        $this->assertSame($manager, $version->getGeneratedBy());
    }

    public function test_generate_creates_missions_compatible_with_existing_mission_lifecycle(): void
    {
        $manager       = $this->makeUser('manager@test.com');
        $surgeon       = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('inst@test.com');
        $site          = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $recurrence = $this->makeRecurrence(1, [1], new \DateTimeImmutable('2026-01-05'));
        $post       = $this->makePost($surgeon, $site, $recurrence, $instrumentist, '2026-01-05', '2026-01-05');
        $this->posts = [$post];

        $this->withFind($site, $post, $manager);

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager);

        $this->assertSame(1, $result['created']);

        $missions = array_values(array_filter($this->persisted, fn ($e) => $e instanceof Mission));
        $this->assertCount(1, $missions);

        /** @var Mission $mission */
        $mission = $missions[0];
        $this->assertInstanceOf(Mission::class, $mission, 'V2 must create plain App\Entity\Mission rows — no new mission subtype');
        $this->assertSame(MissionStatus::DRAFT, $mission->getStatus());
        $this->assertSame(MissionType::BLOCK, $mission->getType());
        $this->assertSame(SchedulePrecision::EXACT, $mission->getSchedulePrecision());
        $this->assertSame($manager, $mission->getCreatedBy());
        $this->assertNotNull($mission->getPlanningVersion(), 'Mission must be linked to the V2-generated PlanningVersion exactly like V1');
        $this->assertSame('2026-01-05 08:00:00', $mission->getStartAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-05 13:00:00', $mission->getEndAt()->format('Y-m-d H:i:s'));
    }

    public function test_generate_skipped_lines_do_not_create_missions(): void
    {
        $manager = $this->makeUser('manager@test.com');
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');
        $this->absenceRows[] = ['userId' => $surgeon->getId(), 'dateStart' => '2026-01-05', 'dateEnd' => '2026-01-05'];

        $recurrence = $this->makeRecurrence(1, [1], new \DateTimeImmutable('2026-01-05'));
        $post       = $this->makePost($surgeon, $site, $recurrence, null, '2026-01-05', '2026-01-05');
        $this->posts = [$post];

        $this->withFind($site, $post, $manager);

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['created']);
        $this->assertCount(0, array_filter($this->persisted, fn ($e) => $e instanceof Mission));
    }

    // ── Batch 14B: generate() for a MONTHLY (nth-weekday) post ───────────────

    public function test_generate_creates_correct_missions_for_a_monthly_nth_weekday_post(): void
    {
        $manager       = $this->makeUser('manager@test.com');
        $surgeon       = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('inst@test.com');
        $site          = $this->makeSite();
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        // Jan 2026 2nd+3rd Thursday = Jan 8 and Jan 15.
        $recurrence = $this->makeMonthlyRecurrence([4], [2, 3], new \DateTimeImmutable('2026-01-01'));
        $post       = $this->makePost($surgeon, $site, $recurrence, $instrumentist, '2026-01-01', '2026-01-31');
        $this->posts = [$post];

        $this->withFind($site, $post, $manager);

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager);

        $this->assertSame(2, $result['created']);

        $missions = array_values(array_filter($this->persisted, fn ($e) => $e instanceof Mission));
        $this->assertCount(2, $missions);

        $dates = array_map(fn (Mission $m) => $m->getStartAt()->format('Y-m-d'), $missions);
        sort($dates);
        $this->assertSame(['2026-01-08', '2026-01-15'], $dates);
    }
}
