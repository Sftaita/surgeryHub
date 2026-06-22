<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\PlanningAlertRaisedMessage;
use App\Message\SendBillingEmailMessage;
use App\MessageHandler\PlanningAlertRaisedMessageHandler;
use App\Service\NotificationChannels;
use App\Service\NotificationPreferenceResolver;
use App\Service\NotificationService;
use App\Service\WebPushServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class PlanningAlertRaisedMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject       $em;
    private NotificationPreferenceResolver&MockObject $resolver;
    private NotificationService&MockObject           $notificationService;
    private WebPushServiceInterface&MockObject       $webPush;
    private MessageBusInterface&MockObject           $bus;

    /** @var array<int, NotificationChannels> keyed by user id */
    private array $channelsByUser = [];
    private array $foundUsers     = [];
    private array $dispatchedEmails = [];

    private static int $idSeq = 0;

    protected function setUp(): void
    {
        $this->em                  = $this->createMock(EntityManagerInterface::class);
        $this->resolver             = $this->createMock(NotificationPreferenceResolver::class);
        $this->notificationService  = $this->createMock(NotificationService::class);
        $this->webPush              = $this->createMock(WebPushServiceInterface::class);
        $this->bus                  = $this->createMock(MessageBusInterface::class);
        $this->channelsByUser       = [];
        $this->foundUsers           = [];
        $this->dispatchedEmails     = [];

        $this->em->method('find')->willReturnCallback(fn (string $class, $id) => $this->foundUsers[$id] ?? null);

        $this->resolver->method('resolve')->willReturnCallback(
            fn (User $user, NotificationType $type) => $this->channelsByUser[$user->getId()] ?? new NotificationChannels(true, false, false)
        );

        $this->bus->method('dispatch')->willReturnCallback(function (object $message) {
            $this->dispatchedEmails[] = $message;
            return new Envelope($message);
        });
    }

    private function makeHandler(): PlanningAlertRaisedMessageHandler
    {
        return new PlanningAlertRaisedMessageHandler(
            $this->em, $this->resolver, $this->notificationService, $this->webPush, $this->bus,
            'noreply@surgicalhub.test', 'SurgicalHub',
        );
    }

    private function makeUser(string $email): User
    {
        $u = new User();
        $u->setEmail($email);
        $rp = new \ReflectionProperty(User::class, 'id');
        $rp->setValue($u, ++self::$idSeq);
        $this->foundUsers[$u->getId()] = $u;
        return $u;
    }

    private function makeMessage(array $recipientIds): PlanningAlertRaisedMessage
    {
        return new PlanningAlertRaisedMessage(
            alertId: 1,
            alertType: 'SURGEON_ABSENCE',
            missionId: 10,
            siteId: 5,
            siteName: 'Alpha',
            missionDate: '2026-01-12',
            absenceId: 99,
            surgeonId: 2,
            instrumentistId: 3,
            recipientUserIds: $recipientIds,
            detectedAt: '2026-01-10T00:00:00+00:00',
        );
    }

    public function test_in_app_notification_created_when_channel_enabled(): void
    {
        $user = $this->makeUser('manager@test.com');
        $this->channelsByUser[$user->getId()] = new NotificationChannels(true, false, false);

        $this->notificationService->expects($this->once())
            ->method('planningAlertRaisedNotifyInApp')
            ->with($user, $this->anything());

        $this->makeHandler()->__invoke($this->makeMessage([$user->getId()]));
    }

    public function test_in_app_notification_skipped_when_channel_disabled(): void
    {
        $user = $this->makeUser('manager@test.com');
        $this->channelsByUser[$user->getId()] = new NotificationChannels(false, false, false);

        $this->notificationService->expects($this->never())->method('planningAlertRaisedNotifyInApp');

        $this->makeHandler()->__invoke($this->makeMessage([$user->getId()]));
    }

    public function test_email_dispatched_when_channel_enabled_and_user_has_email(): void
    {
        $user = $this->makeUser('manager@test.com');
        $this->channelsByUser[$user->getId()] = new NotificationChannels(false, true, false);

        $this->makeHandler()->__invoke($this->makeMessage([$user->getId()]));

        $this->assertCount(1, $this->dispatchedEmails);
        $this->assertInstanceOf(SendBillingEmailMessage::class, $this->dispatchedEmails[0]);
        $this->assertSame('manager@test.com', $this->dispatchedEmails[0]->to);
    }

    public function test_email_not_dispatched_when_channel_disabled(): void
    {
        $user = $this->makeUser('manager@test.com');
        $this->channelsByUser[$user->getId()] = new NotificationChannels(true, false, false);

        $this->makeHandler()->__invoke($this->makeMessage([$user->getId()]));

        $this->assertSame([], $this->dispatchedEmails);
    }

    public function test_push_sent_when_channel_enabled(): void
    {
        $user = $this->makeUser('manager@test.com');
        $this->channelsByUser[$user->getId()] = new NotificationChannels(false, false, true);

        $this->webPush->expects($this->once())->method('sendToUser')->with($user, $this->isType('string'), $this->isType('string'), $this->anything());

        $this->makeHandler()->__invoke($this->makeMessage([$user->getId()]));
    }

    public function test_push_not_sent_when_channel_disabled(): void
    {
        $user = $this->makeUser('manager@test.com');
        $this->channelsByUser[$user->getId()] = new NotificationChannels(true, true, false);

        $this->webPush->expects($this->never())->method('sendToUser');

        $this->makeHandler()->__invoke($this->makeMessage([$user->getId()]));
    }

    public function test_unknown_recipient_id_is_skipped_without_error(): void
    {
        $this->notificationService->expects($this->never())->method('planningAlertRaisedNotifyInApp');

        $this->makeHandler()->__invoke($this->makeMessage([999999]));

        $this->assertTrue(true, 'must not throw for a recipient id that no longer resolves to a User');
    }

    public function test_multiple_recipients_each_get_their_own_resolved_channels(): void
    {
        $managerWantsInApp = $this->makeUser('manager@test.com');
        $this->channelsByUser[$managerWantsInApp->getId()] = new NotificationChannels(true, false, false);

        $instWantsPush = $this->makeUser('inst@test.com');
        $this->channelsByUser[$instWantsPush->getId()] = new NotificationChannels(false, false, true);

        $this->notificationService->expects($this->once())->method('planningAlertRaisedNotifyInApp')->with($managerWantsInApp, $this->anything());
        $this->webPush->expects($this->once())->method('sendToUser')->with($instWantsPush, $this->anything(), $this->anything(), $this->anything());

        $this->makeHandler()->__invoke($this->makeMessage([$managerWantsInApp->getId(), $instWantsPush->getId()]));
    }
}
