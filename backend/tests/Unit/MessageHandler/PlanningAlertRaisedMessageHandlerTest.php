<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\NotificationDispatch;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Message\PlanningAlertRaisedMessage;
use App\MessageHandler\PlanningAlertRaisedMessageHandler;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Planning conflict alert — H5a: one dispatcher intent per recipient (PLANNING_ALERT,
 * channels per preferences), idempotent per alert; formerly an in-app row, a raw push and
 * an untraced email.
 */
final class PlanningAlertRaisedMessageHandlerTest extends TestCase
{
    use RecordsNotificationIntents;

    /** @var array<int, User> */
    private array $users = [];

    private function handler(?NotificationDispatcher $dispatcher = null): PlanningAlertRaisedMessageHandler
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(fn ($class, $id) => $class === User::class ? ($this->users[$id] ?? null) : null);

        return new PlanningAlertRaisedMessageHandler($em, $dispatcher ?? $this->recordingDispatcher(), $this->createMock(LoggerInterface::class));
    }

    private function user(int $id): User
    {
        $u = (new User())->setEmail("u{$id}@test.com")->setFirstname("U{$id}")->setRoles(['ROLE_MANAGER']);
        (new \ReflectionProperty($u, 'id'))->setValue($u, $id);

        return $this->users[$id] = $u;
    }

    private function message(array $recipients): PlanningAlertRaisedMessage
    {
        return new PlanningAlertRaisedMessage(
            alertId: 12, alertType: 'INSTRUMENTIST_DOUBLE_BOOKING', missionId: 99, siteId: 3, siteName: 'CHU', missionDate: '2026-10-20',
            absenceId: null, surgeonId: 5, instrumentistId: 6, recipientUserIds: $recipients, detectedAt: '2026-10-06T08:00:00+00:00',
        );
    }

    public function test_one_intent_per_recipient_idempotent_per_alert(): void
    {
        $a = $this->user(1);
        $b = $this->user(2);
        $this->handler()($this->message([1, 2]));

        self::assertSame([$a, $b], array_map(static fn (NotificationIntent $i) => $i->recipient, $this->intents));
        $i = $this->intents[0];
        self::assertSame(NotificationType::PLANNING_ALERT, $i->type);
        self::assertSame('planning_alert:12', $i->episodeRef);
        self::assertSame('emails/planning_alert.html.twig', $i->email->htmlTemplate);
        self::assertSame(['recipientName' => 'U1', 'alertType' => 'INSTRUMENTIST_DOUBLE_BOOKING', 'siteName' => 'CHU', 'missionDate' => '2026-10-20'], $i->email->context);
        self::assertSame('Une alerte planning concerne une mission du 2026-10-20 à CHU.', $i->push->body);
        self::assertSame(['alertId', 'alertType', 'missionId', 'siteId', 'siteName', 'missionDate'], array_keys($i->inAppPayload), 'no patient data');
    }

    public function test_unknown_recipient_is_skipped_and_one_failure_never_stops_the_others(): void
    {
        $b = $this->user(2);
        $this->user(3);
        $reached = [];
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $i) use (&$reached) {
            if ($i->recipient->getId() === 3) {
                throw new \RuntimeException('boom');
            }
            $reached[] = $i->recipient;

            return new NotificationDispatch();
        });

        $this->handler($dispatcher)($this->message([999, 3, 2]));

        self::assertSame([$b], $reached);
    }
}
