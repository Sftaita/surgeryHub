<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Exception\MissionNotDraftException;
use App\Service\AuditService;
use App\Service\MissionEncodingGuard;
use App\Service\MissionService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * RC1-C, Cluster C fix: MissionService::assignInstrumentistDraft() is the DRAFT-only
 * pre-deploy assignment path, now that the controller no longer mutates Mission directly.
 */
final class MissionServiceAssignInstrumentistDraftTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MissionService                     $service;

    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->service = new MissionService(
            $this->em,
            new MissionEncodingGuard(), // final, stateless — not exercised by assignInstrumentistDraft()
            $this->createMock(AuditService::class),
            $this->createMock(NotificationService::class),
        );
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeMission(MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function makeInstrumentist(): User
    {
        $u = new User();
        $u->setEmail('instr' . self::$nextId . '@test.com');
        $this->setId($u, self::$nextId++);
        return $u;
    }

    public function test_assigns_instrumentist_on_draft_mission(): void
    {
        $mission       = $this->makeMission(MissionStatus::DRAFT);
        $instrumentist = $this->makeInstrumentist();

        $this->em->method('find')->willReturn($instrumentist);
        $this->em->expects($this->once())->method('flush');

        $result = $this->service->assignInstrumentistDraft($mission, $instrumentist->getId());

        $this->assertSame($instrumentist, $result->getInstrumentist());
        $this->assertSame(MissionStatus::DRAFT, $result->getStatus(), 'DRAFT assignment must not change status');
    }

    public function test_clears_instrumentist_when_id_is_null(): void
    {
        $instrumentist = $this->makeInstrumentist();
        $mission       = $this->makeMission(MissionStatus::DRAFT);
        $mission->setInstrumentist($instrumentist);

        $this->em->expects($this->once())->method('flush');

        $result = $this->service->assignInstrumentistDraft($mission, null);

        $this->assertNull($result->getInstrumentist());
    }

    public function test_throws_not_draft_exception_when_mission_is_open(): void
    {
        $mission = $this->makeMission(MissionStatus::OPEN);

        $this->em->expects($this->never())->method('flush');
        $this->expectException(MissionNotDraftException::class);

        $this->service->assignInstrumentistDraft($mission, 1);
    }

    public function test_throws_not_draft_exception_when_mission_is_assigned(): void
    {
        $mission = $this->makeMission(MissionStatus::ASSIGNED);

        $this->expectException(MissionNotDraftException::class);

        $this->service->assignInstrumentistDraft($mission, 1);
    }

    public function test_not_draft_exception_maps_to_409(): void
    {
        $mission = $this->makeMission(MissionStatus::CANCELLED);

        try {
            $this->service->assignInstrumentistDraft($mission, 1);
            $this->fail('Expected MissionNotDraftException');
        } catch (MissionNotDraftException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_throws_not_found_when_instrumentist_does_not_exist(): void
    {
        $mission = $this->makeMission(MissionStatus::DRAFT);

        $this->em->method('find')->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(NotFoundHttpException::class);

        $this->service->assignInstrumentistDraft($mission, 9999);
    }
}
