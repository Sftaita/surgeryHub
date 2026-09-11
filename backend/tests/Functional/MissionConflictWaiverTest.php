<?php

namespace App\Tests\Functional;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionConflictWaiver;
use App\Entity\NotificationEvent;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-091 follow-up — a manager's explicit, per-pair authorization to deploy despite a
 * CROSS_SITE_CONFLICT, for exactly the "surgeon running two rooms of the same site,
 * sharing one floating instrumentist" shape (same surgeon + same instrumentist + same
 * site + overlapping hours). Everything else (different surgeon, different site, ABSENCE)
 * stays unconditionally blocking — see MissionConflictWaiverService's own docblock.
 */
final class MissionConflictWaiverTest extends WebTestCase
{
    private const PASSWORD = 'Waiver91!';

    private EntityManagerInterface $em;
    private array $createdMissionIds = [];
    private array $createdUserIds    = [];
    private array $createdSiteIds    = [];
    private array $createdVersionIds = [];
    private array $createdWaiverIds  = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdWaiverIds as $id) {
                $w = $this->em->find(MissionConflictWaiver::class, $id);
                if ($w !== null) { $this->em->remove($w); }
            }
            $this->em->flush();

            foreach (array_unique($this->createdMissionIds) as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

            foreach ($this->createdVersionIds as $id) {
                $e = $this->em->find(PlanningVersion::class, $id);
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
        $u->setEmail('waiver91-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Waiver91-' . $label . '-' . bin2hex(random_bytes(3)));
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

    private function deploy(KernelBrowser $client, string $token, PlanningVersion $version): Response
    {
        return $this->postJson($client, $token, '/api/planning/v2/deploy', [
            'planningVersionId' => $version->getId(),
        ]);
    }

    private function authorize(KernelBrowser $client, string $token, array $pairs, ?string $reason = null): Response
    {
        return $this->postJson($client, $token, '/api/planning/v2/conflicts/authorize', array_filter([
            'conflicts' => $pairs,
            'reason'    => $reason,
        ], static fn ($v) => $v !== null));
    }

    private function freshMission(int $id): Mission
    {
        $this->em->clear();
        $m = $this->em->find(Mission::class, $id);
        self::assertNotNull($m);
        return $m;
    }

    private function trackWaiversFor(int $missionId): void
    {
        foreach ($this->em->getRepository(MissionConflictWaiver::class)->findBy(['missionLow' => $missionId]) as $w) {
            $this->createdWaiverIds[] = $w->getId();
        }
        foreach ($this->em->getRepository(MissionConflictWaiver::class)->findBy(['missionHigh' => $missionId]) as $w) {
            $this->createdWaiverIds[] = $w->getId();
        }
    }

    // ── Rule 3/4 — same surgeon, same site: reported waivable, authorizing unblocks deploy ──

    #[WithoutErrorHandler]
    public function test_same_surgeon_same_instrumentist_same_site_conflict_is_reported_waivable(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite('Double');

        // Surgeon running two overlapping rooms of the SAME site, same floating instrumentist.
        $this->makeMission(null, $site, $surgeon, $manager, '2026-11-02', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);
        $version = $this->makeDraftVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-02', '10:00', '14:00', $instr);

        $response = $this->deploy($client, $token, $version);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getContent());
        $body     = json_decode((string) $response->getContent(), true);
        $conflict = current(array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $mission->getId()));
        self::assertNotFalse($conflict);
        self::assertSame('CROSS_SITE_CONFLICT', $conflict['type']);
        self::assertTrue($conflict['waivable'], 'Same surgeon + same instrumentist + same site must be reported as waivable.');
    }

    #[WithoutErrorHandler]
    public function test_authorizing_a_waivable_conflict_unblocks_deploy(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite('Double');

        $existing = $this->makeMission(null, $site, $surgeon, $manager, '2026-11-03', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);
        $version  = $this->makeDraftVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission  = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-03', '10:00', '14:00', $instr);

        $blocked = $this->deploy($client, $token, $version);
        self::assertSame(409, $blocked->getStatusCode());

        $authResponse = $this->authorize($client, $token, [
            ['missionId' => $mission->getId(), 'conflictingMissionId' => $existing->getId()],
        ], 'Double salle validée par le chef de bloc.');
        self::assertSame(200, $authResponse->getStatusCode(), (string) $authResponse->getContent());
        $authBody = json_decode((string) $authResponse->getContent(), true);
        self::assertCount(1, $authBody['authorized']);
        self::assertCount(0, $authBody['failed']);
        $this->trackWaiversFor($mission->getId());

        $deployed = $this->deploy($client, $token, $version);
        self::assertSame(200, $deployed->getStatusCode(), (string) $deployed->getContent());
        $fresh = $this->freshMission($mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $fresh->getStatus());

        $waiver = $this->em->getRepository(MissionConflictWaiver::class)->findOneBy(['missionHigh' => $mission->getId()]) ?? $this->em->getRepository(MissionConflictWaiver::class)->findOneBy(['missionLow' => $mission->getId()]);
        self::assertNotNull($waiver, 'The authorization must be persisted.');
        self::assertSame($manager->getId(), $waiver->getAuthorizedBy()->getId());
        self::assertSame('Double salle validée par le chef de bloc.', $waiver->getReason());
        self::assertTrue($waiver->isActive());
    }

    // ── Rule 5 — different surgeon on the two missions: never waivable ──────────

    #[WithoutErrorHandler]
    public function test_authorize_refuses_when_surgeons_differ(): void
    {
        $client    = $this->boot();
        $manager   = $this->createUser('ROLE_MANAGER');
        $token     = $this->login($client, $manager);
        $surgeonA  = $this->createUser('ROLE_SURGEON');
        $surgeonB  = $this->createUser('ROLE_SURGEON');
        $instr     = $this->createUser('ROLE_INSTRUMENTIST');
        $site      = $this->makeSite('Same');

        $existing = $this->makeMission(null, $site, $surgeonB, $manager, '2026-11-04', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);
        $version  = $this->makeDraftVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission  = $this->makeMission($version, $site, $surgeonA, $manager, '2026-11-04', '10:00', '14:00', $instr);

        $blocked = $this->deploy($client, $token, $version);
        $body    = json_decode((string) $blocked->getContent(), true);
        $conflict = current(array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $mission->getId()));
        self::assertFalse($conflict['waivable'], 'Different surgeons must never be reported as waivable.');

        $authResponse = $this->authorize($client, $token, [
            ['missionId' => $mission->getId(), 'conflictingMissionId' => $existing->getId()],
        ]);
        self::assertSame(200, $authResponse->getStatusCode());
        $authBody = json_decode((string) $authResponse->getContent(), true);
        self::assertCount(0, $authBody['authorized']);
        self::assertCount(1, $authBody['failed']);
        self::assertStringContainsString('Chirurgiens différents', $authBody['failed'][0]['reason']);

        // Still blocked — nothing was authorized.
        $stillBlocked = $this->deploy($client, $token, $version);
        self::assertSame(409, $stillBlocked->getStatusCode());
    }

    // ── Rule 5 (site variant) — same surgeon+instrumentist but different sites: never waivable ──

    #[WithoutErrorHandler]
    public function test_authorize_refuses_when_sites_differ_despite_same_surgeon(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $siteA    = $this->makeSite('A');
        $siteB    = $this->makeSite('B');

        $existing = $this->makeMission(null, $siteB, $surgeon, $manager, '2026-11-05', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);
        $version  = $this->makeDraftVersion($siteA, $manager, '2026-11-01', '2026-11-30');
        $mission  = $this->makeMission($version, $siteA, $surgeon, $manager, '2026-11-05', '10:00', '14:00', $instr);

        $blocked = $this->deploy($client, $token, $version);
        $body    = json_decode((string) $blocked->getContent(), true);
        $conflict = current(array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $mission->getId()));
        self::assertFalse($conflict['waivable'], 'Same surgeon but different sites must stay non-waivable — a real cross-site double-booking, not a double-room case.');

        $authResponse = $this->authorize($client, $token, [
            ['missionId' => $mission->getId(), 'conflictingMissionId' => $existing->getId()],
        ]);
        $authBody = json_decode((string) $authResponse->getContent(), true);
        self::assertCount(0, $authBody['authorized']);
        self::assertStringContainsString('Sites différents', $authBody['failed'][0]['reason']);
    }

    // ── Rule 8 — a later change to either mission invalidates the waiver ────────

    #[WithoutErrorHandler]
    public function test_waiver_is_invalidated_when_instrumentist_changes_afterward(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instrA   = $this->createUser('ROLE_INSTRUMENTIST');
        $instrB   = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite('Double');

        $existing = $this->makeMission(null, $site, $surgeon, $manager, '2026-11-06', '08:00', '13:00', $instrA, MissionStatus::ASSIGNED);
        $version  = $this->makeDraftVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission  = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-06', '10:00', '14:00', $instrA);

        $authResponse = $this->authorize($client, $token, [
            ['missionId' => $mission->getId(), 'conflictingMissionId' => $existing->getId()],
        ]);
        self::assertSame(1, count(json_decode((string) $authResponse->getContent(), true)['authorized']));
        $this->trackWaiversFor($mission->getId());

        // Still DRAFT — the manager reassigns this mission to a different instrumentist
        // (e.g. picked someone else in the editor) BEFORE ever deploying. The waiver was
        // granted for instrA on both sides; it must not silently keep applying to instrB.
        // freshMission() clears the EM, so instrB must be re-fetched too — otherwise
        // Doctrine sees it as a new, unmanaged entity on flush().
        $freshMission = $this->freshMission($mission->getId());
        $freshInstrB  = $this->em->find(User::class, $instrB->getId());
        $freshMission->setInstrumentist($freshInstrB);
        $this->em->flush();

        $response = $this->deploy($client, $token, $version);
        self::assertSame(409, $response->getStatusCode(), 'The waiver was granted for a specific instrumentist pairing — it must not survive one side changing instrumentist.');
        $body = json_decode((string) $response->getContent(), true);
        $conflict = current(array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $mission->getId()));
        self::assertNotFalse($conflict);
        self::assertFalse($conflict['waivable'], 'Now a genuinely different-instrumentist conflict — no longer the waivable shape.');

        $waiver = $this->em->getRepository(MissionConflictWaiver::class)->findOneBy(
            ['missionLow' => min($mission->getId(), $existing->getId()), 'missionHigh' => max($mission->getId(), $existing->getId())],
            ['authorizedAt' => 'DESC'],
        );
        self::assertNotNull($waiver);
        self::assertFalse($waiver->isActive(), 'Changing the waived mission\'s instrumentist must invalidate the original waiver.');
        self::assertNotNull($waiver->getInvalidatedReason());
    }

    // ── Rule 2 — "tout autoriser d'un coup": one batch call, mixed success/failure ──

    #[WithoutErrorHandler]
    public function test_bulk_authorize_processes_each_pair_independently(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $surgeonOther = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite('Bulk');

        // Waivable pair.
        $existingA = $this->makeMission(null, $site, $surgeon, $manager, '2026-11-07', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);
        $version   = $this->makeDraftVersion($site, $manager, '2026-11-01', '2026-11-30');
        $missionA  = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-07', '10:00', '14:00', $instr);

        // Non-waivable pair (different surgeon), same version.
        $existingB = $this->makeMission(null, $site, $surgeonOther, $manager, '2026-11-07', '15:00', '18:00', $instr, MissionStatus::ASSIGNED);
        $missionB  = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-07', '16:00', '19:00', $instr);

        $authResponse = $this->authorize($client, $token, [
            ['missionId' => $missionA->getId(), 'conflictingMissionId' => $existingA->getId()],
            ['missionId' => $missionB->getId(), 'conflictingMissionId' => $existingB->getId()],
        ]);
        self::assertSame(200, $authResponse->getStatusCode());
        $authBody = json_decode((string) $authResponse->getContent(), true);
        self::assertCount(1, $authBody['authorized'], 'Exactly the waivable pair must succeed.');
        self::assertCount(1, $authBody['failed'], 'The non-waivable pair must fail without blocking the other one.');
        $this->trackWaiversFor($missionA->getId());

        // Deploy still blocked — one conflict (missionB) was never authorized.
        $response = $this->deploy($client, $token, $version);
        self::assertSame(409, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $remaining = array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $missionB->getId());
        self::assertNotEmpty($remaining, 'missionB\'s conflict must still be reported — it was never authorized.');
        $goneA = array_filter($body['conflicts'], fn ($c) => $c['missionId'] === $missionA->getId());
        self::assertEmpty($goneA, 'missionA\'s conflict must be gone — it was successfully authorized.');
    }

    // ── Regression — deleting a draft must never leave a dangling waiver row ────

    #[WithoutErrorHandler]
    public function test_deleting_a_draft_with_an_authorized_waiver_cleans_up_the_waiver_row(): void
    {
        // Found live (browser walkthrough): authorizing a real double-room conflict on a
        // draft, then deleting that draft, hit a raw ForeignKeyConstraintViolationException
        // — mission_conflict_waiver.mission_low_id/mission_high_id have no ON DELETE CASCADE,
        // and PlanningDraftService::delete()'s CLEANUP_ON_MISSION_DELETE list didn't know
        // about this entity (it doesn't fit that list's single-`mission`-field shape anyway,
        // since a waiver is about a *pair*). Exactly the same failure mode
        // CLEANUP_ON_MISSION_DELETE's own docblock already documents for planning_alert.
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr    = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite('DeleteRegression');

        $existing = $this->makeMission(null, $site, $surgeon, $manager, '2026-11-20', '08:00', '13:00', $instr, MissionStatus::ASSIGNED);
        $version  = $this->makeDraftVersion($site, $manager, '2026-11-01', '2026-11-30');
        $mission  = $this->makeMission($version, $site, $surgeon, $manager, '2026-11-20', '10:00', '14:00', $instr);

        $authResponse = $this->authorize($client, $token, [
            ['missionId' => $mission->getId(), 'conflictingMissionId' => $existing->getId()],
        ]);
        self::assertSame(200, $authResponse->getStatusCode());
        $waiverId = json_decode((string) $authResponse->getContent(), true)['authorized'][0]['waiverId'];

        $response = $this->deleteJson($client, $token, "/api/planning/versions/{$version->getId()}");
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

        $this->em->clear();
        self::assertNull($this->em->find(PlanningVersion::class, $version->getId()));
        self::assertNull($this->em->find(Mission::class, $mission->getId()));
        self::assertNull($this->em->find(MissionConflictWaiver::class, $waiverId), 'The waiver row must be cleaned up along with the mission it referenced.');
        // The OTHER side of the pair (an already-ASSIGNED mission outside this draft) is
        // untouched — deleting a draft must never reach outside its own missions.
        self::assertNotNull($this->em->find(Mission::class, $existing->getId()));
        // No manual untracking needed: tearDown()'s find()-then-remove-if-not-null pattern
        // already no-ops safely for $version/$mission's ids, which the API call above has
        // already deleted.
    }

    private function deleteJson(KernelBrowser $client, string $token, string $uri): Response
    {
        $client->request('DELETE', $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        return $client->getResponse();
    }
}
