<?php

namespace App\Tests\Unit\Service;

use App\Entity\AuditEvent;
use App\Entity\Mission;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Service\PlanningVersionHistoryService;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlanningVersionHistoryServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PlanningVersionHistoryService $service;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->service = new PlanningVersionHistoryService($this->em);
    }

    // ── Version not found ─────────────────────────────────────────────────────

    public function test_returns_null_when_version_not_found(): void
    {
        $this->em->method('find')->willReturn(null);

        $result = $this->service->buildTimeline(999);

        self::assertNull($result);
    }

    // ── Empty version without deployment ─────────────────────────────────────

    public function test_returns_empty_array_when_no_deployment_and_no_events(): void
    {
        $this->mockSetup(deployedAt: null, events: []);

        $timeline = $this->service->buildTimeline(1);

        self::assertNotNull($timeline);
        self::assertSame([], $timeline);
    }

    // ── DEPLOYED entry first ──────────────────────────────────────────────────

    public function test_deployed_entry_is_first_when_version_is_deployed(): void
    {
        $deployedAt = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');
        $this->mockSetup(deployedAt: $deployedAt, events: []);

        $timeline = $this->service->buildTimeline(1);

        self::assertNotNull($timeline);
        self::assertCount(1, $timeline);
        self::assertSame('DEPLOYED', $timeline[0]['type']);
        self::assertSame($deployedAt->format(\DateTimeInterface::ATOM), $timeline[0]['occurredAt']);
    }

    // ── DEPLOYED entry carries summary data ───────────────────────────────────

    public function test_deployed_entry_includes_mission_count_and_open_pool_count(): void
    {
        $deployedAt  = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');
        $generatedBy = $this->makeUser(42, 'Alice', 'Martin');

        $this->mockSetup(
            deployedAt:  $deployedAt,
            events:      [],
            generatedBy: $generatedBy,
            summaryJson: ['missions' => ['total' => 10, 'open' => 3]],
        );

        $timeline = $this->service->buildTimeline(1);

        self::assertNotNull($timeline);
        $entry = $timeline[0];
        self::assertSame(42,            $entry['deployedById']);
        self::assertSame('Alice Martin', $entry['deployedByName']);
        self::assertSame(10,            $entry['missionCount']);
        self::assertSame(3,             $entry['openPoolCount']);
    }

    // ── AuditEvents appended after DEPLOYED ───────────────────────────────────

    public function test_audit_events_appear_after_deployed_entry(): void
    {
        $deployedAt = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');
        $actor      = $this->makeUser(7, 'Bob', 'Dupont');
        $mission    = $this->createMock(Mission::class);
        $mission->method('getId')->willReturn(55);

        $event = $this->makeAuditEvent(
            AuditEventType::MISSION_CLAIMED_FROM_POOL,
            new \DateTimeImmutable('2026-06-05T10:00:00+00:00'),
            $actor,
            $mission,
        );

        $this->mockSetup(deployedAt: $deployedAt, events: [$event]);

        $timeline = $this->service->buildTimeline(1);

        self::assertNotNull($timeline);
        self::assertCount(2, $timeline);
        self::assertSame('DEPLOYED', $timeline[0]['type']);
        self::assertSame(AuditEventType::MISSION_CLAIMED_FROM_POOL->value, $timeline[1]['type']);
        self::assertSame(55,         $timeline[1]['missionId']);
        self::assertSame(7,          $timeline[1]['actorId']);
        self::assertSame('Bob Dupont', $timeline[1]['actorName']);
    }

    // ── Timeline sorted ASC ───────────────────────────────────────────────────

    public function test_timeline_entries_are_in_chronological_order(): void
    {
        $actor   = $this->makeUser(1, 'X', 'Y');
        $mission = $this->createMock(Mission::class);
        $mission->method('getId')->willReturn(10);

        $first  = $this->makeAuditEvent(AuditEventType::MISSION_CLAIMED_FROM_POOL,  new \DateTimeImmutable('2026-06-02T08:00:00+00:00'), $actor, $mission);
        $second = $this->makeAuditEvent(AuditEventType::MISSION_RELEASED_TO_POOL, new \DateTimeImmutable('2026-06-03T09:00:00+00:00'), $actor, $mission);

        $this->mockSetup(
            deployedAt: new \DateTimeImmutable('2026-06-01T00:00:00+00:00'),
            events: [$first, $second],
        );

        $timeline = $this->service->buildTimeline(1);

        self::assertNotNull($timeline);
        self::assertSame('DEPLOYED', $timeline[0]['type']);
        self::assertSame(AuditEventType::MISSION_CLAIMED_FROM_POOL->value,  $timeline[1]['type']);
        self::assertSame(AuditEventType::MISSION_RELEASED_TO_POOL->value, $timeline[2]['type']);
    }

    // ── displayName falls back to email ───────────────────────────────────────

    public function test_actor_name_falls_back_to_email_when_no_first_last_name(): void
    {
        $actor = $this->createMock(User::class);
        $actor->method('getId')->willReturn(9);
        $actor->method('getFirstname')->willReturn(null);
        $actor->method('getLastname')->willReturn(null);
        $actor->method('getEmail')->willReturn('user@example.com');

        $mission = $this->createMock(Mission::class);
        $mission->method('getId')->willReturn(20);

        $event = $this->makeAuditEvent(AuditEventType::MISSION_CLAIMED_FROM_POOL, new \DateTimeImmutable(), $actor, $mission);

        $this->mockSetup(deployedAt: null, events: [$event]);

        $timeline = $this->service->buildTimeline(1);

        self::assertNotNull($timeline);
        self::assertSame('user@example.com', $timeline[0]['actorName']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param AuditEvent[] $events
     */
    private function mockSetup(
        ?\DateTimeImmutable $deployedAt,
        array               $events,
        ?User               $generatedBy = null,
        array               $summaryJson = [],
    ): void {
        $version = $this->createMock(PlanningVersion::class);
        $version->method('getDeployedAt')->willReturn($deployedAt);
        $version->method('getGeneratedBy')->willReturn($generatedBy);
        $version->method('getSummaryJson')->willReturn($summaryJson);

        $this->em->method('find')->willReturn($version);

        $this->em->method('createQuery')->willReturnCallback(function () use ($events): AbstractQuery {
            $q = $this->createMock(Query::class);
            $q->method('setParameter')->willReturnSelf();
            $q->method('getResult')->willReturn($events);
            return $q;
        });
    }

    private function makeUser(int $id, string $first, string $last): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getFirstname')->willReturn($first);
        $user->method('getLastname')->willReturn($last);
        $user->method('getEmail')->willReturn($first . '@example.com');
        return $user;
    }

    private function makeAuditEvent(
        AuditEventType     $type,
        \DateTimeImmutable $createdAt,
        User               $actor,
        Mission            $mission,
        array              $payload = [],
    ): AuditEvent {
        $event = $this->createMock(AuditEvent::class);
        $event->method('getEventType')->willReturn($type);
        $event->method('getCreatedAt')->willReturn($createdAt);
        $event->method('getActor')->willReturn($actor);
        $event->method('getMission')->willReturn($mission);
        $event->method('getPayload')->willReturn($payload);
        return $event;
    }
}
