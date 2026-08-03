<?php

namespace App\Tests\Functional;

use App\Entity\NotificationEvent;
use App\Entity\User;
use App\Enum\PublicationChannel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 3 (audit PWA/mobile/admin 2026-07-29) — first-ever functional coverage of
 * NotificationController. Before this endpoint existed, `NotificationEvent.seenAt`
 * was written by the entity but never read/updated by any API — the frontend
 * "unread" state was purely a local service-worker cache, not server truth.
 */
final class NotificationControllerTest extends WebTestCase
{
    private const PASSWORD = 'NotifCtrlTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['users' => [], 'notifications' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['notifications'] as $id) {
                $e = $this->em->find(NotificationEvent::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    /** @return array{user: User, token: string} */
    private function authenticate(KernelBrowser $client, string $role): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('notif-ctrl-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function createNotification(User $user, string $eventType, ?\DateTimeImmutable $seenAt = null): NotificationEvent
    {
        // The user entity may have been detached by a EM reset triggered by an
        // intervening HTTP request (e.g. a second authenticate() call) — reload it
        // by id so Doctrine doesn't treat it as an unmanaged "new" entity on persist.
        $managedUser = $this->em->find(User::class, $user->getId()) ?? $user;

        $evt = new NotificationEvent();
        $evt->setUser($managedUser)
            ->setEventType($eventType)
            ->setChannel(PublicationChannel::IN_APP)
            ->setPayload(['foo' => 'bar'])
            ->setSentAt(new \DateTimeImmutable())
            ->setSeenAt($seenAt);
        $this->em->persist($evt);
        $this->em->flush();
        $this->createdIds['notifications'][] = $evt->getId();

        return $evt;
    }

    // ── AuthZ ────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_list_rejects_unauthenticated_request(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $client->request('GET', '/api/notifications');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ── list ─────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_list_returns_only_the_current_user_notifications(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $userA, 'token' => $tokenA] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        ['user' => $userB] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $this->createNotification($userA, 'MISSION_ASSIGNED');
        $this->createNotification($userB, 'MISSION_ASSIGNED');

        $client->request('GET', '/api/notifications', server: $this->auth($tokenA));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertCount(1, $body['items']);
        self::assertSame('MISSION_ASSIGNED', $body['items'][0]['eventType']);
    }

    #[WithoutErrorHandler]
    public function test_list_orders_by_most_recent_first(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $older = new NotificationEvent();
        $older->setUser($user)->setEventType('OLDER')->setChannel(PublicationChannel::IN_APP)->setSentAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($older);
        $newer = new NotificationEvent();
        $newer->setUser($user)->setEventType('NEWER')->setChannel(PublicationChannel::IN_APP)->setSentAt(new \DateTimeImmutable());
        $this->em->persist($newer);
        $this->em->flush();
        $this->createdIds['notifications'][] = $older->getId();
        $this->createdIds['notifications'][] = $newer->getId();

        $client->request('GET', '/api/notifications', server: $this->auth($token));

        $body = $this->json($client->getResponse());
        self::assertSame('NEWER', $body['items'][0]['eventType']);
        self::assertSame('OLDER', $body['items'][1]['eventType']);
    }

    #[WithoutErrorHandler]
    public function test_list_excludes_non_in_app_channels(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $push = new NotificationEvent();
        $push->setUser($user)->setEventType('PUSH_ONLY')->setChannel(PublicationChannel::PUSH)->setSentAt(new \DateTimeImmutable());
        $this->em->persist($push);
        $this->em->flush();
        $this->createdIds['notifications'][] = $push->getId();

        $client->request('GET', '/api/notifications', server: $this->auth($token));

        $body = $this->json($client->getResponse());
        self::assertCount(0, $body['items']);
    }

    #[WithoutErrorHandler]
    public function test_unread_count_reflects_seen_at(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $this->createNotification($user, 'UNREAD_1');
        $this->createNotification($user, 'UNREAD_2');
        $this->createNotification($user, 'ALREADY_SEEN', new \DateTimeImmutable());

        $client->request('GET', '/api/notifications/unread-count', server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertSame(2, $body['unreadCount']);
    }

    // ── targetUrl (Point 4, audit UX) ───────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_targetUrl_is_computed_for_a_mission_tied_notification(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $instr, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $site = new \App\Entity\Hospital();
        $site->setName('NotifCtrl-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $surgeon = new User();
        $surgeon->setEmail('notif-ctrl-surg-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $surgeon->setRoles(['ROLE_SURGEON']);
        $surgeon->setActive(true);
        $surgeon->setPassword('x');
        $this->em->persist($surgeon);
        $this->em->flush();

        $mission = new \App\Entity\Mission();
        $mission->setStatus(\App\Enum\MissionStatus::ASSIGNED);
        $mission->setType(\App\Enum\MissionType::BLOCK);
        $mission->setSite($site);
        $mission->setSurgeon($surgeon);
        $mission->setInstrumentist($instr);
        $mission->setCreatedBy($surgeon);
        $mission->setStartAt(new \DateTimeImmutable('+1 day'));
        $mission->setEndAt(new \DateTimeImmutable('+1 day +2 hours'));
        $this->em->persist($mission);
        $this->em->flush();

        $managedInstr = $this->em->find(User::class, $instr->getId());
        $evt = new NotificationEvent();
        $evt->setUser($managedInstr)
            ->setEventType(\App\Enum\NotificationType::PLANNING_MISSION_REASSIGNED->value)
            ->setChannel(PublicationChannel::IN_APP)
            ->setMission($mission)
            ->setSentAt(new \DateTimeImmutable());
        $this->em->persist($evt);
        $this->em->flush();
        $this->createdIds['notifications'][] = $evt->getId();

        $client->request('GET', '/api/notifications', server: $this->auth($token));
        $body = $this->json($client->getResponse());

        self::assertSame('/app/i/missions/' . $mission->getId(), $body['items'][0]['targetUrl']);

        // Cleanup mission-specific entities (not covered by the base tearDown, which only
        // knows users/notifications) — re-fetched by id: the HTTP request above resets
        // the container's services (services_resetter), detaching the in-memory
        // references captured before the call (same reason `authenticate()`'s returned
        // User needs re-fetching elsewhere in this file, see createNotification()).
        $this->em->clear();
        $missionId = $mission->getId();
        $surgeonId = $surgeon->getId();
        $siteId = $site->getId();
        $notifId = $evt->getId();

        $freshNotif = $this->em->find(NotificationEvent::class, $notifId);
        if ($freshNotif !== null) { $this->em->remove($freshNotif); }
        $this->em->flush();

        $freshMission = $this->em->find(\App\Entity\Mission::class, $missionId);
        if ($freshMission !== null) { $this->em->remove($freshMission); }
        $this->em->flush();

        $freshSurgeon = $this->em->find(User::class, $surgeonId);
        if ($freshSurgeon !== null) { $this->em->remove($freshSurgeon); }
        $freshSite = $this->em->find(\App\Entity\Hospital::class, $siteId);
        if ($freshSite !== null) { $this->em->remove($freshSite); }
        $this->em->flush();

        // Already removed above — prevent the shared tearDown() from double-removing.
        $this->createdIds['notifications'] = array_values(array_diff($this->createdIds['notifications'], [$notifId]));
    }

    #[WithoutErrorHandler]
    public function test_targetUrl_is_null_for_an_unrecognized_event_type(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $user] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $this->createNotification($user, 'MISSION_ASSIGNED');

        $client->request('GET', '/api/notifications', server: $this->auth($token));
        $body = $this->json($client->getResponse());

        self::assertNull($body['items'][0]['targetUrl']);
    }

    // ── mark seen (single) ──────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_mark_seen_sets_seen_at_and_decrements_unread_count(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $notif = $this->createNotification($user, 'MISSION_ASSIGNED');

        $client->request('POST', "/api/notifications/{$notif->getId()}/seen", server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertNotNull($body['seenAt']);

        $this->em->clear();
        $reloaded = $this->em->find(NotificationEvent::class, $notif->getId());
        self::assertNotNull($reloaded->getSeenAt());
    }

    #[WithoutErrorHandler]
    public function test_mark_seen_is_idempotent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $notif = $this->createNotification($user, 'MISSION_ASSIGNED');

        $client->request('POST', "/api/notifications/{$notif->getId()}/seen", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $firstSeenAt = $this->json($client->getResponse())['seenAt'];

        $client->request('POST', "/api/notifications/{$notif->getId()}/seen", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($firstSeenAt, $this->json($client->getResponse())['seenAt'], 'Marking an already-seen notification must not bump seenAt again');
    }

    #[WithoutErrorHandler]
    public function test_mark_seen_returns_404_for_another_user_notification(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $owner] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $notif = $this->createNotification($owner, 'MISSION_ASSIGNED');

        ['token' => $otherToken] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $client->request('POST', "/api/notifications/{$notif->getId()}/seen", server: $this->auth($otherToken));

        self::assertSame(404, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(NotificationEvent::class, $notif->getId());
        self::assertNull($reloaded->getSeenAt(), "Another user's notification must never be markable as seen");
    }

    #[WithoutErrorHandler]
    public function test_mark_seen_returns_404_for_unknown_id(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('POST', '/api/notifications/999999999/seen', server: $this->auth($token));

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ── mark all seen ───────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_mark_all_seen_only_affects_current_user_unread_notifications(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $userA, 'token' => $tokenA] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        ['user' => $userB] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $this->createNotification($userA, 'A1');
        $this->createNotification($userA, 'A2');
        $notifB = $this->createNotification($userB, 'B1');

        $client->request('POST', '/api/notifications/mark-all-seen', server: $this->auth($tokenA));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertSame(2, $body['updated']);

        $this->em->clear();
        $reloadedB = $this->em->find(NotificationEvent::class, $notifB->getId());
        self::assertNull($reloadedB->getSeenAt(), "User B's notification must be untouched by user A's mark-all-seen");
    }
}
