<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningAlert;
use App\Entity\PlanningVersion;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\PlanningVersionStatus;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CAS D (D-115) — real-HTTP tests for reopening, editing, and deleting an already-
 * persisted Planning V2 draft. Fixture pattern copied from PlanningV2GenerationControllerTest
 * (Batch 9) — same helpers, same teardown discipline.
 */
final class PlanningV2DraftControllerTest extends WebTestCase
{
    private const PASSWORD = 'CasDTest123!';
    private const YEAR     = 2026;
    private const MONTH    = 11;

    private EntityManagerInterface $em;
    private array $createdIds = [
        'versions' => [], 'missions' => [], 'posts' => [], 'shiftPeriods' => [],
        'users' => [], 'sites' => [], 'absences' => [], 'alerts' => [],
    ];

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
            // Defensive only — the delete() call under test is expected to have already
            // removed these itself; this just guards against a failed assertion leaving
            // orphaned rows behind for the next test run.
            foreach ($this->createdIds['alerts'] as $id) {
                $e = $this->em->find(PlanningAlert::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
                foreach ($this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $id]) as $alert) {
                    $this->em->remove($alert);
                }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['versions'] as $id) {
                $e = $this->em->find(PlanningVersion::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['posts'] as $id) {
                $e = $this->em->find(SurgeonSchedulePost::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['shiftPeriods'] as $id) {
                $e = $this->em->find(ShiftPeriodConfig::class, $id);
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
    private function authenticate($client, string $role): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('casd-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function postJson($client, string $token, string $uri, array $body): Response
    {
        $client->request('POST', $uri, server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($body));
        return $client->getResponse();
    }

    private function patchJson($client, string $token, string $uri, array $body): Response
    {
        $client->request('PATCH', $uri, server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($body));
        return $client->getResponse();
    }

    private function getJson($client, string $token, string $uri): Response
    {
        $client->request('GET', $uri, server: $this->auth($token));
        return $client->getResponse();
    }

    private function deleteJson($client, string $token, string $uri): Response
    {
        $client->request('DELETE', $uri, server: $this->auth($token));
        return $client->getResponse();
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('casd-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('CasD Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        $this->createdIds['sites'][] = $h->getId();
        return $h;
    }

    private function addShiftConfig(Hospital $site, string $start, string $end): void
    {
        $c = new ShiftPeriodConfig();
        $c->setSite($site);
        $c->setPeriod(ShiftPeriod::MATIN);
        $c->setStartTime(new \DateTimeImmutable($start));
        $c->setEndTime(new \DateTimeImmutable($end));
        $this->em->persist($c);
        $this->em->flush();
        $this->createdIds['shiftPeriods'][] = $c->getId();
    }

    private function firstMondayOfTestMonth(): \DateTimeImmutable
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', self::YEAR, self::MONTH));
        $isoDay = (int) $first->format('N');
        return $isoDay === 1 ? $first : $first->modify('+' . (8 - $isoDay) . ' days');
    }

    /** $singleOccurrence caps the post to its anchor Monday only — avoids 4-5 Mondays/month when a test needs exactly one line/mission. */
    private function makePost(User $surgeon, Hospital $site, ?User $instrumentist = null, bool $singleOccurrence = false): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([1]);
        $rule->setAnchorDate($this->firstMondayOfTestMonth());

        $p = new SurgeonSchedulePost();
        $p->setSurgeon($surgeon);
        $p->setSite($site);
        $p->setType(MissionType::BLOCK);
        $p->setPeriod(ShiftPeriod::MATIN);
        $p->setRecurrence($rule);
        $p->setInstrumentist($instrumentist);
        $p->setStartDate(new \DateTimeImmutable(sprintf('%04d-%02d-01', self::YEAR, self::MONTH)));
        if ($singleOccurrence) {
            $p->setEndDate($this->firstMondayOfTestMonth());
        }
        $p->setCreatedBy($surgeon);
        $this->em->persist($p);
        $this->em->flush();
        $this->createdIds['posts'][] = $p->getId();
        return $p;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /**
     * CAS C (D-116) — a brand-new line as sent by "Ajouter" on a reopened DRAFT: never a
     * real Post (postId <= 0, per the editor's own negative-decrementing convention for a
     * manual add — see GeneratePlanningTab.tsx's nextDraftIdRef), no existingMissionId.
     */
    private function adHocLineFor(Hospital $site, User $surgeon, ?User $instrumentist, string $date): array
    {
        return [
            'date'                     => $date,
            'postId'                   => -1,
            'surgeonId'                => $surgeon->getId(),
            'surgeonName'              => '',
            'missionType'              => MissionType::BLOCK->value,
            'startTime'                => '08:00',
            'endTime'                  => '13:00',
            'siteId'                   => $site->getId(),
            'siteName'                 => '',
            'instrumentistId'          => $instrumentist?->getId(),
            'instrumentistName'        => null,
            'status'                   => $instrumentist !== null ? 'COVERED' : 'UNCOVERED',
            'existingMissionId'        => null,
            'existingInstrumentistId'  => null,
            'existingInstrumentistName'=> null,
            'freedFrom'                => false,
        ];
    }

    /** Generates a draft for a single-surgeon/single-site scope and returns its versionId + the created Mission id. */
    private function generateDraft($client, string $token, Hospital $site, User $surgeon, ?User $instrumentist = null): array
    {
        $response = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $body = $this->json($response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->createdIds['versions'][] = $body['versionId'];

        $this->em->clear();
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $body['versionId'])
            ->getQuery()->getResult();
        foreach ($missions as $m) { $this->createdIds['missions'][] = $m->getId(); }

        return ['versionId' => $body['versionId'], 'missionId' => $missions[0]->getId()];
    }

    // ── Test 2 (list) + Test 8 (409 already exists) ─────────────────────────────

    #[WithoutErrorHandler]
    public function test_draft_appears_in_list_filtered_by_status(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        ['versionId' => $versionId] = $this->generateDraft($client, $token, $site, $surgeon);

        $response = $this->getJson($client, $token, sprintf(
            '/api/planning/versions?status=DRAFT&siteId=%d&periodFrom=%04d-%02d-01&periodTo=%04d-%02d-01',
            $site->getId(), self::YEAR, self::MONTH, self::YEAR, self::MONTH,
        ));
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $ids = array_column($body['items'], 'id');
        self::assertContains($versionId, $ids);
    }

    #[WithoutErrorHandler]
    public function test_second_generate_same_scope_refused_with_existing_version_id(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        ['versionId' => $versionId] = $this->generateDraft($client, $token, $site, $surgeon);

        $response = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $body = $this->json($response);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('PLANNING_DRAFT_ALREADY_EXISTS', $body['code']);
        self::assertSame($versionId, $body['versionId']);
    }

    // ── Test 3 (reopen matches persisted) ────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_reopen_draft_reflects_persisted_missions(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon       = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $site          = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site, $instrumentist);

        ['versionId' => $versionId] = $this->generateDraft($client, $token, $site, $surgeon, $instrumentist);

        $response = $this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}");
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($versionId, $body['version']['id']);
        self::assertSame('DRAFT', $body['version']['status']);
        self::assertFalse($body['divergent']);
        self::assertGreaterThanOrEqual(1, count($body['lines']));
        self::assertSame('COVERED', $body['lines'][0]['status']);
        self::assertSame($instrumentist->getId(), $body['lines'][0]['instrumentistId']);
        self::assertNotNull($body['lines'][0]['existingMissionId']);
    }

    // ── Test 4 + Test 5 (update persists, reopen shows it) ───────────────────────

    #[WithoutErrorHandler]
    public function test_update_draft_persists_instrumentist_change_and_survives_reopen(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrA  = $this->makeUser('ROLE_INSTRUMENTIST');
        $instrB  = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site, $instrA, singleOccurrence: true);

        ['versionId' => $versionId, 'missionId' => $missionId] = $this->generateDraft($client, $token, $site, $surgeon, $instrA);

        $reopen = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $lines = $reopen['lines'];
        $lines[0]['instrumentistId'] = $instrB->getId();

        $update = $this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]);
        $updateBody = $this->json($update);

        self::assertSame(Response::HTTP_OK, $update->getStatusCode(), (string) $update->getContent());
        self::assertSame(1, $updateBody['updated']);

        // "Quitter puis revenir" — reopen again, confirm it survived. The Post's own
        // template instrumentist is still instrA (never touched — only the Mission was
        // reassigned) — preview() alone would report `instrumentistId: instrA` (the
        // template) here, but PlanningDraftService::normalizeForDraftEditing() overrides it
        // to the real persisted value (instrB) precisely so that resending this exact line
        // unchanged — e.g. while editing a *different* line — writes back what's actually
        // there instead of silently reverting to the template default. `status` stays
        // `MODIFIED`: still useful information (this occurrence diverges from its Post's
        // current template), just no longer the field the editor reads/resubmits.
        $this->em->clear();
        $reopenAgain = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        self::assertSame('MODIFIED', $reopenAgain['lines'][0]['status']);
        self::assertSame($instrB->getId(), $reopenAgain['lines'][0]['existingInstrumentistId']);
        self::assertSame($instrB->getId(), $reopenAgain['lines'][0]['instrumentistId']);

        $mission = $this->em->find(Mission::class, $missionId);
        self::assertSame($instrB->getId(), $mission->getInstrumentist()->getId());
        self::assertSame(MissionStatus::DRAFT, $mission->getStatus());
    }

    // ── Regression — a PATCH touching one line must never revert another line's real,
    //    already-persisted instrumentist back to its Post template's default. Found via
    //    real HTTP+DB end-to-end testing (§17, CAS D validation) before any unit test
    //    caught it: every existing test always resent every line exactly as reopen()
    //    returned it, which happened to mask this because nothing ever diverged from its
    //    template. Root cause: preview()'s `instrumentistId` on a MODIFIED line is the
    //    template default, but `PlanningDraftService::update()` writes `instrumentistId`
    //    verbatim as the new assignment — see normalizeForDraftEditing().

    #[WithoutErrorHandler]
    public function test_update_draft_does_not_revert_other_lines_when_saving_one(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site   = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $surgeonA = $this->makeUser('ROLE_SURGEON');
        $surgeonB = $this->makeUser('ROLE_SURGEON');
        $instrA   = $this->makeUser('ROLE_INSTRUMENTIST');
        $instrB   = $this->makeUser('ROLE_INSTRUMENTIST');
        // Both posts template no instrumentist — the reassignment below diverges from the
        // template on both lines (MODIFIED), the exact case normalizeForDraftEditing() fixes.
        $this->makePost($surgeonA, $site, null, singleOccurrence: true);
        $this->makePost($surgeonB, $site, null, singleOccurrence: true);

        $generateResponse = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $versionId = $this->json($generateResponse)['versionId'];
        $this->createdIds['versions'][] = $versionId;
        $this->em->clear();
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $versionId)
            ->getQuery()->getResult();
        foreach ($missions as $m) { $this->createdIds['missions'][] = $m->getId(); }
        $missionIdA = current(array_filter($missions, fn (Mission $m) => $m->getSurgeon()->getId() === $surgeonA->getId()))->getId();
        $missionIdB = current(array_filter($missions, fn (Mission $m) => $m->getSurgeon()->getId() === $surgeonB->getId()))->getId();

        // First save — assign instrA to line A only.
        $reopen1 = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $lines1 = $reopen1['lines'];
        foreach ($lines1 as &$l) {
            if ($l['existingMissionId'] === $missionIdA) { $l['instrumentistId'] = $instrA->getId(); }
        }
        unset($l);
        $update1 = $this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines1]);
        self::assertSame(Response::HTTP_OK, $update1->getStatusCode(), (string) $update1->getContent());

        // Second save — assign instrB to line B, resending line A exactly as this reopen()
        // returned it (never locally re-touched — this is what the editor does for every
        // line the manager didn't personally edit in the current session).
        $this->em->clear();
        $reopen2 = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $lines2 = $reopen2['lines'];
        foreach ($lines2 as &$l) {
            if ($l['existingMissionId'] === $missionIdB) { $l['instrumentistId'] = $instrB->getId(); }
        }
        unset($l);
        $update2 = $this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines2]);
        self::assertSame(Response::HTTP_OK, $update2->getStatusCode(), (string) $update2->getContent());

        // Line A's real, persisted instrumentist must have survived the second save.
        $this->em->clear();
        $missionA = $this->em->find(Mission::class, $missionIdA);
        $missionB = $this->em->find(Mission::class, $missionIdB);
        self::assertNotNull($missionA->getInstrumentist(), 'line A must keep its instrumentist across an unrelated save');
        self::assertSame($instrA->getId(), $missionA->getInstrumentist()->getId());
        self::assertSame($instrB->getId(), $missionB->getInstrumentist()->getId());
    }

    // ── Test 10 — ineligible instrumentist refused, not silently kept ────────────

    #[WithoutErrorHandler]
    public function test_update_draft_rejects_absent_instrumentist(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site, singleOccurrence: true);

        ['versionId' => $versionId, 'missionId' => $missionId] = $this->generateDraft($client, $token, $site, $surgeon);

        // generateDraft() clears the EM to prove persistence — $instr/$manager are now
        // detached references; re-fetch them before attaching them to a new entity.
        $instr = $this->em->find(User::class, $instr->getId());
        $manager = $this->em->find(User::class, $manager->getId());

        $absence = new Absence();
        $absence->setUser($instr);
        $absence->setCreatedBy($manager);
        $absence->setDateStart($this->firstMondayOfTestMonth());
        $absence->setDateEnd($this->firstMondayOfTestMonth());
        $this->em->persist($absence);
        $this->em->flush();
        $this->createdIds['absences'][] = $absence->getId();

        $reopen = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $lines = $reopen['lines'];
        $lines[0]['instrumentistId'] = $instr->getId();

        $update = $this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]);
        $updateBody = $this->json($update);

        self::assertSame(Response::HTTP_OK, $update->getStatusCode());
        self::assertCount(1, $updateBody['rejectedAssignments']);
        self::assertContains('ABSENT', $updateBody['rejectedAssignments'][0]['reasons']);

        $mission = $this->em->find(Mission::class, $missionId);
        self::assertNull($mission->getInstrumentist());
    }

    // ── Test 6 (delete fully-DRAFT version) ──────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_delete_draft_removes_version_and_its_missions(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        ['versionId' => $versionId, 'missionId' => $missionId] = $this->generateDraft($client, $token, $site, $surgeon);

        $response = $this->deleteJson($client, $token, "/api/planning/versions/{$versionId}");
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        $this->em->clear();
        self::assertNull($this->em->find(PlanningVersion::class, $versionId));
        self::assertNull($this->em->find(Mission::class, $missionId));

        // Cleanup bookkeeping: both are already gone, don't try to remove them again in tearDown.
        $this->createdIds['versions'] = array_diff($this->createdIds['versions'], [$versionId]);
        $this->createdIds['missions'] = array_diff($this->createdIds['missions'], [$missionId]);

        // Mois à nouveau générable.
        $again = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $againBody = $this->json($again);
        self::assertSame(Response::HTTP_OK, $again->getStatusCode());
        $this->createdIds['versions'][] = $againBody['versionId'];
        foreach ($this->em->createQueryBuilder()->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $againBody['versionId'])
            ->getQuery()->getResult() as $m
        ) {
            $this->createdIds['missions'][] = $m->getId();
        }
    }

    // ── Test 7 (delete refused once part of the version is no longer DRAFT) ──────

    #[WithoutErrorHandler]
    public function test_delete_draft_refused_when_a_mission_left_draft(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        ['versionId' => $versionId, 'missionId' => $missionId] = $this->generateDraft($client, $token, $site, $surgeon);

        $mission = $this->em->find(Mission::class, $missionId);
        $mission->setStatus(MissionStatus::OPEN);
        $this->em->flush();

        $response = $this->deleteJson($client, $token, "/api/planning/versions/{$versionId}");
        $body = $this->json($response);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('PLANNING_VERSION_NOT_DRAFT', $body['error']['code']);

        $this->em->clear();
        self::assertNotNull($this->em->find(PlanningVersion::class, $versionId));
        self::assertNotNull($this->em->find(Mission::class, $missionId));
    }

    // ── Test 9 — a Post added after the draft was created never overwrites it ────

    #[WithoutErrorHandler]
    public function test_reopen_reflects_new_post_without_touching_existing_missions(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon1, $site, $instr, singleOccurrence: true);

        ['versionId' => $versionId, 'missionId' => $missionId] = $this->generateDraft($client, $token, $site, $surgeon1, $instr);

        // generateDraft() clears the EM to prove persistence — $site is now a detached
        // reference; re-fetch it before attaching a new Post to it.
        $site = $this->em->find(Hospital::class, $site->getId());

        // A second surgeon's Post is added to the scope AFTER this draft was generated.
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $this->makePost($surgeon2, $site, singleOccurrence: true);

        $reopen = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        self::assertCount(2, $reopen['lines']);

        $line1 = current(array_filter($reopen['lines'], fn ($l) => $l['existingMissionId'] === $missionId));
        self::assertSame($instr->getId(), $line1['instrumentistId'], 'the original line must not be silently touched');

        $line2 = current(array_filter($reopen['lines'], fn ($l) => $l['existingMissionId'] === null));
        self::assertSame('UNCOVERED', $line2['status']);

        // Saving now must create the new line's mission without touching the first one's
        // actual assignment. `updated` still counts the resent, unchanged first line — same
        // unconditional counting as generate()'s own override mode (matches existing
        // precedent, not a CAS D regression) — the assertion below on the real Mission row
        // is what actually proves it was never mutated.
        $update = $this->json($this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $reopen['lines']]));
        self::assertSame(1, $update['created']);
        self::assertSame(1, $update['updated']);

        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $versionId)
            ->getQuery()->getResult();
        self::assertCount(2, $missions);
        foreach ($missions as $m) { $this->createdIds['missions'][] = $m->getId(); }

        $originalMission = $this->em->find(Mission::class, $missionId);
        self::assertSame($instr->getId(), $originalMission->getInstrumentist()->getId(), 'the original mission must not be silently touched');
    }

    // ── Regression — real prod 500 (2026-09-08): PlanningAlert FK blocked delete() ───

    /**
     * Reproduces exactly the scenario that failed in production: a draft with an
     * absence-triggered PlanningAlert on one of its own missions. Before the fix,
     * delete() removed the Mission first and MySQL rejected it (FK_PA_MISSION,
     * ON DELETE RESTRICT). The Absence itself must survive — it's an independent
     * business record, never touched by draft deletion.
     */
    #[WithoutErrorHandler]
    public function test_delete_draft_cleans_up_planning_alert_and_preserves_absence(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site, singleOccurrence: true);

        ['versionId' => $versionId, 'missionId' => $missionId] = $this->generateDraft($client, $token, $site, $surgeon);

        // Real HTTP call, not a direct entity persist: AbsenceImpactService (which raises
        // the PlanningAlert) is wired into AbsenceController::create(), not onto the
        // Absence entity's lifecycle — the exact reproduction of the real prod failure
        // requires going through the actual endpoint, same as the live QA session did.
        $absenceResponse = $this->postJson($client, $token, '/api/absences', [
            'userId' => $surgeon->getId(),
            'dateStart' => $this->firstMondayOfTestMonth()->format('Y-m-d'),
            'dateEnd' => $this->firstMondayOfTestMonth()->format('Y-m-d'),
            'reason' => 'CAS D regression test',
        ]);
        self::assertSame(Response::HTTP_CREATED, $absenceResponse->getStatusCode(), (string) $absenceResponse->getContent());
        $absenceId = $this->json($absenceResponse)['id'];
        $this->createdIds['absences'][] = $absenceId;

        // AbsenceImpactService raises a SURGEON_ABSENCE PlanningAlert against the DRAFT
        // mission — confirmed present before attempting the delete (proves this test
        // reproduces the real failure mode, not a no-op).
        $this->em->clear();
        $alerts = $this->em->getRepository(PlanningAlert::class)->findBy(['mission' => $missionId]);
        self::assertCount(1, $alerts, 'setup must reproduce the real prod condition: exactly one alert on the draft mission');
        $alertId = $alerts[0]->getId();
        $this->createdIds['alerts'][] = $alertId;

        $response = $this->deleteJson($client, $token, "/api/planning/versions/{$versionId}");
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

        $this->em->clear();
        self::assertNull($this->em->find(PlanningVersion::class, $versionId), 'version must be deleted');
        self::assertNull($this->em->find(Mission::class, $missionId), 'mission must be deleted');
        self::assertNull($this->em->find(PlanningAlert::class, $alertId), 'the alert on the deleted mission must be cleaned up');
        self::assertNotNull($this->em->find(Absence::class, $absenceId), 'the source Absence is independent business data — must survive draft deletion');

        // Bookkeeping: already gone, don't try to remove again in tearDown.
        $this->createdIds['versions'] = array_diff($this->createdIds['versions'], [$versionId]);
        $this->createdIds['missions'] = array_diff($this->createdIds['missions'], [$missionId]);
        $this->createdIds['alerts']   = array_diff($this->createdIds['alerts'], [$alertId]);

        // Mois à nouveau générable.
        $again = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        self::assertSame(Response::HTTP_OK, $again->getStatusCode(), (string) $again->getContent());
        $againBody = $this->json($again);
        $this->createdIds['versions'][] = $againBody['versionId'];
        foreach ($this->em->createQueryBuilder()->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $againBody['versionId'])
            ->getQuery()->getResult() as $m
        ) {
            $this->createdIds['missions'][] = $m->getId();
        }
    }

    /**
     * Same regression, but with several missions and several alerts — guards against a
     * fix that only happens to work for a single row (e.g. an off-by-one in a loop, or a
     * query scoped to the wrong mission).
     */
    #[WithoutErrorHandler]
    public function test_delete_draft_cleans_up_multiple_missions_and_alerts(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeonA = $this->makeUser('ROLE_SURGEON');
        $surgeonB = $this->makeUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeonA, $site, singleOccurrence: true);
        $this->makePost($surgeonB, $site, singleOccurrence: true);

        $response = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $versionId = $this->json($response)['versionId'];
        $this->createdIds['versions'][] = $versionId;

        $this->em->clear();
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $versionId)
            ->getQuery()->getResult();
        self::assertCount(2, $missions);
        $missionIds = [];
        foreach ($missions as $m) {
            $missionIds[] = $m->getId();
            $this->createdIds['missions'][] = $m->getId();
        }
        $surgeonA = $this->em->find(User::class, $surgeonA->getId());
        $surgeonB = $this->em->find(User::class, $surgeonB->getId());

        // Both surgeons absent on the same occurrence date — two independent alerts on
        // two independent missions of the same draft. Real HTTP call (not a direct
        // entity persist): AbsenceImpactService is wired into AbsenceController::create().
        foreach ([$surgeonA->getId(), $surgeonB->getId()] as $surgeonId) {
            $absenceResponse = $this->postJson($client, $token, '/api/absences', [
                'userId' => $surgeonId,
                'dateStart' => $this->firstMondayOfTestMonth()->format('Y-m-d'),
                'dateEnd' => $this->firstMondayOfTestMonth()->format('Y-m-d'),
                'reason' => 'CAS D regression test (multi)',
            ]);
            self::assertSame(Response::HTTP_CREATED, $absenceResponse->getStatusCode(), (string) $absenceResponse->getContent());
            $this->createdIds['absences'][] = $this->json($absenceResponse)['id'];
        }

        $this->em->clear();
        $alerts = $this->em->createQueryBuilder()
            ->select('a')->from(PlanningAlert::class, 'a')
            ->where('a.mission IN (:ids)')->setParameter('ids', $missionIds)
            ->getQuery()->getResult();
        self::assertCount(2, $alerts, 'setup must produce one alert per mission');
        $alertIds = array_map(fn (PlanningAlert $a) => $a->getId(), $alerts);
        foreach ($alertIds as $id) { $this->createdIds['alerts'][] = $id; }

        $response = $this->deleteJson($client, $token, "/api/planning/versions/{$versionId}");
        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

        $this->em->clear();
        self::assertNull($this->em->find(PlanningVersion::class, $versionId));
        foreach ($missionIds as $id) {
            self::assertNull($this->em->find(Mission::class, $id), "mission {$id} must be deleted");
        }
        foreach ($alertIds as $id) {
            self::assertNull($this->em->find(PlanningAlert::class, $id), "alert {$id} must be deleted");
        }
        self::assertSame(2, (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')->from(Absence::class, 'a')
            ->where('a.id IN (:ids)')->setParameter('ids', $this->createdIds['absences'])
            ->getQuery()->getSingleScalarResult(), 'both source Absences must survive');

        $this->createdIds['versions'] = array_diff($this->createdIds['versions'], [$versionId]);
        $this->createdIds['missions'] = array_diff($this->createdIds['missions'], $missionIds);
        $this->createdIds['alerts']   = array_diff($this->createdIds['alerts'], $alertIds);
    }

    /**
     * The other side of the audit: a Mission that reached a post-deploy/post-encoding
     * stage (here, a FinancialCalculation — representative of the whole
     * PROTECTED_IF_MISSION_TOUCHED family; same code path handles the other six) must
     * never be silently force-deleted, and must never surface as a raw 500 either — a
     * clean 409 up front, before any DELETE statement runs. FinancialCalculation is
     * structurally impossible on a real DRAFT mission through any legitimate flow, so
     * this is deliberately constructed by direct persistence to simulate the invariant
     * violation and prove the guard, not a realistic user scenario.
     */
    #[WithoutErrorHandler]
    public function test_delete_draft_refused_when_a_protected_financial_record_exists(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site, singleOccurrence: true);

        ['versionId' => $versionId, 'missionId' => $missionId] = $this->generateDraft($client, $token, $site, $surgeon);

        $mission = $this->em->find(Mission::class, $missionId);
        $calc = new \App\Entity\FinancialCalculation();
        $calc->setMission($mission);
        $calc->setEffectiveAt(new \DateTimeImmutable('2026-01-01'));
        $calc->setCalculatedAt(new \DateTimeImmutable());
        $this->em->persist($calc);
        $this->em->flush();
        $calcId = $calc->getId();

        $response = $this->deleteJson($client, $token, "/api/planning/versions/{$versionId}");
        $body = $this->json($response);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('PLANNING_VERSION_NOT_DRAFT', $body['error']['code']);
        self::assertStringContainsString('FinancialCalculation', $body['error']['message']);

        $this->em->clear();
        self::assertNotNull($this->em->find(PlanningVersion::class, $versionId), 'nothing must be deleted when the guard refuses');
        self::assertNotNull($this->em->find(Mission::class, $missionId));

        $calc = $this->em->find(\App\Entity\FinancialCalculation::class, $calcId);
        if ($calc !== null) { $this->em->remove($calc); $this->em->flush(); }
    }

    // ── CAS C (D-116) — "Ajouter" on a reopened DRAFT ─────────────────────────────
    // Strictly separate handler from apply-modifications/MissionPostDeployService (see
    // PlanningModificationControllerTest's own CAS C block for the ACTIVE-path
    // equivalents): PlanningDraftService::createAdHocDraftMission() only, real Mission
    // persisted with status DRAFT, never ASSIGNED/OPEN, never audited/notified.

    #[WithoutErrorHandler]
    public function test_add_mission_on_reopened_draft_with_instrumentist_creates_and_persists_draft_mission(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon1, $site, singleOccurrence: true);

        ['versionId' => $versionId] = $this->generateDraft($client, $token, $site, $surgeon1);

        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');

        $reopen = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $lines  = $reopen['lines'];
        $lines[] = $this->adHocLineFor($site, $surgeon2, $instr, $this->firstMondayOfTestMonth()->format('Y-m-d'));

        $update = $this->json($this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]));
        self::assertSame(1, $update['created'], json_encode($update));

        $this->em->clear();
        $mission = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->andWhere('m.surgeon = :s')
            ->setParameter('v', $versionId)->setParameter('s', $surgeon2->getId())
            ->getQuery()->getOneOrNullResult();

        self::assertNotNull($mission, 'the ad-hoc line must be persisted as a real Mission');
        $this->createdIds['missions'][] = $mission->getId();
        self::assertSame(MissionStatus::DRAFT, $mission->getStatus());
        self::assertSame($instr->getId(), $mission->getInstrumentist()?->getId());
        self::assertSame($versionId, $mission->getPlanningVersion()?->getId());
    }

    #[WithoutErrorHandler]
    public function test_add_mission_on_reopened_draft_without_instrumentist_creates_draft_mission_with_null_instrumentist(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon1, $site, singleOccurrence: true);

        ['versionId' => $versionId] = $this->generateDraft($client, $token, $site, $surgeon1);

        $surgeon2 = $this->makeUser('ROLE_SURGEON');

        $reopen = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $lines  = $reopen['lines'];
        $lines[] = $this->adHocLineFor($site, $surgeon2, null, $this->firstMondayOfTestMonth()->format('Y-m-d'));

        $update = $this->json($this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]));
        self::assertSame(1, $update['created'], json_encode($update));

        $this->em->clear();
        $mission = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->andWhere('m.surgeon = :s')
            ->setParameter('v', $versionId)->setParameter('s', $surgeon2->getId())
            ->getQuery()->getOneOrNullResult();

        self::assertNotNull($mission);
        $this->createdIds['missions'][] = $mission->getId();
        self::assertSame(MissionStatus::DRAFT, $mission->getStatus());
        self::assertNull($mission->getInstrumentist());
    }

    /**
     * "Quitter/réouvrir → ligne toujours présente" — reopen() re-derives its lines from
     * preview() (Post-occurrence iteration only), which would otherwise silently drop an
     * ad-hoc mission with no backing Post. Regression-guards PlanningDraftService::
     * appendAdHocMissions(), added specifically to keep this line visible.
     */
    #[WithoutErrorHandler]
    public function test_added_draft_mission_survives_reopen(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon1, $site, singleOccurrence: true);

        ['versionId' => $versionId] = $this->generateDraft($client, $token, $site, $surgeon1);

        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');

        $reopen1 = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $lines   = $reopen1['lines'];
        $lines[] = $this->adHocLineFor($site, $surgeon2, $instr, $this->firstMondayOfTestMonth()->format('Y-m-d'));
        $this->json($this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]));

        $this->em->clear();
        $mission = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->andWhere('m.surgeon = :s')
            ->setParameter('v', $versionId)->setParameter('s', $surgeon2->getId())
            ->getQuery()->getOneOrNullResult();
        $this->createdIds['missions'][] = $mission->getId();

        // "Quitter" (nothing else to do — the mission is already persisted) then "réouvrir".
        $reopen2 = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $line = current(array_filter($reopen2['lines'], fn ($l) => $l['existingMissionId'] === $mission->getId()));
        self::assertNotFalse($line, 'the ad-hoc line must still be present after leaving and reopening the draft');
        self::assertSame($instr->getId(), $line['instrumentistId']);
        self::assertSame($surgeon2->getId(), $line['surgeonId']);
    }

    /**
     * No confusion between the two CAS C handlers: a DRAFT-context add must never reach
     * MissionPostDeployService — the surest proof is that it never leaves DRAFT status
     * (only createPostDeploy() sets ASSIGNED/OPEN) and never gets the post-deploy audit
     * event type, which createAdHocDraftMission() deliberately never writes.
     */
    #[WithoutErrorHandler]
    public function test_add_mission_on_reopened_draft_never_triggers_post_deploy_audit(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $site     = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon1, $site, singleOccurrence: true);

        ['versionId' => $versionId] = $this->generateDraft($client, $token, $site, $surgeon1);

        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');

        $reopen = $this->json($this->getJson($client, $token, "/api/planning/v2/drafts/{$versionId}"));
        $lines  = $reopen['lines'];
        $lines[] = $this->adHocLineFor($site, $surgeon2, $instr, $this->firstMondayOfTestMonth()->format('Y-m-d'));
        $this->json($this->patchJson($client, $token, "/api/planning/v2/drafts/{$versionId}", ['lines' => $lines]));

        $this->em->clear();
        $mission = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->andWhere('m.surgeon = :s')
            ->setParameter('v', $versionId)->setParameter('s', $surgeon2->getId())
            ->getQuery()->getOneOrNullResult();
        $this->createdIds['missions'][] = $mission->getId();

        self::assertSame(MissionStatus::DRAFT, $mission->getStatus(), 'a DRAFT-context add must never become ASSIGNED/OPEN via MissionPostDeployService');

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId()]);
        $types  = array_map(static fn (AuditEvent $e) => $e->getEventType(), $events);
        self::assertNotContains(AuditEventType::MISSION_ADDED_POST_DEPLOY, $types, 'a DRAFT-context add must never write the post-deploy audit event');
    }
}
