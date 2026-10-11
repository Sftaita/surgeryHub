<?php

namespace App\Tests\Integration\Notification;

use App\Activity\CorrelationContext;
use App\Entity\NotificationDispatch;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\ActivityActorType;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Message\SendTemplatedEmailMessage;
use App\MessageHandler\SendTemplatedEmailMessageHandler;
use App\Notification\DocumentStore;
use App\Notification\EmailContent;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * H5a — the email-specific guarantees of NotificationDispatcher, against the real test
 * database and the real email handler:
 *   - MANDATORY email: sent whatever the preferences (identity emails, explicit manager
 *     actions), the other channels still per preferences, decision recorded as such;
 *   - invitation (category C): reaches a person who disabled every business email, traced
 *     (QUEUED → SENT), and its token is never persisted anywhere readable;
 *   - the address an email was really handed to is recorded (email change: previous address);
 *   - attachments travel by DocumentReference: stored once, verified by sha256, no bytes in
 *     the message, the delivery record or the timeline.
 */
final class H5aEmailDispatchIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var int[] */
    private array $users = [];
    /** @var int[] */
    private array $documents = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(CorrelationContext::class)->begin(ActivityActorType::WORKER);
        $this->transport()->reset();
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            if ($this->users !== []) {
                $ids = implode(',', array_map('intval', $this->users));
                $conn->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($ids)");
                $conn->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($ids)");
                $conn->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($ids)");
                $conn->executeStatement("DELETE FROM notification_event WHERE user_id IN ($ids)");
                $conn->executeStatement("DELETE FROM notification_preference WHERE user_id IN ($ids)");
                $conn->executeStatement("DELETE FROM user WHERE id IN ($ids)");
            }
            if ($this->documents !== []) {
                $conn->executeStatement('DELETE FROM generated_document WHERE id IN (' . implode(',', array_map('intval', $this->documents)) . ')');
            }
        }
        parent::tearDown();
    }

    public function test_a_mandatory_email_ignores_the_email_preference_but_not_the_other_channels(): void
    {
        $user = $this->makeUser();
        $this->prefer($user, NotificationType::PLANNING_RESENT_MANUAL, inApp: false, email: false, push: false);

        $dispatch = $this->dispatcher()->dispatch(new NotificationIntent(
            recipient: $user, type: NotificationType::PLANNING_RESENT_MANUAL, inAppPayload: ['periodFrom' => '2026-11-01'],
            email: new EmailContent('Planning', 'emails/mission_lifecycle_update.html.twig', ['headline' => 'x', 'intro' => 'y', 'greeting' => 'Bonjour', 'typeLabel' => 'Bloc']),
            episodeRef: 'h5a:' . bin2hex(random_bytes(4)), subjectType: 'Test', subjectId: 1,
            emailPolicy: NotificationEmailPolicy::MANDATORY,
        ));

        $rows = $this->deliveries($dispatch);
        self::assertSame('QUEUED', $rows['EMAIL']['status'], 'an explicit manager action is never filtered by preferences');
        self::assertSame(['SKIPPED', 'DISABLED_BY_PREFERENCE'], [$rows['IN_APP']['status'], $rows['IN_APP']['skip_reason']], 'the in-app item still follows her preference');
        self::assertSame(['inApp' => false, 'email' => true, 'push' => false, 'source' => 'MANDATORY'], $dispatch->getChannelsResolved());
        self::assertSame($user->getEmail(), $rows['EMAIL']['recipient_address']);
    }

    public function test_the_invitation_reaches_a_person_who_disabled_every_business_email_and_its_token_is_never_persisted(): void
    {
        $user = $this->makeUser();
        foreach (NotificationType::cases() as $type) {
            if ($type->isConfigurable()) {
                $this->prefer($user, $type, inApp: false, email: false, push: false);
            }
        }
        $token = bin2hex(random_bytes(32));
        $user->setInvitationToken($token)->setInvitationExpiresAt(new \DateTimeImmutable('+48 hours'))->setRoles(['ROLE_SURGEON']);
        $this->em->flush();

        self::getContainer()->get(NotificationService::class)->sendUserInvitation($user);
        $this->em->flush();

        $conn = $this->em->getConnection();
        $email = $conn->fetchAssociative("SELECT * FROM outbound_notification WHERE recipient_user_id = ? AND channel = 'EMAIL'", [$user->getId()]);
        self::assertSame('QUEUED', $email['status']);
        self::assertSame('ACCOUNT_INVITATION', $email['notification_type']);
        self::assertNotNull($user->getInvitationLastSentAt(), 'timestamped only once really queued');
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM notification_event WHERE user_id = ?', [$user->getId()]), 'no in-app item for an invitation');

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        /** @var SendTemplatedEmailMessage $message */
        $message = $sent[0]->getMessage();
        self::assertStringContainsString($token, $message->context['invitationUrl'], 'the person does get her link');
        self::assertSame('chirurgien', $message->context['roleLabel'], 'role-adapted');
        self::assertSame('SurgicalHub — Activez votre compte chirurgien', $message->subject);
        self::assertFalse($message->storeRenderedBody);

        // The worker sends it: SENT, and still no token anywhere readable.
        self::getContainer()->get(SendTemplatedEmailMessageHandler::class)($message);
        $email = $conn->fetchAssociative('SELECT * FROM outbound_notification WHERE id = ?', [$email['id']]);
        self::assertSame('SENT', $email['status'], 'accepted by the relay — never "received"');
        self::assertNull($email['body_html']);
        self::assertNull($email['body_text']);

        $like = '%' . $token . '%';
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM outbound_notification WHERE recipient_user_id = ? AND CONCAT_WS(\'|\', payload, subject, body_text, body_html, title) LIKE ?', [$user->getId(), $like]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM notification_dispatch WHERE recipient_user_id = ? AND CONCAT_WS(\'|\', payload, episode_ref, idempotency_key) LIKE ?', [$user->getId(), $like]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM activity_event WHERE user_id = ? AND CONCAT_WS(\'|\', payload, summary, subject_label_snapshot) LIKE ?', [$user->getId(), $like]));
        self::assertSame(1, (int) $conn->fetchOne("SELECT COUNT(*) FROM activity_event WHERE event_type = 'NOTIFICATION_CHANNEL_SENT' AND subject_id = ?", [$email['id']]));
    }

    public function test_an_email_change_notice_records_the_previous_address_it_went_to(): void
    {
        $user = $this->makeUser();
        $old  = 'previous-' . bin2hex(random_bytes(4)) . '@surgicalhub.test';

        self::assertTrue(self::getContainer()->get(NotificationService::class)->sendEmailChangeNotice($user, $old, 'old', 'ref1', $old, (string) $user->getEmail()));

        $row = $this->em->getConnection()->fetchAssociative("SELECT * FROM outbound_notification WHERE recipient_user_id = ? AND channel = 'EMAIL'", [$user->getId()]);
        self::assertSame($old, $row['recipient_address']);
        self::assertSame('ACCOUNT_EMAIL_CHANGED', $row['notification_type']);
        self::assertSame($old, $this->transport()->getSent()[0]->getMessage()->to);
    }

    public function test_a_planning_pdf_travels_by_reference_only(): void
    {
        $user  = $this->makeUser();
        $store = self::getContainer()->get(DocumentStore::class);
        $pdf   = '%PDF-1.4 h5a ' . bin2hex(random_bytes(8));

        $ref   = $store->store('PLANNING_INSTRUMENTIST', 'planning-test.pdf', $pdf);
        $again = $store->store('PLANNING_INSTRUMENTIST', 'planning-test.pdf', $pdf);
        $this->documents[] = $ref->documentId;
        self::assertSame($ref->documentId, $again->documentId, 'rendered once, stored once — a retry reuses it');
        self::assertSame(hash('sha256', $pdf), $ref->sha256);

        $dispatch = $this->dispatcher()->dispatch(new NotificationIntent(
            recipient: $user, type: NotificationType::PLANNING_DEPLOYED_INSTRUMENTIST, inAppPayload: [],
            email: new EmailContent('Planning du 01/11/2026 au 30/11/2026', 'emails/mission_encoding_reminder.html.twig',
                ['firstname' => 'Test', 'missionUrl' => 'https://app.test', 'notificationPreferencesUrl' => null], attachments: [$ref]),
            episodeRef: 'h5a:' . bin2hex(random_bytes(4)), subjectType: 'Test', subjectId: 1,
        ));

        /** @var SendTemplatedEmailMessage $message */
        $message = $this->transport()->getSent()[0]->getMessage();
        self::assertSame([], $message->attachments, 'no base64 in the queued message');
        self::assertSame([$ref->toArray()], $message->documents);
        self::assertStringNotContainsString(base64_encode($pdf), serialize($message));

        $email = $this->deliveries($dispatch)['EMAIL'];
        $payload = json_decode((string) $email['payload'], true);
        self::assertEquals([$ref->toArray()], $payload['attachments'], 'which PDF she received: id + sha256 on the delivery record');
        $conn = $this->em->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM activity_event WHERE user_id = ? AND payload LIKE ?', [$user->getId(), '%' . base64_encode($pdf) . '%']));

        // The worker attaches the very bytes the reference names.
        self::getContainer()->get(SendTemplatedEmailMessageHandler::class)($message);
        self::assertSame('SENT', $conn->fetchOne('SELECT status FROM outbound_notification WHERE id = ?', [$email['id']]));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function dispatcher(): NotificationDispatcher
    {
        return self::getContainer()->get(NotificationDispatcher::class);
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
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

    private function makeUser(): User
    {
        $u = (new User())->setEmail('h5a-' . bin2hex(random_bytes(5)) . '@surgicalhub.test')->setRoles(['ROLE_INSTRUMENTIST'])
            ->setFirstname('Test')->setLastname('H5a')->setActive(true)->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();
        $this->users[] = $u->getId();

        return $u;
    }

    private function prefer(User $user, NotificationType $type, bool $inApp, bool $email, bool $push): void
    {
        $this->em->persist((new NotificationPreference())->setUser($user)->setNotificationType($type)
            ->setInAppEnabled($inApp)->setEmailEnabled($email)->setPushEnabled($push));
        $this->em->flush();
    }
}
