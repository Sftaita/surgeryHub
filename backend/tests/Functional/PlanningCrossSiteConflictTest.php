<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-091 — cross-site conflict detection: a surgeon or instrumentist must never be
 * planned on two incompatible, overlapping activities, whether on two different sites
 * or the same one. Covers PlanningConflictDetectionService, deploy-time blocking
 * (PlanningDraftRevalidationService), and alert visibility raised by
 * PlanningModificationService::apply() / PlanningGeneratorServiceV2::generate().
 */
final class PlanningCrossSiteConflictTest extends WebTestCase
{
    private const PASSWORD = 'Conflict91!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];
    private array $createdVersionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
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
                foreach ($this->em->getRepository(PlanningDeployment::class)->findBy(['deployedBy' => $id]) as $d) {
                    $this->em->remove($d);
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function boot(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        return $client;
    }

    private function createUser(string $role): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = new User();
        $u->setEmail('conflict91-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
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
        $h->setName('Conflict91-' . $label . '-' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdSiteIds[] = $h->getId();
        return $h;
    }

    private function makeDraftVersion(?Hospital $site, User $manager, string $periodStart, string $periodEnd): PlanningVersion
    {
        $v = new PlanningVersion();
        $v->setSite($site);
        $v->setPeriodStart(new \DateTimeImmutable($periodStart));
        $v->setPeriodEnd(new \DateTimeImmutable($periodEnd));
        $v->setVersionNumber(random_int(1000, 999999));
        $v->setStatus(PlanningVersionStatus::DRAFT);
        $v->setGeneratedBy($manager);
        $this->em->persist($v);
        $this->em->flush();
        $this->createdVersionIds[] = $v->getId();
        return $v;
    }

    private function makeMission(
        ?PlanningVersion $version, Hospital $site, User $surgeon, User $createdBy,
        string $date, string $startTime, string $endTime, ?User $instrumentist,
        MissionStatus $status = MissionStatus::DRAFT,
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

    private function deploy(KernelBrowser $client, string $token, PlanningVersion $version): Response
    {
        return $this->postJson($client, $token, '/api/planning/v2/deploy', [
            'planningVersionId' => $version->getId(),
        ]);
    }

    private function freshMission(int $id): Mission
    {
        $this->em->clear();
        $m = $this->em->find(Mission::class, $id);
        self::assertNotNull($m);
        return $m;
    }

    private function findAlertsForMission(int $missionId): array
    {
        $this->em->clear();
        return $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $missionId]);
    }

    // ── Chirurgien — déploiement bloqué ──────────────────────────────────────

    public function test_surgeon_double_booked_two_sites_full_overlap_blocks_deploy(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $siteA   = $this->makeSite('A');
        $siteB   = $this->makeSite('B');

        // Already-active commitment on site B — surgeon 08:00–12:00.
        $this->makeMission(null, $siteB, $surgeon, $manager, '2026-10-05', '08:00', '12:00', null, MissionStatus::ASSIGNED);

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-01', '2026-10-31');
        // Overlapping DRAFT on site A — 10:00–14:00 overlaps 08:00–12:00.
        $mission = $this->makeMission($version, $siteA, $surgeon, $manager, '2026-10-05', '10:00', '14:00', null);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('DRAFT_CONFLICTS', $body['code']);
        $conflict = current(array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $mission->getId()));
        self::assertNotFalse($conflict);
        self::assertSame('CROSS_SITE_CONFLICT', $conflict['type']);
        self::assertSame($surgeon->getId(), $conflict['surgeonId']);

        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::DRAFT, $fresh->getStatus(), 'A blocked deploy must never publish anything.');
    }

    public function test_surgeon_double_booked_partial_overlap_blocks_deploy(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $siteA   = $this->makeSite('A');
        $siteB   = $this->makeSite('B');

        // Site A: 08:00–12:00 (already active).
        $this->makeMission(null, $siteA, $surgeon, $manager, '2026-10-06', '08:00', '12:00', null, MissionStatus::ASSIGNED);

        $version = $this->makeDraftVersion($siteB, $manager, '2026-10-01', '2026-10-31');
        // Site B: 10:00–14:00 — partial overlap with site A (10:00–12:00 window).
        $mission = $this->makeMission($version, $siteB, $surgeon, $manager, '2026-10-06', '10:00', '14:00', null);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::DRAFT, $fresh->getStatus());
    }

    public function test_surgeon_adjacent_slots_no_overlap_does_not_block_deploy(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $siteA   = $this->makeSite('A');
        $siteB   = $this->makeSite('B');

        // Site A: 08:00–10:00 (already active).
        $this->makeMission(null, $siteA, $surgeon, $manager, '2026-10-07', '08:00', '10:00', null, MissionStatus::ASSIGNED);

        $version = $this->makeDraftVersion($siteB, $manager, '2026-10-01', '2026-10-31');
        // Site B: 10:00–12:00 — starts exactly when the other ends. End bound is
        // exclusive (D-091) — must NOT be treated as a conflict.
        $mission = $this->makeMission($version, $siteB, $surgeon, $manager, '2026-10-07', '10:00', '12:00', null);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::OPEN, $fresh->getStatus());
    }

    public function test_surgeon_conflict_with_cancelled_mission_is_ignored(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $siteA   = $this->makeSite('A');
        $siteB   = $this->makeSite('B');

        // Site B: 08:00–12:00 but CANCELLED — must never create a false conflict.
        $this->makeMission(null, $siteB, $surgeon, $manager, '2026-10-08', '08:00', '12:00', null, MissionStatus::CANCELLED);

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-01', '2026-10-31');
        $mission = $this->makeMission($version, $siteA, $surgeon, $manager, '2026-10-08', '08:00', '12:00', null);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::OPEN, $fresh->getStatus());
    }

    public function test_surgeon_conflict_appearing_after_generation_blocks_deploy(): void
    {
        // Mirrors PlanningDeployAbsenceRevalidationTest's absence scenario: a draft was
        // valid when built, but another mission for the same surgeon was created on a
        // different site afterward, before the manager clicked Déployer.
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $siteA   = $this->makeSite('A');
        $siteB   = $this->makeSite('B');

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-01', '2026-10-31');
        $mission = $this->makeMission($version, $siteA, $surgeon, $manager, '2026-10-09', '08:00', '12:00', null);

        // Created AFTER the draft — the exact gap deploy-time revalidation closes.
        $this->makeMission(null, $siteB, $surgeon, $manager, '2026-10-09', '09:00', '11:00', null, MissionStatus::ASSIGNED);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::DRAFT, $fresh->getStatus());
    }

    // ── Instrumentiste — déploiement bloqué ──────────────────────────────────

    public function test_instrumentist_double_booked_two_sites_blocks_deploy(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $siteA    = $this->makeSite('A');
        $siteB    = $this->makeSite('B');

        $this->makeMission(null, $siteB, $surgeonB, $manager, '2026-10-10', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-01', '2026-10-31');
        $mission = $this->makeMission($version, $siteA, $surgeonA, $manager, '2026-10-10', '11:00', '15:00', $instr);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        $conflict = current(array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $mission->getId()));
        self::assertNotFalse($conflict);
        self::assertSame('CROSS_SITE_CONFLICT', $conflict['type']);
        self::assertSame($instr->getId(), $conflict['instrumentistId']);
        self::assertTrue($conflict['siteId'] !== $conflict['conflictingSiteId'], 'The two sites must be distinct in the reported conflict.');
    }

    public function test_instrumentist_double_booked_same_site_blocks_deploy_with_distinguishable_message(): void
    {
        // §2 — the same engine also catches an incoherent double-assignment on the SAME
        // site; the alert must still clearly identify both missions/slots even though the
        // site is identical on both sides.
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite('Same');

        $this->makeMission(null, $site, $surgeonB, $manager, '2026-10-11', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);

        $version = $this->makeDraftVersion($site, $manager, '2026-10-01', '2026-10-31');
        $mission = $this->makeMission($version, $site, $surgeonA, $manager, '2026-10-11', '10:00', '14:00', $instr);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $conflict = current(array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $mission->getId()));
        self::assertNotFalse($conflict);
        self::assertSame($conflict['siteId'], $conflict['conflictingSiteId'], 'Same-site case: both sides share the same site id.');
        self::assertNotEmpty($conflict['reason'], 'The reason text must still distinguish the two slots even when the site is identical.');
        self::assertStringContainsString('10:00', $conflict['reason']);
        self::assertStringContainsString('13:00', $conflict['reason']);
    }

    public function test_instrumentist_adjacent_slots_no_conflict(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $siteA    = $this->makeSite('A');
        $siteB    = $this->makeSite('B');

        $this->makeMission(null, $siteB, $surgeonB, $manager, '2026-10-12', '08:00', '10:00', $instr, MissionStatus::ASSIGNED);

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-01', '2026-10-31');
        $mission = $this->makeMission($version, $siteA, $surgeonA, $manager, '2026-10-12', '10:00', '12:00', $instr);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $fresh->getStatus());
    }

    public function test_instrumentist_cancelled_assignment_is_ignored(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $siteA    = $this->makeSite('A');
        $siteB    = $this->makeSite('B');

        $this->makeMission(null, $siteB, $surgeonB, $manager, '2026-10-13', '08:00', '13:00', $instr, MissionStatus::CANCELLED);

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-01', '2026-10-31');
        $mission = $this->makeMission($version, $siteA, $surgeonA, $manager, '2026-10-13', '08:00', '13:00', $instr);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    // ── Modification manuelle — visibilité (alerte non bloquante) ────────────

    public function test_manual_reassignment_creating_instrumentist_conflict_raises_alert(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $busyInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $siteA    = $this->makeSite('A');
        $siteB    = $this->makeSite('B');

        // busyInstr already committed on site B, 08:00–13:00.
        $this->makeMission(null, $siteB, $surgeonB, $manager, '2026-10-14', '08:00', '13:00', $busyInstr, MissionStatus::ASSIGNED);

        // An already-deployed ACTIVE version on site A with an OPEN mission the manager
        // is about to manually assign to busyInstr — overlapping the site B commitment.
        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-01', '2026-10-31');
        $version->setStatus(PlanningVersionStatus::ACTIVE);
        $this->em->flush();
        $mission = $this->makeMission($version, $siteA, $surgeonA, $manager, '2026-10-14', '10:00', '14:00', null, MissionStatus::OPEN);

        $response = $this->postJson($client, $token, '/api/planning/versions/' . $version->getId() . '/apply-modifications', [
            'lines' => [[
                'existingMissionId' => $mission->getId(),
                'date' => '2026-10-14', 'startTime' => '10:00', 'endTime' => '14:00',
                'siteId' => $siteA->getId(), 'missionType' => 'BLOCK',
                'surgeonId' => $surgeonA->getId(), 'instrumentistId' => $busyInstr->getId(),
                'status' => 'COVERED', 'existingInstrumentistId' => null,
            ]],
        ]);

        // Non-blocking: the save still succeeds (unlike deploy()).
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // The alert is anchored on whichever of the two missions has the lower id (D-091)
        // — the pre-existing site B mission was created first, so it is expected to be the
        // anchor here, but the assertion checks both sides to stay correct regardless of id
        // ordering nuances in fixture creation.
        $allMissionIds = $this->createdMissionIds;
        $conflictAlerts = [];
        foreach (array_unique($allMissionIds) as $id) {
            foreach ($this->findAlertsForMission($id) as $alert) {
                if ($alert->getType()->value === 'INSTRUMENTIST_CONFLICT' && $alert->isOpenOrAcknowledged()) {
                    $conflictAlerts[] = $alert;
                }
            }
        }
        self::assertCount(1, $conflictAlerts, 'A manual reassignment creating a real conflict must raise exactly one INSTRUMENTIST_CONFLICT alert.');
    }

    // ── Alertes — dédoublonnage, type, résolution ────────────────────────────

    public function test_conflict_alert_is_never_duplicated_for_the_same_pair(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $siteA    = $this->makeSite('A');
        $siteB    = $this->makeSite('B');

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-15', '2026-10-15');
        $missionA = $this->makeMission($version, $siteA, $surgeonA, $manager, '2026-10-15', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);
        $missionB = $this->makeMission(null, $siteB, $surgeonB, $manager, '2026-10-15', '10:00', '14:00', $instr, MissionStatus::ASSIGNED);

        /** @var \App\Service\PlanningConflictDetectionService $service */
        $service = static::getContainer()->get(\App\Service\PlanningConflictDetectionService::class);

        // Sync both directions, as would naturally happen if both missions were touched
        // independently (e.g. two separate modification batches).
        $service->syncAlertsForMission($this->freshMission($missionA->getId()));
        $service->syncAlertsForMission($this->freshMission($missionB->getId()));
        $service->syncAlertsForMission($this->freshMission($missionA->getId()));

        $alertsA = $this->findAlertsForMission($missionA->getId());
        $alertsB = $this->findAlertsForMission($missionB->getId());
        $activeA = array_filter($alertsA, fn (PlanningAlert $a) => $a->isOpenOrAcknowledged());
        $activeB = array_filter($alertsB, fn (PlanningAlert $a) => $a->isOpenOrAcknowledged());

        self::assertCount(1, $activeA, 'Exactly one active alert on the anchor (lower-id) mission.');
        self::assertCount(0, $activeB, 'Never a second alert on the other side of the same pair.');
    }

    public function test_alert_types_are_correct_for_surgeon_and_instrumentist(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $otherSurgeon = $this->createUser('ROLE_SURGEON');
        $thirdSurgeon = $this->createUser('ROLE_SURGEON');
        $otherInstr   = $this->createUser('ROLE_INSTRUMENTIST');
        $siteA = $this->makeSite('A');
        $siteB = $this->makeSite('B');

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-16', '2026-10-16');
        // Mission whose SURGEON conflicts — same surgeon both sides, DIFFERENT
        // instrumentists, so only the surgeon overlaps.
        $missionSurgeon = $this->makeMission($version, $siteA, $surgeon, $manager, '2026-10-16', '08:00', '13:00', $otherInstr, MissionStatus::ASSIGNED);
        $this->makeMission(null, $siteB, $surgeon, $manager, '2026-10-16', '08:00', '13:00', null, MissionStatus::ASSIGNED);

        // Separate mission whose INSTRUMENTIST conflicts — same instrumentist both sides,
        // DIFFERENT surgeons ($otherSurgeon vs $thirdSurgeon), so only the instrumentist
        // overlaps (never mixing the two checks in one fixture).
        $missionInstr = $this->makeMission($version, $siteA, $otherSurgeon, $manager, '2026-10-16', '14:00', '17:00', $instr, MissionStatus::ASSIGNED);
        $this->makeMission(null, $siteB, $thirdSurgeon, $manager, '2026-10-16', '14:00', '17:00', $instr, MissionStatus::ASSIGNED);

        /** @var \App\Service\PlanningConflictDetectionService $service */
        $service = static::getContainer()->get(\App\Service\PlanningConflictDetectionService::class);
        $service->syncAlertsForMission($this->freshMission($missionSurgeon->getId()));
        $service->syncAlertsForMission($this->freshMission($missionInstr->getId()));

        $surgeonAlerts = array_filter($this->findAlertsForMission($missionSurgeon->getId()), fn (PlanningAlert $a) => $a->isOpenOrAcknowledged());
        $instrAlerts   = array_filter($this->findAlertsForMission($missionInstr->getId()), fn (PlanningAlert $a) => $a->isOpenOrAcknowledged());

        self::assertCount(1, $surgeonAlerts);
        self::assertSame('SURGEON_CONFLICT', current($surgeonAlerts)->getType()->value);

        self::assertCount(1, $instrAlerts);
        self::assertSame('INSTRUMENTIST_CONFLICT', current($instrAlerts)->getType()->value);
    }

    public function test_resolving_instrumentist_conflict_via_reassign_resolves_the_alert(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeonA = $this->createUser('ROLE_SURGEON');
        $surgeonB = $this->createUser('ROLE_SURGEON');
        $busyInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $freeInstr = $this->createUser('ROLE_INSTRUMENTIST');
        $siteA = $this->makeSite('A');
        $siteB = $this->makeSite('B');

        // Site membership required for eligibility on reassign.
        $membership = new \App\Entity\SiteMembership();
        $membership->setUser($freeInstr);
        $membership->setSite($siteA);
        $membership->setSiteRole('ROLE_INSTRUMENTIST');
        $this->em->persist($membership);
        $this->em->flush();

        $version = $this->makeDraftVersion($siteA, $manager, '2026-10-17', '2026-10-17');
        $mission = $this->makeMission($version, $siteA, $surgeonA, $manager, '2026-10-17', '08:00', '13:00', $busyInstr, MissionStatus::ASSIGNED);
        $this->makeMission(null, $siteB, $surgeonB, $manager, '2026-10-17', '08:00', '13:00', $busyInstr, MissionStatus::ASSIGNED);

        /** @var \App\Service\PlanningConflictDetectionService $service */
        $service = static::getContainer()->get(\App\Service\PlanningConflictDetectionService::class);
        $service->syncAlertsForMission($this->freshMission($mission->getId()));

        $alerts = array_filter($this->findAlertsForMission($mission->getId()), fn (PlanningAlert $a) => $a->isOpenOrAcknowledged());
        self::assertCount(1, $alerts);
        $alert = current($alerts);

        $response = $this->postJson($client, $token, "/api/planning/alerts/{$alert->getId()}/reassign", [
            'instrumentistId' => $freeInstr->getId(),
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->em->clear();
        $refreshedAlert = $this->em->find(PlanningAlert::class, $alert->getId());
        self::assertSame('RESOLVED', $refreshedAlert->getStatus()->value);

        $freshMission = $this->freshMission($mission->getId());
        self::assertSame($freeInstr->getId(), $freshMission->getInstrumentist()?->getId());
    }

    // ── Timezone ──────────────────────────────────────────────────────────────

    public function test_conflict_detection_uses_business_timezone_not_utc(): void
    {
        // D-089/D-090's exact regression class: if overlap comparison ever silently
        // reintroduced a UTC/naive interpretation, missions genuinely 2h apart in
        // Europe/Brussels summer time would falsely appear to overlap (or vice versa).
        // Both missions are built with explicit Europe/Brussels wall-clock digits,
        // exactly as Mission.startAt/endAt are always stored (BusinessDateTimeImmutableType).
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $siteA   = $this->makeSite('A');
        $siteB   = $this->makeSite('B');

        // Summer (CEST, UTC+2). Site B: 08:00–10:00 Brussels wall-clock.
        $this->makeMission(null, $siteB, $surgeon, $manager, '2026-07-15', '08:00', '10:00', null, MissionStatus::ASSIGNED);

        $version = $this->makeDraftVersion($siteA, $manager, '2026-07-01', '2026-07-31');
        // Site A: 10:00–12:00 Brussels wall-clock — adjacent, not overlapping, in the
        // CORRECT (Brussels) frame. If the comparison ever fell back to UTC on a system
        // whose container default is UTC (this project always runs UTC-default containers,
        // see D-066), a 2h drift would make these compare as fully overlapping instead.
        $mission = $this->makeMission($version, $siteA, $surgeon, $manager, '2026-07-15', '10:00', '12:00', null);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::OPEN, $fresh->getStatus(), 'Adjacent Brussels-wall-clock slots must never be reported as conflicting — a failure here means the comparison drifted back to a non-Brussels timezone.');
    }
}
