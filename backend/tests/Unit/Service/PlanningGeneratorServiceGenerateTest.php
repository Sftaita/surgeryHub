<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningSlot;
use App\Entity\PlanningTemplate;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningTemplateType;
use App\Enum\PlanningVersionStatus;
use App\Enum\SlotPeriod;
use App\Service\PlanningGeneratorService;
use App\Service\PlanningScoreService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests PlanningGeneratorService::generate() — PlanningVersion creation and mission linkage.
 *
 * Test date: 2026-01-05 (Monday, ISO week 2 = PAIR).
 */
class PlanningGeneratorServiceGenerateTest extends TestCase
{
    private const TEST_DATE = '2026-01-05';

    private EntityManagerInterface&MockObject $em;
    private PlanningScoreService&MockObject   $scoreService;

    private array $templates    = [];
    private array $absenceRows  = []; // [['userId' => int, 'dateStart' => str, 'dateEnd' => str]]

    /** Tracks every entity passed to em->persist() */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->scoreService = $this->createMock(PlanningScoreService::class);
        $this->persisted    = [];
        $this->absenceRows  = [];

        // ── QueryBuilder (for template list AND nextVersionNumber) ─────────────
        $templateQuery = $this->createMock(Query::class);
        $templateQuery->method('getResult')
                      ->willReturnCallback(fn () => $this->templates);
        $templateQuery->method('getSingleScalarResult')
                      ->willReturn(null); // no existing versions → version #1

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('leftJoin')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($templateQuery);

        $this->em->method('createQueryBuilder')->willReturn($qb);

        // ── createQuery: route by DQL content ─────────────────────────────────
        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): AbstractQuery {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('setMaxResults')->willReturnSelf();

                if (str_contains($dql, 'absencesFrom')) {
                    $q->method('getArrayResult')->willReturnCallback(fn () => $this->absenceRows);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturn([]); // no existing missions
                }
                // Conflict check is now in-memory — no createQuery call needed

                return $q;
            });

        // ── find(): return appropriate stubs ───────────────────────────────────
        $this->em->method('find')
            ->willReturnCallback(function (string $class, mixed $id): mixed {
                return match ($class) {
                    Hospital::class      => $this->makeSite(),
                    User::class          => $this->makeUser((string) $id . '@test.com'),
                    PlanningSlot::class  => null, // generate() will skip if slot not found
                    PlanningVersion::class => null,
                    default              => null,
                };
            });

        // ── persist(): capture what's persisted ───────────────────────────────
        $this->em->method('persist')
            ->willReturnCallback(function (object $entity): void {
                $this->persisted[] = $entity;
            });

        $this->em->method('flush');
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    private function makeService(): PlanningGeneratorService
    {
        return new PlanningGeneratorService($this->em, $this->scoreService);
    }

    private function makeSite(int $id = 1): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        // Force-set the ID via reflection (auto-generated in DB, not settable via setter)
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, $id);
        return $h;
    }

    private static int $userIdSeq = 0;

    private function makeUser(string $email = 'user@test.com'): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_MANAGER']);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$userIdSeq);
        return $u;
    }

    private function markAbsent(User $user): void
    {
        $this->absenceRows[] = [
            'userId'    => $user->getId(),
            'dateStart' => self::TEST_DATE,
            'dateEnd'   => self::TEST_DATE,
        ];
    }

    private function makeSlot(User $surgeon, ?User $instrumentist = null): PlanningSlot
    {
        $s = new PlanningSlot();
        $s->setDayOfWeek(1);
        $s->setPeriod(SlotPeriod::AM);
        $s->setStartTime(new \DateTimeImmutable('08:00:00'));
        $s->setEndTime(new \DateTimeImmutable('13:00:00'));
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
        foreach ($slots as $slot) {
            $t->addSlot($slot);
        }
        return $t;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_generate_always_creates_a_planning_version(): void
    {
        $this->templates = []; // No templates — no missions — but version must still be created

        $manager = $this->makeUser('manager@test.com');
        $result  = $this->makeService()->generate(self::TEST_DATE, self::TEST_DATE, null, null, $manager);

        $this->assertArrayHasKey('versionId', $result);

        $versions = array_filter($this->persisted, fn ($e) => $e instanceof PlanningVersion);
        $this->assertCount(1, $versions, 'One PlanningVersion must be persisted');

        /** @var PlanningVersion $version */
        $version = array_values($versions)[0];
        $this->assertSame(PlanningVersionStatus::DRAFT, $version->getStatus());
        $this->assertSame(1, $version->getVersionNumber());
        $this->assertSame($manager, $version->getGeneratedBy());
    }

    public function test_generate_returns_version_id_in_result(): void
    {
        $this->templates = [];

        $manager = $this->makeUser('manager@test.com');
        $result  = $this->makeService()->generate(self::TEST_DATE, self::TEST_DATE, null, null, $manager);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('versionId', $result);
        $this->assertArrayHasKey('created',   $result);
        $this->assertArrayHasKey('updated',   $result);
        $this->assertArrayHasKey('skipped',   $result);
    }

    public function test_generate_skipped_lines_do_not_create_missions(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $slot    = $this->makeSlot($surgeon);
        $this->templates     = [$this->makeTemplate($this->makeSite(), $slot)];
        $this->markAbsent($surgeon); // all slots SKIPPED

        $manager = $this->makeUser('manager@test.com');
        $result  = $this->makeService()->generate(self::TEST_DATE, self::TEST_DATE, null, null, $manager);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['created']);

        $missions = array_filter($this->persisted, fn ($e) => $e instanceof Mission);
        $this->assertCount(0, $missions, 'SKIPPED lines must not create missions');
    }

    public function test_generate_created_by_is_set_to_current_user(): void
    {
        // We need em->find(PlanningSlot::class) to return a valid slot for mission creation
        $surgeon     = $this->makeUser('surgeon@test.com');
        $slotEntity  = $this->makeSlot($surgeon);

        // Override find to return the slot
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->persisted = [];

        // Same QB/query setup
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
                if (str_contains($dql, 'Absence')) {
                    $q->method('getSingleScalarResult')->willReturn(0);
                } elseif (str_contains($dql, 'poolFrom')) {
                    $q->method('getResult')->willReturn([]);
                } else {
                    $q->method('getSingleScalarResult')->willReturn(0);
                }
                return $q;
            });

        $manager = $this->makeUser('manager@example.com');
        $site    = $this->makeSite();

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                Hospital::class     => $site,
                User::class         => $this->makeUser((string) $id . '@test.com'),
                PlanningSlot::class => $slotEntity,
                default             => null,
            });

        $this->em->method('persist')
            ->willReturnCallback(function (object $entity): void {
                $this->persisted[] = $entity;
            });
        $this->em->method('flush');

        $this->templates = [$this->makeTemplate($site, $slotEntity)];

        $result = (new PlanningGeneratorService($this->em, $this->scoreService))
            ->generate(self::TEST_DATE, self::TEST_DATE, null, null, $manager);

        $missions = array_filter($this->persisted, fn ($e) => $e instanceof Mission);
        $this->assertGreaterThanOrEqual(1, count($missions));

        foreach ($missions as $mission) {
            $this->assertSame($manager, $mission->getCreatedBy());
        }
    }
}
