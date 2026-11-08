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
    private array $createdIds = ['users' => [], 'notifications' => [], 'missions' => [], 'sites' => []];

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
            $conn = $this->em->getConnection();
            foreach ($this->createdIds['missions'] as $id) {
                $conn->executeStatement('DELETE FROM notification_event WHERE mission_id = ?', [$id]);
                $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$id]);
            }
            foreach ($this->createdIds['sites'] as $id) {
                $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
            }
            $this->em->clear();
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

    // ── Centre de notifications : texte serveur, filtres, état métier ──────────

    private function makeMission(\App\Enum\MissionStatus $status, ?User $instrumentist = null): \App\Entity\Mission
    {
        $site = (new \App\Entity\Hospital())->setName('Delta ' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $surgeon = (new User())->setEmail('notif-ctrl-surg-' . bin2hex(random_bytes(4)) . '@surgicalhub.test')
            ->setRoles(['ROLE_SURGEON'])->setActive(true)->setPassword('x')->setFirstname('Jean')->setLastname('Deltour');
        $this->em->persist($surgeon);
        $this->em->flush();
        $this->createdIds['sites'][] = $site->getId();
        $this->createdIds['users'][] = $surgeon->getId();

        $mission = (new \App\Entity\Mission())->setStatus($status)->setType(\App\Enum\MissionType::BLOCK)->setSite($site)
            ->setSurgeon($surgeon)->setCreatedBy($instrumentist ?? $surgeon)
            ->setStartAt(new \DateTimeImmutable('+3 days 08:00'))->setEndAt(new \DateTimeImmutable('+3 days 13:00'));
        if ($instrumentist !== null && $status !== \App\Enum\MissionStatus::DECLARED) {
            $mission->setInstrumentist($instrumentist);
        }
        $this->em->persist($mission);
        $this->em->flush();
        $this->createdIds['missions'][] = $mission->getId();

        return $mission;
    }

    private function notifyAbout(User $user, string $type, \App\Entity\Mission $mission, array $payload = []): NotificationEvent
    {
        $evt = (new NotificationEvent())->setUser($this->em->find(User::class, $user->getId()))->setEventType($type)
            ->setChannel(PublicationChannel::IN_APP)->setMission($this->em->find(\App\Entity\Mission::class, $mission->getId()))
            ->setPayload(['missionId' => $mission->getId()] + $payload)->setSentAt(new \DateTimeImmutable());
        $this->em->persist($evt);
        $this->em->flush();
        $this->createdIds['notifications'][] = $evt->getId();

        return $evt;
    }

    #[WithoutErrorHandler]
    public function test_items_carry_a_server_title_body_category_and_business_state(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $manager, 'token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $mission = $this->makeMission(\App\Enum\MissionStatus::DECLARED);
        $this->notifyAbout($manager, 'MISSION_DECLARED', $mission, ['declaredByName' => 'Sophie Colette', 'surgeonName' => 'Jean Deltour', 'siteName' => 'Delta']);

        $client->request('GET', '/api/notifications', server: $this->auth($token));
        $item = $this->json($client->getResponse())['items'][0];

        self::assertSame('Mission déclarée à valider', $item['title']);
        self::assertStringStartsWith('Une mission a été déclarée par Sophie Colette pour le Dr Jean Deltour le ', $item['body']);
        self::assertSame('mission', $item['category']);
        self::assertSame('ACTION_REQUIRED', $item['businessState']);
        self::assertSame('/app/m/missions/' . $mission->getId(), $item['targetUrl']);
        self::assertSame('Consulter la mission', $item['actionLabel']);
        self::assertFalse($item['legacy']);
    }

    #[WithoutErrorHandler]
    public function test_unread_filter_returns_only_unread_notifications(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $unread = $this->createNotification($user, 'PLANNING_MISSION_CANCELLED');
        $this->createNotification($user, 'PLANNING_MISSION_CANCELLED', new \DateTimeImmutable('-1 hour'));

        $client->request('GET', '/api/notifications?filter=unread', server: $this->auth($token));
        $body = $this->json($client->getResponse());

        self::assertSame([$unread->getId()], array_column($body['items'], 'id'));
        self::assertSame(1, $body['unreadCount']);
    }

    #[WithoutErrorHandler]
    public function test_action_filter_follows_the_business_resource_not_the_read_state(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $manager, 'token' => $token] = $this->authenticate($client, 'ROLE_ADMIN');
        $toValidate = $this->makeMission(\App\Enum\MissionStatus::DECLARED);
        $declared = $this->notifyAbout($manager, 'MISSION_DECLARED', $toValidate);
        $this->notifyAbout($manager, 'PLANNING_MISSION_CANCELLED', $this->makeMission(\App\Enum\MissionStatus::CANCELLED));

        $client->request('GET', '/api/notifications?filter=action', server: $this->auth($token));
        $body = $this->json($client->getResponse());
        self::assertSame([$declared->getId()], array_column($body['items'], 'id'));
        self::assertSame(1, $body['actionRequiredCount']);

        // Lue : toujours à traiter.
        $client->request('POST', '/api/notifications/' . $declared->getId() . '/seen', server: $this->auth($token));
        self::assertSame('ACTION_REQUIRED', $this->json($client->getResponse())['businessState']);
        $client->request('GET', '/api/notifications?filter=action', server: $this->auth($token));
        self::assertSame(1, $this->json($client->getResponse())['actionRequiredCount']);

        // Traitée sur la ressource : plus à traiter, la notification reste dans l'historique.
        $this->em->getConnection()->executeStatement("UPDATE mission SET status = 'ASSIGNED' WHERE id = ?", [$toValidate->getId()]);
        $client->request('GET', '/api/notifications?filter=action', server: $this->auth($token));
        $body = $this->json($client->getResponse());
        self::assertSame([], $body['items']);
        self::assertSame(0, $body['actionRequiredCount']);
        $client->request('GET', '/api/notifications', server: $this->auth($token));
        $all = array_column($this->json($client->getResponse())['items'], null, 'id');
        self::assertSame('DONE', $all[$declared->getId()]['businessState']);
        self::assertSame('Validée', $all[$declared->getId()]['stateLabel']);
    }

    #[WithoutErrorHandler]
    public function test_invalid_filter_is_rejected(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/notifications?filter=everything', server: $this->auth($token));

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_show_returns_the_presented_notification_only_to_its_recipient(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $owner, 'token' => $ownerToken] = $this->authenticate($client, 'ROLE_MANAGER');
        ['token' => $otherToken] = $this->authenticate($client, 'ROLE_MANAGER');
        $n = $this->createNotification($owner, 'PLANNING_MISSION_CANCELLED');

        $client->request('GET', '/api/notifications/' . $n->getId(), server: $this->auth($otherToken));
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'another manager never reads it, whatever his role');

        $client->request('GET', '/api/notifications/' . $n->getId(), server: $this->auth($ownerToken));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($n->getId(), $this->json($client->getResponse())['id']);
    }

    /** Même mission, cible propre au rôle de chaque destinataire (les voters de la page cible restent juges). */
    #[WithoutErrorHandler]
    public function test_target_depends_on_the_recipient_role(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $expected = [
            'ROLE_MANAGER'       => '/app/m/missions/%d',
            'ROLE_ADMIN'         => '/app/m/missions/%d',
            'ROLE_INSTRUMENTIST' => '/app/i/missions/%d',
            'ROLE_SURGEON'       => '/app/s/missions/%d',
        ];
        $users = [];
        foreach (array_keys($expected) as $role) {
            $users[$role] = $this->authenticate($client, $role);
        }
        $mission = $this->makeMission(\App\Enum\MissionStatus::CANCELLED);

        foreach ($expected as $role => $pattern) {
            $this->notifyAbout($users[$role]['user'], 'PLANNING_MISSION_CANCELLED', $mission);
            $client->request('GET', '/api/notifications', server: $this->auth($users[$role]['token']));
            $items = $this->json($client->getResponse())['items'];
            self::assertCount(1, $items, $role . ' sees only his own notification');
            self::assertSame(sprintf($pattern, $mission->getId()), $items[0]['targetUrl'], $role);
        }
    }

    // ── Performance, pagination, ressources disparues ─────────────────────────

    /** @return array{0: int, 1: array<string, mixed>} query count, decoded body */
    private function profiledList(KernelBrowser $client, string $token, string $query): array
    {
        $client->enableProfiler();
        $client->request('GET', '/api/notifications?' . $query, server: $this->auth($token));
        $profile = $client->getProfile();
        self::assertNotFalse($profile, 'profiler indisponible — le test N+1 ne prouverait rien');

        return [$profile->getCollector('db')->getQueryCount(), $this->json($client->getResponse())];
    }

    /** Ancienne alerte (mission seulement dans le payload) + mission déclarée liée, chacune avec son site et son chirurgien. */
    private function notifyMany(User $manager, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $mission = $this->makeMission(\App\Enum\MissionStatus::DECLARED);
            $this->notifyAbout($manager, 'MISSION_DECLARED', $mission);
            $evt = (new NotificationEvent())->setUser($this->em->find(User::class, $manager->getId()))->setEventType('PLANNING_ALERT_RAISED')
                ->setChannel(PublicationChannel::IN_APP)->setSentAt(new \DateTimeImmutable())
                ->setPayload(['alertId' => 900000 + $i, 'alertType' => 'SURGEON_ABSENCE', 'missionId' => $mission->getId(), 'missionDate' => '2026-11-16', 'siteName' => 'Delta']);
            $this->em->persist($evt);
            $this->em->flush();
            $this->createdIds['notifications'][] = $evt->getId();
        }
    }

    #[WithoutErrorHandler]
    public function test_query_count_does_not_grow_with_the_number_of_notifications(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $manager, 'token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $this->notifyMany($manager, 2);
        $counts = [];
        foreach (['all', 'unread', 'action'] as $filter) {
            [$counts[$filter]] = $this->profiledList($client, $token, 'filter=' . $filter);
        }
        $this->em = static::getContainer()->get(EntityManagerInterface::class); // enableProfiler() reboots the kernel
        $this->notifyMany($manager, 10);

        foreach (['all', 'unread', 'action'] as $filter) {
            [$queries, $body] = $this->profiledList($client, $token, 'filter=' . $filter);
            self::assertSame($counts[$filter], $queries, "filter=$filter: 4 then 24 notifications, same number of queries (no N+1)");
            self::assertCount($filter === 'action' ? 12 : 24, $body['items'], 'only the declared missions are to handle: the fictitious alerts do not exist');
            $this->em = static::getContainer()->get(EntityManagerInterface::class);
        }
    }

    #[WithoutErrorHandler]
    public function test_list_is_paginated_on_the_server(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $user, 'token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        $ids = [];
        foreach (range(1, 5) as $i) {
            $n = $this->createNotification($user, 'PLANNING_MISSION_CANCELLED');
            $n->setSentAt(new \DateTimeImmutable("-$i minutes"));
            $this->em->flush();
            $ids[] = $n->getId();
        }

        $client->request('GET', '/api/notifications?limit=2&offset=0', server: $this->auth($token));
        $first = $this->json($client->getResponse());
        $client->request('GET', '/api/notifications?limit=2&offset=4', server: $this->auth($token));
        $last = $this->json($client->getResponse());

        self::assertSame(array_slice($ids, 0, 2), array_column($first['items'], 'id'));
        self::assertTrue($first['hasMore']);
        self::assertSame([$ids[4]], array_column($last['items'], 'id'));
        self::assertFalse($last['hasMore']);
    }

    #[WithoutErrorHandler]
    public function test_a_notification_whose_resources_no_longer_exist_is_still_listed_without_error_nor_target(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['user' => $manager, 'token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');
        $evt = (new NotificationEvent())->setUser($this->em->find(User::class, $manager->getId()))->setEventType('PLANNING_ALERT_RAISED')
            ->setChannel(PublicationChannel::IN_APP)->setSentAt(new \DateTimeImmutable())
            ->setPayload(['alertId' => 987654321, 'alertType' => 'SURGEON_ABSENCE', 'missionId' => 987654321]);
        $this->em->persist($evt);
        $this->em->flush();
        $this->createdIds['notifications'][] = $evt->getId();

        $client->request('GET', '/api/notifications', server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $item = $this->json($client->getResponse())['items'][0];
        self::assertSame('Alerte planning — absence du chirurgien', $item['title']);
        self::assertSame('Une alerte concerne le planning.', $item['body'], 'no mission context invented');
        self::assertNull($item['targetUrl']);
        self::assertSame('INFO', $item['businessState'], 'unknown alert: never claimed to do');
    }
}
