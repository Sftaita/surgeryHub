<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 6 (audit PWA/mobile/admin 2026-07-29) — badge "offres non lues". Avant ce lot, la
 * nav instrumentiste comptait TOUTES les offres OPEN éligibles, sans notion de lecture
 * (ne redescendait jamais en visitant l'écran Offres). Couvre GET
 * /api/missions/offers/unread-count + POST /api/me/offers-seen, en réutilisant la
 * branche eligibleToMe=true la plus simple (mission V2 OPEN, aucune MissionPublication,
 * instrumentiste freelance — donc pas de SiteMembership à construire, voir
 * MissionListStartAtTieBreakTest qui documente pourquoi cette branche est la plus
 * simple à fixturer).
 */
final class OffersUnreadCountControllerTest extends WebTestCase
{
    private const PASSWORD = 'OffersUnread28!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds = [];
    private array $createdSiteIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            // claim()/other post-deploy actions can create AuditEvent rows referencing
            // these missions (FK, no cascade) — must go before the missions themselves.
            foreach ($this->createdMissionIds as $id) {
                $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]);
                foreach ($events as $e) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdMissionIds as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdUserIds as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdSiteIds as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createFreelancerInstrumentist(): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('offers-unread-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles(['ROLE_INSTRUMENTIST']);
        $u->setActive(true);
        $u->setEmploymentType(EmploymentType::FREELANCER);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdUserIds[] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());
        return $data['token'];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('OffersUnread-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    /**
     * V2 OPEN mission: no MissionPublication row at all — eligible to any freelancer.
     * Reloads $site/$surgeon/$createdBy by id first: an intervening HTTP request (e.g.
     * an earlier POST /api/me/offers-seen call in the same test) can detach them from
     * the current EntityManager (same pattern as NotificationControllerTest).
     */
    private function makeOpenMission(Hospital $site, User $surgeon, User $createdBy, \DateTimeImmutable $startAt): Mission
    {
        $site = $this->em->find(Hospital::class, $site->getId()) ?? $site;
        $surgeon = $this->em->find(User::class, $surgeon->getId()) ?? $surgeon;
        $createdBy = $this->em->find(User::class, $createdBy->getId()) ?? $createdBy;

        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt($startAt);
        $m->setEndAt($startAt->modify('+4 hours'));
        $m->setStatus(MissionStatus::OPEN);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    /** Unique future window per run — avoids colliding with residual fixtures in surgicalhub_test. */
    private function futureStart(): \DateTimeImmutable
    {
        $offsetMinutes = random_int(1, 5_000_000);
        return (new \DateTimeImmutable('+10 days'))->modify("+{$offsetMinutes} minutes");
    }

    // ── AuthZ ────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_unread_count_rejects_unauthenticated_request(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $client->request('GET', '/api/missions/offers/unread-count');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_offers_seen_rejects_unauthenticated_request(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $client->request('POST', '/api/me/offers-seen');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ── unread-count ─────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_never_seen_counts_all_currently_eligible_open_offers(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createFreelancerInstrumentist();
        $token = $this->login($client, $instrumentist);
        $surgeon = $this->createFreelancerInstrumentist();
        $site = $this->makeSite();

        $this->makeOpenMission($site, $surgeon, $instrumentist, $this->futureStart());
        $this->makeOpenMission($site, $surgeon, $instrumentist, $this->futureStart());

        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($token));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertGreaterThanOrEqual(2, $this->json($client->getResponse())['unreadCount']);
    }

    #[WithoutErrorHandler]
    public function test_offers_seen_then_unread_count_excludes_offers_created_before_checkpoint(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createFreelancerInstrumentist();
        $token = $this->login($client, $instrumentist);
        $surgeon = $this->createFreelancerInstrumentist();
        $site = $this->makeSite();

        $this->makeOpenMission($site, $surgeon, $instrumentist, $this->futureStart());

        // Mark "seen" now — this offer was created before the checkpoint.
        $client->request('POST', '/api/me/offers-seen', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($token));
        self::assertSame(0, $this->json($client->getResponse())['unreadCount'], 'Visiting the offers screen must zero out the badge');
    }

    #[WithoutErrorHandler]
    public function test_a_new_offer_published_after_the_checkpoint_reappears_in_the_count(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createFreelancerInstrumentist();
        $token = $this->login($client, $instrumentist);
        $surgeon = $this->createFreelancerInstrumentist();
        $site = $this->makeSite();

        $client->request('POST', '/api/me/offers-seen', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // New offer created strictly after the checkpoint.
        usleep(1_100_000); // ensure createdAt (set at persist time) is strictly after the checkpoint (second-level DB precision)
        $this->makeOpenMission($site, $surgeon, $instrumentist, $this->futureStart());

        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($token));
        self::assertSame(1, $this->json($client->getResponse())['unreadCount'], 'A new offer published after the checkpoint must reappear in the badge');
    }

    #[WithoutErrorHandler]
    public function test_offers_seen_persists_the_checkpoint_on_the_user(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createFreelancerInstrumentist();
        $token = $this->login($client, $instrumentist);

        self::assertNull($instrumentist->getOffersLastSeenAt());

        $client->request('POST', '/api/me/offers-seen', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $instrumentist->getId());
        self::assertNotNull($reloaded->getOffersLastSeenAt());
    }

    #[WithoutErrorHandler]
    public function test_offers_seen_checkpoint_is_per_user(): void
    {
        $client = $this->boot();
        $instrumentistA = $this->createFreelancerInstrumentist();
        $tokenA = $this->login($client, $instrumentistA);
        $instrumentistB = $this->createFreelancerInstrumentist();
        $surgeon = $this->createFreelancerInstrumentist();
        $site = $this->makeSite();

        $this->makeOpenMission($site, $surgeon, $instrumentistA, $this->futureStart());

        $client->request('POST', '/api/me/offers-seen', server: $this->auth($tokenA));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $reloadedB = $this->em->find(User::class, $instrumentistB->getId());
        self::assertNull($reloadedB->getOffersLastSeenAt(), "User A's checkpoint must never affect user B");
    }

    // ── Revue post-rapport (2026-07-29) : cas supplémentaires demandés ──────

    #[WithoutErrorHandler]
    public function test_a_claimed_offer_disappears_from_the_unread_count(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createFreelancerInstrumentist();
        $token = $this->login($client, $instrumentist);
        $surgeon = $this->createFreelancerInstrumentist();
        $site = $this->makeSite();

        $mission = $this->makeOpenMission($site, $surgeon, $instrumentist, $this->futureStart());

        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($token));
        self::assertSame(1, $this->json($client->getResponse())['unreadCount']);

        // The instrumentist claims it — it leaves the OPEN/eligible pool entirely.
        $client->request('POST', "/api/missions/{$mission->getId()}/claim", server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($token));
        self::assertSame(0, $this->json($client->getResponse())['unreadCount'], 'A claimed mission must no longer count as an unread offer');
    }

    #[WithoutErrorHandler]
    public function test_a_cancelled_offer_disappears_from_the_unread_count(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createFreelancerInstrumentist();
        $token = $this->login($client, $instrumentist);
        $surgeon = $this->createFreelancerInstrumentist();
        $site = $this->makeSite();

        $mission = $this->makeOpenMission($site, $surgeon, $instrumentist, $this->futureStart());
        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($token));
        self::assertSame(1, $this->json($client->getResponse())['unreadCount']);

        // Directly flip the mission out of OPEN (equivalent to a manager cancelling it) —
        // no dedicated cancel-from-OPEN endpoint is exercised here, only the resulting
        // state, since the eligibility filter itself (m.status = OPEN) is what's under
        // test. Reload by id first: the intervening HTTP request above can detach the
        // entity from the current EntityManager (same pattern as elsewhere in this file).
        $managedMission = $this->em->find(Mission::class, $mission->getId());
        $managedMission->setStatus(\App\Enum\MissionStatus::CANCELLED);
        $this->em->flush();

        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($token));
        self::assertSame(0, $this->json($client->getResponse())['unreadCount'], 'A cancelled mission must no longer count as an unread offer');
    }

    #[WithoutErrorHandler]
    public function test_offers_last_seen_at_is_shared_across_two_simulated_devices(): void
    {
        $client = $this->boot();
        $instrumentist = $this->createFreelancerInstrumentist();
        $surgeon = $this->createFreelancerInstrumentist();
        $site = $this->makeSite();
        $this->makeOpenMission($site, $surgeon, $instrumentist, $this->futureStart());

        // Device A: logs in, sees the offer, marks it seen.
        $tokenDeviceA = $this->login($client, $instrumentist);
        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($tokenDeviceA));
        self::assertSame(1, $this->json($client->getResponse())['unreadCount']);
        $client->request('POST', '/api/me/offers-seen', server: $this->auth($tokenDeviceA));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        // Device B: a second, independent login session for the SAME user (simulates a
        // different physical device) — must see the checkpoint device A just set, not a
        // separate/local one, because it's stored on the User row, not per-session.
        $this->em->clear();
        $userForDeviceB = $this->em->find(User::class, $instrumentist->getId());
        $tokenDeviceB = $this->login($client, $userForDeviceB);

        $client->request('GET', '/api/missions/offers/unread-count', server: $this->auth($tokenDeviceB));
        self::assertSame(0, $this->json($client->getResponse())['unreadCount'], "Device B must see device A's checkpoint immediately — same offersLastSeenAt, no per-device state");
    }
}
