<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional HTTP tests for Batch 15B — post-deploy mission lifecycle.
 * Covers: release, cancel, reassign, and the migrated claim endpoint.
 */
final class MissionLifecycleControllerTest extends WebTestCase
{
    private const PASSWORD = 'Lifecycle15B!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdMissionIds as $missionId) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $missionId]) as $evt) {
                    $this->em->remove($evt);
                }
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Returns a KernelBrowser and initialises $this->em. */
    private function boot(): KernelBrowser
    {
        $client   = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('b15b-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
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
        self::assertArrayHasKey('token', $data, 'Login failed: ' . $client->getResponse()->getContent());
        return $data['token'];
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('B15B-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeMission(Hospital $site, User $surgeon, User $createdBy, MissionStatus $status, ?User $instrumentist = null): Mission
    {
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-09-01 12:00:00'));
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function postJson(KernelBrowser $client, string $token, string $uri, array $body = []): Response
    {
        $client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    // ── release ───────────────────────────────────────────────────────────────

    public function test_release_on_assigned_mission_returns_200_with_status_open(): void
    {
        $client      = $this->boot();
        $manager     = $this->createUser('ROLE_MANAGER');
        $token       = $this->login($client, $manager);
        $surgeon     = $this->createUser('ROLE_SURGEON');
        $instr       = $this->createUser('ROLE_INSTRUMENTIST');
        $site        = $this->makeSite();
        $mission     = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/release');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('OPEN', $body['status'] ?? null, json_encode($body));
    }

    public function test_release_creates_audit_event_in_db(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/release');

        $this->em->clear();
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertNotEmpty($events, 'AuditEvent must be created on release');
    }

    public function test_release_on_open_mission_returns_409(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/release');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function test_release_returns_403_for_instrumentist(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $instrToken = $this->login($client, $instr);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $response = $this->postJson($client, $instrToken, '/api/missions/' . $mission->getId() . '/release');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_on_open_mission_returns_200_with_status_cancelled(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/cancel', ['reason' => 'Chirurgien absent']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('CANCELLED', $body['status'] ?? null, json_encode($body));
    }

    public function test_cancel_creates_audit_event_in_db(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/cancel');

        $this->em->clear();
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertNotEmpty($events, 'AuditEvent must be created on cancel');
    }

    /**
     * REGRESSION (feature added after this test was first written): MissionPostDeployService
     * ::cancel() originally only accepted OPEN, so cancelling an ASSIGNED mission via this
     * endpoint returned 409. Extended to also accept ASSIGNED (AbsenceMissionReactionService
     * needs it for surgeon-absence cancellation) — the guard is shared, single-source-of-truth
     * logic, so the general manager-facing endpoint gains the same capability as a deliberate
     * side effect: cancelling an ASSIGNED mission is not itself dangerous, it now also clears
     * the instrumentist as part of the transition.
     */
    public function test_cancel_on_assigned_mission_succeeds_and_clears_instrumentist(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/cancel');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $reloaded->getStatus());
        self::assertNull($reloaded->getInstrumentist());
    }

    public function test_get_mission_after_cancel_shows_cancelled_status(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/cancel');

        $client->request('GET', '/api/missions/' . $mission->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('CANCELLED', $body['status'] ?? null);
    }

    // ── reassign ──────────────────────────────────────────────────────────────

    public function test_reassign_on_assigned_mission_returns_200(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr1   = $this->createUser('ROLE_INSTRUMENTIST');
        $instr2   = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $mission  = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr1);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/reassign', [
            'instrumentistId' => $instr2->getId(),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('ASSIGNED', $body['status'] ?? null, json_encode($body));
    }

    public function test_reassign_creates_audit_event_in_db(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr1  = $this->createUser('ROLE_INSTRUMENTIST');
        $instr2  = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr1);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/reassign', [
            'instrumentistId' => $instr2->getId(),
        ]);

        $this->em->clear();
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertNotEmpty($events, 'AuditEvent must be created on reassign');
    }

    public function test_reassign_on_open_mission_returns_409(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/reassign', [
            'instrumentistId' => $instr->getId(),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    // ── D-056 compliance ──────────────────────────────────────────────────────

    public function test_mission_controller_has_no_direct_em_persist_for_mission(): void
    {
        $path   = dirname(__DIR__, 2) . '/src/Controller/Api/MissionController.php';
        $source = file_get_contents($path);

        self::assertDoesNotMatchRegularExpression(
            '/em->persist\s*\(\s*\$mission\b/',
            $source,
            'D-056 violation: MissionController must not call em->persist($mission) — all mutations go through MissionPostDeployService',
        );
    }
}
