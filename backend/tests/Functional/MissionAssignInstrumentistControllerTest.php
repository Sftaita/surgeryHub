<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional HTTP tests for RC1-C — Cluster C fix.
 *
 * POST /api/missions/{id}/assign-instrumentist is the pre-deploy (DRAFT-only) assignment
 * path. It must go through MissionVoter::ASSIGN_INSTRUMENTIST (MANAGER or ADMIN — not a raw
 * ROLE_MANAGER check) and MissionService::assignInstrumentistDraft() (not a direct
 * controller mutation). Deployed missions must use /release or /reassign instead.
 */
final class MissionAssignInstrumentistControllerTest extends WebTestCase
{
    private const PASSWORD = 'AssignDraft15C!';

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
        $client   = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $u = new User();
        $u->setEmail('rc1c-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('RC1C-' . bin2hex(random_bytes(3)));
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

    public function test_manager_can_assign_instrumentist_to_draft_mission(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::DRAFT);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/assign-instrumentist', [
            'instrumentistId' => $instr->getId(),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame($instr->getId(), $body['instrumentist']['id'] ?? ($body['instrumentistId'] ?? null), json_encode($body));
    }

    public function test_admin_can_assign_instrumentist_to_draft_mission(): void
    {
        $client  = $this->boot();
        $admin   = $this->createUser('ROLE_ADMIN');
        $token   = $this->login($client, $admin);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $admin, MissionStatus::DRAFT);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/assign-instrumentist', [
            'instrumentistId' => $instr->getId(),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
    }

    public function test_instrumentist_cannot_assign_instrumentist(): void
    {
        $client     = $this->boot();
        $manager    = $this->createUser('ROLE_MANAGER');
        $instr      = $this->createUser('ROLE_INSTRUMENTIST');
        $instrToken = $this->login($client, $instr);
        $surgeon    = $this->createUser('ROLE_SURGEON');
        $site       = $this->makeSite();
        $mission    = $this->makeMission($site, $surgeon, $manager, MissionStatus::DRAFT);

        $response = $this->postJson($client, $instrToken, '/api/missions/' . $mission->getId() . '/assign-instrumentist', [
            'instrumentistId' => $instr->getId(),
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_surgeon_cannot_assign_instrumentist(): void
    {
        $client      = $this->boot();
        $manager     = $this->createUser('ROLE_MANAGER');
        $surgeon     = $this->createUser('ROLE_SURGEON');
        $surgeonToken = $this->login($client, $surgeon);
        $instr       = $this->createUser('ROLE_INSTRUMENTIST');
        $site        = $this->makeSite();
        $mission     = $this->makeMission($site, $surgeon, $manager, MissionStatus::DRAFT);

        $response = $this->postJson($client, $surgeonToken, '/api/missions/' . $mission->getId() . '/assign-instrumentist', [
            'instrumentistId' => $instr->getId(),
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_assign_on_open_mission_returns_409_mission_not_draft(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/assign-instrumentist', [
            'instrumentistId' => $instr->getId(),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('MISSION_NOT_DRAFT', $body['error']['code'] ?? null, json_encode($body));
    }

    public function test_assign_on_assigned_mission_returns_409_mission_not_draft(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr1  = $this->createUser('ROLE_INSTRUMENTIST');
        $instr2  = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr1);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/assign-instrumentist', [
            'instrumentistId' => $instr2->getId(),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('MISSION_NOT_DRAFT', $body['error']['code'] ?? null, json_encode($body));
    }

    public function test_assign_can_clear_instrumentist_on_draft_mission(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::DRAFT, $instr);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/assign-instrumentist', [
            'instrumentistId' => null,
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertNull($reloaded->getInstrumentist());
    }

    public function test_assign_with_unknown_instrumentist_returns_404(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::DRAFT);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/assign-instrumentist', [
            'instrumentistId' => 999999999,
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // ── D-056 compliance ──────────────────────────────────────────────────────

    public function test_assign_instrumentist_action_has_no_direct_em_persist_or_flush_in_controller(): void
    {
        $path   = dirname(__DIR__, 2) . '/src/Controller/Api/MissionController.php';
        $source = file_get_contents($path);

        // Isolate the assignInstrumentist() method body.
        $start = strpos($source, 'function assignInstrumentist(');
        self::assertNotFalse($start, 'assignInstrumentist() method not found');
        $end = strpos($source, "\n    }\n", $start);
        $body = substr($source, $start, $end - $start);

        self::assertStringNotContainsString('setInstrumentist', $body, 'D-056 violation: controller must not mutate Mission directly — use MissionService::assignInstrumentistDraft()');
        self::assertStringNotContainsString('->flush(', $body, 'D-056 violation: controller must not flush Mission mutations directly');
    }
}
