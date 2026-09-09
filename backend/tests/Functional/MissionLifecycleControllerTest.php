<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionClaim;
use App\Entity\User;
use App\Enum\EmploymentType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional HTTP tests for Batch 15B — post-deploy mission lifecycle.
 * Covers: release, cancel, reassign, and the migrated claim endpoint.
 */
final class MissionLifecycleControllerTest extends WebTestCase
{
    private const PASSWORD = 'Lifecycle15B!';

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

    /** Returns a KernelBrowser and initialises $this->em. */
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
        $u->setEmail('b15b-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('B15B-' . bin2hex(random_bytes(3)));
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

    /**
     * FREELANCER bypasses MissionEligibilityService's site-membership check (RC1-C) —
     * lets the claim() tests below exercise real eligibility/DB round trips without also
     * having to fixture a SiteMembership for every instrumentist.
     */
    private function createFreelancerInstrumentist(): User
    {
        $u = $this->createUser('ROLE_INSTRUMENTIST');
        $u->setEmploymentType(EmploymentType::FREELANCER);
        $this->em->flush();
        return $u;
    }

    // ── release ───────────────────────────────────────────────────────────────

    public function test_release_on_assigned_mission_returns_200_with_status_open(): void
    {
        $client      = $this->boot();
        $manager     = $this->createUser('ROLE_MANAGER');
        $token       = $this->login($client, $manager);
        $surgeon     = $this->createUser('ROLE_SURGEON');
        $instr       = $this->createUser('ROLE_INSTRUMENTIST');
        $site        = $this->makeSite();
        $mission     = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/release');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('OPEN', $body['status'] ?? null, json_encode($body));
    }

    public function test_release_creates_audit_event_in_db(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/release');

        $this->em->clear();
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertNotEmpty($events, 'AuditEvent must be created on release');
    }

    public function test_release_on_open_mission_returns_409(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/release');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function test_release_returns_403_for_instrumentist(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $instrToken = $this->login($client, $instr);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $response = $this->postJson($client, $instrToken, '/api/missions/' . $mission->getId() . '/release');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_on_open_mission_returns_200_with_status_cancelled(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/cancel', ['reason' => 'Chirurgien absent']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('CANCELLED', $body['status'] ?? null, json_encode($body));
    }

    public function test_cancel_creates_audit_event_in_db(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/cancel');

        $this->em->clear();
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertNotEmpty($events, 'AuditEvent must be created on cancel');
    }

    /**
     * REGRESSION (feature added after this test was first written): MissionPostDeployService
     * ::cancel() originally only accepted OPEN, so cancelling an ASSIGNED mission via this
     * endpoint returned 409. Extended to also accept ASSIGNED (AbsenceMissionReactionService
     * needs it for surgeon-absence cancellation) — the guard is shared, single-source-of-truth
     * logic, so the general manager-facing endpoint gains the same capability as a deliberate
     * side effect: cancelling an ASSIGNED mission is not itself dangerous, it now also clears
     * the instrumentist as part of the transition.
     */
    public function test_cancel_on_assigned_mission_succeeds_and_clears_instrumentist(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/cancel');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $reloaded->getStatus());
        self::assertNull($reloaded->getInstrumentist());
    }

    public function test_get_mission_after_cancel_shows_cancelled_status(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/cancel');

        $client->request('GET', '/api/missions/' . $mission->getId(),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('CANCELLED', $body['status'] ?? null);
    }

    // ── reassign ──────────────────────────────────────────────────────────────

    public function test_reassign_on_assigned_mission_returns_200(): void
    {
        $client   = $this->boot();
        $manager  = $this->createUser('ROLE_MANAGER');
        $token    = $this->login($client, $manager);
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $instr1   = $this->createUser('ROLE_INSTRUMENTIST');
        $instr2   = $this->createUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $mission  = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr1);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/reassign', [
            'instrumentistId' => $instr2->getId(),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $response->getContent());
        $body = json_decode($response->getContent(), true);
        self::assertSame('ASSIGNED', $body['status'] ?? null, json_encode($body));
    }

    public function test_reassign_creates_audit_event_in_db(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr1  = $this->createUser('ROLE_INSTRUMENTIST');
        $instr2  = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instr1);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/reassign', [
            'instrumentistId' => $instr2->getId(),
        ]);

        $this->em->clear();
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        self::assertNotEmpty($events, 'AuditEvent must be created on reassign');
    }

    public function test_reassign_on_open_mission_returns_409(): void
    {
        $client  = $this->boot();
        $manager = $this->createUser('ROLE_MANAGER');
        $token   = $this->login($client, $manager);
        $surgeon = $this->createUser('ROLE_SURGEON');
        $instr   = $this->createUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/reassign', [
            'instrumentistId' => $instr->getId(),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    // ── D-056 compliance ──────────────────────────────────────────────────────

    public function test_mission_controller_has_no_direct_em_persist_for_mission(): void
    {
        $path   = dirname(__DIR__, 2) . '/src/Controller/Api/MissionController.php';
        $source = file_get_contents($path);

        self::assertDoesNotMatchRegularExpression(
            '/em->persist\s*\(\s*\$mission\b/',
            $source,
            'D-056 violation: MissionController must not call em->persist($mission) — all mutations go through MissionPostDeployService',
        );
    }

    // ── Finition espace chirurgien — robustesse routage (2026-08-09) ──────────
    // Découvert lors d'une revue manuelle : `/api/missions/{id}` et
    // `/api/missions/{id}/encoding` n'avaient pas `requirements: ['id' => '\d+']`
    // (contrairement à d'autres controllers). Un id non numérique faisait matcher
    // getOne(int $id)/getEncoding(int $id) quand même, PHP levait alors un TypeError
    // non rattrapé (500, trace d'exception exposée au client) au lieu d'une 404
    // propre. Jamais atteignable via l'app (le frontend garde toujours
    // Number.isFinite(id) avant tout appel), mais un client externe/URL malformée
    // pouvait déclencher la fuite — corrigé en ajoutant la contrainte de route.

    public function test_get_mission_with_non_numeric_id_never_returns_a_raw_500(): void
    {
        $client = $this->boot();
        $user = $this->createUser('ROLE_SURGEON');
        $token = $this->login($client, $user);

        $client->request('GET', '/api/missions/not-a-number',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertNotSame(500, $client->getResponse()->getStatusCode());
    }

    public function test_get_mission_encoding_with_non_numeric_id_never_returns_a_raw_500(): void
    {
        $client = $this->boot();
        $user = $this->createUser('ROLE_SURGEON');
        $token = $this->login($client, $user);

        $client->request('GET', '/api/missions/not-a-number/encoding',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertNotSame(500, $client->getResponse()->getStatusCode());
    }

    // ── claim (BUG B, D-115bis) ───────────────────────────────────────────────
    //
    // This file's own docblock has claimed to cover "the migrated claim endpoint" since
    // Batch 15B — it never did. The only test ever written for claim() lives in
    // MissionPostDeployServiceTest (unit), which mocks EntityManagerInterface::getRepository()
    // entirely — MissionClaim::findOneBy() always resolved to the mock's default `null`
    // return, so a real, persisted, historical MissionClaim row (exactly what a genuine
    // claim→release cycle leaves behind) was never exercised. These are the first tests
    // to hit the real HTTP endpoint against a real database for claim().

    public function test_claim_on_open_mission_succeeds(): void
    {
        $client  = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $manager = $this->createUser('ROLE_MANAGER');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);
        $instr   = $this->createFreelancerInstrumentist();
        $token   = $this->login($client, $instr);

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/claim');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $reloaded->getStatus());
        self::assertSame($instr->getId(), $reloaded->getInstrumentist()?->getId());
        self::assertCount(1, $this->em->getRepository(MissionClaim::class)->findBy(['mission' => $mission->getId()]));
    }

    /**
     * "Concurrent" claim, as far as a single-threaded functional test can express it:
     * two sequential requests for the same mission, only the first must win. The second
     * gets 403 (not 409) — MissionVoter::canClaim() denies access outright once the
     * mission is no longer OPEN, before the request ever reaches
     * MissionPostDeployService::claim()'s own 409 guards. Pre-existing, correct behavior
     * (OffersPage.tsx's handleClaim() branches on exactly this 403 to show "Accès
     * refusé" and navigate away) — not part of BUG B, asserted here only so the
     * anti-double-claim invariant itself has a real HTTP-level test.
     */
    public function test_claim_by_second_instrumentist_after_first_claim_returns_403(): void
    {
        $client  = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $manager = $this->createUser('ROLE_MANAGER');
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);
        $instrA  = $this->createFreelancerInstrumentist();
        $instrB  = $this->createFreelancerInstrumentist();
        $tokenA  = $this->login($client, $instrA);
        $tokenB  = $this->login($client, $instrB);

        $first  = $this->postJson($client, $tokenA, '/api/missions/' . $mission->getId() . '/claim');
        $second = $this->postJson($client, $tokenB, '/api/missions/' . $mission->getId() . '/claim');

        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame(Response::HTTP_FORBIDDEN, $second->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame($instrA->getId(), $reloaded->getInstrumentist()?->getId(), 'only the first claimant wins');
    }

    /**
     * The RED test (BUG B, primary scenario): OPEN → A claims → ASSIGNED → manager
     * releases → OPEN, instrumentist NULL, but the MissionClaim row from A's claim is
     * never deleted (it is an append-only historical record — see docs/decisions.md).
     * B must still be able to claim it. Before the fix, MissionPostDeployService::claim()
     * consulted that leftover row as a business guard and refused with 409 "Mission
     * already claimed" even though the mission was genuinely OPEN and unassigned.
     */
    public function test_claim_after_release_by_different_instrumentist_succeeds(): void
    {
        $client  = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);
        $instrA  = $this->createFreelancerInstrumentist();
        $instrB  = $this->createFreelancerInstrumentist();
        $tokenA  = $this->login($client, $instrA);

        // 1. A claims.
        $claimResponse = $this->postJson($client, $tokenA, '/api/missions/' . $mission->getId() . '/claim');
        self::assertSame(Response::HTTP_OK, $claimResponse->getStatusCode(), (string) $claimResponse->getContent());

        $this->em->clear();
        $afterClaim = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $afterClaim->getStatus());
        self::assertSame($instrA->getId(), $afterClaim->getInstrumentist()?->getId());
        self::assertCount(1, $this->em->getRepository(MissionClaim::class)->findBy(['mission' => $mission->getId()]));

        // 2. Manager releases — ASSIGNED → OPEN, instrumentist cleared, MissionClaim
        //    history from step 1 deliberately left untouched (append-only, D-059).
        $releaseResponse = $this->postJson($client, $managerToken, '/api/missions/' . $mission->getId() . '/release');
        self::assertSame(Response::HTTP_OK, $releaseResponse->getStatusCode(), (string) $releaseResponse->getContent());

        $this->em->clear();
        $afterRelease = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $afterRelease->getStatus());
        self::assertNull($afterRelease->getInstrumentist());
        self::assertCount(1, $this->em->getRepository(MissionClaim::class)->findBy(['mission' => $mission->getId()]), 'the historical claim row must survive release()');

        // 3. B claims the same, now-genuinely-OPEN mission. This is the assertion that
        //    fails before the fix (409 "Mission already claimed").
        $tokenB = $this->login($client, $instrB);
        $secondClaim = $this->postJson($client, $tokenB, '/api/missions/' . $mission->getId() . '/claim');
        self::assertSame(Response::HTTP_OK, $secondClaim->getStatusCode(), (string) $secondClaim->getContent());

        $this->em->clear();
        $final = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $final->getStatus());
        self::assertSame($instrB->getId(), $final->getInstrumentist()?->getId());

        // Both the original (A) and the new (B) MissionClaim rows must exist — history
        // is additive, never mutated or deleted by a later claim.
        $claims = $this->em->getRepository(MissionClaim::class)->findBy(['mission' => $mission->getId()]);
        self::assertCount(2, $claims);
        $claimantIds = array_map(fn (MissionClaim $c) => $c->getInstrumentist()?->getId(), $claims);
        self::assertContains($instrA->getId(), $claimantIds);
        self::assertContains($instrB->getId(), $claimantIds);

        // Audit trail: CLAIM, RELEASE, CLAIM, in order.
        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()], ['createdAt' => 'ASC', 'id' => 'ASC']);
        $eventTypes = array_map(fn (AuditEvent $e) => $e->getEventType()->value, $events);
        self::assertSame(['MISSION_CLAIMED_FROM_POOL', 'MISSION_RELEASED_TO_POOL', 'MISSION_CLAIMED_FROM_POOL'], $eventTypes);
    }

    /** B4 — the SAME instrumentist reclaiming after their own release must also succeed. */
    public function test_claim_after_release_by_same_instrumentist_succeeds(): void
    {
        $client  = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $manager = $this->createUser('ROLE_MANAGER');
        $managerToken = $this->login($client, $manager);
        $site    = $this->makeSite();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);
        $instr   = $this->createFreelancerInstrumentist();
        $token   = $this->login($client, $instr);

        $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/claim');
        $this->postJson($client, $managerToken, '/api/missions/' . $mission->getId() . '/release');

        $response = $this->postJson($client, $token, '/api/missions/' . $mission->getId() . '/claim');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $reloaded->getStatus());
        self::assertSame($instr->getId(), $reloaded->getInstrumentist()?->getId());
        self::assertCount(2, $this->em->getRepository(MissionClaim::class)->findBy(['mission' => $mission->getId()]));
    }

    /**
     * B5 — a mission that is currently ASSIGNED must still refuse a claim (for the
     * genuinely correct reason: incompatible status), regardless of whether a MissionClaim
     * history row also happens to exist. Proves the fix didn't just delete a guard without
     * replacing it with the real one already provided by MissionVoter::canClaim() (the
     * status check there denies access before the request ever reaches the service).
     */
    public function test_claim_refused_when_mission_assigned_despite_claim_history(): void
    {
        $client   = $this->boot();
        $surgeon  = $this->createUser('ROLE_SURGEON');
        $manager  = $this->createUser('ROLE_MANAGER');
        $site     = $this->makeSite();
        $instrA   = $this->createFreelancerInstrumentist();
        $instrB   = $this->createFreelancerInstrumentist();
        $mission  = $this->makeMission($site, $surgeon, $manager, MissionStatus::ASSIGNED, $instrA);

        // Historical claim row from an earlier, unrelated cycle — must never leak into
        // this decision.
        $claim = new MissionClaim();
        $claim->setMission($mission)->setInstrumentist($instrA)->setClaimedAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($claim);
        $this->em->flush();

        $tokenB = $this->login($client, $instrB);
        $response = $this->postJson($client, $tokenB, '/api/missions/' . $mission->getId() . '/claim');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $reloaded->getStatus());
        self::assertSame($instrA->getId(), $reloaded->getInstrumentist()?->getId(), 'unchanged');
    }

    /**
     * B6 — a mission that is OPEN but the candidate is genuinely ineligible (absent) must
     * be refused for that real eligibility reason — never a stale "already claimed" from
     * an unrelated historical claim row on the same mission.
     */
    public function test_claim_refused_for_ineligibility_not_claim_history_when_open(): void
    {
        $client  = $this->boot();
        $surgeon = $this->createUser('ROLE_SURGEON');
        $manager = $this->createUser('ROLE_MANAGER');
        $site    = $this->makeSite();
        $instrA  = $this->createFreelancerInstrumentist();
        $instrB  = $this->createFreelancerInstrumentist();
        $mission = $this->makeMission($site, $surgeon, $manager, MissionStatus::OPEN);

        // Unrelated historical claim (e.g. instrA claimed and was released earlier).
        $claim = new MissionClaim();
        $claim->setMission($mission)->setInstrumentist($instrA)->setClaimedAt(new \DateTimeImmutable('-1 day'));
        $this->em->persist($claim);

        $absence = new \App\Entity\Absence();
        $absence->setUser($instrB);
        $absence->setCreatedBy($manager);
        $absence->setDateStart($mission->getStartAt());
        $absence->setDateEnd($mission->getStartAt());
        $this->em->persist($absence);
        $this->em->flush();
        $absenceId = $absence->getId();

        $tokenB = $this->login($client, $instrB);
        $response = $this->postJson($client, $tokenB, '/api/missions/' . $mission->getId() . '/claim');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('Absent', (string) $response->getContent(), (string) $response->getContent());
        self::assertStringNotContainsString('already claimed', (string) $response->getContent());

        // BUG A (§18) — the frontend must never parse the free-text message to decide
        // whether to offer "Retirer mon absence pour ce jour"; it needs structured fields.
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('MISSION_CLAIM_INELIGIBLE', $body['error']['code'] ?? null, (string) $response->getContent());
        self::assertSame('ABSENT', $body['error']['reason'] ?? null, (string) $response->getContent());
        self::assertSame($mission->getStartAt()->format('Y-m-d'), $body['error']['date'] ?? null);
        self::assertSame($absenceId, $body['error']['absenceId'] ?? null);
        self::assertSame($mission->getStartAt()->format('Y-m-d'), $body['error']['absenceDateStart'] ?? null);
        self::assertSame($mission->getStartAt()->format('Y-m-d'), $body['error']['absenceDateEnd'] ?? null);

        // login()'s HTTP request detaches everything from $this->em — re-fetch before removing.
        $absence = $this->em->find(\App\Entity\Absence::class, $absenceId);

        $this->em->remove($absence);
        $this->em->flush();
    }
}
