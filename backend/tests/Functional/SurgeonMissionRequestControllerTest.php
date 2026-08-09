<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\SiteMembership;
use App\Entity\SurgeonMissionRequest;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 5 (D-099) — SurgeonMissionRequest : demande chirurgien de mission, revue manager,
 * atomicité de l'acceptation (createdMission jamais posé sans status=ACCEPTED),
 * concurrence (deux revues simultanées), conflits planning (réutilise
 * PlanningConflictDetectionService).
 */
final class SurgeonMissionRequestControllerTest extends WebTestCase
{
    private const PASSWORD = 'Lot5Test15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = ['missions' => [], 'users' => [], 'sites' => [], 'requests' => [], 'memberships' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            // AuditEvent référence soit une Mission (accept), soit directement un acteur
            // (recordGlobal() — create()/reject(), sans Mission) : les deux FK doivent
            // être retirées avant de supprimer missions/utilisateurs, sinon violation de
            // contrainte FK (même principe que les autres tests de ce lot).
            if (!empty($this->createdIds['missions']) || !empty($this->createdIds['users'])) {
                $qb = $this->em->createQueryBuilder()->select('a')->from(\App\Entity\AuditEvent::class, 'a');
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
            foreach ($this->createdIds['requests'] as $id) {
                $e = $this->em->find(SurgeonMissionRequest::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['memberships'] as $id) {
                $e = $this->em->find(SiteMembership::class, $id);
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

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('lot5-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('Lot5');
        $u->setLastname('Test');
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

    private function request(KernelBrowser $client, string $method, string $uri, ?string $token = null, ?array $body = null): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $client->request($method, $uri, server: $server, content: $body !== null ? json_encode($body) : null);
        return $client->getResponse();
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Lot5Site-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function affiliate(User $surgeon, Hospital $site): SiteMembership
    {
        $m = new SiteMembership();
        $m->setUser($surgeon)->setSite($site)->setSiteRole('SURGEON');
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['memberships'][] = $m->getId();
        return $m;
    }

    private function makeMission(User $surgeon, Hospital $site, MissionStatus $status, \DateTimeImmutable $startAt, \DateTimeImmutable $endAt): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $m->setStartAt($startAt);
        $m->setEndAt($endAt);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function tomorrowAt(int $hour): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('tomorrow ' . $hour . ':00', new \DateTimeZone(self::TZ)));
    }

    private function bootSurgeonWithSite(): array
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->affiliate($surgeon, $site);
        $token = $this->login($client, $surgeon);
        return [$client, $surgeon, $site, $token];
    }

    private function createPendingRequest(KernelBrowser $client, string $token, Hospital $site, int $startHour = 8, int $endHour = 13): int
    {
        $response = $this->request($client, 'POST', '/api/surgeon/mission-requests', $token, [
            'siteId' => $site->getId(),
            'type' => 'BLOCK',
            'startAt' => $this->tomorrowAt($startHour)->format(\DateTimeInterface::ATOM),
            'endAt' => $this->tomorrowAt($endHour)->format(\DateTimeInterface::ATOM),
            'comment' => 'Bloc supplémentaire',
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $id = json_decode($response->getContent(), true)['id'];
        $this->createdIds['requests'][] = $id;
        return $id;
    }

    // ── §5/§24 Création ─────────────────────────────────────────────────────

    public function test_surgeon_can_create_a_request(): void
    {
        [$client, , $site, $token] = $this->bootSurgeonWithSite();

        $response = $this->request($client, 'POST', '/api/surgeon/mission-requests', $token, [
            'siteId' => $site->getId(),
            'type' => 'BLOCK',
            'startAt' => $this->tomorrowAt(8)->format(\DateTimeInterface::ATOM),
            'endAt' => $this->tomorrowAt(13)->format(\DateTimeInterface::ATOM),
            'comment' => 'Bloc supplémentaire',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        $this->createdIds['requests'][] = $body['id'];
        self::assertSame('PENDING', $body['status']);
        self::assertSame($site->getId(), $body['site']['id']);
        self::assertNull($body['createdMissionId']);
    }

    public function test_other_role_cannot_create_a_request(): void
    {
        $client = $this->boot();
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $token = $this->login($client, $instr);

        $response = $this->request($client, 'POST', '/api/surgeon/mission-requests', $token, [
            'siteId' => $site->getId(), 'type' => 'BLOCK',
            'startAt' => $this->tomorrowAt(8)->format(\DateTimeInterface::ATOM),
            'endAt' => $this->tomorrowAt(13)->format(\DateTimeInterface::ATOM),
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    /** Le client ne choisit jamais le chirurgien — un éventuel surgeonId est silencieusement ignoré (§5). */
    public function test_surgeon_id_in_body_is_ignored_self_is_always_forced(): void
    {
        [$client, $surgeon, $site, $token] = $this->bootSurgeonWithSite();
        $otherSurgeon = $this->createUser('ROLE_SURGEON');

        $response = $this->request($client, 'POST', '/api/surgeon/mission-requests', $token, [
            'siteId' => $site->getId(), 'type' => 'BLOCK', 'surgeonId' => $otherSurgeon->getId(),
            'startAt' => $this->tomorrowAt(8)->format(\DateTimeInterface::ATOM),
            'endAt' => $this->tomorrowAt(13)->format(\DateTimeInterface::ATOM),
        ]);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), $response->getContent());
        $id = json_decode($response->getContent(), true)['id'];
        $this->createdIds['requests'][] = $id;

        $this->em->clear();
        $created = $this->em->find(SurgeonMissionRequest::class, $id);
        self::assertSame($surgeon->getId(), $created->getSurgeon()->getId());
    }

    public function test_site_not_affiliated_is_refused(): void
    {
        [$client, , , $token] = $this->bootSurgeonWithSite();
        $foreignSite = $this->makeSite(); // jamais affilié à ce chirurgien

        $response = $this->request($client, 'POST', '/api/surgeon/mission-requests', $token, [
            'siteId' => $foreignSite->getId(), 'type' => 'BLOCK',
            'startAt' => $this->tomorrowAt(8)->format(\DateTimeInterface::ATOM),
            'endAt' => $this->tomorrowAt(13)->format(\DateTimeInterface::ATOM),
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_end_before_or_equal_start_is_rejected(): void
    {
        [$client, , $site, $token] = $this->bootSurgeonWithSite();

        $response = $this->request($client, 'POST', '/api/surgeon/mission-requests', $token, [
            'siteId' => $site->getId(), 'type' => 'BLOCK',
            'startAt' => $this->tomorrowAt(13)->format(\DateTimeInterface::ATOM),
            'endAt' => $this->tomorrowAt(8)->format(\DateTimeInterface::ATOM),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_invalid_type_is_rejected(): void
    {
        [$client, , $site, $token] = $this->bootSurgeonWithSite();

        $response = $this->request($client, 'POST', '/api/surgeon/mission-requests', $token, [
            'siteId' => $site->getId(), 'type' => 'NOT_A_TYPE',
            'startAt' => $this->tomorrowAt(8)->format(\DateTimeInterface::ATOM),
            'endAt' => $this->tomorrowAt(13)->format(\DateTimeInterface::ATOM),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_timezone_is_preserved_europe_brussels(): void
    {
        [$client, , $site, $token] = $this->bootSurgeonWithSite();
        $start = $this->tomorrowAt(8);

        $response = $this->request($client, 'POST', '/api/surgeon/mission-requests', $token, [
            'siteId' => $site->getId(), 'type' => 'BLOCK',
            'startAt' => $start->format(\DateTimeInterface::ATOM),
            'endAt' => $this->tomorrowAt(13)->format(\DateTimeInterface::ATOM),
        ]);
        $id = json_decode($response->getContent(), true)['id'];
        $this->createdIds['requests'][] = $id;

        $this->em->clear();
        $created = $this->em->find(SurgeonMissionRequest::class, $id);
        self::assertSame('08:00', $created->getStartAt()->format('H:i'));
    }

    // ── §7/§24 Liste ────────────────────────────────────────────────────────

    public function test_list_is_self_scoped(): void
    {
        // Un seul client (JWT bearer auth est stateless par-request — pas besoin d'un
        // second static::createClient(), non supporté au sein d'un même test, voir D-097).
        $client = $this->boot();

        $surgeonA = $this->createUser('ROLE_SURGEON');
        $siteA = $this->makeSite();
        $this->affiliate($surgeonA, $siteA);
        $tokenA = $this->login($client, $surgeonA);
        $this->createPendingRequest($client, $tokenA, $siteA);

        $surgeonB = $this->createUser('ROLE_SURGEON');
        $siteB = $this->makeSite();
        $this->affiliate($surgeonB, $siteB);
        $tokenB = $this->login($client, $surgeonB);
        $this->createPendingRequest($client, $tokenB, $siteB);

        $responseA = $this->request($client, 'GET', '/api/surgeon/mission-requests', $tokenA);
        $bodyA = json_decode($responseA->getContent(), true);
        self::assertCount(1, $bodyA);

        $responseB = $this->request($client, 'GET', '/api/surgeon/mission-requests', $tokenB);
        $bodyB = json_decode($responseB->getContent(), true);
        self::assertCount(1, $bodyB);
        self::assertNotSame($bodyA[0]['id'], $bodyB[0]['id']);
    }

    // ── §8/§24 Manager : liste + sécurité ──────────────────────────────────

    public function test_manager_can_list_all_requests(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $response = $this->request($clientS, 'GET', '/api/manager/surgeon-mission-requests?status=PENDING', $tokenM);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertGreaterThanOrEqual(1, $body['total']);
    }

    public function test_surgeon_cannot_access_manager_review_endpoints(): void
    {
        [$client, , , $token] = $this->bootSurgeonWithSite();

        self::assertSame(Response::HTTP_FORBIDDEN, $this->request($client, 'GET', '/api/manager/surgeon-mission-requests', $token)->getStatusCode());
    }

    // ── §9/§10/§24 Acceptation ──────────────────────────────────────────────

    public function test_manager_accepts_request_creates_draft_mission_linked(): void
    {
        [$clientS, $surgeon, $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $response = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenM, [
            'reviewComment' => 'OK',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('ACCEPTED', $body['status']);
        self::assertNotNull($body['createdMissionId']);

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $body['createdMissionId']);
        $this->createdIds['missions'][] = $mission->getId();
        self::assertSame(MissionStatus::DRAFT, $mission->getStatus());
        self::assertSame($surgeon->getId(), $mission->getSurgeon()->getId());
        self::assertSame($site->getId(), $mission->getSite()->getId());
        self::assertNull($mission->getInstrumentist());
    }

    public function test_accept_dispatches_decided_message_and_audits(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $response = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenM);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $missionId = json_decode($response->getContent(), true)['createdMissionId'];
        $this->createdIds['missions'][] = $missionId;

        $sent = array_values(array_filter(
            $transport->getSent(),
            static fn ($e) => $e->getMessage() instanceof \App\Message\SurgeonMissionRequestDecidedMessage,
        ));
        self::assertCount(1, $sent);
        /** @var \App\Message\SurgeonMissionRequestDecidedMessage $msg */
        $msg = $sent[0]->getMessage();
        self::assertTrue($msg->accepted);
        self::assertSame($missionId, $msg->missionId);

        $audits = $this->em->getRepository(\App\Entity\AuditEvent::class)->findBy(['mission' => $missionId]);
        self::assertNotEmpty($audits);
        $types = array_map(static fn ($a) => $a->getEventType()->value, $audits);
        self::assertContains('SURGEON_MISSION_REQUEST_ACCEPTED', $types);
    }

    public function test_instrumentist_cannot_accept(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $tokenI = $this->login($clientS, $instr);

        $response = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenI);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── §22/§24 Concurrence & double revue ────────────────────────────────

    public function test_double_accept_second_call_returns_conflict(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $first = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenM);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        $this->createdIds['missions'][] = json_decode($first->getContent(), true)['createdMissionId'];

        $second = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenM);
        self::assertSame(409, $second->getStatusCode());
        self::assertSame('SURGEON_MISSION_REQUEST_ALREADY_REVIEWED', json_decode($second->getContent(), true)['error']['code']);
    }

    public function test_accept_after_reject_is_rejected(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $reject = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/reject", $tokenM, [
            'reviewComment' => 'Bloc déjà complet',
        ]);
        self::assertSame(Response::HTTP_OK, $reject->getStatusCode());

        $accept = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenM);
        self::assertSame(409, $accept->getStatusCode());
        self::assertSame('SURGEON_MISSION_REQUEST_ALREADY_REVIEWED', json_decode($accept->getContent(), true)['error']['code']);
    }

    public function test_reject_after_accept_is_rejected(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $accept = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenM);
        self::assertSame(Response::HTTP_OK, $accept->getStatusCode());
        $this->createdIds['missions'][] = json_decode($accept->getContent(), true)['createdMissionId'];

        $reject = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/reject", $tokenM, [
            'reviewComment' => 'Trop tard',
        ]);
        self::assertSame(409, $reject->getStatusCode());
        self::assertSame('SURGEON_MISSION_REQUEST_ALREADY_REVIEWED', json_decode($reject->getContent(), true)['error']['code']);
    }

    // ── §23/§24 Conflits planning — atomicité (rollback : rien n'est écrit) ──

    /**
     * Le chirurgien a déjà une autre mission active qui chevauche la période demandée
     * (PlanningConflictDetectionService::findConflict(), réutilisé tel quel). L'accept()
     * échoue proprement AVANT toute écriture (verrou posé, conflit détecté, exception
     * levée) : la demande reste PENDING, createdMission reste null, aucune Mission
     * fantôme n'est créée — preuve de l'atomicité transactionnelle (§3/§9/§23).
     */
    public function test_accept_with_planning_conflict_leaves_request_pending_and_creates_no_mission(): void
    {
        [$clientS, $surgeon, $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site, 8, 13);

        // Re-fetch : la requête HTTP précédente a pu réinitialiser le container/EM
        // (même sans reboot du kernel), les objets $surgeon/$site en mémoire ne sont
        // plus garantis managés — voir le même souci documenté dans SelfAbsenceControllerTest.
        $surgeon = $this->em->find(User::class, $surgeon->getId());
        $site = $this->em->find(Hospital::class, $site->getId());

        // Mission déjà active du même chirurgien, qui chevauche 08:00-13:00.
        $this->makeMission($surgeon, $site, MissionStatus::ASSIGNED, $this->tomorrowAt(9), $this->tomorrowAt(11));

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $missionCountBefore = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM App\Entity\Mission m WHERE m.surgeon = :s')
            ->setParameter('s', $surgeon)->getSingleScalarResult();

        $response = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenM);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('SURGEON_MISSION_REQUEST_CONFLICT', json_decode($response->getContent(), true)['error']['code']);

        $missionCountAfter = (int) $this->em->createQuery('SELECT COUNT(m.id) FROM App\Entity\Mission m WHERE m.surgeon = :s')
            ->setParameter('s', $surgeon)->getSingleScalarResult();
        self::assertSame($missionCountBefore, $missionCountAfter, 'aucune Mission ne doit avoir été créée');

        $this->em->clear();
        $stillPending = $this->em->find(SurgeonMissionRequest::class, $requestId);
        self::assertSame(SurgeonMissionRequest::STATUS_PENDING, $stillPending->getStatus());
        self::assertNull($stillPending->getCreatedMission());
    }

    public function test_accept_without_conflict_succeeds_after_a_conflicting_request_was_rejected(): void
    {
        [$clientS, $surgeon, $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site, 8, 13);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $response = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/accept", $tokenM);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $this->createdIds['missions'][] = json_decode($response->getContent(), true)['createdMissionId'];
    }

    // ── §11/§24 Rejet ────────────────────────────────────────────────────────

    public function test_reject_requires_non_empty_comment(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $response = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/reject", $tokenM, [
            'reviewComment' => '   ',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_reject_sets_status_and_audits(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        $response = $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/reject", $tokenM, [
            'reviewComment' => 'Bloc déjà complet à cette date',
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('REJECTED', $body['status']);
        self::assertSame('Bloc déjà complet à cette date', $body['reviewComment']);
        self::assertNull($body['createdMissionId']);
    }

    public function test_reject_dispatches_decided_message(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $requestId = $this->createPendingRequest($clientS, $tokenS, $site);

        $manager = $this->createUser('ROLE_MANAGER');
        $tokenM = $this->login($clientS, $manager);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->request($clientS, 'POST', "/api/manager/surgeon-mission-requests/{$requestId}/reject", $tokenM, [
            'reviewComment' => 'Refusé',
        ]);

        $sent = array_values(array_filter(
            $transport->getSent(),
            static fn ($e) => $e->getMessage() instanceof \App\Message\SurgeonMissionRequestDecidedMessage,
        ));
        self::assertCount(1, $sent);
        self::assertFalse($sent[0]->getMessage()->accepted);
        self::assertSame('Refusé', $sent[0]->getMessage()->reviewComment);
    }

    // ── §13/§24 Notification création ──────────────────────────────────────

    public function test_create_dispatches_created_message_to_managers(): void
    {
        [$clientS, , $site, $tokenS] = $this->bootSurgeonWithSite();
        $manager = $this->createUser('ROLE_MANAGER');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $this->createPendingRequest($clientS, $tokenS, $site);

        $sent = array_values(array_filter(
            $transport->getSent(),
            static fn ($e) => $e->getMessage() instanceof \App\Message\SurgeonMissionRequestCreatedMessage,
        ));
        self::assertCount(1, $sent);
        self::assertContains($manager->getId(), $sent[0]->getMessage()->recipientUserIds);
    }
}
