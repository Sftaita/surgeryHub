<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Exécution & Valorisation, Lot 1 — endpoints GET/PATCH /api/missions/{id}/execution.
 */
final class MissionExecutionControllerTest extends WebTestCase
{
    private const PASSWORD = 'Execution1!';

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
                foreach ($this->em->getRepository(MissionExecution::class)->findBy(['mission' => $missionId]) as $e) {
                    $this->em->remove($e);
                }
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
        $u->setEmail('exec1-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Exec1-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeMission(Hospital $site, User $surgeon, User $createdBy, ?User $instrumentist = null): Mission
    {
        $m = new Mission();
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt(new \DateTimeImmutable('2026-09-01 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-09-01 10:00:00'));
        $m->setStatus(MissionStatus::ASSIGNED);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function getJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    private function patchJson(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('PATCH', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    // ── GET — sans MissionExecution (§3.1 repli sur le planifié) ────────────────

    public function test_get_execution_without_record_falls_back_to_planned(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->getJson($client, $token, "/api/missions/{$mission->getId()}/execution");

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertFalse($data['hasExecutionRecord']);
        self::assertSame(120, $data['effectiveDurationMinutes']);
        self::assertSame('PLANNED', $data['effectiveDurationSource']);
        self::assertSame([], $data['disputes']);
    }

    public function test_unrelated_user_cannot_view(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $stranger = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $mission  = $this->makeMission($site, $surgeon, $manager, $instr);
        $token    = $this->login($client, $stranger);

        $response = $this->getJson($client, $token, "/api/missions/{$mission->getId()}/execution");

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── PATCH — durée explicite ──────────────────────────────────────────────

    public function test_instrumentist_can_declare_explicit_duration(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/execution", [
            'actualDurationMinutes' => 135,
            'hoursSource' => 'INSTRUMENTIST',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertTrue($data['hasExecutionRecord']);
        self::assertSame(135, $data['actualDurationMinutes']);
        self::assertSame(135, $data['effectiveDurationMinutes']);
        self::assertSame('ACTUAL_EXPLICIT', $data['effectiveDurationSource']);
    }

    public function test_actual_times_derive_duration(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/execution", [
            'actualStartAt' => '2026-09-01T08:05:00+02:00',
            'actualEndAt' => '2026-09-01T10:20:00+02:00',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(135, $data['actualDurationMinutes']);
        self::assertSame('ACTUAL_TIMES', $data['effectiveDurationSource']);
    }

    public function test_contradictory_duration_returns_422(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/execution", [
            'actualStartAt' => '2026-09-01T08:00:00+02:00',
            'actualEndAt' => '2026-09-01T09:00:00+02:00',
            'actualDurationMinutes' => 45,
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_single_actual_time_without_the_other_returns_422(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/execution", [
            'actualStartAt' => '2026-09-01T08:00:00+02:00',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function test_surgeon_cannot_update_execution(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $surgeon);

        $response = $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/execution", [
            'actualDurationMinutes' => 60,
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_update_produces_audit_trail(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $instr);

        $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/execution", ['actualDurationMinutes' => 90]);

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()], ['id' => 'ASC']);
        $types = array_map(static fn (AuditEvent $e) => $e->getEventType()->value, $events);

        self::assertSame(['MISSION_EXECUTION_CREATED', 'MISSION_EXECUTION_DURATION_CHANGED'], $types);
    }
}
