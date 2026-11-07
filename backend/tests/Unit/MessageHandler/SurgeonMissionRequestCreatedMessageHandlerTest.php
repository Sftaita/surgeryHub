<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\NotificationDispatch;
use App\Entity\User;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\SurgeonMissionRequestCreatedMessage;
use App\MessageHandler\SurgeonMissionRequestCreatedMessageHandler;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Service\DefaultNotificationPreferenceResolver;
use App\Service\NotificationTargetResolver;
use App\Tests\Support\NotificationServiceFactory;
use App\Tests\Support\RecordsNotificationIntents;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lot 5 (D-099) — every recipient manager learns a surgeon requested a mission. The email is
 * an opt-in channel (default off): the intent always carries it, the dispatcher sends it only
 * when the manager's preference enables it.
 */
final class SurgeonMissionRequestCreatedMessageHandlerTest extends TestCase
{
    use RecordsNotificationIntents;

    private EntityManagerInterface&MockObject $em;
    private LoggerInterface&MockObject $logger;
    /** @var array<int, User> */
    private array $users = [];
    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em     = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->em->method('find')->willReturnCallback(fn ($class, $id) => $class === User::class ? ($this->users[$id] ?? null) : null);
    }

    private function handler(?NotificationDispatcher $dispatcher = null): SurgeonMissionRequestCreatedMessageHandler
    {
        return new SurgeonMissionRequestCreatedMessageHandler(
            $this->em, $dispatcher ?? $this->recordingDispatcher(), new NotificationTargetResolver(),
            NotificationServiceFactory::create($this), $this->logger,
        );
    }

    private function manager(): User
    {
        $u = (new User())->setEmail('mgr' . self::$nextId . '@test.com')->setFirstname('Max')->setRoles(['ROLE_MANAGER']);
        (new \ReflectionProperty($u, 'id'))->setValue($u, self::$nextId++);
        $this->users[$u->getId()] = $u;

        return $u;
    }

    /** @param int[] $recipients */
    private function message(array $recipients): SurgeonMissionRequestCreatedMessage
    {
        return new SurgeonMissionRequestCreatedMessage(
            77, 5, 'Dupont', 3, 'Site Test', 'BLOCK',
            '2026-11-04T08:00:00+01:00', '2026-11-04T12:30:00+01:00', $recipients,
        );
    }

    public function test_one_intent_per_manager_with_an_opt_in_email(): void
    {
        [$a, $b] = [$this->manager(), $this->manager()];

        $this->handler()($this->message([$a->getId(), $b->getId(), 99999]));

        self::assertSame([$a, $b], array_map(static fn (NotificationIntent $i) => $i->recipient, $this->intents));
        $intent = $this->intents[0];
        self::assertSame(NotificationType::SURGEON_MISSION_REQUEST_CREATED, $intent->type);
        self::assertSame(NotificationEmailPolicy::WHEN_ENABLED, $intent->emailPolicy);
        self::assertSame('surgeon_mission_request_created:77', $intent->episodeRef);
        self::assertSame('emails/surgeon_mission_request_created.html.twig', $intent->email->htmlTemplate);
        self::assertSame('Dupont', $intent->email->context['surgeonName']);
        self::assertSame('04/11/2026', $intent->email->context['date']);
        self::assertSame('08:00', $intent->email->context['startTime']);
        self::assertSame('12:30', $intent->email->context['endTime']);
        self::assertStringEndsWith('/app/m/missions/requests', $intent->email->context['requestsUrl']);
    }

    public function test_email_is_off_by_default_so_only_managers_who_tick_it_receive_it(): void
    {
        self::assertFalse(DefaultNotificationPreferenceResolver::defaultsFor(NotificationType::SURGEON_MISSION_REQUEST_CREATED)->email);
        self::assertTrue(NotificationType::SURGEON_MISSION_REQUEST_CREATED->isConfigurable());
    }

    public function test_one_manager_failing_does_not_block_the_others(): void
    {
        [$a, $b] = [$this->manager(), $this->manager()];
        $reached = [];
        $dispatcher = $this->createMock(NotificationDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $i) use ($a, &$reached) {
            if ($i->recipient === $a) {
                throw new \RuntimeException('boom');
            }
            $reached[] = $i->recipient;

            return new NotificationDispatch();
        });
        $this->logger->expects($this->once())->method('error');

        $this->handler($dispatcher)($this->message([$a->getId(), $b->getId()]));

        self::assertSame([$b], $reached);
    }
}
