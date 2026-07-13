<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
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
            // AuditEvent.mission/actor and NotificationEvent.user/mission have no ON DELETE
            // SET NULL either — AbsenceMissionReactionService's release()/cancel() calls
            // (via MissionPostDeployService) and its own manager notification-on-delete
            // create rows referencing the test's missions/users, which must go before them.
            foreach ($this->createdIds['missions'] as $id) {
                $mission = $this->em->find(Mission::class, $id);
                if ($mission === null) { continue; }
                $events = $this->em->createQueryBuilder()
                    ->select('e')->from(AuditEvent::class, 'e')
                    ->where('e.mission = :m')->setParameter('m', $mission)
                    ->getQuery()->getResult();
                foreach ($events as $event) { $this->em->remove($event); }
            }
            foreach ($this->createdIds['users'] as $id) {
                $user = $this->em->find(User::class, $id);
                if ($user === null) { continue; }
                $notifications = $this->em->createQueryBuilder()
                    ->select('n')->from(NotificationEvent::class, 'n')
                    ->where('n.user = :u')->setParameter('u', $user)
                    ->getQuery()->getResult();
                foreach ($notifications as $notification) { $this->em->remove($notification); }
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

    private function makeMissionOnDay(User $surgeon, Hospital $site, string $day): Mission
    {
        $m = new Mission();
        $m->setStatus(MissionStatus::DRAFT);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable("{$day} 08:00:00"));
        $m->setEndAt(new \DateTimeImmutable("{$day} 13:00:00"));
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

    // ── Serialization: role field for manager-facing list display ──────────

    #[WithoutErrorHandler]
    public function test_create_response_includes_user_role_for_surgeon_and_instrumentist(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-01', 'dateEnd' => '2026-08-01',
        ]));
        $surgeonAbsence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $surgeonAbsence['id'];
        self::assertSame('SURGEON', $surgeonAbsence['user']['role']);
        self::assertArrayHasKey('firstname', $surgeonAbsence['user']);
        self::assertArrayHasKey('lastname', $surgeonAbsence['user']);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-02', 'dateEnd' => '2026-08-02',
        ]));
        $instrAbsence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $instrAbsence['id'];
        self::assertSame('INSTRUMENTIST', $instrAbsence['user']['role']);
    }

    // ── Create: surgeon absence ──────────────────────────────────────────────

    /**
     * REGRESSION (feature added after this test was first written): a surgeon absence over
     * an ASSIGNED mission used to only raise a SURGEON_ABSENCE alert and leave the mission
     * untouched. AbsenceMissionReactionService now auto-cancels it — see that service's class
     * docblock. Because the mission transitions to CANCELLED (not one of
     * AbsenceImpactService::ALERTABLE_STATUSES), no SURGEON_ABSENCE alert is raised either —
     * the two services compose correctly given AbsenceController's call order, with no
     * changes needed to AbsenceImpactService itself.
     */
    #[WithoutErrorHandler]
    public function test_creating_surgeon_absence_over_assigned_mission_cancels_it_and_raises_no_stale_alert(): void
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
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus(), 'Surgeon absence must auto-cancel an ASSIGNED mission');
        self::assertNull($mission->getInstrumentist(), 'A cancelled mission must have no assignee');
        self::assertCount(0, $this->findAlertsForMission($mission), 'No SURGEON_ABSENCE alert once the mission is already auto-cancelled — would be a stale duplicate of the automatic action');
    }

    // ── Create: instrumentist absence on ASSIGNED mission ───────────────────

    /**
     * REGRESSION (feature added after this test was first written): an instrumentist absence
     * over an ASSIGNED mission used to only raise a REASSIGNMENT_REQUIRED alert and leave the
     * mission untouched. AbsenceMissionReactionService now auto-releases it back to the pool —
     * see that service's class docblock. Because the mission's instrumentist FK is cleared
     * (no longer matches "m.instrumentist = :absentUser"), AbsenceImpactService's own
     * unmodified query no longer raises REASSIGNMENT_REQUIRED either.
     */
    #[WithoutErrorHandler]
    public function test_creating_instrumentist_absence_over_assigned_mission_releases_it_and_raises_no_stale_alert(): void
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
        self::assertSame(MissionStatus::OPEN, $mission->getStatus(), 'Instrumentist absence must auto-release an ASSIGNED mission back to OPEN');
        self::assertNull($mission->getInstrumentist());
        self::assertCount(0, $this->findAlertsForMission($mission), 'No REASSIGNMENT_REQUIRED alert once the mission is already auto-released — would be a stale duplicate of the automatic action');
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

    // ── Jours isolés (ticket "Support des jours d'absence isolés") ──────────
    //
    // The frontend creates one Absence row per isolated day (dateStart === dateEnd) instead
    // of a single multi-day row. These tests prove the existing model/engine already handles
    // that pattern correctly with zero entity/migration change.

    #[WithoutErrorHandler]
    public function test_creating_several_isolated_day_absences_creates_one_row_each_and_each_is_recognized(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        // Three DRAFT missions, one per isolated day the surgeon will declare absent.
        $missionA = $this->makeMissionOnDay($surgeon, $site, '2026-07-04');
        $missionB = $this->makeMissionOnDay($surgeon, $site, '2026-07-09');
        $missionC = $this->makeMissionOnDay($surgeon, $site, '2026-07-18');

        foreach (['2026-07-04', '2026-07-09', '2026-07-18'] as $day) {
            $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
                'userId' => $surgeon->getId(), 'dateStart' => $day, 'dateEnd' => $day,
            ]));
            self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
            $absence = $this->json($client->getResponse());
            $this->createdIds['absences'][] = $absence['id'];
            self::assertSame($day, $absence['dateStart']);
            self::assertSame($day, $absence['dateEnd']);
        }

        $list = $this->em->createQueryBuilder()
            ->select('a')->from(Absence::class, 'a')
            ->where('a.user = :u')->setParameter('u', $surgeon)
            ->getQuery()->getResult();
        self::assertCount(3, $list, 'Three isolated-day selections must produce three Absence rows');

        $this->em->clear();
        foreach ([$missionA, $missionB, $missionC] as $mission) {
            $reloaded = $this->em->find(Mission::class, $mission->getId());
            $alerts   = $this->findAlertsForMission($reloaded);
            self::assertCount(1, $alerts, "Mission on {$reloaded->getStartAt()->format('Y-m-d')} must have exactly one alert");
            self::assertSame('SURGEON_ABSENCE', $alerts[0]->getType()->value);
        }
    }

    #[WithoutErrorHandler]
    public function test_deleting_one_isolated_day_absence_leaves_the_others_intact(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $ids = [];
        foreach (['2026-07-04', '2026-07-09', '2026-07-18'] as $day) {
            $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
                'userId' => $surgeon->getId(), 'dateStart' => $day, 'dateEnd' => $day,
            ]));
            $absence = $this->json($client->getResponse());
            $ids[$day] = $absence['id'];
            $this->createdIds['absences'][] = $absence['id'];
        }

        $client->request('DELETE', "/api/absences/{$ids['2026-07-09']}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'] = array_values(array_diff($this->createdIds['absences'], [$ids['2026-07-09']]));

        $remaining = $this->em->createQueryBuilder()
            ->select('a')->from(Absence::class, 'a')
            ->where('a.user = :u')->setParameter('u', $surgeon)
            ->orderBy('a.dateStart', 'ASC')
            ->getQuery()->getResult();

        self::assertCount(2, $remaining);
        self::assertSame('2026-07-04', $remaining[0]->getDateStart()->format('Y-m-d'));
        self::assertSame('2026-07-18', $remaining[1]->getDateStart()->format('Y-m-d'));
    }

    #[WithoutErrorHandler]
    public function test_mixing_a_range_and_isolated_days_all_are_taken_into_account(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $missionInRange   = $this->makeMissionOnDay($surgeon, $site, '2026-07-05');
        $missionDay21     = $this->makeMissionOnDay($surgeon, $site, '2026-07-21');
        $missionDay28     = $this->makeMissionOnDay($surgeon, $site, '2026-07-28');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-07-01', 'dateEnd' => '2026-07-15',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        foreach (['2026-07-21', '2026-07-28'] as $day) {
            $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
                'userId' => $surgeon->getId(), 'dateStart' => $day, 'dateEnd' => $day,
            ]));
            self::assertSame(201, $client->getResponse()->getStatusCode());
            $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        }

        $this->em->clear();
        foreach ([$missionInRange, $missionDay21, $missionDay28] as $mission) {
            $reloaded = $this->em->find(Mission::class, $mission->getId());
            self::assertCount(1, $this->findAlertsForMission($reloaded), "Mission on {$reloaded->getStartAt()->format('Y-m-d')} must be alerted");
        }
    }

    /**
     * Cas 6 — regression test for the PlanningAlertService::findActiveAlert() fix: an
     * isolated day already covered by an existing range must NOT raise a second alert for
     * the same mission.
     */
    #[WithoutErrorHandler]
    public function test_isolated_day_already_covered_by_an_existing_range_does_not_duplicate_the_alert(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMissionOnDay($surgeon, $site, '2026-07-09');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-07-01', 'dateEnd' => '2026-07-15',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertCount(1, $this->findAlertsForMission($mission), 'Alert must exist after the range is created');

        // An isolated day already inside the range above, for the same person.
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-07-09', 'dateEnd' => '2026-07-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts, 'A second overlapping Absence row for the same person must not create a duplicate alert');
        self::assertSame('OPEN', $alerts[0]->getStatus()->value);
    }

    /**
     * D-050 follow-up — deleting the absence that originally raised an alert must not
     * resolve it if another absence for the same person still overlaps the same mission.
     * Steps exactly as specified: range → isolated day inside it → delete the range (alert
     * must stay OPEN, re-pointed to the isolated day) → delete the isolated day too (alert
     * must now resolve, nothing left covering the mission).
     */
    #[WithoutErrorHandler]
    public function test_deleting_one_of_two_overlapping_absences_keeps_the_alert_open_until_the_last_one_is_gone(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        // 1) Create a mission.
        $mission = $this->makeMissionOnDay($surgeon, $site, '2026-07-09');

        // 2) Create a range absence that generates the alert.
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-07-01', 'dateEnd' => '2026-07-15',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $rangeAbsenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts, 'Alert must exist after the range is created');
        $alertId = $alerts[0]->getId();

        // 3) Create an overlapping isolated-day absence.
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-07-09', 'dateEnd' => '2026-07-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $isolatedDayAbsenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertCount(1, $this->findAlertsForMission($mission), 'Still exactly one alert — no duplicate from the second absence');

        // 4) Delete the range absence.
        $client->request('DELETE', "/api/absences/{$rangeAbsenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // 5) The alert must remain OPEN — the isolated day still covers the mission.
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts, 'Alert row must still be the same one, not duplicated');
        self::assertSame($alertId, $alerts[0]->getId());
        self::assertSame('OPEN', $alerts[0]->getStatus()->value, 'Alert must stay active: the isolated day still overlaps this mission');
        self::assertNotNull($alerts[0]->getAbsence(), 'Alert must be re-pointed to the surviving absence, not left dangling');
        self::assertSame($isolatedDayAbsenceId, $alerts[0]->getAbsence()->getId());

        // 6) Now delete the isolated-day absence too — nothing overlaps the mission anymore.
        $client->request('DELETE', "/api/absences/{$isolatedDayAbsenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // 7) The alert must now be resolved.
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        $alerts  = $this->findAlertsForMission($mission);
        self::assertCount(1, $alerts, 'Alert row must survive, only resolved (never deleted)');
        self::assertSame($alertId, $alerts[0]->getId());
        self::assertSame('RESOLVED', $alerts[0]->getStatus()->value);
        self::assertNull($alerts[0]->getAbsence(), 'absence FK must be SET NULL once nothing replaces it');

        // Clean up the now-resolved alert directly — both absences are already deleted.
        $this->em->remove($alerts[0]);
        $this->em->flush();
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
