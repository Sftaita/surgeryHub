<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Message\MissionUncoveredEscalationMessage;
use App\Message\SendBillingEmailMessage;
use App\MessageHandler\MissionUncoveredEscalationMessageHandler;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationTargetResolver;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-110 (J-14) — unit coverage for channel routing and message content. Real Twig
 * rendering of the email template is covered separately; end-to-end delivery is verified
 * live per the Lot 7 methodology (MAIL_SAFE_MODE + fresh DB read).
 */
final class MissionUncoveredEscalationMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NotificationPreferenceResolver&MockObject $preferenceResolver;
    private WebPushServiceInterface&MockObject $webPushService;
    private MessageBusInterface&MockObject $bus;

    private array $dispatched = [];
    private array $persisted  = [];
    private array $entitiesById = [];
    private array $pushCalls = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->preferenceResolver = $this->createMock(NotificationPreferenceResolver::class);
        $this->webPushService = $this->createMock(WebPushServiceInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $this->dispatched = [];
        $this->bus->method('dispatch')->willReturnCallback(function (object $msg): Envelope {
            $this->dispatched[] = $msg;
            return new Envelope($msg);
        });

        $this->persisted = [];
        $this->em->method('persist')->willReturnCallback(function ($e) { $this->persisted[] = $e; });
        $this->em->method('flush');

        $this->entitiesById = [];
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => $this->entitiesById[$class][$id] ?? null);

        $this->pushCalls = [];
        $this->webPushService->method('sendToUser')->willReturnCallback(
            function (User $user, string $title, string $body, array $data) {
                $this->pushCalls[] = ['user' => $user, 'title' => $title, 'body' => $body, 'data' => $data];
            }
        );
    }

    private function makeHandler(): MissionUncoveredEscalationMessageHandler
    {
        return new MissionUncoveredEscalationMessageHandler(
            $this->em,
            $this->preferenceResolver,
            $this->webPushService,
            new NotificationTargetResolver(),
            $this->bus,
            new \Psr\Log\NullLogger(),
            'noreply@test.com',
            'SurgicalHub',
        );
    }

    private static int $nextId = 1;

    private function registerUser(string $role = 'ROLE_SURGEON'): User
    {
        $u = new User();
        $u->setEmail('d110-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname('Jean');
        $u->setLastname('Dupont');
        $id = self::$nextId++;
        $ref = new \ReflectionProperty($u, 'id');
        $ref->setAccessible(true);
        $ref->setValue($u, $id);
        $this->entitiesById[User::class][$id] = $u;
        return $u;
    }

    private function registerMission(): Mission
    {
        $m = new Mission();
        $id = self::$nextId++;
        $ref = new \ReflectionProperty($m, 'id');
        $ref->setAccessible(true);
        $ref->setValue($m, $id);
        $this->entitiesById[Mission::class][$id] = $m;
        return $m;
    }

    public function test_all_three_channels_enabled_dispatches_inapp_push_and_email(): void
    {
        $surgeon = $this->registerUser();
        $mission = $this->registerMission();

        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: true, push: true));

        $message = new MissionUncoveredEscalationMessage(
            missionId: $mission->getId(),
            surgeonId: $surgeon->getId(),
            surgeonName: 'Jean Dupont',
            siteId: 5,
            siteName: 'Delta',
            startAt: '2026-09-30T08:00:00+02:00',
            occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        self::assertCount(1, $this->persisted, 'One in-app NotificationEvent.');
        self::assertCount(1, $this->pushCalls, 'One push send.');
        self::assertStringContainsString('30/09/2026', $this->pushCalls[0]['body']);
        self::assertStringContainsString('Delta', $this->pushCalls[0]['body']);

        $emails = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage));
        self::assertCount(1, $emails);
        self::assertSame('30/09/2026', $emails[0]->context['dateLabel']);
        self::assertSame('Delta', $emails[0]->context['siteName']);
        self::assertSame('emails/mission_uncovered_escalation.html.twig', $emails[0]->htmlTemplate);
    }

    public function test_email_disabled_skips_email_but_keeps_inapp(): void
    {
        $surgeon = $this->registerUser();
        $mission = $this->registerMission();

        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: false, push: false));

        $message = new MissionUncoveredEscalationMessage(
            missionId: $mission->getId(), surgeonId: $surgeon->getId(), surgeonName: 'Jean Dupont',
            siteId: null, siteName: null, startAt: '2026-09-30T08:00:00+02:00', occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        self::assertCount(1, $this->persisted);
        self::assertEmpty(array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage));
        self::assertEmpty($this->pushCalls);
    }

    public function test_mission_not_found_is_skipped_without_throwing(): void
    {
        $surgeon = $this->registerUser();

        $message = new MissionUncoveredEscalationMessage(
            missionId: 999999, surgeonId: $surgeon->getId(), surgeonName: 'Jean Dupont',
            siteId: null, siteName: null, startAt: '2026-09-30T08:00:00+02:00', occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        self::assertEmpty($this->persisted);
        self::assertEmpty($this->dispatched);
    }

    public function test_payload_carries_no_patient_shaped_keys(): void
    {
        $surgeon = $this->registerUser();
        $mission = $this->registerMission();

        $this->preferenceResolver->method('resolve')
            ->willReturn(new NotificationChannels(inApp: true, email: true, push: false));

        $message = new MissionUncoveredEscalationMessage(
            missionId: $mission->getId(), surgeonId: $surgeon->getId(), surgeonName: 'Jean Dupont',
            siteId: 5, siteName: 'Delta', startAt: '2026-09-30T08:00:00+02:00', occurredAt: new \DateTimeImmutable(),
        );

        $this->makeHandler()->__invoke($message);

        /** @var \App\Entity\NotificationEvent $evt */
        $evt = $this->persisted[0];
        foreach (array_keys($evt->getPayload()) as $key) {
            self::assertStringNotContainsStringIgnoringCase('patient', $key);
        }

        $emails = array_values(array_filter($this->dispatched, fn ($m) => $m instanceof SendBillingEmailMessage));
        foreach (array_keys($emails[0]->context) as $key) {
            self::assertStringNotContainsStringIgnoringCase('patient', $key);
        }
    }
}
