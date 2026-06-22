<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningOccurrenceException;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\OccurrenceExceptionType;
use App\Enum\PlanningAlertType;
use App\Service\PlanningAlertService;
use App\Service\PlanningOccurrenceExceptionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlanningOccurrenceExceptionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PlanningAlertService&MockObject   $alertService;

    private array $existingExceptions = []; // keyed "postId_date"
    private array $missionsOnDay      = [];
    private array $persisted          = [];
    private array $removed            = [];
    private array $listResult         = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->alertService = $this->createMock(PlanningAlertService::class);
        $this->existingExceptions = [];
        $this->missionsOnDay      = [];
        $this->persisted          = [];
        $this->removed            = [];
        $this->listResult         = [];

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturnCallback(function (array $criteria) {
            $key = $criteria['post']->getId() . '_' . $criteria['occurrenceDate']->format('Y-m-d');
            return $this->existingExceptions[$key] ?? null;
        });
        $this->em->method('getRepository')->willReturn($repo);

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getResult')->willReturnCallback(fn () => $this->missionsOnDay);
                return $q;
            });

        $this->em->method('persist')->willReturnCallback(function (object $e): void {
            $this->persisted[] = $e;
        });
        $this->em->method('remove')->willReturnCallback(function (object $e): void {
            $this->removed[] = $e;
        });
        $this->em->method('flush');

        $listQuery = $this->createMock(Query::class);
        $listQuery->method('getResult')->willReturnCallback(fn () => $this->listResult);
        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($listQuery);
        $this->em->method('createQueryBuilder')->willReturn($qb);
    }

    private function makeService(): PlanningOccurrenceExceptionService
    {
        return new PlanningOccurrenceExceptionService($this->em, $this->alertService);
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$idSeq);
        return $h;
    }

    private function makeUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        return $u;
    }

    private function makePost(User $surgeon, Hospital $site): SurgeonSchedulePost
    {
        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType(MissionType::BLOCK);
        $p->setStartDate(new \DateTimeImmutable('2026-01-01'));
        $p->setCreatedBy($surgeon);
        $rp = new \ReflectionProperty(SurgeonSchedulePost::class, 'id');
        $rp->setValue($p, ++self::$idSeq);
        return $p;
    }

    private function makeMission(User $surgeon, Hospital $site, string $date, MissionStatus $status = MissionStatus::ASSIGNED): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable("$date 08:00:00"));
        $m->setEndAt(new \DateTimeImmutable("$date 13:00:00"));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(\App\Enum\SchedulePrecision::EXACT);
        $rp = new \ReflectionProperty(Mission::class, 'id');
        $rp->setValue($m, ++self::$idSeq);
        return $m;
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_occurrence_creates_a_cancelled_exception_for_only_that_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $exception = $this->makeService()->cancelOccurrence($post, new \DateTimeImmutable('2026-01-12'), $surgeon);

        $this->assertSame(OccurrenceExceptionType::CANCELLED, $exception->getType());
        $this->assertSame('2026-01-12', $exception->getOccurrenceDate()->format('Y-m-d'));
        $this->assertSame($post, $exception->getPost());
        $this->assertContains($exception, $this->persisted);
    }

    public function test_cancel_occurrence_raises_occurrence_cancelled_alert_when_mission_already_exists(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);
        $mission = $this->makeMission($surgeon, $site, '2026-01-12');
        $this->missionsOnDay = [$mission];

        $this->alertService->expects($this->once())
            ->method('createIfNotDuplicate')
            ->with($mission, PlanningAlertType::OCCURRENCE_CANCELLED, null, $this->anything())
            ->willReturn(['alert' => $this->createMock(\App\Entity\PlanningAlert::class), 'created' => true]);

        $this->makeService()->cancelOccurrence($post, new \DateTimeImmutable('2026-01-12'), $surgeon);
    }

    public function test_cancel_occurrence_does_not_raise_alert_when_no_mission_exists(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);
        $this->missionsOnDay = [];

        $this->alertService->expects($this->never())->method('createIfNotDuplicate');

        $this->makeService()->cancelOccurrence($post, new \DateTimeImmutable('2026-01-12'), $surgeon);
    }

    public function test_cancel_occurrence_never_mutates_the_post(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $startDateBefore = $post->getStartDate();
        $activeBefore     = $post->isActive();

        $this->makeService()->cancelOccurrence($post, new \DateTimeImmutable('2026-01-12'), $surgeon);

        $this->assertEquals($startDateBefore, $post->getStartDate());
        $this->assertSame($activeBefore, $post->isActive());
    }

    // ── Move ──────────────────────────────────────────────────────────────────

    public function test_move_occurrence_sets_override_date_and_optional_time(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $exception = $this->makeService()->moveOccurrence(
            $post,
            new \DateTimeImmutable('2026-01-12'),
            new \DateTimeImmutable('2026-01-14'),
            $surgeon,
            new \DateTimeImmutable('14:00:00'),
            new \DateTimeImmutable('17:00:00'),
        );

        $this->assertSame(OccurrenceExceptionType::MOVED, $exception->getType());
        $this->assertSame('2026-01-12', $exception->getOccurrenceDate()->format('Y-m-d'));
        $this->assertSame('2026-01-14', $exception->getOverrideDate()->format('Y-m-d'));
        $this->assertSame('14:00:00', $exception->getOverrideStartTime()->format('H:i:s'));
    }

    // ── Change period ─────────────────────────────────────────────────────────

    public function test_change_period_sets_time_override_without_moving_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $exception = $this->makeService()->changePeriod(
            $post,
            new \DateTimeImmutable('2026-01-12'),
            new \DateTimeImmutable('13:00:00'),
            new \DateTimeImmutable('18:00:00'),
            $surgeon,
        );

        $this->assertSame(OccurrenceExceptionType::TIME_OVERRIDE, $exception->getType());
        $this->assertNull($exception->getOverrideDate());
        $this->assertSame('13:00:00', $exception->getOverrideStartTime()->format('H:i:s'));
        $this->assertSame('18:00:00', $exception->getOverrideEndTime()->format('H:i:s'));
    }

    // ── Change instrumentist ──────────────────────────────────────────────────

    public function test_change_instrumentist_sets_instrumentist_override(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $inst    = $this->makeUser('inst@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $exception = $this->makeService()->changeInstrumentist($post, new \DateTimeImmutable('2026-01-12'), $inst, $surgeon);

        $this->assertSame(OccurrenceExceptionType::INSTRUMENTIST_OVERRIDE, $exception->getType());
        $this->assertSame($inst, $exception->getOverrideInstrumentist());
    }

    // ── Upsert replaces a prior exception on the same date (one exception per occurrence) ──

    public function test_creating_a_new_exception_on_an_already_excepted_date_replaces_it(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $prior = new PlanningOccurrenceException();
        $prior->setPost($post);
        $prior->setOccurrenceDate(new \DateTimeImmutable('2026-01-12'));
        $prior->setType(OccurrenceExceptionType::TIME_OVERRIDE);
        $prior->setCreatedBy($surgeon);
        $this->existingExceptions[$post->getId() . '_2026-01-12'] = $prior;

        $result = $this->makeService()->cancelOccurrence($post, new \DateTimeImmutable('2026-01-12'), $surgeon);

        $this->assertSame($prior, $result, 'Must reuse the existing row for this occurrence, not create a second one');
        $this->assertSame(OccurrenceExceptionType::CANCELLED, $result->getType());
        $this->assertNotContains($prior, $this->persisted, 'Updating an existing exception must not call persist() again');
    }

    // ── Batch 6: REST CRUD ───────────────────────────────────────────────────

    public function test_create_exception_persists_cancelled(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $exception = $this->makeService()->createException($post, OccurrenceExceptionType::CANCELLED, new \DateTimeImmutable('2026-01-12'), $surgeon);

        $this->assertSame(OccurrenceExceptionType::CANCELLED, $exception->getType());
        $this->assertContains($exception, $this->persisted);
    }

    public function test_create_exception_rejects_duplicate_for_same_post_and_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $existing = new PlanningOccurrenceException();
        $existing->setPost($post);
        $existing->setOccurrenceDate(new \DateTimeImmutable('2026-01-12'));
        $existing->setType(OccurrenceExceptionType::CANCELLED);
        $existing->setCreatedBy($surgeon);
        $this->existingExceptions[$post->getId() . '_2026-01-12'] = $existing;

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $this->makeService()->createException($post, OccurrenceExceptionType::MOVED, new \DateTimeImmutable('2026-01-12'), $surgeon);
    }

    public function test_create_exception_moved_requires_override_date(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->makeService()->createException($post, OccurrenceExceptionType::MOVED, new \DateTimeImmutable('2026-01-12'), $surgeon);
    }

    public function test_create_exception_time_override_requires_both_times_in_order(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->makeService()->createException(
            $post, OccurrenceExceptionType::TIME_OVERRIDE, new \DateTimeImmutable('2026-01-12'), $surgeon,
            null, null, new \DateTimeImmutable('18:00'), new \DateTimeImmutable('13:00'), // start after end
        );
    }

    public function test_list_for_post_returns_exceptions(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);
        $exception = $this->makeService()->createException($post, OccurrenceExceptionType::CANCELLED, new \DateTimeImmutable('2026-01-12'), $surgeon);
        $this->listResult = [$exception];

        $result = $this->makeService()->listForPost($post);

        $this->assertSame([$exception], $result);
    }

    public function test_update_exception_changes_type_and_fields(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);
        $exception = $this->makeService()->createException($post, OccurrenceExceptionType::CANCELLED, new \DateTimeImmutable('2026-01-12'), $surgeon);

        $this->makeService()->updateException($exception, [
            'type'              => OccurrenceExceptionType::TIME_OVERRIDE,
            'overrideStartTime' => new \DateTimeImmutable('09:00'),
            'overrideEndTime'   => new \DateTimeImmutable('12:00'),
        ]);

        $this->assertSame(OccurrenceExceptionType::TIME_OVERRIDE, $exception->getType());
        $this->assertSame('09:00:00', $exception->getOverrideStartTime()->format('H:i:s'));
    }

    public function test_update_exception_rejects_invalid_time_override(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);
        $exception = $this->makeService()->createException($post, OccurrenceExceptionType::TIME_OVERRIDE, new \DateTimeImmutable('2026-01-12'), $surgeon, null, null, new \DateTimeImmutable('08:00'), new \DateTimeImmutable('13:00'));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $this->makeService()->updateException($exception, ['overrideStartTime' => new \DateTimeImmutable('15:00'), 'overrideEndTime' => new \DateTimeImmutable('10:00')]);
    }

    public function test_delete_exception_removes_without_touching_post(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $site    = $this->makeSite();
        $post    = $this->makePost($surgeon, $site);
        $exception = $this->makeService()->createException($post, OccurrenceExceptionType::CANCELLED, new \DateTimeImmutable('2026-01-12'), $surgeon);

        $this->makeService()->deleteException($exception);

        $this->assertContains($exception, $this->removed);
        $this->assertTrue($post->isActive(), 'Deleting an exception must never affect the recurring post');
    }
}
