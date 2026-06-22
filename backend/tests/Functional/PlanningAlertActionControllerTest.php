<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\SiteMembership;
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
 * Real-HTTP tests for the Batch 5 reassign / open-as-available / eligible-instrumentists
 * endpoints — the actual Mission mutations behind Batch 4's advisory action flags.
 */
final class PlanningAlertActionControllerTest extends WebTestCase
{
    private const PASSWORD = 'Batch5Test123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['alerts' => [], 'missions' => [], 'sites' => [], 'users' => [], 'memberships' => []];

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
            foreach ($this->createdIds['memberships'] as $id) {
                $e = $this->em->find(SiteMembership::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['users'] as $id) {
                foreach ($this->em->getRepository(NotificationEvent::class)->findBy(['user' => $id]) as $notification) {
                    $this->em->remove($notification);
                }
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
        $user->setEmail('batch5-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeUser(string $role, bool $active = true): User
    {
        $u = new User();
        $u->setEmail('batch5-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive($active);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Batch5 Test Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function affiliate(User $user, Hospital $site): void
    {
        $m = new SiteMembership();
        $m->setUser($user);
        $m->setSite($site);
        $m->setSiteRole('INSTRUMENTIST');
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['memberships'][] = $m->getId();
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable('2026-03-03 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-03-03 13:00:00'));
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

    // ── Reassign ──────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_successful_reassign_updates_mission_and_resolves_alert(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission, PlanningAlertType::REASSIGNMENT_REQUIRED);

        $newInst = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->affiliate($newInst, $site);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/reassign',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['instrumentistId' => $newInst->getId(), 'note' => 'Remplacement trouvé.']),
        );
        $body = $this->json($client->getResponse());

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame('RESOLVED', $body['status']);
        self::assertSame('Remplacement trouvé.', $body['resolutionNote']);

        $this->em->clear();
        $missionAfter = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $missionAfter->getStatus());
        self::assertSame($newInst->getId(), $missionAfter->getInstrumentist()->getId());
    }

    #[WithoutErrorHandler]
    public function test_reassign_rejects_inactive_instrumentist_with_normalized_422(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission, PlanningAlertType::REASSIGNMENT_REQUIRED);

        $inactiveInst = $this->makeUser('ROLE_INSTRUMENTIST', false);
        $this->affiliate($inactiveInst, $site);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/reassign',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['instrumentistId' => $inactiveInst->getId()]),
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $body = $this->json($client->getResponse());
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);

        $this->em->clear();
        $missionAfter = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $missionAfter->getStatus());
        self::assertNull($missionAfter->getInstrumentist());
    }

    #[WithoutErrorHandler]
    public function test_reassign_rejects_locked_mission_with_normalized_409(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::VALIDATED);
        $alert   = $this->makeAlert($mission, PlanningAlertType::REASSIGNMENT_REQUIRED);

        $newInst = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->affiliate($newInst, $site);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/reassign',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['instrumentistId' => $newInst->getId()]),
        );

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        self::assertSame('CONFLICT', $this->json($client->getResponse())['error']['code']);
    }

    #[WithoutErrorHandler]
    public function test_reassign_rejects_on_already_resolved_alert(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission, PlanningAlertType::REASSIGNMENT_REQUIRED, PlanningAlertStatus::RESOLVED);

        $newInst = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->affiliate($newInst, $site);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/reassign',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['instrumentistId' => $newInst->getId()]),
        );

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
    }

    // ── Open as available ─────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_open_as_available_clears_instrumentist_and_resolves_alert(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $inst    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $inst, $site, MissionStatus::ASSIGNED);
        $alert   = $this->makeAlert($mission, PlanningAlertType::INSTRUMENTIST_ABSENCE);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/open-as-available',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['note' => 'Ouvert au pool.']),
        );
        $body = $this->json($client->getResponse());

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame('RESOLVED', $body['status']);

        $this->em->clear();
        $missionAfter = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $missionAfter->getStatus());
        self::assertNull($missionAfter->getInstrumentist());
    }

    #[WithoutErrorHandler]
    public function test_open_as_available_rejects_locked_mission(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $inst    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $inst, $site, MissionStatus::SUBMITTED);
        $alert   = $this->makeAlert($mission, PlanningAlertType::INSTRUMENTIST_ABSENCE);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/open-as-available',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode([]),
        );

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $missionAfter = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::SUBMITTED, $missionAfter->getStatus());
        self::assertNotNull($missionAfter->getInstrumentist());
    }

    #[WithoutErrorHandler]
    public function test_open_as_available_rejects_on_ignored_alert(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $inst    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $inst, $site, MissionStatus::ASSIGNED);
        $alert   = $this->makeAlert($mission, PlanningAlertType::INSTRUMENTIST_ABSENCE, PlanningAlertStatus::IGNORED);

        $client->request('POST', '/api/planning/alerts/' . $alert->getId() . '/open-as-available', server: $this->auth($token));

        self::assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
    }

    // ── Eligible instrumentists ───────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_eligible_instrumentists_excludes_non_affiliated(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $otherSite = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission, PlanningAlertType::REASSIGNMENT_REQUIRED);

        $eligible = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->affiliate($eligible, $site);
        $notAffiliated = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->affiliate($notAffiliated, $otherSite);

        $client->request('GET', '/api/planning/alerts/' . $alert->getId() . '/eligible-instrumentists', server: $this->auth($token));
        $body = $this->json($client->getResponse());

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $ids = array_map(fn ($i) => $i['id'], $body['items']);
        self::assertContains($eligible->getId(), $ids);
        self::assertNotContains($notAffiliated->getId(), $ids);

        $eligibleEntry = current(array_filter($body['items'], fn ($i) => $i['id'] === $eligible->getId()));
        self::assertContains($site->getName(), $eligibleEntry['sites']);
    }

    // ── Security ──────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_reassign_rejects_unauthorized_role(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::OPEN);
        $alert   = $this->makeAlert($mission, PlanningAlertType::REASSIGNMENT_REQUIRED);

        $client->request(
            'POST',
            '/api/planning/alerts/' . $alert->getId() . '/reassign',
            server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode(['instrumentistId' => 1]),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }
}
