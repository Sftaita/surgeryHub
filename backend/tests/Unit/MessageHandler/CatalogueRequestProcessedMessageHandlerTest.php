<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\OutboundNotification;
use App\Entity\OutboundNotificationAttempt;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use App\Message\CatalogueRequestProcessedMessage;
use App\MessageHandler\CatalogueRequestProcessedMessageHandler;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * D-093 — CatalogueRequestProcessedMessageHandler mirrors MissionPublishedMessageHandler's
 * surgeon-notification test style: push-priority with email fallback, in-app row shape,
 * both outcomes (accepted/ignored) and both proposal kinds (intervention type / material).
 */
final class CatalogueRequestProcessedMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private OutboundNotificationService&MockObject $outboundNotificationService;
    private NotificationService&MockObject $notificationService;
    private NotificationPreferenceResolver&MockObject $preferenceResolver;
    private NotificationTargetResolver $targetResolver;
    private LoggerInterface&MockObject $logger;

    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->outboundNotificationService = $this->createMock(OutboundNotificationService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->targetResolver = new NotificationTargetResolver();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeInstrumentist(): User
    {
        $u = new User();
        $u->setEmail('instr-' . self::$nextId . '@test.com');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(): Mission
    {
        $site = new Hospital();
        $site->setName('Site Test');
        $this->setId($site, self::$nextId++);

        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable('2026-08-10 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-08-10 10:00:00'));
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function makePushNotification(OutboundNotificationStatus $status, bool $withExpiredAttempt = false): OutboundNotification
    {
        $n = (new OutboundNotification())->setChannel(OutboundNotificationChannel::PUSH)->setStatus($status);
        if ($withExpiredAttempt) {
            $n->addAttempt((new OutboundNotificationAttempt())->setSuccess(false)->setReason('expired'));
        }
        $this->setId($n, self::$nextId++);
        return $n;
    }

    private function handler(): CatalogueRequestProcessedMessageHandler
    {
        return new CatalogueRequestProcessedMessageHandler(
            $this->em,
            $this->outboundNotificationService,
            $this->notificationService,
            $this->preferenceResolver,
            $this->targetResolver,
            $this->logger,
        );
    }

    private function message(User $recipient, Mission $mission, bool $accepted, CatalogueRequestKind $kind = CatalogueRequestKind::INTERVENTION_TYPE, string $label = 'PTG'): CatalogueRequestProcessedMessage
    {
        return new CatalogueRequestProcessedMessage(
            kind: $kind,
            requestId: 42,
            accepted: $accepted,
            recipientUserId: $recipient->getId(),
            missionId: $mission->getId(),
            label: $label,
            occurredAt: new \DateTimeImmutable(),
        );
    }

    private function mockEmFind(User $instrumentist, Mission $mission): void
    {
        $this->em->method('find')->willReturnCallback(
            fn (string $class, $id) => match ($class) {
                User::class => $instrumentist,
                Mission::class => $mission,
                default => null,
            },
        );
    }

    // ── Canal : push d'abord, repli email ────────────────────────────────────

    public function test_accepted_gets_push_only_when_deliverable_no_email(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission();
        $this->mockEmFind($instr, $mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $this->outboundNotificationService->expects($this->once())
            ->method('recordPushSend')
            ->with($instr, NotificationType::CATALOGUE_REQUEST_RESOLVED->value, $this->anything(), $this->anything())
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));
        $this->notificationService->expects($this->never())->method('catalogueRequestResolvedNotifyInstrumentist');

        $this->handler()->__invoke($this->message($instr, $mission, accepted: true));
    }

    public function test_accepted_falls_back_to_email_when_push_not_deliverable(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission();
        $this->mockEmFind($instr, $mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SKIPPED));
        $this->notificationService->expects($this->once())
            ->method('catalogueRequestResolvedNotifyInstrumentist')
            ->with($mission, $instr, 'PTG', 'intervention', $this->isInstanceOf(OutboundNotification::class), OutboundNotificationFallbackReason::NO_SUBSCRIPTION);

        $this->handler()->__invoke($this->message($instr, $mission, accepted: true));
    }

    public function test_ignored_falls_back_to_email_when_push_not_deliverable(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission();
        $this->mockEmFind($instr, $mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SKIPPED));
        $this->notificationService->expects($this->once())
            ->method('catalogueRequestIgnoredNotifyInstrumentist')
            ->with($mission, $instr, 'Vis titane', 'matériel', $this->isInstanceOf(OutboundNotification::class), OutboundNotificationFallbackReason::NO_SUBSCRIPTION);
        $this->notificationService->expects($this->never())->method('catalogueRequestResolvedNotifyInstrumentist');

        $this->handler()->__invoke($this->message($instr, $mission, accepted: false, kind: CatalogueRequestKind::MATERIAL_ITEM, label: 'Vis titane'));
    }

    public function test_push_preference_disabled_goes_straight_to_email_without_attempting_push(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission();
        $this->mockEmFind($instr, $mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: false));

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->once())
            ->method('catalogueRequestResolvedNotifyInstrumentist')
            ->with($mission, $instr, 'PTG', 'intervention');

        $this->handler()->__invoke($this->message($instr, $mission, accepted: true));
    }

    public function test_both_channels_disabled_sends_nothing(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission();
        $this->mockEmFind($instr, $mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: false, email: false, push: false));

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->never())->method('catalogueRequestResolvedNotifyInstrumentist');
        $this->notificationService->expects($this->never())->method('catalogueRequestIgnoredNotifyInstrumentist');

        $this->handler()->__invoke($this->message($instr, $mission, accepted: true));
    }

    public function test_never_sends_push_and_email_together(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission();
        $this->mockEmFind($instr, $mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));
        $this->notificationService->expects($this->never())->method('catalogueRequestResolvedNotifyInstrumentist');

        $this->handler()->__invoke($this->message($instr, $mission, accepted: true));
    }

    // ── Défensif ──────────────────────────────────────────────────────────────

    public function test_does_nothing_when_recipient_not_found(): void
    {
        $mission = $this->makeMission();
        $this->em->method('find')->willReturnCallback(
            fn (string $class, $id) => $class === Mission::class ? $mission : null,
        );

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->never())->method('catalogueRequestResolvedNotifyInstrumentist');

        $this->handler()->__invoke($this->message($this->makeInstrumentist(), $mission, accepted: true));
    }

    public function test_does_nothing_when_mission_not_found(): void
    {
        $instr = $this->makeInstrumentist();
        $this->em->method('find')->willReturnCallback(
            fn (string $class, $id) => $class === User::class ? $instr : null,
        );

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->never())->method('catalogueRequestResolvedNotifyInstrumentist');

        $this->handler()->__invoke($this->message($instr, $this->makeMission(), accepted: true));
    }

    // ── Contenu : in-app row shape, aucune donnée patient ────────────────────

    public function test_in_app_notification_event_is_created_with_mission_and_correct_type(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission();
        $this->mockEmFind($instr, $mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $persisted = null;
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(function ($entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $this->em->expects($this->once())->method('flush');

        $this->handler()->__invoke($this->message($instr, $mission, accepted: true, label: 'PTG'));

        $this->assertInstanceOf(NotificationEvent::class, $persisted);
        $this->assertSame(NotificationType::CATALOGUE_REQUEST_RESOLVED->value, $persisted->getEventType());
        $this->assertSame($mission, $persisted->getMission());
        $this->assertSame($instr, $persisted->getUser());
        $payload = $persisted->getPayload();
        $this->assertSame('PTG', $payload['label']);
        $this->assertArrayNotHasKey('patientName', $payload);
    }

    public function test_ignored_in_app_notification_event_uses_ignored_type(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission();
        $this->mockEmFind($instr, $mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $persisted = null;
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted = $entity;
        });

        $this->handler()->__invoke($this->message($instr, $mission, accepted: false));

        $this->assertSame(NotificationType::CATALOGUE_REQUEST_IGNORED->value, $persisted->getEventType());
    }
}
