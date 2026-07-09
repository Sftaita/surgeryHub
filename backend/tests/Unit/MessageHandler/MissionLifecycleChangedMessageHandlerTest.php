<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\MissionChangeType;
use App\Enum\MissionStatus;
use App\Enum\NotificationType;
use App\Message\MissionLifecycleChangedMessage;
use App\MessageHandler\MissionLifecycleChangedMessageHandler;
use App\Service\MissionEligibilityService;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MissionLifecycleChangedMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject         $em;
    private NotificationPreferenceResolver&MockObject $preferenceResolver;
    private WebPushServiceInterface&MockObject        $webPushService;
    private LoggerInterface&MockObject                $logger;
    private MissionEligibilityService&MockObject      $eligibilityService;
    private array                                     $persisted = [];

    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->webPushService     = $this->createMock(WebPushServiceInterface::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);

        // Default: inApp=true, email=false, push=false
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        // Default: no eligible instrumentists for the pool (RELEASED) — tests opt in explicitly.
        $this->eligibilityService->method('findEligible')->willReturn([]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeHandler(): MissionLifecycleChangedMessageHandler
    {
        return new MissionLifecycleChangedMessageHandler(
            $this->em,
            $this->preferenceResolver,
            $this->webPushService,
            $this->logger,
            $this->eligibilityService,
        );
    }

    private function setId(object $entity, int $id): void
    {
        $rp = new \ReflectionProperty($entity, 'id');
        $rp->setAccessible(true);
        $rp->setValue($entity, $id);
    }

    private function makeUser(string $email = null): User
    {
        $u = new User();
        $u->setEmail($email ?? ('user' . self::$nextId . '@test.com'));
        $u->setFirstname('First');
        $u->setLastname('Last');
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeSite(string $name = 'Delta'): Hospital
    {
        $s = new Hospital();
        $s->setName($name);
        $this->setId($s, self::$nextId++);
        return $s;
    }

    private function makeMission(?User $surgeon = null, MissionStatus $status = MissionStatus::OPEN, ?User $instrumentist = null): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setSite($this->makeSite());
        $m->setStartAt(new \DateTimeImmutable('2026-07-15 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-07-15 13:00:00'));
        if ($surgeon !== null) {
            $m->setSurgeon($surgeon);
        }
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function makeMessage(
        Mission           $mission,
        MissionChangeType $changeType,
        array             $payload = [],
        int               $actorId = 99,
    ): MissionLifecycleChangedMessage {
        return new MissionLifecycleChangedMessage(
            missionId:  $mission->getId(),
            changeType: $changeType,
            actorId:    $actorId,
            payload:    $payload,
            occurredAt: new \DateTimeImmutable('2026-07-15T10:18:00+02:00'),
        );
    }

    /** Stubs em->find() for the mission plus an optional map of userId => User. */
    private function stubFind(Mission $mission, array $usersById = []): void
    {
        $this->em->method('find')
            ->willReturnCallback(function (string $class, $id) use ($mission, $usersById) {
                if ($class === Mission::class && $id === $mission->getId()) {
                    return $mission;
                }
                if ($class === User::class && isset($usersById[$id])) {
                    return $usersById[$id];
                }
                return null;
            });
    }

    private function stubMissionFind(Mission $mission): void
    {
        $this->stubFind($mission);
    }

    /** Wires em->persist() to accumulate every persisted entity into $this->persisted. */
    private function capturePersisted(): void
    {
        $this->persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($e): void {
            $this->persisted[] = $e;
        });
    }

    private function eventsOfType(array $persisted, NotificationType $type): array
    {
        return array_values(array_filter(
            $persisted,
            fn ($e) => $e instanceof NotificationEvent && $e->getEventType() === $type->value,
        ));
    }

    // ── CLAIMED → SURGEON_POST_COVERED ────────────────────────────────────────

    public function test_claimed_creates_surgeon_post_covered_inapp_notification(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $message = $this->makeMessage($mission, MissionChangeType::CLAIMED, [
            'instrumentistId'   => 7,
            'instrumentistName' => 'Sophie Martin',
        ]);

        $this->makeHandler()->__invoke($message);

        $events = $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_COVERED);

        $this->assertCount(1, $events);
        $this->assertSame($surgeon, $events[0]->getUser());
        $this->assertSame($mission, $events[0]->getMission());
    }

    public function test_claimed_payload_contains_instrumentist_name_and_covered_at(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $message = $this->makeMessage($mission, MissionChangeType::CLAIMED, [
            'instrumentistId'   => 7,
            'instrumentistName' => 'Sophie Martin',
        ]);

        $this->makeHandler()->__invoke($message);

        $event = $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_COVERED)[0] ?? null;

        $this->assertNotNull($event);
        $payload = $event->getPayload();
        $this->assertSame(7, $payload['instrumentistId']);
        $this->assertSame('Sophie Martin', $payload['instrumentistName']);
        $this->assertSame($mission->getId(), $payload['missionId']);
        $this->assertSame($mission->getSite()->getName(), $payload['siteName']);
        $this->assertSame('Matin', $payload['periodLabel']);
        $this->assertArrayHasKey('coveredAt', $payload);
        $this->assertSame($message->occurredAt->format(\DateTimeInterface::ATOM), $payload['coveredAt']);
    }

    public function test_claimed_sends_push_when_push_enabled(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);
        $this->em->method('persist');

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: true));

        $this->webPushService->expects($this->once())
            ->method('sendToUser')
            ->with(
                $surgeon,
                'Mission couverte',
                $this->stringContains('pris en charge'),
                $this->arrayHasKey('missionId'),
            );

        $message = $this->makeMessage($mission, MissionChangeType::CLAIMED, [
            'instrumentistName' => 'Sophie Martin',
        ]);

        $this->makeHandler()->__invoke($message);
    }

    public function test_claimed_no_push_when_push_disabled(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);
        $this->em->method('persist');

        // Default setUp(): push=false
        $this->webPushService->expects($this->never())->method('sendToUser');

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::CLAIMED, ['instrumentistName' => 'Sophie Martin'])
        );
    }

    public function test_claimed_no_inapp_when_inapp_disabled(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: false, email: false, push: false));

        $this->em->expects($this->never())->method('persist');

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::CLAIMED)
        );
    }

    public function test_claimed_skips_gracefully_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $msg = new MissionLifecycleChangedMessage(
            missionId:  9999,
            changeType: MissionChangeType::CLAIMED,
            actorId:    1,
            payload:    [],
            occurredAt: new \DateTimeImmutable(),
        );

        // Must not throw
        $this->makeHandler()->__invoke($msg);
    }

    public function test_claimed_skips_gracefully_when_no_surgeon(): void
    {
        $mission = $this->makeMission(null, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);
        $this->em->expects($this->never())->method('persist');

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::CLAIMED)
        );
    }

    // ── RELEASED → SURGEON_POST_UNCOVERED ─────────────────────────────────────

    public function test_released_creates_surgeon_post_uncovered_inapp_notification(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $message = $this->makeMessage($mission, MissionChangeType::RELEASED, [
            'fromInstrumentistName' => 'Françoise Dubois',
        ]);

        $this->makeHandler()->__invoke($message);

        $events = $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_UNCOVERED);

        $this->assertCount(1, $events);
        $this->assertSame($surgeon, $events[0]->getUser());
        $this->assertSame($mission, $events[0]->getMission());
    }

    public function test_released_payload_contains_from_instrumentist_name_and_released_at(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $message = $this->makeMessage($mission, MissionChangeType::RELEASED, [
            'fromInstrumentistName' => 'Françoise Dubois',
        ]);

        $this->makeHandler()->__invoke($message);

        $event = $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_UNCOVERED)[0] ?? null;

        $this->assertNotNull($event);
        $payload = $event->getPayload();
        $this->assertSame('Françoise Dubois', $payload['fromInstrumentistName']);
        $this->assertSame($mission->getId(), $payload['missionId']);
        $this->assertSame($mission->getSite()->getName(), $payload['siteName']);
        $this->assertSame('Matin', $payload['periodLabel']);
        $this->assertArrayHasKey('releasedAt', $payload);
        $this->assertSame($message->occurredAt->format(\DateTimeInterface::ATOM), $payload['releasedAt']);
    }

    public function test_released_sends_push_when_push_enabled(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubMissionFind($mission);
        $this->em->method('persist');

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: true));

        $this->webPushService->expects($this->once())
            ->method('sendToUser')
            ->with(
                $surgeon,
                'Mission non couverte',
                $this->stringContains("n'a plus d'instrumentiste"),
                $this->arrayHasKey('missionId'),
            );

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::RELEASED, [
                'fromInstrumentistName' => 'Françoise Dubois',
            ])
        );
    }

    public function test_released_skips_gracefully_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $msg = new MissionLifecycleChangedMessage(
            missionId:  9999,
            changeType: MissionChangeType::RELEASED,
            actorId:    1,
            payload:    [],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($msg);
    }

    // ── RELEASED → OPEN_MISSION_AVAILABLE (pool, RC1-B) ───────────────────────

    public function test_released_notifies_eligible_instrumentists_of_open_mission(): void
    {
        $surgeon       = $this->makeUser('surgeon@test.com');
        $mission       = $this->makeMission($surgeon, MissionStatus::OPEN);
        $siteId        = $mission->getSite()->getId();
        $instrumentist = $this->makeUser('instr@test.com');
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->expects($this->once())
            ->method('findEligible')
            ->with([$mission])
            ->willReturn([$siteId => [$instrumentist]]);

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::RELEASED, ['fromInstrumentistName' => 'Ancien'])
        );

        $events = $this->eventsOfType($this->persisted,NotificationType::OPEN_MISSION_AVAILABLE);
        $this->assertCount(1, $events);
        $this->assertSame($instrumentist, $events[0]->getUser());

        $payload = $events[0]->getPayload();
        $this->assertSame([$mission->getId()], $payload['openMissionIds']);
        $this->assertSame(1, $payload['missionCount']);
        $this->assertSame($mission->getSite()->getName(), $payload['siteName']);
    }

    public function test_released_pool_push_sent_to_all_eligible_instrumentists(): void
    {
        $surgeon        = $this->makeUser('surgeon@test.com');
        $mission        = $this->makeMission($surgeon, MissionStatus::OPEN);
        $siteId         = $mission->getSite()->getId();
        $instrumentist1 = $this->makeUser('i1@test.com');
        $instrumentist2 = $this->makeUser('i2@test.com');
        $this->stubMissionFind($mission);
        $this->em->method('persist');

        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('findEligible')
            ->willReturn([$siteId => [$instrumentist1, $instrumentist2]]);

        $this->webPushService->expects($this->once())
            ->method('sendToUsers')
            ->with(
                [$instrumentist1, $instrumentist2],
                'Nouvelle mission disponible',
                $this->stringContains('disponible'),
                $this->arrayHasKey('missionId'),
            );

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::RELEASED, ['fromInstrumentistName' => 'Ancien'])
        );
    }

    public function test_released_pool_no_op_when_no_eligible_instrumentists(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubMissionFind($mission);
        $this->em->method('persist');

        // Default setUp(): findEligible() returns []
        $this->webPushService->expects($this->never())->method('sendToUsers');

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::RELEASED, ['fromInstrumentistName' => 'Ancien'])
        );
    }

    public function test_released_pool_eligibility_failure_does_not_prevent_surgeon_notification(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::OPEN);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('findEligible')
            ->willThrowException(new \RuntimeException('DB unavailable'));

        // Must not throw
        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::RELEASED, ['fromInstrumentistName' => 'Ancien'])
        );

        $this->assertCount(1, $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_UNCOVERED));
        $this->assertCount(0, $this->eventsOfType($this->persisted,NotificationType::OPEN_MISSION_AVAILABLE));
    }

    public function test_released_pool_respects_preference_gating_per_instrumentist(): void
    {
        $surgeon        = $this->makeUser('surgeon@test.com');
        $mission        = $this->makeMission($surgeon, MissionStatus::OPEN);
        $siteId         = $mission->getSite()->getId();
        $enabledInstr   = $this->makeUser('enabled@test.com');
        $disabledInstr  = $this->makeUser('disabled@test.com');
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $this->eligibilityService = $this->createMock(MissionEligibilityService::class);
        $this->eligibilityService->method('findEligible')
            ->willReturn([$siteId => [$enabledInstr, $disabledInstr]]);

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturnCallback(function (User $user, NotificationType $type) use ($surgeon, $enabledInstr) {
                if ($type === NotificationType::OPEN_MISSION_AVAILABLE) {
                    return new NotificationChannels(inApp: $user === $enabledInstr, email: false, push: false);
                }
                return new NotificationChannels(inApp: true, email: false, push: false);
            });

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::RELEASED, ['fromInstrumentistName' => 'Ancien'])
        );

        $events = $this->eventsOfType($this->persisted,NotificationType::OPEN_MISSION_AVAILABLE);
        $this->assertCount(1, $events);
        $this->assertSame($enabledInstr, $events[0]->getUser());
    }

    // ── REASSIGNED → PLANNING_MISSION_REASSIGNED (RC1-B) ──────────────────────

    public function test_reassigned_notifies_old_instrumentist_of_removal(): void
    {
        $oldInstr = $this->makeUser('old@test.com');
        $newInstr = $this->makeUser('new@test.com');
        $mission  = $this->makeMission(null, MissionStatus::ASSIGNED, $newInstr);
        $this->stubFind($mission, [$oldInstr->getId() => $oldInstr, $newInstr->getId() => $newInstr]);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId'   => $oldInstr->getId(),
            'fromInstrumentistName' => 'Old Instr',
            'toInstrumentistId'     => $newInstr->getId(),
            'toInstrumentistName'   => 'New Instr',
        ]));

        $events = $this->eventsOfType($this->persisted,NotificationType::PLANNING_MISSION_REASSIGNED);
        $recipients = array_map(fn ($e) => $e->getUser(), $events);
        $this->assertContains($oldInstr, $recipients);
    }

    public function test_reassigned_notifies_new_instrumentist_of_assignment(): void
    {
        $oldInstr = $this->makeUser('old@test.com');
        $newInstr = $this->makeUser('new@test.com');
        $mission  = $this->makeMission(null, MissionStatus::ASSIGNED, $newInstr);
        $this->stubFind($mission, [$oldInstr->getId() => $oldInstr, $newInstr->getId() => $newInstr]);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId'   => $oldInstr->getId(),
            'fromInstrumentistName' => 'Old Instr',
            'toInstrumentistId'     => $newInstr->getId(),
            'toInstrumentistName'   => 'New Instr',
        ]));

        $events = $this->eventsOfType($this->persisted,NotificationType::PLANNING_MISSION_REASSIGNED);
        $recipients = array_map(fn ($e) => $e->getUser(), $events);
        $this->assertContains($newInstr, $recipients);
        $this->assertCount(2, $events, 'both old and new instrumentist must each receive one PLANNING_MISSION_REASSIGNED event');
    }

    public function test_reassigned_notifies_surgeon_when_assigned_from_pool(): void
    {
        // fromInstrumentistId === null → this reassignment is really a manager assign() from OPEN.
        $newInstr = $this->makeUser('new@test.com');
        $surgeon  = $this->makeUser('surgeon@test.com');
        $mission  = $this->makeMission($surgeon, MissionStatus::ASSIGNED, $newInstr);
        $this->stubFind($mission, [$newInstr->getId() => $newInstr]);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => null,
            'toInstrumentistId'   => $newInstr->getId(),
            'toInstrumentistName' => 'New Instr',
        ]));

        $events = $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_COVERED);
        $this->assertCount(1, $events);
        $this->assertSame($surgeon, $events[0]->getUser());
    }

    public function test_reassigned_does_not_notify_surgeon_on_pure_reassign(): void
    {
        // fromInstrumentistId present → ASSIGNED → ASSIGNED, surgeon's coverage view is unchanged.
        $oldInstr = $this->makeUser('old@test.com');
        $newInstr = $this->makeUser('new@test.com');
        $surgeon  = $this->makeUser('surgeon@test.com');
        $mission  = $this->makeMission($surgeon, MissionStatus::ASSIGNED, $newInstr);
        $this->stubFind($mission, [$oldInstr->getId() => $oldInstr, $newInstr->getId() => $newInstr]);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => $oldInstr->getId(),
            'toInstrumentistId'   => $newInstr->getId(),
            'toInstrumentistName' => 'New Instr',
        ]));

        $this->assertCount(0, $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_COVERED));
    }

    public function test_reassigned_old_instrumentist_not_found_does_not_error(): void
    {
        $newInstr = $this->makeUser('new@test.com');
        $mission  = $this->makeMission(null, MissionStatus::ASSIGNED, $newInstr);
        // fromInstrumentistId points to a user that no longer exists.
        $this->stubFind($mission, [$newInstr->getId() => $newInstr]);
        $this->em->method('persist');

        // Must not throw
        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => 12345,
            'toInstrumentistId'   => $newInstr->getId(),
            'toInstrumentistName' => 'New Instr',
        ]));

        $this->addToAssertionCount(1);
    }

    public function test_reassigned_respects_preference_gating_per_recipient(): void
    {
        $oldInstr = $this->makeUser('old@test.com');
        $newInstr = $this->makeUser('new@test.com');
        $mission  = $this->makeMission(null, MissionStatus::ASSIGNED, $newInstr);
        $this->stubFind($mission, [$oldInstr->getId() => $oldInstr, $newInstr->getId() => $newInstr]);
        $this->capturePersisted();

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturnCallback(fn (User $u) => new NotificationChannels(inApp: $u === $newInstr, email: false, push: false));

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => $oldInstr->getId(),
            'toInstrumentistId'   => $newInstr->getId(),
            'toInstrumentistName' => 'New Instr',
        ]));

        $events = $this->eventsOfType($this->persisted,NotificationType::PLANNING_MISSION_REASSIGNED);
        $this->assertCount(1, $events);
        $this->assertSame($newInstr, $events[0]->getUser());
    }

    public function test_reassigned_skips_gracefully_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $msg = new MissionLifecycleChangedMessage(
            missionId:  9999,
            changeType: MissionChangeType::REASSIGNED,
            actorId:    1,
            payload:    [],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($msg);
    }

    public function test_reassigned_push_failure_for_old_instrumentist_does_not_block_new_instrumentist(): void
    {
        $oldInstr = $this->makeUser('old@test.com');
        $newInstr = $this->makeUser('new@test.com');
        $mission  = $this->makeMission(null, MissionStatus::ASSIGNED, $newInstr);
        $this->stubFind($mission, [$oldInstr->getId() => $oldInstr, $newInstr->getId() => $newInstr]);
        $this->capturePersisted();

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: true));

        $this->webPushService->method('sendToUser')
            ->willThrowException(new \RuntimeException('push down'));

        // Must not throw, and both inApp events must still be persisted.
        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::REASSIGNED, [
            'fromInstrumentistId' => $oldInstr->getId(),
            'toInstrumentistId'   => $newInstr->getId(),
            'toInstrumentistName' => 'New Instr',
        ]));

        $this->assertCount(2, $this->eventsOfType($this->persisted,NotificationType::PLANNING_MISSION_REASSIGNED));
    }

    // ── CANCELLED → PLANNING_MISSION_CANCELLED (RC1-B) ────────────────────────

    public function test_cancelled_notifies_surgeon(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::CANCELLED);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED, [
            'reason' => 'Chirurgien absent',
        ]));

        $events = $this->eventsOfType($this->persisted,NotificationType::PLANNING_MISSION_CANCELLED);
        $this->assertNotEmpty($events);
        $recipients = array_map(fn ($e) => $e->getUser(), $events);
        $this->assertContains($surgeon, $recipients);

        $surgeonEvent = current(array_filter($events, fn ($e) => $e->getUser() === $surgeon));
        $this->assertSame('Chirurgien absent', $surgeonEvent->getPayload()['reason']);
    }

    public function test_cancelled_notifies_instrumentist_when_assigned(): void
    {
        // cancel() currently requires OPEN, so this exercises the defensive branch.
        $surgeon = $this->makeUser('surgeon@test.com');
        $instr   = $this->makeUser('instr@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::CANCELLED, $instr);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED));

        $events = $this->eventsOfType($this->persisted,NotificationType::PLANNING_MISSION_CANCELLED);
        $recipients = array_map(fn ($e) => $e->getUser(), $events);
        $this->assertContains($instr, $recipients);
        $this->assertCount(2, $events, 'both surgeon and instrumentist must each receive one event');
    }

    public function test_cancelled_does_not_notify_instrumentist_when_none_assigned(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::CANCELLED);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED));

        $events = $this->eventsOfType($this->persisted,NotificationType::PLANNING_MISSION_CANCELLED);
        $this->assertCount(1, $events, 'only the surgeon is notified when no instrumentist is assigned');
    }

    public function test_cancelled_skips_gracefully_when_no_surgeon_and_no_instrumentist(): void
    {
        $mission = $this->makeMission(null, MissionStatus::CANCELLED);
        $this->stubMissionFind($mission);
        $this->em->expects($this->never())->method('persist');

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED));
    }

    public function test_cancelled_skips_gracefully_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->em->expects($this->never())->method('persist');

        $msg = new MissionLifecycleChangedMessage(
            missionId:  9999,
            changeType: MissionChangeType::CANCELLED,
            actorId:    1,
            payload:    [],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($msg);
    }

    public function test_cancelled_respects_preference_gating(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $instr   = $this->makeUser('instr@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::CANCELLED, $instr);
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturnCallback(fn (User $u) => new NotificationChannels(inApp: $u === $surgeon, email: false, push: false));

        $this->makeHandler()->__invoke($this->makeMessage($mission, MissionChangeType::CANCELLED));

        $events = $this->eventsOfType($this->persisted,NotificationType::PLANNING_MISSION_CANCELLED);
        $this->assertCount(1, $events);
        $this->assertSame($surgeon, $events[0]->getUser());
    }

    // ── Unknown / forward-compatible changeTypes ──────────────────────────────

    public function test_added_change_type_logs_and_does_not_throw(): void
    {
        $this->em->expects($this->never())->method('persist');

        $msg = new MissionLifecycleChangedMessage(
            missionId:  1,
            changeType: MissionChangeType::ADDED,
            actorId:    1,
            payload:    [],
            occurredAt: new \DateTimeImmutable(),
        );

        // Must not throw
        $this->makeHandler()->__invoke($msg);
    }

    public function test_time_changed_change_type_logs_and_does_not_throw(): void
    {
        $this->em->expects($this->never())->method('persist');

        $msg = new MissionLifecycleChangedMessage(
            missionId:  1,
            changeType: MissionChangeType::TIME_CHANGED,
            actorId:    1,
            payload:    [],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($msg);
    }

    // ── Failure isolation ─────────────────────────────────────────────────────

    public function test_inapp_failure_does_not_prevent_push(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: true));

        // em->flush() throws — inApp step fails
        $this->em->method('persist');
        $this->em->method('flush')->willThrowException(new \RuntimeException('DB write failed'));

        // push must still be attempted
        $this->webPushService->expects($this->once())
            ->method('sendToUser');

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::CLAIMED, ['instrumentistName' => 'Sophie'])
        );
    }

    public function test_push_failure_does_not_propagate_exception(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: true));

        $this->capturePersisted();

        $this->webPushService->method('sendToUser')
            ->willThrowException(new \RuntimeException('Push service unavailable'));

        // Must not throw; inApp notification must still be persisted
        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::CLAIMED, ['instrumentistName' => 'Sophie'])
        );

        $events = $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_COVERED);
        $this->assertCount(1, $events, 'inApp notification must survive a push failure.');
    }

    public function test_preference_resolver_failure_falls_back_to_inapp_only(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $this->stubMissionFind($mission);

        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->preferenceResolver->method('resolve')
            ->willThrowException(new \RuntimeException('Preference service unavailable'));

        $this->capturePersisted();

        // Must not throw and must still create the inApp notification (fallback: inApp=true)
        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::CLAIMED, ['instrumentistName' => 'Sophie'])
        );

        $events = $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_COVERED);
        $this->assertCount(1, $events);
    }

    // ── Structured logging ────────────────────────────────────────────────────

    public function test_handler_logs_received_event_on_every_invocation(): void
    {
        $this->em->method('find')->willReturn(null);

        $this->logger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                'MissionLifecycleChanged received',
                $this->arrayHasKey('missionId'),
            );

        $msg = new MissionLifecycleChangedMessage(
            missionId:  42,
            changeType: MissionChangeType::CLAIMED,
            actorId:    1,
            payload:    [],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($msg);
    }

    public function test_handler_logs_warning_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('mission not found'),
                $this->arrayHasKey('missionId'),
            );

        $msg = new MissionLifecycleChangedMessage(
            missionId:  9999,
            changeType: MissionChangeType::CLAIMED,
            actorId:    1,
            payload:    [],
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($msg);
    }

    // ── Afternoon period label ─────────────────────────────────────────────────

    public function test_afternoon_mission_uses_apres_midi_period_label(): void
    {
        $surgeon = $this->makeUser('surgeon@test.com');
        $mission = $this->makeMission($surgeon, MissionStatus::ASSIGNED);
        $mission->setStartAt(new \DateTimeImmutable('2026-07-15 14:00:00'));
        $this->stubMissionFind($mission);
        $this->capturePersisted();

        $this->makeHandler()->__invoke(
            $this->makeMessage($mission, MissionChangeType::CLAIMED, ['instrumentistName' => 'Sophie'])
        );

        $event = $this->eventsOfType($this->persisted,NotificationType::SURGEON_POST_COVERED)[0] ?? null;

        $this->assertSame('Après-midi', $event?->getPayload()['periodLabel'] ?? null);
    }
}
