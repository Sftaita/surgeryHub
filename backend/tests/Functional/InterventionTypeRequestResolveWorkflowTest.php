<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Firm;
use App\Entity\Hospital;
use App\Entity\InterventionType;
use App\Entity\InterventionTypeRequest;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Revue instrumentiste, Lot 3, commit 5 — matrice complète HTTP de
 * POST /api/intervention-type-requests/{id}/resolve : autorisations et codes d'erreur
 * stables. Le scénario de succès nominal et le refus instrumentiste sont déjà couverts
 * par InterventionControllerLot5Test ; ce fichier couvre le reste de la matrice
 * demandée (admin autorisé, utilisateur sans droit, demande sans draft, référentiels
 * invalides, body incomplet, seconde résolution concurrente).
 */
final class InterventionTypeRequestResolveWorkflowTest extends WebTestCase
{
    private const PASSWORD = 'ResolveWF15!';
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'missions' => [], 'users' => [], 'sites' => [], 'firms' => [], 'types' => [], 'requests' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['missions'] as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['requests'] as $id) {
                $req = $this->em->find(InterventionTypeRequest::class, $id);
                if ($req !== null && $req->getDraft() !== null) {
                    $this->em->remove($req->getDraft());
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['requests'] as $id) {
                $e = $this->em->find(InterventionTypeRequest::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $m = $this->em->find(Mission::class, $id);
                if ($m !== null) {
                    foreach ($m->getInterventions() as $i) { $this->em->remove($i); }
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['types'] as $id) {
                $e = $this->em->find(InterventionType::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            foreach ($this->createdIds['firms'] as $id) {
                $e = $this->em->find(Firm::class, $id);
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
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('resolvewf-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname('ResolveWF');
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
        $h->setName('ResolveWFSite-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeFirm(bool $active = true): Firm
    {
        $f = new Firm();
        $f->setName('ResolveWFFirm-' . bin2hex(random_bytes(3)));
        $f->setActive($active);
        $this->em->persist($f);
        $this->em->flush();
        $this->createdIds['firms'][] = $f->getId();
        return $f;
    }

    private function makeType(bool $active = true): InterventionType
    {
        $t = new InterventionType();
        $t->setCode('RWF-' . bin2hex(random_bytes(4)));
        $t->setLabel('Type ResolveWF ' . bin2hex(random_bytes(2)));
        $t->setActive($active);
        $this->em->persist($t);
        $this->em->flush();
        $this->createdIds['types'][] = $t->getId();
        return $t;
    }

    private function makeEncodableMission(User $surgeon, User $instr, Hospital $site): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::ASSIGNED);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instr);
        $m->setSite($site);
        $m->setCreatedBy($surgeon);
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
        $m->setStartAt($now->modify('-1 hour'));
        $m->setEndAt($now->modify('+2 hours'));
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    /** @return array{0: KernelBrowser, 1: Mission, 2: string, 3: User} */
    private function bootMissionScenario(): array
    {
        $client = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr = $this->createUser('ROLE_INSTRUMENTIST');
        $site = $this->makeSite();
        $mission = $this->makeEncodableMission($surgeon, $instr, $site);
        $token = $this->login($client, $instr);
        return [$client, $mission, $token, $instr];
    }

    private function createPendingRequest(KernelBrowser $client, Mission $mission, string $instrToken, string $label = 'Demande ResolveWF'): int
    {
        $created = $this->request($client, 'POST', "/api/missions/{$mission->getId()}/intervention-type-requests", $instrToken, [
            'label' => $label,
        ]);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), $created->getContent());
        $requestId = json_decode($created->getContent(), true)['id'];
        $this->createdIds['requests'][] = $requestId;
        return $requestId;
    }

    // ── Autorisations ─────────────────────────────────────────────────

    public function test_admin_is_authorized_to_resolve(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType();

        $admin = $this->createUser('ROLE_ADMIN');
        $adminToken = $this->login($client, $admin);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $adminToken, [
            'interventionTypeId' => $type->getId(),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        self::assertSame('RESOLVED', json_decode($response->getContent(), true)['status']);
    }

    public function test_user_without_manager_or_admin_role_is_refused(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType();

        $surgeon = $this->createUser('ROLE_SURGEON');
        $surgeonToken = $this->login($client, $surgeon);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $surgeonToken, [
            'interventionTypeId' => $type->getId(),
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_unauthenticated_request_is_refused(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType();

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", null, [
            'interventionTypeId' => $type->getId(),
        ]);

        self::assertContains($response->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
    }

    // ── Erreurs métier ───────────────────────────────────────────────

    public function test_missing_intervention_type_id_returns_422(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, []);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_nonexistent_intervention_type_returns_404_with_stable_code(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'interventionTypeId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('INTERVENTION_TYPE_NOT_FOUND', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_inactive_intervention_type_returns_422_with_stable_code(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType(active: false);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('INTERVENTION_TYPE_INACTIVE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_nonexistent_firm_returns_404_with_stable_code(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType();

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
            'firmId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('PRIMARY_FIRM_NOT_FOUND', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_inactive_firm_returns_422_with_stable_code(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType();
        $firm = $this->makeFirm(active: false);

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
            'firmId' => $firm->getId(),
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('PRIMARY_FIRM_INACTIVE', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_nonexistent_request_returns_404(): void
    {
        [$client] = $this->bootMissionScenario();
        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', '/api/intervention-type-requests/999999999/resolve', $managerToken, [
            'interventionTypeId' => 1,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function test_legacy_request_without_draft_returns_409(): void
    {
        // Simule une InterventionTypeRequest antérieure au Lot 3 (aucun draft associé) :
        // insérée directement, en dehors du flux POST habituel qui crée toujours le draft.
        [$client, $mission] = $this->bootMissionScenario();
        $instr = $this->em->getRepository(User::class)->findOneBy(['email' => $mission->getInstrumentist()->getEmail()]);

        $req = new InterventionTypeRequest();
        $req->setMission($mission)->setLabel('Demande héritée sans draft')->setCreatedBy($instr);
        $this->em->persist($req);
        $this->em->flush();
        $this->createdIds['requests'][] = $req->getId();

        $type = $this->makeType();
        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $response = $this->request($client, 'POST', "/api/intervention-type-requests/{$req->getId()}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('INTERVENTION_TYPE_REQUEST_WITHOUT_DRAFT', json_decode($response->getContent(), true)['error']['code']);
    }

    public function test_second_resolution_attempt_returns_409_and_creates_no_second_intervention(): void
    {
        [$client, $mission, $instrToken] = $this->bootMissionScenario();
        $requestId = $this->createPendingRequest($client, $mission, $instrToken);
        $type = $this->makeType();

        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);

        $first = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
        ]);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), $first->getContent());

        $second = $this->request($client, 'POST', "/api/intervention-type-requests/{$requestId}/resolve", $managerToken, [
            'interventionTypeId' => $type->getId(),
        ]);
        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
        self::assertSame('DRAFT_ALREADY_RESOLVED', json_decode($second->getContent(), true)['error']['code']);

        $count = $this->em->getRepository(MissionIntervention::class)->count(['mission' => $mission->getId()]);
        self::assertSame(1, $count, 'exactly one MissionIntervention must exist after a refused second resolution');
    }
}
