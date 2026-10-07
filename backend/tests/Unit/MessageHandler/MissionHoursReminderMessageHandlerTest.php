<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use App\Message\MissionHoursReminderMessage;
use App\Message\SendTemplatedEmailMessage;
use App\MessageHandler\MissionHoursReminderMessageHandler;
use App\Service\MissionHoursReminderService;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-133 — routage Push → repli email du rappel des heures, garde de réassignation et
 * isolation des échecs (le rappel est déjà audité côté requête manager).
 */
final class MissionHoursReminderMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private OutboundNotificationService&MockObject $outbound;
    private MessageBusInterface&MockObject $bus;

    /** @var object[] */
    private array $dispatched = [];
    /** @var array<int, array<string, mixed>> */
    private array $pushCalls = [];
    /** @var array<int, array<string, mixed>> */
    private array $emailCalls = [];
    private array $entitiesById = [];
    private OutboundNotificationStatus $pushStatus = OutboundNotificationStatus::SENT;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->outbound = $this->createMock(OutboundNotificationService::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => $this->entitiesById[$class][$id] ?? null);

        $this->bus->method('dispatch')->willReturnCallback(function (object $msg): Envelope {
            $this->dispatched[] = $msg;
            return new Envelope($msg);
        });

        $this->outbound->method('recordPushSend')->willReturnCallback(
            function (User $recipient, string $type, string $title, string $body, array $data, ?Mission $mission) {
                $this->pushCalls[] = compact('recipient', 'type', 'title', 'body', 'data', 'mission');
                return (new OutboundNotification())->setStatus($this->pushStatus);
            }
        );
        $this->outbound->method('recordEmailQueued')->willReturnCallback(
            function (User $recipient, string $type, string $subject, ?string $bodyText = null, ?string $bodyHtml = null, array $rawData = [], ?Mission $mission = null, ?OutboundNotification $fallbackOf = null, ?OutboundNotificationFallbackReason $fallbackReason = null) {
                $this->emailCalls[] = compact('recipient', 'type', 'subject', 'rawData', 'mission', 'fallbackOf', 'fallbackReason');
                return new OutboundNotification();
            }
        );
    }

    private function makeHandler(): MissionHoursReminderMessageHandler
    {
        return new MissionHoursReminderMessageHandler(
            $this->em,
            $this->outbound,
            $this->bus,
            new NullLogger(),
            'https://app.test',
            'noreply@test.com',
            'SurgicalHub',
        );
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }

    private function registerMission(int $missionId, int $instrumentistId): Mission
    {
        $instrumentist = (new User())->setEmail('instr@test.local')->setRoles(['ROLE_INSTRUMENTIST'])->setFirstname('Alice');
        $this->setId($instrumentist, $instrumentistId);

        $tz = new \DateTimeZone('Europe/Brussels');
        $mission = (new Mission())
            ->setInstrumentist($instrumentist)
            ->setStartAt(new \DateTimeImmutable('2026-10-06 08:00:00', $tz))
            ->setEndAt(new \DateTimeImmutable('2026-10-06 17:00:00', $tz));
        $this->setId($mission, $missionId);
        $this->entitiesById[Mission::class][$missionId] = $mission;

        return $mission;
    }

    public function test_push_delivered_sends_no_email(): void
    {
        $this->registerMission(501, 7);

        ($this->makeHandler())(new MissionHoursReminderMessage(501, 7));

        self::assertCount(1, $this->pushCalls);
        self::assertSame(MissionHoursReminderService::NOTIFICATION_TYPE, $this->pushCalls[0]['type']);
        self::assertSame('Heures réelles à renseigner', $this->pushCalls[0]['title']);
        self::assertStringContainsString('heures réellement prestées', $this->pushCalls[0]['body']);
        self::assertStringContainsString('06/10/2026', $this->pushCalls[0]['body']);
        self::assertSame('/app/i/missions/501', $this->pushCalls[0]['data']['url']);
        self::assertSame([], $this->emailCalls);
        self::assertSame([], $this->dispatched);
    }

    public function test_undelivered_push_falls_back_to_a_traced_email(): void
    {
        $this->pushStatus = OutboundNotificationStatus::FAILED;
        $this->registerMission(502, 8);

        ($this->makeHandler())(new MissionHoursReminderMessage(502, 8));

        self::assertCount(1, $this->emailCalls);
        self::assertSame(MissionHoursReminderService::NOTIFICATION_TYPE, $this->emailCalls[0]['type']);
        self::assertSame(OutboundNotificationFallbackReason::NO_SUBSCRIPTION, $this->emailCalls[0]['fallbackReason']);

        self::assertCount(1, $this->dispatched);
        $email = $this->dispatched[0];
        self::assertInstanceOf(SendTemplatedEmailMessage::class, $email);
        self::assertSame('instr@test.local', $email->to);
        self::assertSame('emails/mission_hours_reminder.html.twig', $email->htmlTemplate);
        self::assertSame('https://app.test/app/i/missions/502', $email->context['missionUrl']);
        self::assertSame('06/10/2026', $email->context['missionDay']);
    }

    public function test_reassigned_mission_notifies_nobody(): void
    {
        $this->registerMission(503, 9);

        ($this->makeHandler())(new MissionHoursReminderMessage(503, 999));

        self::assertSame([], $this->pushCalls);
        self::assertSame([], $this->emailCalls);
    }

    public function test_missing_mission_notifies_nobody(): void
    {
        ($this->makeHandler())(new MissionHoursReminderMessage(404, 1));

        self::assertSame([], $this->pushCalls);
    }

    public function test_delivery_failure_is_absorbed(): void
    {
        $this->registerMission(504, 10);
        $outbound = $this->createMock(OutboundNotificationService::class);
        $outbound->method('recordPushSend')->willThrowException(new \RuntimeException('push gateway down'));

        $handler = new MissionHoursReminderMessageHandler($this->em, $outbound, $this->bus, new NullLogger(), 'https://app.test', 'noreply@test.com', 'SurgicalHub');

        $handler(new MissionHoursReminderMessage(504, 10));

        self::assertSame([], $this->dispatched); // aucune exception remontée, aucun email partiel
    }
}
