<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\RecurrenceRule;
use App\Entity\SurgeonSchedulePost;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Service\PlanningGeneratorServiceV2;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Batch 14.5 — previewVersion hash and generate() override-lines behaviour.
 *
 * computePreviewVersion() contract:
 *   - Returns a 64-character lowercase hex string (SHA-256)
 *   - Deterministic: same inputs → same hash
 *   - Sensitive: different posts/absences/shifts → different hash
 *
 * generate() with $overrideLines:
 *   - Skips internal preview() call (no postsFrom/poolFrom queries)
 *   - SKIPPED status lines → $skipped++, no Mission persisted
 *   - Line with existingMissionId whose Mission is DRAFT → $updated++, instrumentist synced (R-01 preserved)
 *   - Line with existingMissionId whose Mission is non-DRAFT → $skipped++ (R-01 enforced)
 *   - Line without existingMissionId → new Mission created (same path as standard mode)
 */
final class PlanningGeneratorServiceV2PreviewVersionTest extends TestCase
{
    private const MONTH = '2026-01';
    private const SITE_ID = 42;

    private EntityManagerInterface&MockObject $em;

    private array $posts            = [];
    private array $absenceRows      = [];
    private array $shiftConfigRows  = [];
    private array $persisted        = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em               = $this->createMock(EntityManagerInterface::class);
        $this->posts            = [];
        $this->absenceRows      = [];
        $this->shiftConfigRows  = [];
        $this->persisted        = [];

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $versionNumberQuery = $this->createMock(Query::class);
        $versionNumberQuery->method('getSingleScalarResult')->willReturn(null);
        $versionNumberQuery->method('getOneOrNullResult')->willReturn(null);
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
                    $q->method('getResult')->willReturn([]);
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

    // ── Factories ─────────────────────────────────────────────────────────────

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

    private function makePost(User $surgeon, Hospital $site, RecurrenceRule $recurrence, string $startDate, string $endDate): SurgeonSchedulePost
    {
        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType(MissionType::BLOCK);
        $p->setPeriod(ShiftPeriod::MATIN);
        $p->setRecurrence($recurrence);
        $p->setStartDate(new \DateTimeImmutable($startDate));
        $p->setEndDate(new \DateTimeImmutable($endDate));
        $p->setCreatedBy($surgeon);
        $rp = new \ReflectionProperty(SurgeonSchedulePost::class, 'id');
        $rp->setValue($p, ++self::$idSeq);
        return $p;
    }

    private function makeDraftMission(Hospital $site, User $surgeon, ?User $instrumentist = null): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::DRAFT);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setInstrumentist($instrumentist);
        $m->setStartAt(new \DateTimeImmutable('2026-01-05 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-01-05 13:00:00'));
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($m, ++self::$idSeq);
        return $m;
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

    // ── computePreviewVersion tests ───────────────────────────────────────────

    public function test_computePreviewVersion_returns_64_char_hex_string(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $rec     = $this->makeRecurrence(1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $rec, '2026-01-01', '2026-01-31')];
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $version = $this->makeService()->computePreviewVersion(self::MONTH, $site->getId(), null);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $version);
    }

    public function test_computePreviewVersion_is_deterministic_for_same_inputs(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $rec     = $this->makeRecurrence(1, [2, 4], new \DateTimeImmutable('2026-01-06'));
        $this->posts = [$this->makePost($surgeon, $site, $rec, '2026-01-01', '2026-01-31')];
        $this->absenceRows = [['userId' => $surgeon->getId(), 'dateStart' => '2026-01-06', 'dateEnd' => '2026-01-06']];
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $svc = $this->makeService();
        $v1  = $svc->computePreviewVersion(self::MONTH, $site->getId(), null);
        $v2  = $svc->computePreviewVersion(self::MONTH, $site->getId(), null);

        $this->assertSame($v1, $v2);
    }

    public function test_computePreviewVersion_changes_when_a_post_is_added(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $rec     = $this->makeRecurrence(1, [1], new \DateTimeImmutable('2026-01-05'));
        $post1   = $this->makePost($surgeon, $site, $rec, '2026-01-01', '2026-01-31');
        $this->posts = [$post1];
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $svc = $this->makeService();
        $v1  = $svc->computePreviewVersion(self::MONTH, $site->getId(), null);

        $post2 = $this->makePost($surgeon, $site, $this->makeRecurrence(1, [3], new \DateTimeImmutable('2026-01-07')), '2026-01-01', '2026-01-31');
        $this->posts = [$post1, $post2];

        $v2 = $svc->computePreviewVersion(self::MONTH, $site->getId(), null);

        $this->assertNotSame($v1, $v2);
    }

    public function test_computePreviewVersion_changes_when_an_absence_is_added(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $rec     = $this->makeRecurrence(1, [1], new \DateTimeImmutable('2026-01-05'));
        $this->posts = [$this->makePost($surgeon, $site, $rec, '2026-01-01', '2026-01-31')];
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $svc = $this->makeService();
        $v1  = $svc->computePreviewVersion(self::MONTH, $site->getId(), null);

        $this->absenceRows = [['userId' => $surgeon->getId(), 'dateStart' => '2026-01-05', 'dateEnd' => '2026-01-05']];
        $v2 = $svc->computePreviewVersion(self::MONTH, $site->getId(), null);

        $this->assertNotSame($v1, $v2);
    }

    // ── generate() with overrideLines tests ───────────────────────────────────

    public function test_generate_with_override_lines_skips_skipped_status(): void
    {
        $manager = $this->makeUser('manager@test.com');
        $site    = $this->makeSite();

        $this->em->method('find')->willReturn(null);

        $overrideLines = [
            [
                'status'           => 'SKIPPED',
                'existingMissionId' => null,
                'postId'           => 99,
                'surgeonId'        => 1,
                'siteId'           => $site->getId(),
                'instrumentistId'  => null,
                'date'             => '2026-01-05',
                'startTime'        => '08:00',
                'endTime'          => '13:00',
            ],
        ];

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager, $overrideLines);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertCount(0, array_filter($this->persisted, fn ($e) => $e instanceof Mission));
    }

    public function test_generate_with_override_lines_updates_draft_mission_and_syncs_instrumentist(): void
    {
        $manager       = $this->makeUser('manager@test.com');
        $site          = $this->makeSite();
        $surgeon       = $this->makeUser('surgeon@test.com');
        $instrumentist = $this->makeUser('inst@test.com');
        $mission       = $this->makeDraftMission($site, $surgeon);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                Mission::class => $mission,
                User::class    => $instrumentist,
                Hospital::class => $site,
                default        => null,
            });

        $overrideLines = [
            [
                'status'            => 'COVERED',
                'existingMissionId' => $mission->getId(),
                'instrumentistId'   => $instrumentist->getId(),
                'postId'            => 99,
                'surgeonId'         => $surgeon->getId(),
                'siteId'            => $site->getId(),
                'date'              => '2026-01-05',
                'startTime'         => '08:00',
                'endTime'           => '13:00',
            ],
        ];

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager, $overrideLines);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['created']);
        $this->assertSame($instrumentist, $mission->getInstrumentist(), 'Instrumentist must be synced in override mode');
        $this->assertNotNull($mission->getPlanningVersion(), 'Mission must be associated with the new PlanningVersion');
    }

    public function test_generate_with_override_lines_enforces_r01_skips_non_draft_mission(): void
    {
        $manager  = $this->makeUser('manager@test.com');
        $site     = $this->makeSite();
        $surgeon  = $this->makeUser('surgeon@test.com');
        $mission  = $this->makeDraftMission($site, $surgeon);
        $mission->setStatus(MissionStatus::OPEN);

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                Mission::class  => $mission,
                Hospital::class => $site,
                default         => null,
            });

        $overrideLines = [
            [
                'status'            => 'COVERED',
                'existingMissionId' => $mission->getId(),
                'instrumentistId'   => null,
                'postId'            => 99,
                'surgeonId'         => $surgeon->getId(),
                'siteId'            => $site->getId(),
                'date'              => '2026-01-05',
                'startTime'         => '08:00',
                'endTime'           => '13:00',
            ],
        ];

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager, $overrideLines);

        $this->assertSame(1, $result['skipped'], 'Non-DRAFT mission must be skipped (R-01)');
        $this->assertSame(0, $result['updated']);
    }

    public function test_generate_with_override_lines_creates_new_mission_for_lines_without_existing_id(): void
    {
        $manager  = $this->makeUser('manager@test.com');
        $surgeon  = $this->makeUser('surgeon@test.com');
        $site     = $this->makeSite();
        $post     = $this->makePost($surgeon, $site, $this->makeRecurrence(1, [1], new \DateTimeImmutable('2026-01-05')), '2026-01-01', '2026-01-31');
        $this->addShiftConfig($site, '08:00:00', '13:00:00');

        $this->em->method('find')
            ->willReturnCallback(fn ($class, $id) => match ($class) {
                Hospital::class            => $site,
                SurgeonSchedulePost::class => $post,
                User::class                => $surgeon,
                Mission::class             => null,
                default                    => null,
            });

        $overrideLines = [
            [
                'status'            => 'COVERED',
                'existingMissionId' => null,
                'instrumentistId'   => null,
                'postId'            => $post->getId(),
                'surgeonId'         => $surgeon->getId(),
                'siteId'            => $site->getId(),
                'siteName'          => 'Alpha',
                'date'              => '2026-01-05',
                'startTime'         => '08:00',
                'endTime'           => '13:00',
                'missionType'       => 'BLOCK',
                'freedFrom'         => false,
            ],
        ];

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager, $overrideLines);

        $this->assertSame(1, $result['created']);
        $missions = array_values(array_filter($this->persisted, fn ($e) => $e instanceof Mission));
        $this->assertCount(1, $missions);
        $this->assertSame(MissionStatus::DRAFT, $missions[0]->getStatus());
    }

    public function test_generate_without_override_lines_still_calls_preview_internally(): void
    {
        $manager = $this->makeUser('manager@test.com');
        $site    = $this->makeSite();

        $this->em->method('find')->willReturn(null);

        // No posts → preview() returns [] → generate creates nothing
        $this->posts = [];

        $result = $this->makeService()->generate(self::MONTH, $site->getId(), null, null, $manager, null);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
    }
}
