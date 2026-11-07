<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionCommunicationSource;
use App\Enum\NotificationType;
use App\Message\AbsenceMissionsReactedMessage;
use App\MessageHandler\AbsenceMissionsReactedMessageHandler;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Level 3 of an absence (D-062, CAS B D-118, H4 D-130): WHO gets WHICH notification type for
 * the published missions AbsenceMissionReactionService mutated, and that one recipient gets
 * one grouped email per type for the whole run. Channels, idempotency and delivery are the
 * dispatcher's (NotificationDispatcherIntegrationTest, AbsenceImpactFunctionalTest).
 */
final class AbsenceMissionsReactedMessageHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NotificationDispatcher&MockObject $dispatcher;
    /** @var array<string, list<NotificationIntent>> type => intents */
    private array $batches = [];
    /** @var array<string, callable> type => group email builder */
    private array $emailBuilders = [];
    /** @var array<int, User> */
    private array $usersById = [];
    private static int $nextId = 1;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->dispatcher = $this->createMock(NotificationDispatcher::class);
        $this->batches = $this->emailBuilders = $this->usersById = [];

        $this->em->method('find')->willReturnCallback(fn ($class, $id) => $this->usersById[$id] ?? null);
        $this->em->method('getReference')->willReturnCallback(function ($class, $id) {
            $m = new Mission();
            (new \ReflectionProperty($m, 'id'))->setValue($m, $id);

            return $m;
        });
        $this->dispatcher->method('dispatchBatch')->willReturnCallback(function (array $intents, callable $email) {
            $type = $intents[0]->type->value;
            $this->batches[$type] = array_merge($this->batches[$type] ?? [], $intents);
            $this->emailBuilders[$type] = $email;

            return [];
        });
    }

    private function handler(): AbsenceMissionsReactedMessageHandler
    {
        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->method('notificationPreferencesUrl')->willReturn('https://app.test/prefs');

        return new AbsenceMissionsReactedMessageHandler($this->em, $this->dispatcher, $notificationService, $this->createMock(LoggerInterface::class));
    }

    private function user(string $email): User
    {
        $u = (new User())->setEmail($email)->setFirstname('Test')->setLastname('User');
        $id = self::$nextId++;
        (new \ReflectionProperty($u, 'id'))->setValue($u, $id);
        $this->usersById[$id] = $u;

        return $u;
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'missionId' => 1, 'changeType' => 'RELEASED', 'date' => '10/09/2026', 'moment' => 'Matin', 'horaire' => '08:00–13:00',
            'siteName' => 'Site Test', 'surgeonId' => null, 'surgeonName' => 'Philippe Schiepers', 'instrumentistId' => null,
            'instrumentistName' => null, 'reassignedTo' => [],
        ], $overrides);
    }

    /** @return array<int, EmailContent> recipient id => the grouped email of $type */
    private function emailsOf(NotificationType $type): array
    {
        $byRecipient = [];
        foreach ($this->batches[$type->value] ?? [] as $intent) {
            $byRecipient[$intent->recipient->getId()][] = $intent;
        }
        $emails = [];
        foreach ($byRecipient as $id => $intents) {
            $emails[$id] = ($this->emailBuilders[$type->value])($intents[0]->recipient, $intents);
        }

        return $emails;
    }

    // ── Instrumentist absence ────────────────────────────────────────────────

    public function test_instrumentist_absence_informs_each_surgeon_once_and_never_the_absent_person(): void
    {
        $instr = $this->user('instr@test.com');
        $a = $this->user('a@test.com');
        $b = $this->user('b@test.com');

        $this->handler()(new AbsenceMissionsReactedMessage(1, $instr->getId(), 'INSTRUMENTIST', 99, [
            $this->row(['missionId' => 10, 'surgeonId' => $a->getId()]),
            $this->row(['missionId' => 11, 'surgeonId' => $a->getId()]),
            $this->row(['missionId' => 12, 'surgeonId' => $b->getId()]),
        ], new \DateTimeImmutable()));

        self::assertSame(['ABSENCE_SURGEON_MISSION_OPENED'], array_keys($this->batches), 'H4: no notification to the absent instrumentist about her own absence');
        self::assertCount(3, $this->batches['ABSENCE_SURGEON_MISSION_OPENED'], 'one dispatch (and in-app item) per mission');
        $surgeons = $this->emailsOf(NotificationType::ABSENCE_SURGEON_MISSION_OPENED);
        self::assertCount(2, $surgeons[$a->getId()]->context['missions'], 'ONE email per surgeon, covering only his missions');
        self::assertCount(1, $surgeons[$b->getId()]->context['missions']);
        self::assertSame('PUBLISHED', $this->batches['ABSENCE_SURGEON_MISSION_OPENED'][0]->inAppPayload['level']);
    }

    // ── Surgeon absence ───────────────────────────────────────────────────────

    public function test_surgeon_absence_tells_each_instrumentist_her_mission_is_cancelled(): void
    {
        $x = $this->user('x@test.com');
        $y = $this->user('y@test.com');

        $this->handler()(new AbsenceMissionsReactedMessage(1, 50, 'SURGEON', 99, [
            $this->row(['missionId' => 20, 'changeType' => 'CANCELLED', 'instrumentistId' => $x->getId()]),
            $this->row(['missionId' => 21, 'changeType' => 'CANCELLED', 'instrumentistId' => $y->getId()]),
            $this->row(['missionId' => 22, 'changeType' => 'CANCELLED', 'instrumentistId' => null]),
        ], new \DateTimeImmutable()));

        self::assertCount(2, $this->batches['ABSENCE_MISSION_CANCELLED'], 'a mission that was OPEN had nobody to tell');
        $intent = $this->batches['ABSENCE_MISSION_CANCELLED'][0];
        self::assertSame(20, $intent->mission->getId());
        self::assertSame('absence:1:PUBLISHED:mission:20:ABSENCE_MISSION_CANCELLED', $intent->episodeRef);
        self::assertNull($intent->communicates);
    }

    public function test_cas_b_reassignment_replaces_the_cancellation_notice_and_communicates_the_new_mission(): void
    {
        $x = $this->user('x@test.com');

        $this->handler()(new AbsenceMissionsReactedMessage(1, 50, 'SURGEON', 99, [
            $this->row(['missionId' => 20, 'changeType' => 'CANCELLED', 'instrumentistId' => $x->getId(), 'reassignedTo' => [['missionId' => 77, 'date' => '10/09/2026']]]),
        ], new \DateTimeImmutable()));

        self::assertArrayNotHasKey('ABSENCE_MISSION_CANCELLED', $this->batches, 'never both for the same mission');
        $intent = $this->batches['ABSENCE_INSTRUMENTIST_REASSIGNED'][0];
        self::assertSame(MissionCommunicationSource::ASSIGN, $intent->communicates->source);
        self::assertSame([77], $intent->communicates->missionIds);
    }

    public function test_empty_or_unknown_recipients_send_nothing(): void
    {
        $this->handler()(new AbsenceMissionsReactedMessage(1, 1, 'INSTRUMENTIST', 99, [], new \DateTimeImmutable()));
        $this->handler()(new AbsenceMissionsReactedMessage(1, 999999, 'INSTRUMENTIST', 99, [$this->row(['missionId' => 10])], new \DateTimeImmutable()));

        self::assertSame([], $this->batches);
    }

    public function test_the_absence_recap_never_addresses_the_pool(): void
    {
        $instr = $this->user('absent@test.com');
        $surgeon = $this->user('surgeon@test.com');

        $this->handler()(new AbsenceMissionsReactedMessage(1, $instr->getId(), 'INSTRUMENTIST', 99, [$this->row(['missionId' => 10, 'surgeonId' => $surgeon->getId()])], new \DateTimeImmutable()));

        self::assertSame(['ABSENCE_SURGEON_MISSION_OPENED'], array_keys($this->batches), 'the pool is OpenMissionPoolNotifier\'s, never this handler\'s');
    }
}
