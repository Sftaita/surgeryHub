<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Batch 11 Fix 2 — real-HTTP coverage that AbsenceController actually wires
 * AbsenceImpactService into create/update/delete, since the service itself was already
 * fully unit-tested (AbsenceImpactServiceTest) but never invoked from the controller.
 */
final class AbsenceControllerTest extends WebTestCase
{
    private const PASSWORD = 'Batch11Test123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'missions' => [], 'sites' => [], 'users' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['absences'] as $id) {
                $e = $this->em->find(Absence::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            // PlanningAlert.mission has no ON DELETE SET NULL — alerts created by the
            // impact engine during these tests must be removed before their mission.
            foreach ($this->createdIds['missions'] as $id) {
                $mission = $this->em->find(Mission::class, $id);
                if ($mission === null) { continue; }
                $alerts = $this->em->createQueryBuilder()
                    ->select('a')->from(PlanningAlert::class, 'a')
                    ->where('a.mission = :m')->setParameter('m', $mission)
                    ->getQuery()->getResult();
                foreach ($alerts as $alert) { $this->em->remove($alert); }
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
        $user->setEmail('batch11-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $user->setRoles([$role]);
        $user->setActive(true);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
        $this->createdIds['users'][] = $user->getId();

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $client->getResponse()->getContent());

        return ['user' => $user, 'token' => $data['token']];
    }

    private function auth(string $token, array $extra = []): array
    {
        return array_merge(['HTTP_AUTHORIZATION' => 'Bearer ' . $token], $extra);
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('batch11-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Batch11 Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
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
        $m->setStartAt(new \DateTimeImmutable('2026-02-09 08:00:00'));
        $m->setEndAt(new \DateTimeImmutable('2026-02-09 13:00:00'));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /** @return PlanningAlert[] */
    private function findAlertsForMission(Mission $mission): array
    {
        return $this->em->createQueryBuilder()
            ->select('a')->from(PlanningAlert::class, 'a')
            ->where('a.mission = :m')->setParameter('m', $mission)
            ->getQuery()->getResult();
    }

    // ── Create: surgeon absence ──────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_creating_surgeon_absence_over_assigned_mission_creates_surgeon_absence_alert(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $absence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $absence['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts);
        self::assertSame('SURGEON_ABSENCE', $alerts[0]->getType()->value);
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus(), 'Mission must never be mutated by absence impact detection');
    }

    // ── Create: instrumentist absence on ASSIGNED mission ───────────────────

    #[WithoutErrorHandler]
    public function test_creating_instrumentist_absence_over_assigned_mission_creates_reassignment_required_alert(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $absence['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts);
        self::assertSame('REASSIGNMENT_REQUIRED', $alerts[0]->getType()->value);
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
    }

    // ── SUBMITTED/VALIDATED missions: alert only, no mutation ───────────────

    #[WithoutErrorHandler]
    public function test_absence_over_submitted_and_validated_missions_only_alerts_never_mutates(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        $submitted = $this->makeMission($surgeon, null, $site, MissionStatus::SUBMITTED);
        $validated = $this->makeMission($surgeon, null, $site, MissionStatus::VALIDATED);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $absence['id'];

        $this->em->clear();
        $submittedReloaded = $this->em->find(Mission::class, $submitted->getId());
        $validatedReloaded = $this->em->find(Mission::class, $validated->getId());

        self::assertCount(1, $this->findAlertsForMission($submittedReloaded));
        self::assertCount(1, $this->findAlertsForMission($validatedReloaded));
        self::assertSame(MissionStatus::SUBMITTED, $submittedReloaded->getStatus());
        self::assertSame(MissionStatus::VALIDATED, $validatedReloaded->getStatus());
    }

    // ── Update: resync moves/resolves/creates alerts ─────────────────────────

    #[WithoutErrorHandler]
    public function test_updating_absence_resolves_alert_when_no_longer_overlapping(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        $absence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $absence['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertCount(1, $this->findAlertsForMission($mission), 'Alert must exist before the move');

        // Move the absence to a date that no longer overlaps the mission.
        $client->request('PATCH', "/api/absences/{$absence['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-03-01', 'dateEnd' => '2026-03-01',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts, 'Alert row must still exist (resolved, not deleted)');
        self::assertSame('RESOLVED', $alerts[0]->getStatus()->value);
    }

    #[WithoutErrorHandler]
    public function test_updating_absence_creates_alert_for_newly_overlapping_mission(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);

        // Absence starts on a date that does NOT overlap the mission (2026-02-09).
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-03-01', 'dateEnd' => '2026-03-01',
        ]));
        $absence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $absence['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertCount(0, $this->findAlertsForMission($mission), 'No alert before the move — dates do not overlap yet');

        // Widen the absence to now cover the mission's date.
        $client->request('PATCH', "/api/absences/{$absence['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts);
        self::assertSame('OPEN', $alerts[0]->getStatus()->value);
    }

    // ── Delete: resolves linked active alerts, preserves history ────────────

    #[WithoutErrorHandler]
    public function test_deleting_absence_resolves_linked_active_alerts(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        $absence = $this->json($client->getResponse());
        $absenceId = $absence['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertCount(1, $this->findAlertsForMission($mission));

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts, 'Alert row must survive the absence deletion (never deleted, only resolved)');
        self::assertSame('RESOLVED', $alerts[0]->getStatus()->value);
        self::assertNull($alerts[0]->getAbsence(), 'absence FK must be SET NULL after the absence row is gone');

        // Clean up the now-resolved alert directly since the absence is already deleted.
        $this->em->remove($alerts[0]);
        $this->em->flush();
    }

    // ── Idempotence: repeated sync does not duplicate alerts ────────────────

    #[WithoutErrorHandler]
    public function test_repeated_update_with_unchanged_dates_does_not_duplicate_alerts(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, null, $site, MissionStatus::DRAFT);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09', 'reason' => 'v1',
        ]));
        $absence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $absence['id'];

        // PATCH with the same date range twice (only the reason changes) — must not duplicate.
        $client->request('PATCH', "/api/absences/{$absence['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09', 'reason' => 'v2',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('PATCH', "/api/absences/{$absence['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09', 'reason' => 'v3',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts, 'Re-syncing an absence whose range did not change must not create a duplicate alert');
    }

    // ── Unauthorized role rejected ───────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_absence_endpoints_reject_unauthorized_role(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => 1, 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
