<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Real-HTTP-pipeline tests for the Batch 4 manager/admin alert API: routing, the
 * PlanningVoter security gate, the global ApiExceptionSubscriber's normalized error
 * shape, and that no endpoint here ever mutates a Mission.
 *
 * Authenticates via a real POST /api/auth/login (not loginUser()) because the "api"
 * firewall is stateless: the JWT is re-validated from the Authorization header on every
 * request, so a real token survives across multiple requests in the same test the way
 * loginUser()'s injected token does not for a stateless firewall.
 */
final class PlanningAlertControllerTest extends WebTestCase
{
    private const PASSWORD = 'Batch4Test123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['alerts' => [], 'missions' => [], 'sites' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['alerts'] as $id) {
                $e = $this->em->find(PlanningAlert::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $auditEvent) {
                    $this->em->remove($auditEvent);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
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

    /** @return array{user: User, token: string} */
    private function authenticate(KernelBrowser $client, string $role): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('batch4-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]),
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];

        self::assertArrayHasKey('token', $data, 'Login must succeed and return a JWT: ' . $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('batch4-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Batch4 Test Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable('2026-02-02 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-02-02 13:00:00'));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function makeAlert(Mission $mission, PlanningAlertType $type, PlanningAlertStatus $status = PlanningAlertStatus::OPEN): PlanningAlert
    {
        $alert = new PlanningAlert();
        $alert->setMission($mission);
        $alert->setType($type);
        $alert->setSnapshotJson(['test' => true]);
        if ($status !== PlanningAlertStatus::OPEN) {
            $rp = new \ReflectionProperty(PlanningAlert::class, 'status');
            $rp->setValue($alert, $status);
        }
        $this->em->persist($alert);
        $this->em->flush();
        $this->createdIds['alerts'][] = $alert->getId();
        return $alert;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    // ── Security ──────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_unauthorized_role_is_rejected_with_normalized_403(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('GET', '/api/planning/alerts', server: $this->auth($token));
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $body = $this->json($response);
        self::assertArrayHasKey('error', $body);
        self::assertSame(403, $body['error']['status']);
        self::assertSame('FORBIDDEN', $body['error']['code']);
    }

    // ── List + filters ────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_list_filters_by_status_and_type(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $missionA = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $missionB = $this->makeMission($surgeon, null, $site, MissionStatus::ASSIGNED);

        $alertOpenSurgeon  = $this->makeAlert($missionA, PlanningAlertType::SURGEON_ABSENCE, PlanningAlertStatus::OPEN);
        $alertResolvedInst = $this->makeAlert($missionB, PlanningAlertType::INSTRUMENTIST_ABSENCE, PlanningAlertStatus::RESOLVED);

        $client->request('GET', '/api/planning/alerts?status=OPEN&type=SURGEON_ABSENCE', server: $this->auth($token));
        $body = $this->json($client->getResponse());

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $ids = array_map(fn ($i) => $i['id'], $body['items']);
        self::assertContains($alertOpenSurgeon->getId(), $ids);
        self::assertNotContains($alertResolvedInst->getId(), $ids);
    }

    #[WithoutErrorHandler]
    public function test_list_filters_by_site_id(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $siteA   = $this->makeSite();
        $siteB   = $this->makeSite();
        $missionOnA = $this->makeMission($surgeon, null, $siteA, MissionStatus::DRAFT);
        $missionOnB = $this->makeMission($surgeon, null, $siteB, MissionStatus::DRAFT);

        $alertA = $this->makeAlert($missionOnA, PlanningAlertType::SURGEON_ABSENCE);
        $alertB = $this->makeAlert($missionOnB, PlanningAlertType::SURGEON_ABSENCE);

        $client->request('GET', '/api/planning/alerts?siteId=' . $siteA->getId(), server: $this->auth($token));
        $body = $this->json($client->getResponse());

        $ids = array_map(fn ($i) => $i['id'], $body['items']);
        self::assertContains($alertA->getId(), $ids);
        self::assertNotContains($alertB->getId(), $ids);
    }

    // ── Detail ────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_show_returns_404_normalized_for_missing_alert(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('GET', '/api/planning/alerts/999999999', server: $this->auth($token));
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('NOT_FOUND', $body['error']['code']);
    }

    #[WithoutErrorHandler]
    public function test_show_returns_alert_with_action_flags(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::ASSIGNED);
        $alert   = $this->makeAlert($mission, PlanningAlertType::REASSIGNMENT_REQUIRED);

        $client->request('GET', '/api/planning/alerts/' . $alert->getId(), server: $this->auth($token));
        $body = $this->json($client->getResponse());

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame($alert->getId(), $body['id']);
        self::assertSame('REASSIGNMENT_REQUIRED', $body['type']);
        self::assertTrue($body['actions']['canReassign']);
        self::assertSame('REASSIGN', $body['actions']['recommendedAction']);
    }

    // ── Transitions ───────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_acknowledge_is_idempotent_and_never_mutates_mission(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $alert   = $this->makeAlert($mission, PlanningAlertType::SURGEON_ABSENCE);

        $client->request('POST', '/api/planning/alerts/' . $alert->getId() . '/acknowledge', server: $this->auth($token));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $first = $this->json($client->getResponse());
        self::assertSame('ACKNOWLEDGED', $first['status']);

        $client->request('POST', '/api/planning/alerts/' . $alert->getId() . '/acknowledge', server: $this->auth($token));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), 'Repeating the same transition must not error');
        $second = $this->json($client->getResponse());
        self::assertSame('ACKNOWLEDGED', $second['status']);

        $this->em->clear();
        $missionAfter = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::DRAFT, $missionAfter->getStatus(), 'Acknowledging an alert must never change the Mission');
    }

    #[WithoutErrorHandler]
    public function test_resolve_then_acknowledge_returns_normalized_409(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);
        $alert   = $this->makeAlert($mission, PlanningAlertType::SURGEON_ABSENCE);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/resolve',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['note' => 'Mission annulée.']),
        );
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/planning/alerts/' . $alert->getId() . '/acknowledge', server: $this->auth($token));
        $response = $client->getResponse();

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('CONFLICT', $body['error']['code']);

        $this->em->clear();
        $missionAfter = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::DRAFT, $missionAfter->getStatus(), 'Even a rejected transition attempt must never touch the Mission');
    }

    #[WithoutErrorHandler]
    public function test_ignore_records_note_and_never_mutates_mission(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon       = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $site          = $this->makeSite();
        $mission       = $this->makeMission($surgeon, $instrumentist, $site, MissionStatus::ASSIGNED);
        $alert         = $this->makeAlert($mission, PlanningAlertType::INSTRUMENTIST_ABSENCE);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/ignore',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['note' => 'Faux positif.']),
        );
        $body = $this->json($client->getResponse());

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame('IGNORED', $body['status']);
        self::assertSame('Faux positif.', $body['resolutionNote']);

        $this->em->clear();
        $missionAfter = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $missionAfter->getStatus());
        self::assertSame($instrumentist->getId(), $missionAfter->getInstrumentist()->getId(), 'Ignoring an alert must never reassign or clear the instrumentist');
    }
}
