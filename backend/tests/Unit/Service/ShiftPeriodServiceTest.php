<?php

namespace App\Tests\Unit\Service;

use App\Entity\Hospital;
use App\Entity\ShiftPeriodConfig;
use App\Enum\ShiftPeriod;
use App\Service\ShiftPeriodService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShiftPeriodServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private int $duplicateCount = 0;
    private array $persisted = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->duplicateCount = 0;
        $this->persisted = [];

        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturnCallback(fn () => $this->duplicateCount);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('persist')->willReturnCallback(function (object $e): void { $this->persisted[] = $e; });
    }

    private function makeService(): ShiftPeriodService
    {
        return new ShiftPeriodService($this->em);
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Alpha');
        $rp = new \ReflectionProperty(Hospital::class, 'id');
        $rp->setValue($h, ++self::$idSeq);
        return $h;
    }

    public function test_create_persists_valid_config(): void
    {
        $site = $this->makeSite();
        $config = $this->makeService()->create($site, ShiftPeriod::MATIN, new \DateTimeImmutable('08:00'), new \DateTimeImmutable('13:00'));

        $this->assertSame($site, $config->getSite());
        $this->assertSame(ShiftPeriod::MATIN, $config->getPeriod());
        $this->assertTrue($config->isActive());
        $this->assertContains($config, $this->persisted);
    }

    public function test_create_rejects_invalid_time_range(): void
    {
        $site = $this->makeSite();

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->create($site, ShiftPeriod::MATIN, new \DateTimeImmutable('13:00'), new \DateTimeImmutable('08:00'));
    }

    public function test_create_rejects_equal_start_and_end(): void
    {
        $site = $this->makeSite();

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->create($site, ShiftPeriod::MATIN, new \DateTimeImmutable('08:00'), new \DateTimeImmutable('08:00'));
    }

    public function test_create_rejects_duplicate_active_period_for_same_site(): void
    {
        $site = $this->makeSite();
        $this->duplicateCount = 1;

        $this->expectException(ConflictHttpException::class);
        $this->makeService()->create($site, ShiftPeriod::MATIN, new \DateTimeImmutable('08:00'), new \DateTimeImmutable('13:00'));
    }

    public function test_update_changes_hours(): void
    {
        $site = $this->makeSite();
        $config = $this->makeService()->create($site, ShiftPeriod::MATIN, new \DateTimeImmutable('08:00'), new \DateTimeImmutable('13:00'));

        $this->makeService()->update($config, null, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('14:00'));

        $this->assertSame('09:00', $config->getStartTime()->format('H:i'));
        $this->assertSame('14:00', $config->getEndTime()->format('H:i'));
    }

    public function test_update_rejects_invalid_resulting_range(): void
    {
        $site = $this->makeSite();
        $config = $this->makeService()->create($site, ShiftPeriod::MATIN, new \DateTimeImmutable('08:00'), new \DateTimeImmutable('13:00'));

        $this->expectException(BadRequestHttpException::class);
        $this->makeService()->update($config, null, new \DateTimeImmutable('15:00'), null); // new start (15:00) > existing end (13:00)
    }

    public function test_deactivate_sets_active_false(): void
    {
        $site = $this->makeSite();
        $config = $this->makeService()->create($site, ShiftPeriod::MATIN, new \DateTimeImmutable('08:00'), new \DateTimeImmutable('13:00'));

        $this->makeService()->deactivate($config);

        $this->assertFalse($config->isActive());
    }

    public function test_reactivate_rejects_when_another_active_duplicate_exists(): void
    {
        $site = $this->makeSite();
        $config = $this->makeService()->create($site, ShiftPeriod::MATIN, new \DateTimeImmutable('08:00'), new \DateTimeImmutable('13:00'));
        $this->makeService()->deactivate($config);

        $this->duplicateCount = 1; // another active MATIN config now exists for this site

        $this->expectException(ConflictHttpException::class);
        $this->makeService()->reactivate($config);
    }
}
