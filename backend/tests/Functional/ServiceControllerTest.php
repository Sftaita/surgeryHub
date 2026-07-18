<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\MissionExecutionDispute;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * EPIC Exécution & Valorisation, Lot 1 — endpoints LEGACY (§7 du lot) : mêmes URLs,
 * même forme de payload qu'avant, désormais délégués à MissionExecution/
 * MissionExecutionService. Couvre aussi la correction de sécurité (§9) : un appel non
 * autorisé ne doit plus créer de ligne avant d'être refusé.
 */
final class ServiceControllerTest extends WebTestCase
{
    private const PASSWORD = 'ServiceLegacy1!';

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
        $u->setEmail('svclegacy-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('SvcLegacy-' . bin2hex(random_bytes(3)));
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

    private function patchJson(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('PATCH', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    private function postJson(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('POST', $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode($body),
        );
        return $client->getResponse();
    }

    private function getJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    // ── PATCH /missions/{id}/service (legacy, hours décimal) ─────────────────

    public function test_legacy_patch_service_converts_hours_to_duration_minutes(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $instr);

        $response = $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/service", [
            'hours' => 1.75,
            'hoursSource' => 'INSTRUMENTIST',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $execution = $this->em->getRepository(MissionExecution::class)->findOneBy(['mission' => $mission->getId()]);
        self::assertNotNull($execution);
        self::assertSame(105, $execution->getActualDurationMinutes());
    }

    public function test_legacy_patch_service_ignores_dead_financial_fields_without_error(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);
        $token   = $this->login($client, $manager);

        $response = $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/service", [
            'hours' => 2.0,
            'consultationFeeApplied' => 150,
            'status' => 'APPROVED',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function test_legacy_patch_service_denied_does_not_create_a_row(): void
    {
        // §9 — correction : la permission est vérifiée avant toute création, plus après.
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $stranger = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $mission  = $this->makeMission($site, $surgeon, $manager, $instr);
        $token    = $this->login($client, $stranger);

        $response = $this->patchJson($client, $token, "/api/missions/{$mission->getId()}/service", ['hours' => 1.0]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $execution = $this->em->getRepository(MissionExecution::class)->findOneBy(['mission' => $mission->getId()]);
        self::assertNull($execution, 'A denied PATCH must never create a MissionExecution row.');
    }

    // ── Disputes legacy (POST /services/{id}/disputes, GET/PATCH /disputes) ──

    public function test_legacy_dispute_lifecycle(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, $instr);

        $instrToken = $this->login($client, $instr);
        $this->patchJson($client, $instrToken, "/api/missions/{$mission->getId()}/service", ['hours' => 1.5]);
        $execution = $this->em->getRepository(MissionExecution::class)->findOneBy(['mission' => $mission->getId()]);
        self::assertNotNull($execution);

        $surgeonToken = $this->login($client, $surgeon);
        $createResponse = $this->postJson($client, $surgeonToken, "/api/services/{$execution->getId()}/disputes", [
            'reasonCode' => 'DURATION_INCOHERENT',
            'comment' => 'Trop long par rapport au bloc réel',
        ]);
        self::assertSame(Response::HTTP_CREATED, $createResponse->getStatusCode());
        $dispute = json_decode((string) $createResponse->getContent(), true);

        // Une deuxième contestation OPEN pendant que la première est encore ouverte est refusée.
        $duplicateResponse = $this->postJson($client, $surgeonToken, "/api/services/{$execution->getId()}/disputes", [
            'reasonCode' => 'OTHER',
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $duplicateResponse->getStatusCode());

        $managerToken = $this->login($client, $manager);
        $listResponse = $this->getJson($client, $managerToken, '/api/disputes?status=OPEN');
        self::assertSame(Response::HTTP_OK, $listResponse->getStatusCode());
        $list = json_decode((string) $listResponse->getContent(), true);
        self::assertGreaterThanOrEqual(1, $list['total']);

        $resolveResponse = $this->patchJson($client, $managerToken, "/api/disputes/{$dispute['id']}", [
            'status' => 'RESOLVED',
            'resolutionComment' => 'Heures corrigées',
        ]);
        self::assertSame(Response::HTTP_OK, $resolveResponse->getStatusCode());
        $resolved = json_decode((string) $resolveResponse->getContent(), true);
        self::assertSame('RESOLVED', $resolved['status']);

        $disputeEntity = $this->em->getRepository(MissionExecutionDispute::class)->find($dispute['id']);
        if ($disputeEntity !== null) {
            $this->em->remove($disputeEntity);
            $this->em->flush();
        }
    }

    public function test_instrumentist_cannot_manage_disputes(): void
    {
        $client  = $this->boot();
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $token   = $this->login($client, $instr);

        $response = $this->getJson($client, $token, '/api/disputes');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
