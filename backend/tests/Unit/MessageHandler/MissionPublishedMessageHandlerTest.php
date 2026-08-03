<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\OutboundNotification;
use App\Entity\OutboundNotificationAttempt;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use App\Message\MissionPublishedMessage;
use App\MessageHandler\MissionPublishedMessageHandler;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Point 8 (audit UX) — MissionPublishedMessageHandler covers two side effects of a
 * mission publish (DRAFT → OPEN): the instrumentist broadcast (moved here, now async —
 * resolves the D-081 tech debt) and the new surgeon notification, push-priority with
 * email fallback (D-083 pattern, mocked the same way as EncodingReminderServiceTest).
 */
final class MissionPublishedMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private WebPushService&MockObject $webPushService;
    private OutboundNotificationService&MockObject $outboundNotificationService;
    private NotificationService&MockObject $notificationService;
    private NotificationPreferenceResolver&MockObject $preferenceResolver;
    private NotificationTargetResolver $targetResolver;
    private LoggerInterface&MockObject $logger;

    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->webPushService = $this->createMock(WebPushService::class);
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

    private function makeSurgeon(): User
    {
        $u = new User();
        $u->setEmail('surgeon-' . self::$nextId . '@test.com');
        $u->setRoles(['ROLE_SURGEON']);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(?User $surgeon): Mission
    {
        $site = new Hospital();
        $site->setName('Site Test');
        $this->setId($site, self::$nextId++);

        $m = new Mission();
        $m->setStatus(MissionStatus::OPEN);
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        if ($surgeon !== null) {
            $m->setSurgeon($surgeon);
        }
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

    private function handler(): MissionPublishedMessageHandler
    {
        return new MissionPublishedMessageHandler(
            $this->em,
            $this->webPushService,
            $this->outboundNotificationService,
            $this->notificationService,
            $this->preferenceResolver,
            $this->targetResolver,
            $this->logger,
        );
    }

    private function message(int $missionId): MissionPublishedMessage
    {
        return new MissionPublishedMessage($missionId, actorId: 1, occurredAt: new \DateTimeImmutable());
    }

    // ── Instrumentistes du site — comportement préservé, désormais async ────────

    public function test_sends_the_same_instrumentist_broadcast_as_before(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: false));

        $this->webPushService->expects($this->once())
            ->method('sendToSiteInstrumentists')
            ->with($mission, 'Nouvelle mission disponible', $this->anything(), ['missionId' => $mission->getId()]);

        $this->handler()->__invoke($this->message($mission->getId()));
    }

    // ── Chirurgien : canal ───────────────────────────────────────────────────

    public function test_surgeon_gets_push_only_when_deliverable_no_email(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $this->outboundNotificationService->expects($this->once())
            ->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));
        $this->notificationService->expects($this->never())->method('missionOpenNotifySurgeon');

        $this->handler()->__invoke($this->message($mission->getId()));
    }

    public function test_surgeon_falls_back_to_email_when_push_not_deliverable(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SKIPPED));
        $this->notificationService->expects($this->once())
            ->method('missionOpenNotifySurgeon')
            ->with($mission, $mission->getSurgeon(), $this->isInstanceOf(OutboundNotification::class), OutboundNotificationFallbackReason::NO_SUBSCRIPTION);

        $this->handler()->__invoke($this->message($mission->getId()));
    }

    public function test_surgeon_with_push_preference_disabled_goes_straight_to_email_without_attempting_push(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: false));

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->once())
            ->method('missionOpenNotifySurgeon')
            ->with($mission, $mission->getSurgeon());

        $this->handler()->__invoke($this->message($mission->getId()));
    }

    public function test_surgeon_with_both_channels_disabled_sends_nothing(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: false, email: false, push: false));

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->never())->method('missionOpenNotifySurgeon');

        $this->handler()->__invoke($this->message($mission->getId()));
    }

    public function test_never_sends_push_and_email_together_for_surgeon(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));
        $this->notificationService->expects($this->never())->method('missionOpenNotifySurgeon');

        $this->handler()->__invoke($this->message($mission->getId()));
    }

    public function test_skips_defensively_when_mission_somehow_has_no_surgeon(): void
    {
        $mission = $this->makeMission(null);
        $this->em->method('find')->willReturn($mission);

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->never())->method('missionOpenNotifySurgeon');

        $this->handler()->__invoke($this->message($mission->getId()));
    }

    public function test_does_nothing_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);

        $this->webPushService->expects($this->never())->method('sendToSiteInstrumentists');
        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');

        $this->handler()->__invoke($this->message(999999));
    }

    // ── Contenu : aucune donnée patient ──────────────────────────────────────

    public function test_push_payload_contains_no_patient_data(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $captured = [];
        $this->outboundNotificationService->method('recordPushSend')
            ->willReturnCallback(function (User $user, string $type, string $title, string $body, array $data) use (&$captured): OutboundNotification {
                $captured = ['title' => $title, 'body' => $body, 'data' => $data];
                return $this->makePushNotification(OutboundNotificationStatus::SENT);
            });

        $this->handler()->__invoke($this->message($mission->getId()));

        $this->assertStringNotContainsStringIgnoringCase('patient', $captured['title']);
        $this->assertStringNotContainsStringIgnoringCase('patient', $captured['body']);
        $this->assertSame(['missionId', 'url'], array_keys($captured['data']));
        $this->assertSame($mission->getId(), $captured['data']['missionId']);
    }

    public function test_in_app_notification_event_is_created_with_mission_and_no_patient_data(): void
    {
        $mission = $this->makeMission($this->makeSurgeon());
        $this->em->method('find')->willReturn($mission);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $persisted = null;
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(function ($entity) use (&$persisted): void {
                $persisted = $entity;
            });
        $this->em->expects($this->once())->method('flush');

        $this->handler()->__invoke($this->message($mission->getId()));

        $this->assertInstanceOf(NotificationEvent::class, $persisted);
        $this->assertSame(NotificationType::SURGEON_MISSION_OPEN_PUBLISHED->value, $persisted->getEventType());
        $this->assertSame($mission, $persisted->getMission());
        $this->assertSame($mission->getSurgeon(), $persisted->getUser());
        $payload = $persisted->getPayload();
        $this->assertArrayNotHasKey('patientName', $payload);
        $this->assertSame(['missionId', 'dayLabel', 'siteName'], array_keys($payload));
    }
}
