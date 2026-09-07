<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * « Salles libérées » (Lot D, post D-114) — RBAC et scoping des deux endpoints de lecture.
 * `GET /api/planning/available-rooms` (manager, tous sites) et `GET /api/me/available-rooms`
 * (chirurgien, strictement scopé à ses `SiteMembership` — jamais un `siteId` client de
 * confiance au-delà de cette intersection, §10 de la demande).
 */
final class AvailableRoomsControllerTest extends WebTestCase
{
    private const PASSWORD = 'AvailableRoomsTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['slots' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['slots'] as $id) {
                $e = $this->em->find(ReleasedOperatingRoomSlot::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdIds['sites'] as $siteId) {
                $site = $this->em->find(Hospital::class, $siteId);
                if ($site === null) { continue; }
                foreach ($this->em->createQueryBuilder()->select('sm')->from(SiteMembership::class, 'sm')->where('sm.site = :s')->setParameter('s', $site)->getQuery()->getResult() as $sm) {
                    $this->em->remove($sm);
                }
                $this->em->flush();
            }

            foreach ($this->createdIds['users'] as $id) {
                $e = $this->em->find(User::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdIds['sites'] as $id) {
                $e = $this->em->find(Hospital::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
        }
        parent::tearDown();
    }

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('avail-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Avail Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function affiliate(User $user, Hospital $site): void
    {
        $sm = new SiteMembership();
        $sm->setUser($user);
        $sm->setSite($site);
        $sm->setSiteRole('SURGEON');
        $this->em->persist($sm);
        $this->em->flush();
    }

    private function makeSlot(Hospital $site, User $surgeon, string $date, int $postId): ReleasedOperatingRoomSlot
    {
        $slot = new ReleasedOperatingRoomSlot();
        $slot->setSite($site);
        $slot->setPostId($postId);
        $slot->setOccurrenceDate(new \DateTimeImmutable($date));
        $slot->setPeriod(ShiftPeriod::MATIN);
        $slot->setSurgeon($surgeon);
        $this->em->persist($slot);
        $this->em->flush();
        $this->createdIds['slots'][] = $slot->getId();
        return $slot;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    // ── 10 — accès manager, tous les sites ───────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_manager_sees_slots_across_every_site(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        // Re-fetch après la 2e requête de login (le login détache les entités déjà
        // persistées de l'identity map — même précaution que RoomReleaseCommunicationFunctionalTest
        // après un $this->em->clear() explicite).
        $surgeonId = $this->authenticate($client, 'ROLE_SURGEON')['user']->getId();
        $surgeon = $this->em->find(User::class, $surgeonId);
        $siteA = $this->makeSite();
        $siteB = $this->makeSite();
        $today = new \DateTimeImmutable('today');
        $this->makeSlot($siteA, $surgeon, $today->format('Y-m-d'), 1001);
        $this->makeSlot($siteB, $surgeon, $today->format('Y-m-d'), 1002);

        $client->request('GET', '/api/planning/available-rooms', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertGreaterThanOrEqual(2, $data['total'], 'le manager voit tous les sites, sans restriction');
    }

    #[WithoutErrorHandler]
    public function test_surgeon_is_forbidden_from_the_manager_endpoint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('GET', '/api/planning/available-rooms', server: $this->auth($token));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    // ── 9/11 — scoping chirurgien par affiliation, site non autorisé interdit ───

    #[WithoutErrorHandler]
    public function test_surgeon_sees_only_slots_of_their_affiliated_sites(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewerRaw] = $this->authenticate($client, 'ROLE_SURGEON');
        $viewerId = $viewerRaw->getId();

        $otherSurgeonId = $this->authenticate($client, 'ROLE_SURGEON')['user']->getId();
        // Re-fetch après la 2e requête de login — voir commentaire équivalent ci-dessus.
        $viewer = $this->em->find(User::class, $viewerId);
        $otherSurgeon = $this->em->find(User::class, $otherSurgeonId);
        $mySite = $this->makeSite();
        $otherSite = $this->makeSite();
        $this->affiliate($viewer, $mySite);
        // Le viewer n'est PAS affilié à $otherSite — même si un autre chirurgien y libère un créneau.

        $today = new \DateTimeImmutable('today');
        $this->makeSlot($mySite, $viewer, $today->format('Y-m-d'), 2001);
        $this->makeSlot($otherSite, $otherSurgeon, $today->format('Y-m-d'), 2002);

        $client->request('GET', '/api/me/available-rooms', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());

        $siteIds = array_map(fn ($item) => $item['site']['id'], $data['items']);
        self::assertContains($mySite->getId(), $siteIds);
        self::assertNotContains($otherSite->getId(), $siteIds, 'un site sans affiliation ne doit jamais être exposé au chirurgien');
    }

    #[WithoutErrorHandler]
    public function test_surgeon_with_no_site_membership_sees_an_empty_list_never_all_sites(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $otherSurgeonId = $this->authenticate($client, 'ROLE_SURGEON')['user']->getId();
        $otherSurgeon = $this->em->find(User::class, $otherSurgeonId);
        $otherSite = $this->makeSite();
        $this->makeSlot($otherSite, $otherSurgeon, (new \DateTimeImmutable('today'))->format('Y-m-d'), 3001);

        $client->request('GET', '/api/me/available-rooms', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = $this->json($client->getResponse());
        self::assertSame(0, $data['total'], 'aucune affiliation ne doit jamais être interprétée comme "tous les sites"');
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden_from_the_self_endpoint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/me/available-rooms', server: $this->auth($token));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    // ── 12 — dates passées non retournées par défaut ────────────────────────────

    #[WithoutErrorHandler]
    public function test_past_slots_excluded_by_default_but_included_with_includePast(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewer] = $this->authenticate($client, 'ROLE_SURGEON');

        $site = $this->makeSite();
        $this->affiliate($viewer, $site);
        $this->makeSlot($site, $viewer, '2020-01-01', 4001);
        $this->makeSlot($site, $viewer, (new \DateTimeImmutable('today'))->format('Y-m-d'), 4002);

        $client->request('GET', '/api/me/available-rooms', server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertSame(1, $data['total'], 'par défaut, uniquement aujourd\'hui/futur');

        $client->request('GET', '/api/me/available-rooms?includePast=1', server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertSame(2, $data['total'], 'includePast=1 lève le filtre pour le manager comme pour le chirurgien');
    }
}
