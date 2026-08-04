<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\CatalogueRequestKind;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Message\CatalogueRequestCreatedMessage;
use App\MessageHandler\CatalogueRequestCreatedMessageHandler;
use App\Repository\UserRepository;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Follow-up D-093 — CatalogueRequestCreatedMessageHandler prévient les managers/admins
 * actifs (jamais l'instrumentiste créateur) qu'une nouvelle proposition catalogue attend
 * leur traitement. In-app + push uniquement — jamais d'email, ni en défaut ni en repli
 * push-échoué (contrairement à CatalogueRequestProcessedMessageHandler).
 */
final class CatalogueRequestCreatedMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $userRepository;
    private OutboundNotificationService&MockObject $outboundNotificationService;
    private NotificationPreferenceResolver&MockObject $preferenceResolver;
    private NotificationTargetResolver $targetResolver;
    private LoggerInterface&MockObject $logger;

    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->outboundNotificationService = $this->createMock(OutboundNotificationService::class);
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

    private function makeManager(): User
    {
        $u = new User();
        $u->setEmail('mgr-' . self::$nextId . '@test.com');
        $u->setRoles(['ROLE_MANAGER']);
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

    private function makePushNotification(OutboundNotificationStatus $status): OutboundNotification
    {
        $n = (new OutboundNotification())->setChannel(OutboundNotificationChannel::PUSH)->setStatus($status);
        $this->setId($n, self::$nextId++);
        return $n;
    }

    private function handler(): CatalogueRequestCreatedMessageHandler
    {
        return new CatalogueRequestCreatedMessageHandler(
            $this->em,
            $this->userRepository,
            $this->outboundNotificationService,
            $this->preferenceResolver,
            $this->targetResolver,
            $this->logger,
        );
    }

    private function message(Mission $mission, CatalogueRequestKind $kind = CatalogueRequestKind::INTERVENTION_TYPE, string $label = 'PTH'): CatalogueRequestCreatedMessage
    {
        return new CatalogueRequestCreatedMessage(
            kind: $kind,
            requestId: 42,
            missionId: $mission->getId(),
            label: $label,
            occurredAt: new \DateTimeImmutable(),
        );
    }

    private function mockEmFindMission(Mission $mission): void
    {
        $this->em->method('find')->willReturnCallback(
            fn (string $class, $id) => $class === Mission::class ? $mission : null,
        );
    }

    // ── Destinataires ─────────────────────────────────────────────────────────

    public function test_notifies_every_active_manager_and_admin(): void
    {
        $mission = $this->makeMission();
        $this->mockEmFindMission($mission);
        $mgr1 = $this->makeManager();
        $mgr2 = $this->makeManager();
        $this->userRepository->method('findManagersAndAdmins')->with(true)->willReturn([$mgr1, $mgr2]);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->handler()->__invoke($this->message($mission));

        $this->assertCount(2, $persisted);
        $this->assertSame($mgr1, $persisted[0]->getUser());
        $this->assertSame($mgr2, $persisted[1]->getUser());
    }

    public function test_one_manager_failing_does_not_block_the_others(): void
    {
        $mission = $this->makeMission();
        $this->mockEmFindMission($mission);
        $mgr1 = $this->makeManager();
        $mgr2 = $this->makeManager();
        $this->userRepository->method('findManagersAndAdmins')->willReturn([$mgr1, $mgr2]);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        // mgr1's in-app persist blows up entirely (simulates any unexpected failure
        // mid-processing) — the per-recipient try/catch in notifyManager() must still
        // let mgr2 be processed normally afterward.
        $persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($entity) use (&$persisted, $mgr1): void {
            if ($entity instanceof NotificationEvent && $entity->getUser() === $mgr1) {
                throw new \RuntimeException('boom');
            }
            $persisted[] = $entity;
        });
        $this->logger->expects($this->once())->method('error');

        $this->handler()->__invoke($this->message($mission));

        $this->assertCount(1, $persisted);
        $this->assertSame($mgr2, $persisted[0]->getUser());
    }

    // ── In-app ────────────────────────────────────────────────────────────────

    public function test_in_app_notification_event_has_correct_type_and_payload(): void
    {
        $mission = $this->makeMission();
        $this->mockEmFindMission($mission);
        $mgr = $this->makeManager();
        $this->userRepository->method('findManagersAndAdmins')->willReturn([$mgr]);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $persisted = null;
        $this->em->expects($this->once())->method('persist')->willReturnCallback(function ($entity) use (&$persisted): void {
            $persisted = $entity;
        });

        $this->handler()->__invoke($this->message($mission, CatalogueRequestKind::MATERIAL_ITEM, 'Vis titane'));

        $this->assertInstanceOf(NotificationEvent::class, $persisted);
        $this->assertSame(NotificationType::CATALOGUE_REQUEST_CREATED->value, $persisted->getEventType());
        $this->assertSame($mgr, $persisted->getUser());
        $this->assertSame($mission, $persisted->getMission());
        $payload = $persisted->getPayload();
        $this->assertSame('Vis titane', $payload['label']);
        $this->assertSame('MATERIAL_ITEM', $payload['kind']);
        $this->assertArrayNotHasKey('patientName', $payload);
        $this->assertArrayNotHasKey('amount', $payload);
    }

    public function test_in_app_disabled_by_preference_creates_no_notification_event(): void
    {
        $mission = $this->makeMission();
        $this->mockEmFindMission($mission);
        $mgr = $this->makeManager();
        $this->userRepository->method('findManagersAndAdmins')->willReturn([$mgr]);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: false, email: false, push: false));

        $this->em->expects($this->never())->method('persist');

        $this->handler()->__invoke($this->message($mission));
    }

    // ── Push, jamais de repli email ──────────────────────────────────────────

    public function test_push_enabled_records_a_push_send(): void
    {
        $mission = $this->makeMission();
        $this->mockEmFindMission($mission);
        $mgr = $this->makeManager();
        $this->userRepository->method('findManagersAndAdmins')->willReturn([$mgr]);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: false, email: false, push: true));

        $this->outboundNotificationService->expects($this->once())
            ->method('recordPushSend')
            ->with($mgr, NotificationType::CATALOGUE_REQUEST_CREATED->value, $this->anything(), $this->anything())
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));

        $this->handler()->__invoke($this->message($mission));
    }

    public function test_push_disabled_by_preference_never_attempts_push(): void
    {
        $mission = $this->makeMission();
        $this->mockEmFindMission($mission);
        $mgr = $this->makeManager();
        $this->userRepository->method('findManagersAndAdmins')->willReturn([$mgr]);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');

        $this->handler()->__invoke($this->message($mission));
    }

    /**
     * Le point central du lot : contrairement à CatalogueRequestProcessedMessageHandler,
     * un push non livrable (SKIPPED) ne doit JAMAIS déclencher de repli email ici — le
     * handler n'a même pas de dépendance capable d'envoyer un email applicatif
     * (NotificationService n'est pas injecté), donc "aucun repli" n'est pas juste un
     * comportement observé, c'est structurellement impossible à violer par erreur.
     */
    public function test_undeliverable_push_never_falls_back_to_email(): void
    {
        $mission = $this->makeMission();
        $this->mockEmFindMission($mission);
        $mgr = $this->makeManager();
        $this->userRepository->method('findManagersAndAdmins')->willReturn([$mgr]);
        $this->preferenceResolver->method('resolve')->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SKIPPED));
        $this->outboundNotificationService->expects($this->never())->method('recordEmailQueued');

        $this->handler()->__invoke($this->message($mission));
    }

    // ── Défensif ──────────────────────────────────────────────────────────────

    public function test_does_nothing_when_mission_not_found(): void
    {
        $this->em->method('find')->willReturn(null);
        $this->userRepository->expects($this->never())->method('findManagersAndAdmins');
        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');

        $this->handler()->__invoke($this->message($this->makeMission()));
    }
}
