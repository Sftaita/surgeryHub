<?php

namespace App\Tests\Integration\Notification;

use App\Activity\CorrelationContext;
use App\Activity\Recorder\NotificationActivityRecorder;
use App\Entity\NotificationDispatch;
use App\Entity\NotificationPreference;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\ActivityActorType;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\SendTemplatedEmailMessage;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Notification\PushContent;
use App\Service\NotificationPreferenceResolver;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * H2 (D-128) — NotificationDispatcher against the real test database: channel decisions
 * through the real resolver, per-channel deliveries with honest statuses and skip reasons,
 * idempotency on retry, failure isolation between channels, BATCH grouping (one email per
 * recipient for N dispatches), and the activity_event trail (dispatch → queued → sent/failed).
 */
final class NotificationDispatcherIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CorrelationContext $context;
    /** @var int[] */
    private array $users = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em      = self::getContainer()->get(EntityManagerInterface::class);
        $this->context = self::getContainer()->get(CorrelationContext::class);
        $this->context->begin(ActivityActorType::WORKER);
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen() && $this->users !== []) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            $ids  = implode(',', array_map('intval', $this->users));
            $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($ids)");
            $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM notification_preference WHERE user_id IN ($ids)");
            $conn->executeStatement("DELETE FROM user WHERE id IN ($ids)");
        }
        parent::tearDown();
    }

    public function test_isolated_dispatch_records_every_channel_with_an_honest_status(): void
    {
        $user = $this->makeUser('Françoise', 'Detrembleur');

        $dispatch = $this->dispatcher()->dispatch($this->intent($user, NotificationType::PLANNING_MISSION_CANCELLED));

        $rows = $this->deliveries($dispatch);
        self::assertSame('SENT', $rows['IN_APP']['status']);
        self::assertNotNull($rows['IN_APP']['notification_event_id'], 'IN_APP delivery is linked to its feed item');
        self::assertSame('QUEUED', $rows['EMAIL']['status'], 'email enabled by default for this type, waiting for the worker');
        self::assertSame('SKIPPED', $rows['PUSH']['status']);
        self::assertSame('DISABLED_BY_PREFERENCE', $rows['PUSH']['skip_reason'], 'push is off by default for this type');

        self::assertSame(['inApp' => true, 'email' => true, 'push' => false, 'source' => 'DEFAULT'], $dispatch->getChannelsResolved());
        self::assertSame($this->context->correlationId(), $dispatch->getCorrelationId());

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendTemplatedEmailMessage::class, $message);
        self::assertSame((int) $rows['EMAIL']['id'], $message->outboundNotificationId);

        $events = $this->activity($dispatch);
        self::assertSame(["NOTIFICATION_CHANNEL_QUEUED", "NOTIFICATION_DISPATCHED"], array_column($events, "event_type"), json_encode(array_column($events, "event_type")));
        $dispatched = $events[1];
        self::assertStringContainsString('Notification « Mission annulée » pour Françoise Detrembleur', $dispatched['summary']);
        self::assertStringContainsString('push : non envoyé (désactivé par préférence)', $dispatched['summary']);
        self::assertStringContainsString('email : en file d\'envoi', $dispatched['summary']);
        self::assertStringContainsString('in-app : créée', $dispatched['summary']);
        $payload = json_decode((string) $dispatched['payload'], true);
        self::assertSame('DISABLED_BY_PREFERENCE', $payload['channels']['PUSH']['skipReason']);
        self::assertSame((int) $events[0]['id'], (int) $this->em->getConnection()->fetchOne(
            "SELECT id FROM activity_event WHERE event_type = 'NOTIFICATION_CHANNEL_QUEUED' AND CAST(JSON_EXTRACT(payload, '$.dispatchId') AS UNSIGNED) = ?", [$dispatch->getId()]));
    }

    public function test_explicit_preferences_are_sovereign(): void
    {
        $user = $this->makeUser();
        $this->prefer($user, NotificationType::PLANNING_MISSION_CANCELLED, inApp: false, email: false, push: true);

        $dispatch = $this->dispatcher()->dispatch($this->intent($user, NotificationType::PLANNING_MISSION_CANCELLED));

        $rows = $this->deliveries($dispatch);
        self::assertSame(['SKIPPED', 'DISABLED_BY_PREFERENCE'], [$rows['IN_APP']['status'], $rows['IN_APP']['skip_reason']]);
        self::assertSame(['SKIPPED', 'DISABLED_BY_PREFERENCE'], [$rows['EMAIL']['status'], $rows['EMAIL']['skip_reason']]);
        self::assertSame(['SKIPPED', 'NO_PUSH_SUBSCRIPTION'], [$rows['PUSH']['status'], $rows['PUSH']['skip_reason']], 'push allowed, but no device registered');
        self::assertSame('PREFERENCE', $dispatch->getChannelsResolved()['source']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_event WHERE user_id = ?', [$user->getId()]));
        self::assertCount(0, $this->transport()->getSent());
    }

    public function test_a_retry_of_the_same_episode_never_duplicates_any_channel(): void
    {
        $user   = $this->makeUser();
        $intent = $this->intent($user, NotificationType::PLANNING_MISSION_CANCELLED, episode: 'lifecycle:CANCELLED:1:2026-10-04T10:00:00.000000+02:00:INSTRUMENTIST');

        $first  = $this->dispatcher()->dispatch($intent);
        $second = $this->dispatcher()->dispatch($intent);

        self::assertSame($first->getId(), $second->getId());
        $conn = $this->em->getConnection();
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM notification_dispatch WHERE recipient_user_id = ?', [$user->getId()]));
        self::assertSame(3, (int) $conn->fetchOne('SELECT COUNT(*) FROM outbound_notification WHERE recipient_user_id = ?', [$user->getId()]));
        self::assertSame(1, (int) $conn->fetchOne('SELECT COUNT(*) FROM notification_event WHERE user_id = ?', [$user->getId()]));
        self::assertCount(1, $this->transport()->getSent(), 'one email, even after a retry');
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM activity_event WHERE event_type = 'NOTIFICATION_DISPATCHED' AND subject_id = ?", [$first->getId()]));
    }

    public function test_a_failing_channel_never_blocks_the_others(): void
    {
        $user = $this->makeUser();
        $brokenBus = $this->createMock(MessageBusInterface::class);
        $brokenBus->method('dispatch')->willThrowException(new \RuntimeException('transport unavailable'));

        $dispatch = $this->dispatcher($brokenBus)->dispatch($this->intent($user, NotificationType::PLANNING_MISSION_CANCELLED));

        $rows = $this->deliveries($dispatch);
        self::assertSame('SENT', $rows['IN_APP']['status'], 'in-app delivered despite the email failure');
        self::assertArrayNotHasKey('EMAIL', $rows, 'the QUEUED row rolled back with its failed message — never an orphan "queued" email');
        self::assertSame('SKIPPED', $rows['PUSH']['status']);
        $payload = json_decode((string) $this->em->getConnection()->fetchOne(
            "SELECT payload FROM activity_event WHERE event_type = 'NOTIFICATION_DISPATCHED' AND subject_id = ?", [$dispatch->getId()]), true);
        self::assertSame('FAILED', $payload['channels']['EMAIL']['status']);
    }

    public function test_missing_channel_content_is_reported_not_applicable(): void
    {
        $user = $this->makeUser();
        $intent = new NotificationIntent($user, NotificationType::PLANNING_MISSION_CANCELLED, ['missionId' => null],
            email: null, push: null, episodeRef: 'test:' . bin2hex(random_bytes(4)), subjectType: 'Test', subjectId: 1);

        $rows = $this->deliveries($this->dispatcher()->dispatch($intent));

        self::assertSame(['SKIPPED', 'NOT_APPLICABLE'], [$rows['EMAIL']['status'], $rows['EMAIL']['skip_reason']]);
        self::assertSame(['SKIPPED', 'NOT_APPLICABLE'], [$rows['PUSH']['status'], $rows['PUSH']['skip_reason']]);
    }

    public function test_fallback_policy_links_the_email_to_the_unsent_push(): void
    {
        $user = $this->makeUser();
        $this->prefer($user, NotificationType::MISSION_OFFERED, inApp: true, email: true, push: true);
        $intent = $this->intent($user, NotificationType::MISSION_OFFERED, policy: NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT);

        $rows = $this->deliveries($this->dispatcher()->dispatch($intent));

        self::assertSame('NO_PUSH_SUBSCRIPTION', $rows['PUSH']['skip_reason']);
        self::assertSame('QUEUED', $rows['EMAIL']['status']);
        self::assertSame((int) $rows['PUSH']['id'], (int) $rows['EMAIL']['fallback_of_id']);
        self::assertSame('NO_SUBSCRIPTION', $rows['EMAIL']['fallback_reason']);
    }

    public function test_batch_mode_sends_one_email_per_recipient_for_the_whole_operation(): void
    {
        $francoise = $this->makeUser('Françoise', 'Detrembleur');
        $sophie    = $this->makeUser('Sophie', 'Gillard');
        $intents = [
            $this->intent($francoise, NotificationType::PLANNING_MISSION_CANCELLED, episode: 'batch:m1', subjectId: 1),
            $this->intent($francoise, NotificationType::PLANNING_MISSION_CANCELLED, episode: 'batch:m2', subjectId: 2),
            $this->intent($sophie, NotificationType::PLANNING_MISSION_CANCELLED, episode: 'batch:m1', subjectId: 1),
        ];
        $groupEmail = static fn (User $u, array $its) => new EmailContent(sprintf('%d missions annulées', count($its)), 'emails/mission_lifecycle_update.html.twig', ['headline' => 'x', 'intro' => 'y', 'greeting' => 'Bonjour', 'typeLabel' => 'Bloc']);
        $groupPush  = static fn (User $u, array $its) => new PushContent('Missions annulées', sprintf('%d missions', count($its)));

        $dispatches = $this->dispatcher()->dispatchBatch($intents, $groupEmail, $groupPush);
        $this->dispatcher()->dispatchBatch($intents, $groupEmail, $groupPush); // retry of the same operation

        self::assertCount(3, $dispatches);
        $conn = $this->em->getConnection();
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM notification_event WHERE user_id = ?', [$francoise->getId()]), 'in-app stays one item per mission');
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM outbound_notification WHERE recipient_user_id = ? AND channel = 'EMAIL'", [$francoise->getId()]));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM outbound_notification WHERE recipient_user_id = ? AND channel = 'EMAIL'", [$sophie->getId()]));
        self::assertCount(2, $this->transport()->getSent(), 'exactly one email per recipient, retry included');
        self::assertSame('2 missions annulées', $this->transport()->getSent()[0]->getMessage()->subject);

        $emailId = (int) $conn->fetchOne("SELECT id FROM outbound_notification WHERE recipient_user_id = ? AND channel = 'EMAIL'", [$francoise->getId()]);
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM notification_dispatch_delivery WHERE outbound_notification_id = ?', [$emailId]),
            'the single grouped email is traced against each mission dispatch');
    }

    public function test_email_acceptance_and_final_failure_are_traced_once(): void
    {
        $user = $this->makeUser();
        $dispatch = $this->dispatcher()->dispatch($this->intent($user, NotificationType::PLANNING_MISSION_CANCELLED));
        $emailId = (int) $this->deliveries($dispatch)['EMAIL']['id'];
        $outbound = self::getContainer()->get(OutboundNotificationService::class);

        $outbound->recordEmailAttempt($emailId, success: true, reason: null);
        $outbound->recordEmailAttempt($emailId, success: true, reason: null); // duplicate worker ack

        $conn = $this->em->getConnection();
        self::assertSame('SENT', $conn->fetchOne('SELECT status FROM outbound_notification WHERE id = ?', [$emailId]));
        $sent = $conn->fetchAllAssociative("SELECT * FROM activity_event WHERE event_type = 'NOTIFICATION_CHANNEL_SENT' AND subject_id = ?", [$emailId]);
        self::assertCount(1, $sent);
        self::assertStringContainsString('accepté par le serveur d\'envoi', $sent[0]['summary'], 'SENT is never presented as received/read');
        self::assertSame($dispatch->getId(), json_decode((string) $sent[0]['payload'], true)['dispatchId']);
        self::assertSame($dispatch->getCorrelationId(), $sent[0]['correlation_id']);

        $other = $this->dispatcher()->dispatch($this->intent($user, NotificationType::PLANNING_MISSION_CANCELLED));
        $failedId = (int) $this->deliveries($other)['EMAIL']['id'];
        $outbound->recordEmailAttempt($failedId, success: false, reason: 'Connection refused', final: false);
        self::assertSame(0, (int) $conn->fetchOne("SELECT COUNT(*) FROM activity_event WHERE event_type = 'NOTIFICATION_CHANNEL_FAILED' AND subject_id = ?", [$failedId]),
            'a retryable failure is an attempt, not a failure event');
        $outbound->recordEmailAttempt($failedId, success: false, reason: 'Connection refused', final: true);
        self::assertSame('FAILED', $conn->fetchOne('SELECT status FROM outbound_notification WHERE id = ?', [$failedId]));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM activity_event WHERE event_type = 'NOTIFICATION_CHANNEL_FAILED' AND subject_id = ?", [$failedId]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function dispatcher(?MessageBusInterface $bus = null): NotificationDispatcher
    {
        $c = self::getContainer();
        if ($bus === null) {
            return $c->get(NotificationDispatcher::class);
        }

        return new NotificationDispatcher(
            $this->em, $c->get(NotificationPreferenceResolver::class), $c->get(OutboundNotificationService::class),
            $c->get(NotificationActivityRecorder::class), $this->context, $bus, $this->stderrLogger(),
            $c->get(\App\Notification\MissionCommunicationService::class),
            'no-reply@surgicalhub.test', 'SurgicalHub',
        );
    }

    private function stderrLogger(): \Psr\Log\LoggerInterface
    {
        return new class extends \Psr\Log\AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'error') {
                    fwrite(STDERR, $message . ' ' . json_encode($context) . PHP_EOL);
                }
            }
        };
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function intent(
        User $user,
        NotificationType $type,
        ?string $episode = null,
        int $subjectId = 1,
        NotificationEmailPolicy $policy = NotificationEmailPolicy::WHEN_ENABLED,
    ): NotificationIntent {
        return new NotificationIntent(
            recipient: $user,
            type: $type,
            inAppPayload: ['missionId' => null, 'siteName' => 'Delta'],
            email: new EmailContent('Une mission de votre planning est annulée', 'emails/mission_lifecycle_update.html.twig',
                ['headline' => 'Mission annulée', 'intro' => 'Cette mission est annulée.', 'greeting' => 'Bonjour', 'typeLabel' => 'Bloc opératoire']),
            push: new PushContent('Mission annulée', 'Une mission de votre planning a été annulée.'),
            episodeRef: $episode ?? ('test:' . bin2hex(random_bytes(6))),
            subjectType: 'TestSubject',
            subjectId: $subjectId,
            emailPolicy: $policy,
        );
    }

    /** @return array<string, array<string, mixed>> channel => outbound row */
    private function deliveries(NotificationDispatch $dispatch): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT o.* FROM outbound_notification o JOIN notification_dispatch_delivery d ON d.outbound_notification_id = o.id WHERE d.dispatch_id = ?',
            [$dispatch->getId()],
        );

        return array_column($rows, null, 'channel');
    }

    /** @return list<array<string, mixed>> */
    private function activity(NotificationDispatch $dispatch): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            "SELECT * FROM activity_event WHERE correlation_id = ? AND domain = 'NOTIFICATIONS' AND CAST(JSON_EXTRACT(payload, '$.dispatchId') AS UNSIGNED) = ? ORDER BY id",
            [$dispatch->getCorrelationId(), $dispatch->getId()],
        );
    }

    private function makeUser(string $first = 'Test', string $last = 'User'): User
    {
        $u = (new User())->setEmail('h2-' . bin2hex(random_bytes(5)) . '@surgicalhub.test')->setRoles(['ROLE_INSTRUMENTIST'])
            ->setFirstname($first)->setLastname($last)->setActive(true)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->users[] = $u->getId();

        return $u;
    }

    private function prefer(User $user, NotificationType $type, bool $inApp, bool $email, bool $push): void
    {
        $p = (new NotificationPreference())->setUser($user)->setNotificationType($type)
            ->setInAppEnabled($inApp)->setEmailEnabled($email)->setPushEnabled($push);
        $this->em->persist($p);
        $this->em->flush();
    }
}
