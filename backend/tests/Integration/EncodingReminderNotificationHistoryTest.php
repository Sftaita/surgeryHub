<?php

namespace App\Tests\Integration;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use App\Enum\SchedulePrecision;
use App\Service\EncodingReminderService;
use App\Service\NotificationService;
use App\Service\OutboundNotificationService;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-084 — real-DB, end-to-end proof that the D+1 encoding reminder (D-083) produces the
 * exact traced history described in D-084 §8: a Push success is ONE OutboundNotification,
 * no email; a Push failure/skip is a Push trace PLUS a linked email trace (fallbackOf),
 * never both channels for the same successful attempt, and never a duplicate on a second
 * run of the same mission (idempotence, already covered by
 * EncodingReminderServiceEligibilityTest, re-verified here at the history level).
 *
 * H5a (D-131): the reminder now goes through NotificationDispatcher — the same push-first /
 * linked-email-fallback history, plus the dispatcher's honest rows for the channels it did
 * not use (IN_APP SKIPPED/NOT_APPLICABLE, EMAIL SKIPPED/COVERED_BY_PUSH).
 */
final class EncodingReminderNotificationHistoryTest extends KernelTestCase
{
    use \App\Tests\Support\PurgesNotificationTraces;

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'subscriptions' => [], 'notifications' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->purgeNotificationTraces($this->em, $this->createdIds['users']);
        foreach ($this->createdIds['notifications'] as $id) {
            $e = $this->em->find(OutboundNotification::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['subscriptions'] as $id) {
            $e = $this->em->find(PushSubscription::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['missions'] as $id) {
            $e = $this->em->find(Mission::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        foreach ($this->createdIds['users'] as $id) {
            $e = $this->em->find(User::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        foreach ($this->createdIds['sites'] as $id) {
            $e = $this->em->find(Hospital::class, $id);
            if ($e !== null) { $this->em->remove($e); }
        }
        $this->em->flush();
        parent::tearDown();
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('D084-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('d084-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Test');
        $u->setLastname('D084');
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSubscription(User $user, string $endpoint): PushSubscription
    {
        $s = (new PushSubscription())
            ->setUser($user)
            ->setEndpoint($endpoint)
            ->setPublicKey('BIU71jF27lGRVeJSQ1Bg82JpaC7r71OOff55wBTFM8CAEowOuj1udpNHJOMFm53Hm1FLLAQH5QrAfdCRutwiWuc')
            ->setAuthToken('fEIRI1HhX0nIGF4RXV9-dA')
            ->setContentEncoding('aes128gcm');
        $this->em->persist($s);
        $this->em->flush();
        $this->createdIds['subscriptions'][] = $s->getId();
        return $s;
    }

    private function makeMission(Hospital $site, User $surgeon, User $instrumentist, string $endAt): Mission
    {
        $tz = new \DateTimeZone('Europe/Brussels');
        $m = new Mission();
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setStartAt(new \DateTimeImmutable($endAt . ' -1 hour', $tz));
        $m->setEndAt(new \DateTimeImmutable($endAt, $tz));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    /** Real OutboundNotificationService/EncodingReminderService wired to a mocked Push transport (never a real HTTP call). */
    private function encodingReminderService(MockHandler $mockHandler): EncodingReminderService
    {
        $container = self::getContainer();

        $webPushService = new WebPushService(
            $this->em,
            new NullLogger(),
            $container->getParameter('app.vapid.public_key'),
            $container->getParameter('app.vapid.private_key'),
            $container->getParameter('app.vapid.subject'),
            ['handler' => HandlerStack::create($mockHandler)],
        );

        $outboundNotificationService = new OutboundNotificationService($this->em, $webPushService, $container->get(\App\Activity\Recorder\NotificationActivityRecorder::class), $container->get(\App\Notification\MissionCommunicationService::class));

        /** @var NotificationService $notificationService */
        $notificationService = $container->get(NotificationService::class);

        /** @var \App\Service\AuditService $auditService */
        $auditService = $container->get(\App\Service\AuditService::class);

        $dispatcher = new \App\Notification\NotificationDispatcher(
            $this->em,
            $container->get(\App\Service\NotificationPreferenceResolver::class),
            $outboundNotificationService,
            $container->get(\App\Activity\Recorder\NotificationActivityRecorder::class),
            $container->get(\App\Activity\CorrelationContext::class),
            $container->get(\Symfony\Component\Messenger\MessageBusInterface::class),
            new NullLogger(),
            $container->get(\App\Notification\MissionCommunicationService::class),
            'no-reply@surgicalhub.test',
            'SurgicalHub',
        );

        return new EncodingReminderService($this->em, $dispatcher, $notificationService, $auditService, new NullLogger());
    }

    private function findNotifications(User $recipient): array
    {
        return $this->em->getRepository(OutboundNotification::class)->findBy(['recipientUser' => $recipient], ['id' => 'ASC']);
    }

    /** @return array<string, OutboundNotification> channel => delivery */
    private function byChannel(array $notifications): array
    {
        $rows = [];
        foreach ($notifications as $n) {
            $rows[$n->getChannel()->value] = $n;
        }

        return $rows;
    }

    // ── Push réussi ──────────────────────────────────────────────────────────

    public function test_successful_push_creates_a_single_trace_and_no_email(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeSubscription($instrumentist, 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(16)));
        $mission = $this->makeMission($site, $surgeon, $instrumentist, '2026-07-25 18:00:00');

        $service = $this->encodingReminderService(new MockHandler([new Response(201)]));
        $result = $service->processMission($mission, new \DateTimeImmutable('2026-07-26 10:00:00', new \DateTimeZone('Europe/Brussels')));

        $this->assertSame('push', $result);

        $notifications = $this->findNotifications($instrumentist);
        $this->createdIds['notifications'] = array_map(fn (OutboundNotification $n) => $n->getId(), $notifications);

        $rows = $this->byChannel($notifications);
        $this->assertCount(3, $notifications, 'one row per channel, each with its honest outcome');
        $this->assertSame(OutboundNotificationStatus::SENT, $rows['PUSH']->getStatus());
        $this->assertSame($mission->getId(), $rows['PUSH']->getMission()?->getId());
        $this->assertFalse($rows['PUSH']->isFallback());
        $this->assertSame(OutboundNotificationStatus::SKIPPED, $rows['EMAIL']->getStatus(), 'no email alongside a successful push');
        $this->assertSame(\App\Enum\NotificationDeliverySkipReason::COVERED_BY_PUSH, $rows['EMAIL']->getSkipReason());
        $this->assertSame(\App\Enum\NotificationDeliverySkipReason::NOT_APPLICABLE, $rows['IN_APP']->getSkipReason(), 'no in-app item for this reminder');
    }

    // ── Push impossible → repli email ───────────────────────────────────────

    public function test_push_without_subscription_creates_push_skipped_and_linked_email(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST'); // no PushSubscription
        $mission = $this->makeMission($site, $surgeon, $instrumentist, '2026-07-25 18:00:00');

        $service = $this->encodingReminderService(new MockHandler([]));
        $result = $service->processMission($mission, new \DateTimeImmutable('2026-07-26 10:00:00', new \DateTimeZone('Europe/Brussels')));

        $this->assertSame('email', $result);

        $notifications = $this->findNotifications($instrumentist);
        $this->createdIds['notifications'] = array_map(fn (OutboundNotification $n) => $n->getId(), $notifications);

        $rows = $this->byChannel($notifications);
        $push = $rows['PUSH'];
        $email = $rows['EMAIL'];

        $this->assertSame(OutboundNotificationChannel::PUSH, $push->getChannel());
        $this->assertSame(OutboundNotificationStatus::SKIPPED, $push->getStatus());

        $this->assertSame(OutboundNotificationChannel::EMAIL, $email->getChannel());
        $this->assertSame($push->getId(), $email->getFallbackOf()?->getId(), 'the email must be linked back to the push it replaces');
        $this->assertTrue($email->isFallback());
        $this->assertSame(OutboundNotificationFallbackReason::NO_SUBSCRIPTION, $email->getFallbackReason());
        $this->assertSame('SurgicalHub — Encodage à finaliser', $email->getSubject());
        $this->assertSame($mission->getId(), $email->getMission()?->getId());
    }

    public function test_push_transport_failure_creates_push_failed_and_linked_email(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeSubscription($instrumentist, 'https://web.push.apple.com/' . bin2hex(random_bytes(16)));
        $mission = $this->makeMission($site, $surgeon, $instrumentist, '2026-07-25 18:00:00');

        $service = $this->encodingReminderService(new MockHandler([
            new Response(403, [], '{"reason":"BadJwtToken"}'),
        ]));
        $result = $service->processMission($mission, new \DateTimeImmutable('2026-07-26 10:00:00', new \DateTimeZone('Europe/Brussels')));

        $this->assertSame('email', $result);

        $notifications = $this->findNotifications($instrumentist);
        $this->createdIds['notifications'] = array_map(fn (OutboundNotification $n) => $n->getId(), $notifications);

        $rows = $this->byChannel($notifications);
        $push = $rows['PUSH'];
        $email = $rows['EMAIL'];
        $this->assertSame($push->getId(), $email->getFallbackOf()?->getId());

        $this->assertSame(OutboundNotificationStatus::FAILED, $push->getStatus());
        $this->assertSame(OutboundNotificationFallbackReason::ALL_FAILED, $email->getFallbackReason());
        $this->assertCount(1, $push->getAttempts());
        $this->assertSame('APPLE', $push->getAttempts()->first()->getProvider());
        $this->assertSame('BadJwtToken', $push->getAttempts()->first()->getReason());
    }

    // ── idempotence au niveau historique ────────────────────────────────────

    public function test_second_run_does_not_duplicate_traces(): void
    {
        $site = $this->makeSite();
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->makeSubscription($instrumentist, 'https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(16)));
        $mission = $this->makeMission($site, $surgeon, $instrumentist, '2026-07-25 18:00:00');
        $now = new \DateTimeImmutable('2026-07-26 10:00:00', new \DateTimeZone('Europe/Brussels'));

        $first = $this->encodingReminderService(new MockHandler([new Response(201)]))->processMission($mission, $now);
        $afterFirst = count($this->findNotifications($instrumentist));
        // Reload the mission the way the real command would on a second run: encodingReminderSentAt is now set.
        $this->em->clear();
        $reloadedMission = $this->em->find(Mission::class, $mission->getId());
        $second = $this->encodingReminderService(new MockHandler([new Response(201)]))->processMission($reloadedMission, $now);

        $this->assertSame('push', $first);
        $this->assertSame('skipped', $second);

        $notifications = $this->findNotifications($instrumentist);
        $this->createdIds['notifications'] = array_map(fn (OutboundNotification $n) => $n->getId(), $notifications);
        $this->assertCount($afterFirst, $notifications, 'the second run must not create any new trace');
    }
}
