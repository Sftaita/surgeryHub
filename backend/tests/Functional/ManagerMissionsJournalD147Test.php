<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\SiteMembership;
use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-147 — vue manager Missions (recherche `q`, ordre `order`), compteurs et historique
 * des demandes chirurgien, journal d'activité (GET /api/manager/audit-events).
 */
final class ManagerMissionsJournalD147Test extends WebTestCase
{
    private const PASSWORD = 'D147Test15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'requests' => [], 'memberships' => []];
    /** Unique per test — isolates searches from whatever else lives in the shared test DB. */
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->tag = 'd147' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            if (!empty($this->createdIds['missions']) || !empty($this->createdIds['users'])) {
                $qb = $this->em->createQueryBuilder()->select('a')->from(AuditEvent::class, 'a');
                $conditions = [];
                if (!empty($this->createdIds['missions'])) {
                    $conditions[] = 'a.mission IN (:missionIds)';
                    $qb->setParameter('missionIds', $this->createdIds['missions']);
                }
                if (!empty($this->createdIds['users'])) {
                    $conditions[] = 'a.actor IN (:userIds)';
                    $qb->setParameter('userIds', $this->createdIds['users']);
                }
                $qb->where(implode(' OR ', $conditions));
                foreach ($qb->getQuery()->getResult() as $evt) {
                    $this->em->remove($evt);
                }
                $this->em->flush();
            }
            foreach (['requests' => SurgeonMissionRequest::class, 'missions' => Mission::class, 'memberships' => SiteMembership::class, 'users' => User::class, 'sites' => Hospital::class] as $key => $class) {
                foreach ($this->createdIds[$key] as $id) {
                    $e = $this->em->find($class, $id);
                    if ($e !== null) { $this->em->remove($e); }
                }
                $this->em->flush();
            }
        }
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role, string $firstname = 'D147', ?string $lastname = null): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('d147-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname($lastname ?? 'Test' . $this->tag);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function login(KernelBrowser $client, User $user): string
    {
        $client->request('POST', '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function get(KernelBrowser $client, string $uri, string $token): Response
    {
        $client->request('GET', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    private function post(KernelBrowser $client, string $uri, string $token, array $body): Response
    {
        $client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: json_encode($body));
        return $client->getResponse();
    }

    private function json(Response $r): array
    {
        return json_decode((string) $r->getContent(), true) ?? [];
    }

    private function makeSite(string $name): Hospital
    {
        $h = new Hospital();
        $h->setName($name);
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeMission(User $surgeon, Hospital $site, string $start, ?User $instrumentist = null): Mission
    {
        $startAt = new \DateTimeImmutable($start, new \DateTimeZone(self::TZ));
        $m = new Mission();
        $m->setStatus($instrumentist ? MissionStatus::ASSIGNED : MissionStatus::OPEN);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $m->setStartAt($startAt);
        $m->setEndAt($startAt->modify('+4 hours'));
        if ($instrumentist) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    /** @return list<int> ids of the returned page */
    private function listIds(KernelBrowser $client, string $token, array $query): array
    {
        $r = $this->get($client, '/api/missions?' . http_build_query($query + ['limit' => 100]), $token);
        self::assertSame(Response::HTTP_OK, $r->getStatusCode(), (string) $r->getContent());
        return array_map(static fn (array $i) => $i['id'], $this->json($r)['items']);
    }

    private function createPendingRequest(KernelBrowser $client, User $surgeon, Hospital $site, int $dayOffset): int
    {
        $token = $this->login($client, $surgeon);
        $start = (new \DateTimeImmutable('today 08:00', new \DateTimeZone(self::TZ)))->modify("+{$dayOffset} days");
        $r = $this->post($client, '/api/surgeon/mission-requests', $token, [
            'siteId' => $site->getId(),
            'type' => 'BLOCK',
            'startAt' => $start->format(\DateTimeInterface::ATOM),
            'endAt' => $start->modify('+5 hours')->format(\DateTimeInterface::ATOM),
        ]);
        self::assertSame(Response::HTTP_CREATED, $r->getStatusCode(), (string) $r->getContent());
        $id = $this->json($r)['id'];
        $this->createdIds['requests'][] = $id;
        return $id;
    }

    // ── GET /api/missions ?q / ?order ───────────────────────────────────────

    public function test_search_matches_surgeon_instrumentist_site_and_mission_id(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $dupont = $this->createUser('ROLE_SURGEON', 'Jean', 'Dupont' . $this->tag);
        $martin = $this->createUser('ROLE_SURGEON', 'Paul', 'Martin' . $this->tag);
        $instr = $this->createUser('ROLE_INSTRUMENTIST', 'Alice', 'Lemaire' . $this->tag);
        $siteA = $this->makeSite('Clinique ' . $this->tag . ' Nord');
        $siteB = $this->makeSite('Hôpital ' . $this->tag . ' Sud');

        $m1 = $this->makeMission($dupont, $siteA, '+3 days 08:00');
        $m2 = $this->makeMission($martin, $siteB, '+4 days 08:00', $instr);
        $m3 = $this->makeMission($dupont, $siteB, '+5 days 08:00');

        $token = $this->login($client, $manager);

        self::assertEqualsCanonicalizing([$m1->getId(), $m3->getId()], $this->listIds($client, $token, ['q' => 'dupont' . $this->tag]));
        self::assertSame([$m2->getId()], $this->listIds($client, $token, ['q' => 'LEMAIRE' . $this->tag]), 'case-insensitive, instrumentist');
        self::assertEqualsCanonicalizing([$m2->getId(), $m3->getId()], $this->listIds($client, $token, ['q' => $this->tag . ' sud']), 'site name');
        // Every term must match: surgeon AND site.
        self::assertSame([$m3->getId()], $this->listIds($client, $token, ['q' => 'jean dupont' . $this->tag . ' sud']));
        self::assertContains($m1->getId(), $this->listIds($client, $token, ['q' => '#' . $m1->getId()]));
        self::assertSame([], $this->listIds($client, $token, ['q' => $this->tag . '%']), 'LIKE wildcards are literal');
    }

    public function test_order_asc_returns_soonest_first_and_invalid_order_is_rejected(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite('Site ' . $this->tag);
        $late = $this->makeMission($surgeon, $site, '+6 days 08:00');
        $early = $this->makeMission($surgeon, $site, '+2 days 08:00');

        $token = $this->login($client, $manager);

        self::assertSame([$early->getId(), $late->getId()], $this->listIds($client, $token, ['q' => $this->tag, 'order' => 'asc']));
        self::assertSame([$late->getId(), $early->getId()], $this->listIds($client, $token, ['q' => $this->tag]), 'default stays DESC');

        $r = $this->get($client, '/api/missions?order=sideways', $token);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $r->getStatusCode(), (string) $r->getContent());
    }

    public function test_search_stays_self_scoped_for_a_surgeon(): void
    {
        $client = $this->boot();
        $me = $this->createUser('ROLE_SURGEON', 'Moi', 'Self' . $this->tag);
        $other = $this->createUser('ROLE_SURGEON', 'Autre', 'Other' . $this->tag);
        $site = $this->makeSite('Site ' . $this->tag);
        $mine = $this->makeMission($me, $site, '+3 days 08:00');
        $this->makeMission($other, $site, '+3 days 13:00');

        $token = $this->login($client, $me);
        self::assertSame([$mine->getId()], $this->listIds($client, $token, ['q' => $this->tag]));
    }

    // ── /api/manager/surgeon-mission-requests/counts & /history ─────────────

    public function test_counts_follow_review_transitions(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite('Site ' . $this->tag);
        $membership = (new SiteMembership())->setUser($surgeon)->setSite($site)->setSiteRole('SURGEON');
        $this->em->persist($membership);
        $this->em->flush();
        $this->createdIds['memberships'][] = $membership->getId();

        $tokenM = $this->login($client, $manager);
        $before = $this->json($this->get($client, '/api/manager/surgeon-mission-requests/counts', $tokenM));
        self::assertSame(['PENDING', 'ACCEPTED', 'REJECTED'], array_keys($before));

        $a = $this->createPendingRequest($client, $surgeon, $site, 3);
        $this->createPendingRequest($client, $surgeon, $site, 4);

        $tokenM = $this->login($client, $manager);
        $r = $this->post($client, "/api/manager/surgeon-mission-requests/{$a}/reject", $tokenM, ['reviewComment' => 'Complet']);
        self::assertSame(Response::HTTP_OK, $r->getStatusCode(), (string) $r->getContent());

        $after = $this->json($this->get($client, '/api/manager/surgeon-mission-requests/counts', $tokenM));
        self::assertSame($before['PENDING'] + 1, $after['PENDING']);
        self::assertSame($before['REJECTED'] + 1, $after['REJECTED']);
        self::assertSame($before['ACCEPTED'], $after['ACCEPTED']);

        // History: the rejection, then both creations — with the request summary even
        // though neither event carries a Mission.
        $history = $this->json($this->get($client, '/api/manager/surgeon-mission-requests/history?limit=100', $tokenM));
        $mine = array_values(array_filter($history['items'], fn (array $i) => ($i['surgeonMissionRequest']['surgeon']['id'] ?? null) === $surgeon->getId()));
        self::assertSame(
            ['SURGEON_MISSION_REQUEST_REJECTED', 'SURGEON_MISSION_REQUEST_CREATED', 'SURGEON_MISSION_REQUEST_CREATED'],
            array_column($mine, 'eventType'),
        );
        self::assertSame($a, $mine[0]['surgeonMissionRequest']['id']);
        self::assertSame('REJECTED', $mine[0]['surgeonMissionRequest']['status']);
        self::assertSame($manager->getId(), $mine[0]['actor']['id']);
        self::assertSame('Complet', $mine[0]['payload']['reviewComment']);
        self::assertSame($site->getName(), $mine[0]['surgeonMissionRequest']['site']['name']);
        self::assertSame('SURGEON_REQUEST', $mine[0]['category']);
    }

    public function test_counts_and_history_are_manager_only(): void
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $token = $this->login($client, $surgeon);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->get($client, '/api/manager/surgeon-mission-requests/counts', $token)->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->get($client, '/api/manager/surgeon-mission-requests/history', $token)->getStatusCode());
    }

    // ── GET /api/manager/audit-events ───────────────────────────────────────

    public function test_journal_filters_by_mission_and_category_newest_first(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER', 'Gérard', 'Manager' . $this->tag);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite('Site ' . $this->tag);
        $mission = $this->makeMission($surgeon, $site, '+3 days 08:00');

        foreach ([AuditEventType::MISSION_CREATED_MANUALLY, AuditEventType::MISSION_PUBLISHED_TO_POOL, AuditEventType::MISSION_ENCODING_VALIDATED] as $type) {
            $e = (new AuditEvent())->setMission($mission)->setActor($manager)->setEventType($type)->setPayload(['missionId' => $mission->getId()]);
            $this->em->persist($e);
            $this->em->flush();
        }

        $token = $this->login($client, $manager);

        $all = $this->json($this->get($client, '/api/manager/audit-events?missionId=' . $mission->getId(), $token));
        self::assertSame(3, $all['total']);
        self::assertSame(
            ['MISSION_ENCODING_VALIDATED', 'MISSION_PUBLISHED_TO_POOL', 'MISSION_CREATED_MANUALLY'],
            array_column($all['items'], 'eventType'),
        );
        self::assertSame($site->getName(), $all['items'][0]['mission']['site']['name']);
        self::assertSame('Gérard Manager' . $this->tag, $all['items'][0]['actor']['displayName']);

        $missionOnly = $this->json($this->get($client, '/api/manager/audit-events?category=MISSION&missionId=' . $mission->getId(), $token));
        self::assertSame(['MISSION_PUBLISHED_TO_POOL', 'MISSION_CREATED_MANUALLY'], array_column($missionOnly['items'], 'eventType'));

        $encoding = $this->json($this->get($client, '/api/manager/audit-events?category=ENCODING&missionId=' . $mission->getId(), $token));
        self::assertSame(['MISSION_ENCODING_VALIDATED'], array_column($encoding['items'], 'eventType'));
        self::assertSame('ENCODING', $encoding['items'][0]['category']);

        // q matches the actor's name; pagination keeps the total.
        $byActor = $this->json($this->get($client, '/api/manager/audit-events?limit=1&q=manager' . $this->tag, $token));
        self::assertSame(3, $byActor['total']);
        self::assertCount(1, $byActor['items']);
    }

    public function test_journal_rejects_invalid_filters_and_non_managers(): void
    {
        $client = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');

        $tokenM = $this->login($client, $manager);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, '/api/manager/audit-events?category=NOPE', $tokenM)->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, '/api/manager/audit-events?eventType=NOPE', $tokenM)->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get($client, '/api/manager/audit-events?from=not-a-date', $tokenM)->getStatusCode());

        $tokenI = $this->login($client, $instr);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->get($client, '/api/manager/audit-events', $tokenI)->getStatusCode());
    }

    public function test_every_audit_event_type_has_a_known_category(): void
    {
        foreach (AuditEventType::cases() as $type) {
            self::assertContains($type->category(), AuditEventType::CATEGORIES, $type->value);
        }
        self::assertSame(AuditEventType::CATEGORY_SURGEON_REQUEST, AuditEventType::SURGEON_MISSION_REQUEST_ACCEPTED->category());
        self::assertSame(AuditEventType::CATEGORY_FINANCE, AuditEventType::CREDIT_NOTE_CREATED->category());
        self::assertSame(AuditEventType::CATEGORY_PLANNING, AuditEventType::ROOM_SLOT_TAKEN_OVER->category());
        self::assertSame(AuditEventType::CATEGORY_MISSION, AuditEventType::MISSION_ASSIGNED_DIRECTLY->category());
    }
}
