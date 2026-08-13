<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Service\MissionPostDeployService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 6 (D-106) — real-HTTP, real-database coverage for the manual "Vérifier les conflits"
 * scan: POST /api/planning/versions/{id}/verify-conflicts.
 */
final class PlanningVersionAuditFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'Lot6Audit123!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];
    private array $createdVersionIds = [];
    private array $createdAbsenceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdAbsenceIds as $id) {
                $e = $this->em->find(Absence::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            if (!empty($this->createdVersionIds)) {
                $missions = $this->em->createQueryBuilder()
                    ->select('m')->from(Mission::class, 'm')
                    ->where('m.planningVersion IN (:v)')
                    ->setParameter('v', $this->createdVersionIds)
                    ->getQuery()->getResult();
                foreach ($missions as $m) {
                    $this->createdMissionIds[] = $m->getId();
                }
            }
            foreach (array_unique($this->createdMissionIds) as $id) {
                foreach ($this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $id]) as $alert) {
                    $this->em->remove($alert);
                }
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();

            foreach (array_unique($this->createdMissionIds) as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdUserIds as $id) {
                foreach ($this->em->getRepository(NotificationEvent::class)->findBy(['user' => $id]) as $n) {
                    $this->em->remove($n);
                }
            }
            $this->em->flush();

            foreach ($this->createdVersionIds as $id) {
                $e = $this->em->find(PlanningVersion::class, $id);
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

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role, bool $active = true): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('lot6audit-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive($active);
        $u->setFirstname('Test');
        $u->setLastname('User');
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

    private function makeSite(string $label = ''): Hospital
    {
        $h = new Hospital();
        $h->setName('Lot6Audit-' . $label . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeActiveVersion(?Hospital $site, User $manager, string $periodStart, string $periodEnd): PlanningVersion
    {
        $v = new PlanningVersion();
        $v->setSite($site);
        $v->setPeriodStart(new \DateTimeImmutable($periodStart));
        $v->setPeriodEnd(new \DateTimeImmutable($periodEnd));
        $v->setVersionNumber(random_int(1000, 999999));
        $v->setStatus(PlanningVersionStatus::ACTIVE);
        $v->setGeneratedBy($manager);
        $this->em->persist($v);
        $this->em->flush();
        $this->createdVersionIds[] = $v->getId();
        return $v;
    }

    private function makeMission(
        ?PlanningVersion $version, Hospital $site, User $surgeon, User $createdBy,
        string $date, string $startTime, string $endTime, ?User $instrumentist,
        MissionStatus $status = MissionStatus::ASSIGNED,
    ): Mission {
        $tz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $m = new Mission();
        if ($version !== null) {
            $m->setPlanningVersion($version);
        }
        $m->setType(MissionType::BLOCK);
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($createdBy);
        $m->setStartAt(new \DateTimeImmutable("{$date}T{$startTime}:00", $tz));
        $m->setEndAt(new \DateTimeImmutable("{$date}T{$endTime}:00", $tz));
        $m->setStatus($status);
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->em->persist($m);
        $this->em->flush();
        $this->createdMissionIds[] = $m->getId();
        return $m;
    }

    private function makeAbsence(User $user, User $createdBy, string $start, string $end): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($start));
        $a->setDateEnd(new \DateTimeImmutable($end));
        $a->setCreatedBy($createdBy);
        $this->em->persist($a);
        $this->em->flush();
        $this->createdAbsenceIds[] = $a->getId();
        return $a;
    }

    private function postJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }

    private function verify(KernelBrowser $client, string $token, PlanningVersion $version): array
    {
        $response = $this->postJson($client, $token, '/api/planning/versions/' . $version->getId() . '/verify-conflicts');
        return [$response, json_decode((string) $response->getContent(), true) ?? []];
    }

    private function freshMission(int $id): Mission
    {
        $this->em->clear();
        $m = $this->em->find(Mission::class, $id);
        self::assertNotNull($m);
        return $m;
    }

    // ── §37.1 — aucun problème ──────────────────────────────────────────────────

    public function test_no_issues_returns_zeroes(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();

        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $this->makeMission($version, $site, $surgeon, $manager, '2026-11-05', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $body['issuesFound']);
        self::assertSame(0, $body['automaticCorrections']);
        self::assertSame(0, $body['alertsCreated']);
        self::assertSame(0, $body['alertsResolved']);
        self::assertSame(1, $body['checkedMissions']);
    }

    // ── §37.2 — Sophie absente mais assignée → correction ───────────────────────

    public function test_instrumentist_absent_but_still_assigned_is_released(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $sophie  = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();

        $this->makeAbsence($sophie, $manager, '2026-11-01', '2026-11-16');
        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-14', '08:00', '18:00', $sophie, MissionStatus::ASSIGNED);

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['issuesFound']);
        self::assertSame(1, $body['automaticCorrections']);
        self::assertSame('INSTRUMENTIST_ABSENCE', $body['issues'][0]['type']);
        self::assertSame('RELEASED_TO_POOL', $body['issues'][0]['action']);

        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::OPEN, $fresh->getStatus());
        self::assertNull($fresh->getInstrumentist());

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertNotEmpty(array_filter($events, fn (AuditEvent $e) => $e->getEventType() === AuditEventType::MISSION_RELEASED_TO_POOL));
    }

    // ── §37.3 — chirurgien absent + mission active → correction ─────────────────

    public function test_surgeon_absent_with_active_mission_is_cancelled(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();

        $this->makeAbsence($surgeon, $manager, '2026-11-01', '2026-11-16');
        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-14', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['issuesFound']);
        self::assertSame('SURGEON_ABSENCE', $body['issues'][0]['type']);
        self::assertSame('CANCELLED', $body['issues'][0]['action']);

        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $fresh->getStatus());
    }

    // ── §37.4/5/6/7 — conflits horaires (alerte uniquement) ──────────────────────

    public function test_same_site_schedule_conflict_raises_alert(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $missionA = $this->makeMission($version, $site, $surgeonA, $manager, '2026-11-10', '08:00', '13:00', $instr);
        $this->makeMission($version, $site, $surgeonB, $manager, '2026-11-10', '10:00', '14:00', $instr);

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode());
        self::assertGreaterThanOrEqual(1, $body['alertsCreated']);
        $conflictIssue = current(array_filter($body['issues'], fn ($i) => $i['type'] === 'INSTRUMENTIST_CONFLICT'));
        self::assertNotFalse($conflictIssue);

        $alerts = $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $missionA->getId()]);
        self::assertNotEmpty(array_filter($alerts, fn (PlanningAlert $a) => $a->getType()->value === 'INSTRUMENTIST_CONFLICT' && $a->isOpenOrAcknowledged()));

        // Mission itself is never mutated by a conflict finding — manager decides.
        self::assertSame(MissionStatus::ASSIGNED, $this->freshMission($missionA->getId())->getStatus());
    }

    public function test_cross_site_schedule_conflict_raises_alert(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $siteA   = $this->makeSite('A');
        $siteB   = $this->makeSite('B');

        $version = $this->makeActiveVersion($siteA, $manager, '2026-11-01', '2026-11-30');
        $missionA = $this->makeMission($version, $siteA, $surgeon, $manager, '2026-11-11', '08:00', '13:00', null);
        $this->makeMission(null, $siteB, $surgeon, $manager, '2026-11-11', '12:00', '16:00', null);

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode());
        self::assertGreaterThanOrEqual(1, $body['alertsCreated']);
        $alerts = $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $missionA->getId()]);
        self::assertNotEmpty(array_filter($alerts, fn (PlanningAlert $a) => $a->getType()->value === 'SURGEON_CONFLICT' && $a->isOpenOrAcknowledged()));
    }

    public function test_cross_planning_version_schedule_conflict_raises_alert_without_duplication(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        $versionA = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-15');
        $versionB = $this->makeActiveVersion($site, $manager, '2026-11-16', '2026-11-30');
        $missionA = $this->makeMission($versionA, $site, $surgeon, $manager, '2026-11-14', '08:00', '12:00', null);
        $missionB = $this->makeMission($versionB, $site, $surgeon, $manager, '2026-11-14', '11:00', '15:00', null);

        [$responseA, $bodyA] = $this->verify($client, $token, $versionA);
        self::assertSame(200, $responseA->getStatusCode());
        self::assertGreaterThanOrEqual(1, $bodyA['alertsCreated'], 'Scanning A must detect the conflict against a mission in a DIFFERENT PlanningVersion.');

        [$responseB, $bodyB] = $this->verify($client, $token, $versionB);
        self::assertSame(200, $responseB->getStatusCode());
        self::assertSame(0, $bodyB['alertsCreated'], 'Scanning B must find the same real conflict already alerted — never a second, duplicate alert.');

        $anchorId = min($missionA->getId(), $missionB->getId());
        $activeAlerts = array_filter(
            $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $anchorId]),
            fn (PlanningAlert $a) => $a->isOpenOrAcknowledged(),
        );
        self::assertCount(1, $activeAlerts, 'Exactly one active alert total across both scans.');
    }

    public function test_adjacent_slots_are_never_flagged_as_conflict(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $this->makeMission($version, $site, $surgeon, $manager, '2026-11-12', '08:00', '10:00', null);
        $this->makeMission($version, $site, $surgeon, $manager, '2026-11-12', '10:00', '12:00', null);

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $body['alertsCreated'], 'Adjacent, non-overlapping slots (end-exclusive) must never raise a conflict alert.');
    }

    // ── §37.8/9 — dédoublonnage / résolution alerte obsolète ─────────────────────

    public function test_second_scan_never_duplicates_an_already_open_conflict_alert(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $missionA = $this->makeMission($version, $site, $surgeonA, $manager, '2026-11-13', '08:00', '13:00', $instr);
        $this->makeMission($version, $site, $surgeonB, $manager, '2026-11-13', '10:00', '14:00', $instr);

        [, $first]  = $this->verify($client, $token, $version);
        [, $second] = $this->verify($client, $token, $version);

        self::assertGreaterThanOrEqual(1, $first['alertsCreated']);
        self::assertSame(0, $second['alertsCreated'], 'A still-real conflict must not be re-created on a second scan.');

        $activeAlerts = array_filter(
            $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $missionA->getId()]),
            fn (PlanningAlert $a) => $a->isOpenOrAcknowledged(),
        );
        self::assertCount(1, $activeAlerts);
    }

    public function test_stale_conflict_alert_is_resolved_once_overlap_disappears(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $version  = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $missionA = $this->makeMission($version, $site, $surgeonA, $manager, '2026-11-17', '08:00', '13:00', $instr);
        $missionB = $this->makeMission($version, $site, $surgeonB, $manager, '2026-11-17', '10:00', '14:00', $instr);

        [, $first] = $this->verify($client, $token, $version);
        self::assertGreaterThanOrEqual(1, $first['alertsCreated']);

        // Cancel missionB directly — the overlap disappears (CANCELLED is excluded from
        // ACTIVE_STATUSES) without going through any absence/reconciliation path.
        // freshMission() clears the EntityManager, detaching $manager — must re-fetch it.
        $freshMissionB = $this->freshMission($missionB->getId());
        $freshManager  = $this->em->find(User::class, $manager->getId());
        static::getContainer()->get(MissionPostDeployService::class)
            ->cancel($freshMissionB, $freshManager, notify: false);

        [$response, $second] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode());
        self::assertGreaterThanOrEqual(1, $second['alertsResolved'], 'The alert must be resolved once the conflict is genuinely gone.');

        // Regression (Lot 7 live campaign): PlanningConflictDetectionService::applySync()'s
        // "resolve stale alert" branch mutates the entity but never flushes itself — a bug
        // that this exact assertion originally missed, because without em->clear() the
        // repository call below returns the SAME in-memory object the request's own
        // EntityManager already mutated (Doctrine's identity map), which looks "resolved"
        // even when the UPDATE was never actually sent to the database. Only a query against
        // a cleared identity map (or, as the live campaign did, a completely separate DB
        // connection) proves the mutation is durable. See PlanningVersionAuditService's
        // trailing flush() and its docblock for the fix.
        $this->em->clear();
        $anchorId = min($missionA->getId(), $missionB->getId());
        $activeAlerts = array_filter(
            $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $anchorId]),
            fn (PlanningAlert $a) => $a->isOpenOrAcknowledged(),
        );
        self::assertCount(0, $activeAlerts);

        $resolvedAlert = current($this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $anchorId]));
        self::assertSame('RESOLVED', $resolvedAlert->getStatus()->value);
        self::assertNotNull($resolvedAlert->getResolvedAt(), 'resolved_at must be persisted, not just held in memory.');
    }

    // ── §37.10/11 — restauration oubliée + protection décision manager ──────────

    public function test_forgotten_restoration_after_absence_deleted_bypassing_normal_flow(): void
    {
        // Fixture deliberately bypasses the normal Absence-delete flow (which would
        // already reconcile this) to artificially reproduce "a restoration that never
        // happened" — the exact residual-gap scenario this scan is a safety net for.
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();

        $absence = $this->makeAbsence($surgeon, $manager, '2026-11-01', '2026-11-16');
        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-14', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);

        $freshMissionForCancel = $this->freshMission($mission->getId());
        $freshManagerForCancel = $this->em->find(User::class, $manager->getId());
        static::getContainer()->get(MissionPostDeployService::class)
            ->cancel($freshMissionForCancel, $freshManagerForCancel, reason: 'Absence chirurgien enregistrée', notify: false, causedByAbsenceId: $absence->getId());

        // Absence removed directly via the EntityManager — deliberately skipping
        // AbsenceController::delete() / AbsenceImpactReconciliationService entirely.
        $this->em->remove($this->em->find(Absence::class, $absence->getId()));
        $this->em->flush();
        array_splice($this->createdAbsenceIds, array_search($absence->getId(), $this->createdAbsenceIds, true), 1);

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(1, $body['automaticCorrections']);
        self::assertSame('FORGOTTEN_RESTORATION', $body['issues'][0]['type']);
        self::assertSame('RESTORED_ASSIGNED', $body['issues'][0]['action']);

        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $fresh->getStatus());
        self::assertSame($instr->getId(), $fresh->getInstrumentist()?->getId());

        // Second scan — nothing left to do.
        [, $second] = $this->verify($client, $token, $version);
        self::assertSame(0, $second['automaticCorrections']);
    }

    public function test_manager_decision_after_cancellation_is_never_overwritten_by_scan(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();

        $absence = $this->makeAbsence($surgeon, $manager, '2026-11-01', '2026-11-16');
        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-14', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);

        $deployService = static::getContainer()->get(MissionPostDeployService::class);
        $freshMissionForCancel2 = $this->freshMission($mission->getId());
        $freshManagerForCancel2 = $this->em->find(User::class, $manager->getId());
        $deployService->cancel($freshMissionForCancel2, $freshManagerForCancel2, reason: 'Absence chirurgien enregistrée', notify: false, causedByAbsenceId: $absence->getId());

        $this->em->remove($this->em->find(Absence::class, $absence->getId()));
        $this->em->flush();
        array_splice($this->createdAbsenceIds, array_search($absence->getId(), $this->createdAbsenceIds, true), 1);

        // Manager independently touches the mission AFTER the cancellation — a later,
        // unrelated AuditEvent breaks the "latest event is exactly the absence reaction"
        // match, exactly like Lot 4's own protection.
        $auditService = static::getContainer()->get(\App\Service\AuditService::class);
        $freshMissionForTouch = $this->freshMission($mission->getId());
        $freshManagerForTouch = $this->em->find(User::class, $manager->getId());
        $auditService->record($freshMissionForTouch, $freshManagerForTouch, AuditEventType::MISSION_TIME_CHANGED_POST_DEPLOY, ['note' => 'manual manager touch']);
        $this->em->flush(); // AuditService::record() only persists — the normal caller (MissionPostDeployService) always flushes itself.

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $body['automaticCorrections'], 'A manager touch after the cancellation must block any automatic restoration.');
        self::assertSame(MissionStatus::CANCELLED, $this->freshMission($mission->getId())->getStatus());
    }

    // ── §37.12 — idempotence ─────────────────────────────────────────────────────

    public function test_second_scan_with_no_data_change_finds_nothing_new(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $sophie  = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();

        $this->makeAbsence($sophie, $manager, '2026-11-01', '2026-11-16');
        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $this->makeMission($version, $site, $surgeon, $manager, '2026-11-14', '08:00', '18:00', $sophie, MissionStatus::ASSIGNED);

        [, $first]  = $this->verify($client, $token, $version);
        [, $second] = $this->verify($client, $token, $version);
        [, $third]  = $this->verify($client, $token, $version);

        self::assertSame(1, $first['issuesFound']);
        self::assertSame(0, $second['issuesFound'], 'Repeated scans with no data change must converge to zero.');
        self::assertSame(0, $third['issuesFound']);
    }

    // ── §6D — instrumentiste devenu INACTIVE : alerte uniquement, jamais de mutation ──

    public function test_inactive_instrumentist_raises_alert_without_mutating_mission(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST', active: false);
        $site    = $this->makeSite();

        $version = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-18', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);

        [$response, $body] = $this->verify($client, $token, $version);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $body['alertsCreated']);
        self::assertSame(0, $body['automaticCorrections'], 'INACTIVE must never trigger an automatic mutation — alert-only per user decision.');
        self::assertSame('INSTRUMENTIST_INACTIVE', $body['issues'][0]['type']);

        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $fresh->getStatus(), 'The mission itself must never be mutated for an INACTIVE finding.');
        self::assertSame($instr->getId(), $fresh->getInstrumentist()?->getId());

        $alerts = array_filter(
            $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $mission->getId()]),
            fn (PlanningAlert $a) => $a->isOpenOrAcknowledged(),
        );
        self::assertCount(1, $alerts);
        self::assertSame('INSTRUMENTIST_INACTIVE', current($alerts)->getType()->value);

        // Second scan — no duplicate.
        [, $second] = $this->verify($client, $token, $version);
        self::assertSame(0, $second['alertsCreated']);
    }

    // ── §37.13/14/15 — RBAC / 404 / statut non supporté ──────────────────────────

    public function test_non_manager_is_denied(): void
    {
        $client       = $this->boot();
        $manager      = $this->createUser('ROLE_MANAGER');
        $instr        = $this->createUser('ROLE_INSTRUMENTIST');
        $instrToken   = $this->login($client, $instr);
        $site         = $this->makeSite();
        $version      = $this->makeActiveVersion($site, $manager, '2026-11-01', '2026-11-30');

        $response = $this->postJson($client, $instrToken, '/api/planning/versions/' . $version->getId() . '/verify-conflicts');

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_nonexistent_version_returns_404(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);

        $response = $this->postJson($client, $token, '/api/planning/versions/999999999/verify-conflicts');

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_draft_version_returns_business_error(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $site    = $this->makeSite();

        $version = new PlanningVersion();
        $version->setSite($site);
        $version->setPeriodStart(new \DateTimeImmutable('2026-11-01'));
        $version->setPeriodEnd(new \DateTimeImmutable('2026-11-30'));
        $version->setVersionNumber(random_int(1000, 999999));
        $version->setStatus(PlanningVersionStatus::DRAFT);
        $version->setGeneratedBy($manager);
        $this->em->persist($version);
        $this->em->flush();
        $this->createdVersionIds[] = $version->getId();

        $response = $this->postJson($client, $token, '/api/planning/versions/' . $version->getId() . '/verify-conflicts');

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $body);
    }
}
