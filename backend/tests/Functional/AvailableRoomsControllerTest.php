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
    public function test_past_slots_excluded_by_default_manager_can_opt_in(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $managerToken] = $this->authenticate($client, 'ROLE_MANAGER');
        $surgeonId = $this->authenticate($client, 'ROLE_SURGEON')['user']->getId();
        // Re-fetch après la 2e requête de login — voir commentaire équivalent plus haut dans ce fichier.
        $surgeon = $this->em->find(User::class, $surgeonId);

        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $this->makeSlot($site, $surgeon, '2020-01-01', 4001);
        $this->makeSlot($site, $surgeon, (new \DateTimeImmutable('today'))->format('Y-m-d'), 4002);

        // Base de test partagée, non transactionnelle — le manager voit TOUS les sites sans
        // scoping, donc filtrer explicitement sur ce site précis est indispensable pour ne
        // jamais dépendre du volume total de la table (déjà >100 lignes d'autres classes).
        $client->request('GET', '/api/planning/available-rooms?siteId=' . $site->getId(), server: $this->auth($managerToken));
        $data = $this->json($client->getResponse());
        self::assertSame(1, $data['total'], 'par défaut, uniquement aujourd\'hui/futur');

        $client->request('GET', '/api/planning/available-rooms?siteId=' . $site->getId() . '&includePast=1', server: $this->auth($managerToken));
        $data = $this->json($client->getResponse());
        self::assertSame(2, $data['total'], 'le manager peut lever le filtre pour consulter l\'historique');
    }

    /**
     * Revue intégration planning chirurgien (2026-09-07, §9) : `includePast` n'est jamais lu
     * côté self-endpoint — même en le forçant explicitement dans la query string, le passé
     * reste invisible pour un chirurgien. Contraste volontaire avec le manager ci-dessus.
     */
    #[WithoutErrorHandler]
    public function test_surgeon_never_sees_the_past_even_with_includePast_forced(): void
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
        self::assertSame(1, $data['total'], 'includePast=1 est ignoré côté chirurgien — jamais le passé, même sur demande explicite');
    }

    // ── Revue intégration planning chirurgien (2026-09-07) — dateFrom/dateTo/period/count ──

    #[WithoutErrorHandler]
    public function test_dateFrom_and_dateTo_bound_the_window(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewer] = $this->authenticate($client, 'ROLE_SURGEON');

        $site = $this->makeSite();
        $this->affiliate($viewer, $site);
        $today = new \DateTimeImmutable('today');
        $this->makeSlot($site, $viewer, $today->format('Y-m-d'), 5001);
        $this->makeSlot($site, $viewer, $today->modify('+10 days')->format('Y-m-d'), 5002);
        $this->makeSlot($site, $viewer, $today->modify('+40 days')->format('Y-m-d'), 5003);

        $client->request('GET', sprintf(
            '/api/me/available-rooms?dateFrom=%s&dateTo=%s',
            $today->format('Y-m-d'),
            $today->modify('+15 days')->format('Y-m-d'),
        ), server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(2, $data['total'], 'seules les 2 occurrences dans [today, today+15j] sont comptées');
    }

    #[WithoutErrorHandler]
    public function test_malformed_dateFrom_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('GET', '/api/me/available-rooms?dateFrom=not-a-date', server: $this->auth($token));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_period_filters_correctly(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewer] = $this->authenticate($client, 'ROLE_SURGEON');

        $site = $this->makeSite();
        $this->affiliate($viewer, $site);
        $today = new \DateTimeImmutable('today');
        $matin = $this->makeSlot($site, $viewer, $today->format('Y-m-d'), 6001);
        $apresMidi = new ReleasedOperatingRoomSlot();
        $apresMidi->setSite($site);
        $apresMidi->setPostId(6002);
        $apresMidi->setOccurrenceDate($today);
        $apresMidi->setPeriod(ShiftPeriod::APRES_MIDI);
        $apresMidi->setSurgeon($viewer);
        $this->em->persist($apresMidi);
        $this->em->flush();
        $this->createdIds['slots'][] = $apresMidi->getId();

        $client->request('GET', '/api/me/available-rooms?period=APRES_MIDI', server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertSame(1, $data['total']);
        self::assertSame('APRES_MIDI', $data['items'][0]['period']);
    }

    #[WithoutErrorHandler]
    public function test_invalid_period_returns_400(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('GET', '/api/me/available-rooms?period=NOT_A_PERIOD', server: $this->auth($token));
        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_count_endpoint_matches_list_total_with_same_filters(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewer] = $this->authenticate($client, 'ROLE_SURGEON');

        $site = $this->makeSite();
        $this->affiliate($viewer, $site);
        $today = new \DateTimeImmutable('today');
        $this->makeSlot($site, $viewer, $today->format('Y-m-d'), 7001);
        $this->makeSlot($site, $viewer, $today->modify('+3 days')->format('Y-m-d'), 7002);

        $client->request('GET', '/api/me/available-rooms', server: $this->auth($token));
        $listData = $this->json($client->getResponse());

        $client->request('GET', '/api/me/available-rooms/count', server: $this->auth($token));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $countData = $this->json($client->getResponse());

        self::assertSame($listData['total'], $countData['count']);
        self::assertSame(2, $countData['count']);
    }

    #[WithoutErrorHandler]
    public function test_count_endpoint_never_sees_the_past_and_is_scoped_by_affiliation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewerRaw] = $this->authenticate($client, 'ROLE_SURGEON');
        $viewerId = $viewerRaw->getId();
        $otherSurgeonId = $this->authenticate($client, 'ROLE_SURGEON')['user']->getId();
        // Les deux authentifications (chacune une requête HTTP de login) doivent précéder
        // toute création d'entité ici — même schéma que test_surgeon_sees_only_slots_of_
        // their_affiliated_sites plus haut : une entité créée entre deux client->request()
        // se retrouve détachée par la requête suivante.
        $viewer = $this->em->find(User::class, $viewerId);
        $otherSurgeon = $this->em->find(User::class, $otherSurgeonId);

        $affiliatedSite = $this->makeSite();
        $foreignSite = $this->makeSite();
        $this->affiliate($viewer, $affiliatedSite);
        $this->makeSlot($affiliatedSite, $viewer, '2020-01-01', 8001);
        $this->makeSlot($affiliatedSite, $viewer, (new \DateTimeImmutable('today'))->format('Y-m-d'), 8002);
        $this->affiliate($otherSurgeon, $foreignSite);
        $this->makeSlot($foreignSite, $otherSurgeon, (new \DateTimeImmutable('today'))->format('Y-m-d'), 8003);

        $client->request('GET', '/api/me/available-rooms/count', server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertSame(1, $data['count'], 'ni le passé, ni le site étranger, ne comptent');
    }

    #[WithoutErrorHandler]
    public function test_instrumentist_is_forbidden_from_the_count_endpoint(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $client->request('GET', '/api/me/available-rooms/count', server: $this->auth($token));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_siteId_narrows_within_the_affiliated_sites(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewerRaw] = $this->authenticate($client, 'ROLE_SURGEON');
        $viewer = $this->em->find(User::class, $viewerRaw->getId());

        $siteA = $this->makeSite();
        $siteB = $this->makeSite();
        $this->affiliate($viewer, $siteA);
        $this->affiliate($viewer, $siteB);
        $this->makeSlot($siteA, $viewer, (new \DateTimeImmutable('today'))->format('Y-m-d'), 9001);
        $this->makeSlot($siteB, $viewer, (new \DateTimeImmutable('today'))->format('Y-m-d'), 9002);

        $client->request('GET', '/api/me/available-rooms?siteId=' . $siteA->getId(), server: $this->auth($token));
        $data = $this->json($client->getResponse());
        self::assertSame(1, $data['total'], 'siteId doit filtrer même quand le chirurgien est affilié aux deux sites');
        self::assertSame($siteA->getId(), $data['items'][0]['site']['id']);
    }

    #[WithoutErrorHandler]
    public function test_list_is_sorted_chronologically_ascending(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewerRaw] = $this->authenticate($client, 'ROLE_SURGEON');
        $viewer = $this->em->find(User::class, $viewerRaw->getId());

        $site = $this->makeSite();
        $this->affiliate($viewer, $site);
        $today = new \DateTimeImmutable('today');
        // Créés volontairement dans le désordre pour ne jamais dépendre de l'ordre d'insertion.
        $this->makeSlot($site, $viewer, $today->modify('+20 days')->format('Y-m-d'), 9101);
        $this->makeSlot($site, $viewer, $today->format('Y-m-d'), 9102);
        $this->makeSlot($site, $viewer, $today->modify('+5 days')->format('Y-m-d'), 9103);

        $client->request('GET', '/api/me/available-rooms', server: $this->auth($token));
        $data = $this->json($client->getResponse());
        $dates = array_column($data['items'], 'occurrenceDate');
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates, 'la liste doit être triée par date croissante, jamais l\'ordre d\'insertion');
    }

    /**
     * Le badge CTA (`/count`) et la liste (`/available-rooms`) doivent partager exactement le
     * même scoping métier — affiliations, futur uniquement, siteId/dateFrom/dateTo/period —
     * jamais diverger. `countForList()`/`findForList()` réutilisent la même `applyFilters()`
     * privée ; ce test exerce les deux endpoints avec la totalité des filtres combinés
     * simultanément (pas juste isolément comme les tests précédents) pour verrouiller cette
     * garantie contre une future dérive entre les deux méthodes.
     */
    #[WithoutErrorHandler]
    public function test_count_never_diverges_from_list_total_under_combined_filters(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $viewerRaw] = $this->authenticate($client, 'ROLE_SURGEON');
        $viewer = $this->em->find(User::class, $viewerRaw->getId());

        $siteA = $this->makeSite();
        $siteB = $this->makeSite();
        $this->affiliate($viewer, $siteA);
        $this->affiliate($viewer, $siteB);
        $today = new \DateTimeImmutable('today');

        // Dans la fenêtre + filtres ciblés (siteA, MATIN, [today, today+10]) : ces deux-là comptent.
        $this->makeSlot($siteA, $viewer, $today->modify('+2 days')->format('Y-m-d'), 9201);
        $this->makeSlot($siteA, $viewer, $today->modify('+8 days')->format('Y-m-d'), 9202);
        // Bruit volontaire, chacun exclu par un seul des filtres à la fois — si count et list
        // divergeaient sur un seul de ces filtres, ce test le révélerait.
        $this->makeSlot($siteB, $viewer, $today->modify('+3 days')->format('Y-m-d'), 9203); // mauvais site
        $this->makeSlot($siteA, $viewer, $today->modify('+15 days')->format('Y-m-d'), 9204); // hors fenêtre dateTo
        $apresMidi = new ReleasedOperatingRoomSlot();
        $apresMidi->setSite($siteA);
        $apresMidi->setPostId(9205);
        $apresMidi->setOccurrenceDate($today->modify('+4 days'));
        $apresMidi->setPeriod(ShiftPeriod::APRES_MIDI);
        $apresMidi->setSurgeon($viewer);
        $this->em->persist($apresMidi);
        $this->em->flush();
        $this->createdIds['slots'][] = $apresMidi->getId(); // mauvaise période

        $query = sprintf(
            'siteId=%d&period=MATIN&dateFrom=%s&dateTo=%s',
            $siteA->getId(),
            $today->format('Y-m-d'),
            $today->modify('+10 days')->format('Y-m-d'),
        );

        $client->request('GET', '/api/me/available-rooms?' . $query, server: $this->auth($token));
        $listData = $this->json($client->getResponse());

        $client->request('GET', '/api/me/available-rooms/count?' . $query, server: $this->auth($token));
        $countData = $this->json($client->getResponse());

        self::assertSame(2, $listData['total'], json_encode($listData));
        self::assertSame(2, $countData['count'], json_encode($countData));
        self::assertSame($listData['total'], $countData['count']);
    }
}
