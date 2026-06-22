<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Service\SurgeonSchedulePostService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SurgeonSchedulePostServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private array $foundUsers = [];
    private array $foundSites = [];
    private bool  $periodBelongsToSite = true;
    private bool  $instrumentistAffiliated = true;
    private array $persisted = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->foundUsers = [];
        $this->foundSites = [];
        $this->periodBelongsToSite = true;
        $this->instrumentistAffiliated = true;
        $this->persisted = [];

        $this->em->method('find')->willReturnCallback(function (string $class, $id) {
            return match ($class) {
                User::class     => $this->foundUsers[$id] ?? null,
                Hospital::class => $this->foundSites[$id] ?? null,
                default         => null,
            };
        });

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturnCallback(fn () => $this->periodBelongsToSite ? 1 : 0);
        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('createQuery')
            ->willReturnCallback(function (string $dql): Query {
                $q = $this->createMock(Query::class);
                $q->method('setParameter')->willReturnSelf();
                $q->method('getSingleScalarResult')->willReturnCallback(fn () => $this->instrumentistAffiliated ? 1 : 0);
                return $q;
            });

        $this->em->method('persist')->willReturnCallback(function (object $e): void { $this->persisted[] = $e; });
    }

    private function makeService(): SurgeonSchedulePostService
    {
        return new SurgeonSchedulePostService($this->em);
    }

    private function makeSurgeon(): User
    {
        $u = new User();
        $u->setEmail('surgeon@test.com');
        $u->setRoles(['ROLE_SURGEON']);
        $u->setActive(true);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        $this->foundUsers[$u->getId()] = $u;
        return $u;
    }

    private function makeInstrumentist(bool $active = true): User
    {
        $u = new User();
        $u->setEmail('inst@test.com');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive($active);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        $this->foundUsers[$u->getId()] = $u;
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$idSeq);
        $this->foundSites[$h->getId()] = $h;
        return $h;
    }

    private function validInput(int $surgeonId, int $siteId, ?int $instrumentistId = null): array
    {
        return [
            'surgeonId'  => $surgeonId,
            'siteId'     => $siteId,
            'type'       => MissionType::BLOCK,
            'period'     => ShiftPeriod::MATIN,
            'instrumentistId' => $instrumentistId,
            'startDate'  => new \DateTimeImmutable('2026-01-01'),
            'endDate'    => null,
            'recurrence' => [
                'frequency'  => RecurrenceFrequency::WEEKLY,
                'interval'   => 1,
                'weekdays'   => [1],
                'anchorDate' => new \DateTimeImmutable('2026-01-05'),
            ],
        ];
    }

    // ── Create: success ──────────────────────────────────────────────────────

    public function test_create_persists_valid_post(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();

        $post = $this->makeService()->create($this->validInput($surgeon->getId(), $site->getId()), $surgeon);

        $this->assertSame($surgeon, $post->getSurgeon());
        $this->assertSame($site, $post->getSite());
        $this->assertContains($post, $this->persisted);
    }

    public function test_create_with_eligible_instrumentist_succeeds(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $inst    = $this->makeInstrumentist();

        $post = $this->makeService()->create($this->validInput($surgeon->getId(), $site->getId(), $inst->getId()), $surgeon);

        $this->assertSame($inst, $post->getInstrumentist());
    }

    // ── Create: rejections ────────────────────────────────────────────────────

    public function test_create_rejects_unknown_surgeon(): void
    {
        $site = $this->makeSite();

        $this->expectException(NotFoundHttpException::class);
        $this->makeService()->create($this->validInput(999999, $site->getId()), $this->makeSurgeon());
    }

    public function test_create_rejects_user_without_surgeon_role(): void
    {
        $notSurgeon = $this->makeInstrumentist(); // ROLE_INSTRUMENTIST, not ROLE_SURGEON
        $site = $this->makeSite();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->create($this->validInput($notSurgeon->getId(), $site->getId()), $notSurgeon);
    }

    public function test_create_rejects_period_not_belonging_to_site(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $this->periodBelongsToSite = false;

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->create($this->validInput($surgeon->getId(), $site->getId()), $surgeon);
    }

    public function test_create_rejects_inactive_instrumentist(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $inactiveInst = $this->makeInstrumentist(false);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->create($this->validInput($surgeon->getId(), $site->getId(), $inactiveInst->getId()), $surgeon);
    }

    public function test_create_rejects_instrumentist_not_affiliated_with_site(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $inst    = $this->makeInstrumentist();
        $this->instrumentistAffiliated = false;

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->makeService()->create($this->validInput($surgeon->getId(), $site->getId(), $inst->getId()), $surgeon);
    }

    public function test_create_rejects_end_date_before_start_date(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $input   = $this->validInput($surgeon->getId(), $site->getId());
        $input['endDate'] = new \DateTimeImmutable('2025-12-31'); // before startDate 2026-01-01

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->create($input, $surgeon);
    }

    // ── Recurrence validation ────────────────────────────────────────────────

    public function test_create_rejects_weekly_recurrence_without_weekdays(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $input   = $this->validInput($surgeon->getId(), $site->getId());
        $input['recurrence']['weekdays'] = [];

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->create($input, $surgeon);
    }

    public function test_create_rejects_out_of_range_weekday(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $input   = $this->validInput($surgeon->getId(), $site->getId());
        $input['recurrence']['weekdays'] = [8];

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->create($input, $surgeon);
    }

    public function test_create_rejects_missing_anchor_date(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $input   = $this->validInput($surgeon->getId(), $site->getId());
        unset($input['recurrence']['anchorDate']);

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->create($input, $surgeon);
    }

    public function test_create_rejects_interval_below_one(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $input   = $this->validInput($surgeon->getId(), $site->getId());
        $input['recurrence']['interval'] = 0;

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->create($input, $surgeon);
    }

    public function test_create_accepts_monthly_recurrence_without_weekdays(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $input   = $this->validInput($surgeon->getId(), $site->getId());
        $input['recurrence'] = [
            'frequency'  => RecurrenceFrequency::MONTHLY,
            'interval'   => 1,
            'anchorDate' => new \DateTimeImmutable('2026-01-05'),
        ];

        $post = $this->makeService()->create($input, $surgeon);

        $this->assertSame(RecurrenceFrequency::MONTHLY, $post->getRecurrence()->getFrequency());
        $this->assertSame([], $post->getRecurrence()->getWeekdays());
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_deactivate_sets_active_false_without_deleting(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $post    = $this->makeService()->create($this->validInput($surgeon->getId(), $site->getId()), $surgeon);

        $this->makeService()->deactivate($post);

        $this->assertFalse($post->isActive());
        $this->assertInstanceOf(SurgeonSchedulePost::class, $post, 'Entity must still exist — deactivation is not deletion');
    }

    public function test_reactivate_sets_active_true(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $post    = $this->makeService()->create($this->validInput($surgeon->getId(), $site->getId()), $surgeon);
        $this->makeService()->deactivate($post);

        $this->makeService()->reactivate($post);

        $this->assertTrue($post->isActive());
    }

    public function test_update_changes_recurrence_without_affecting_other_fields(): void
    {
        $surgeon = $this->makeSurgeon();
        $site    = $this->makeSite();
        $post    = $this->makeService()->create($this->validInput($surgeon->getId(), $site->getId()), $surgeon);

        $this->makeService()->update($post, [
            'recurrence' => [
                'frequency'  => RecurrenceFrequency::WEEKLY,
                'interval'   => 2,
                'weekdays'   => [3],
                'anchorDate' => new \DateTimeImmutable('2026-01-07'),
            ],
        ]);

        $this->assertSame(2, $post->getRecurrence()->getInterval());
        $this->assertSame([3], $post->getRecurrence()->getWeekdays());
        $this->assertSame($site, $post->getSite(), 'Unrelated fields must remain unchanged');
    }
}
