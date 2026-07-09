<?php

namespace App\Tests\Functional;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningDeployment;
use App\Entity\PlanningVersion;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SiteGroup;
use App\Entity\SiteGroupMembership;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\EmploymentType;
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
 * Real-HTTP tests for the Batch 9 V2 generation endpoints — the one hard blocker
 * identified by the Batch 8 architecture freeze (PlanningGeneratorServiceV2 had never
 * been wired to a controller before this batch). V1 routes/services are never touched.
 */
final class PlanningV2GenerationControllerTest extends WebTestCase
{
    private const PASSWORD = 'Batch9Test123!';
    private const YEAR     = 2026;
    private const MONTH    = 9;

    private EntityManagerInterface $em;
    private array $createdIds = [
        'versions' => [], 'missions' => [], 'posts' => [], 'shiftPeriods' => [],
        'siteGroups' => [], 'memberships' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            // AuditEvent has a FK to Mission — must be deleted before the mission itself.
            // (claim()/release()/etc. all create one via MissionPostDeployService — D-055.)
            foreach ($this->createdIds['missions'] as $id) {
                foreach ($this->em->getRepository(AuditEvent::class)->findBy(['mission' => $id]) as $evt) {
                    $this->em->remove($evt);
                }
            }
            $this->em->flush();
            // Mission has a FK to PlanningVersion — missions must be deleted first.
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
            foreach ($this->createdIds['siteGroups'] as $id) {
                $e = $this->em->find(SiteGroup::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['memberships'] as $id) {
                $e = $this->em->find(SiteGroupMembership::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            // Deploy may have created PlanningDeployment rows referencing our test users.
            foreach ($this->createdIds['users'] as $id) {
                foreach ($this->em->getRepository(PlanningDeployment::class)->findBy(['deployedBy' => $id]) as $deployment) {
                    $this->em->remove($deployment);
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
        $user->setEmail('batch9-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function postJson(KernelBrowser $client, string $token, string $uri, array $body): Response
    {
        $client->request('POST', $uri, server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($body));
        return $client->getResponse();
    }

    private function makeUser(string $role): User
    {
        $u = new User();
        $u->setEmail('batch9-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Batch9 Site ' . bin2hex(random_bytes(3)));
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

    /** First Monday of self::YEAR/self::MONTH — computed, never hardcoded. */
    private function firstMondayOfTestMonth(): \DateTimeImmutable
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', self::YEAR, self::MONTH));
        $isoDay = (int) $first->format('N');
        return $isoDay === 1 ? $first : $first->modify('+' . (8 - $isoDay) . ' days');
    }

    private function makePost(User $surgeon, Hospital $site, ?User $instrumentist = null): SurgeonSchedulePost
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

    // ── Preview ───────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_preview_single_site_returns_lines_and_summary(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon       = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $site          = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site, $instrumentist);

        $response = $this->postJson($client, $token, '/api/planning/v2/preview', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertGreaterThanOrEqual(1, count($body['lines']));
        self::assertSame(count($body['lines']), $body['summary']['total']);
        self::assertSame('COVERED', $body['lines'][0]['status']);
        self::assertSame($site->getId(), $body['lines'][0]['siteId']);
        self::assertGreaterThanOrEqual(1, $body['summary']['covered']);
    }

    #[WithoutErrorHandler]
    public function test_preview_site_group_aggregates_member_sites(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $siteA   = $this->makeSite();
        $siteB   = $this->makeSite();
        $this->addShiftConfig($siteA, '08:00', '13:00');
        $this->addShiftConfig($siteB, '08:00', '13:00');
        $this->makePost($surgeon, $siteA);
        $this->makePost($surgeon, $siteB);

        $group = new SiteGroup();
        $group->setName('Batch9 Group');
        $group->setCreatedBy($surgeon);
        $this->em->persist($group);
        $this->em->flush();
        $this->createdIds['siteGroups'][] = $group->getId();

        $memberships = [];
        foreach ([$siteA, $siteB] as $site) {
            $m = new SiteGroupMembership();
            $m->setGroup($group);
            $m->setSite($site);
            $this->em->persist($m);
            $memberships[] = $m;
        }
        $this->em->flush();
        foreach ($memberships as $m) {
            $this->createdIds['memberships'][] = $m->getId();
        }

        $response = $this->postJson($client, $token, '/api/planning/v2/preview', [
            'siteId' => null, 'siteGroupId' => $group->getId(), 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $siteIds = array_unique(array_map(fn ($l) => $l['siteId'], $body['lines']));
        sort($siteIds);
        $expected = [$siteA->getId(), $siteB->getId()];
        sort($expected);
        self::assertSame($expected, $siteIds);
    }

    #[WithoutErrorHandler]
    public function test_preview_rejects_both_site_and_group(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $response = $this->postJson($client, $token, '/api/planning/v2/preview', [
            'siteId' => 1, 'siteGroupId' => 2, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('BAD_REQUEST', $this->json($response)['error']['code']);
    }

    #[WithoutErrorHandler]
    public function test_preview_rejects_neither_site_nor_group(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $response = $this->postJson($client, $token, '/api/planning/v2/preview', [
            'year' => self::YEAR, 'month' => self::MONTH,
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // ── Generate ──────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_generate_creates_planning_version_and_draft_missions(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        $response = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayHasKey('versionId', $body);
        self::assertGreaterThanOrEqual(1, $body['created']);
        $this->createdIds['versions'][] = $body['versionId'];

        $this->em->clear();
        $version = $this->em->find(PlanningVersion::class, $body['versionId']);
        self::assertNotNull($version);
        self::assertSame(PlanningVersionStatus::DRAFT, $version->getStatus());

        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $version)
            ->getQuery()->getResult();
        self::assertGreaterThanOrEqual(1, count($missions));
        foreach ($missions as $m) {
            $this->createdIds['missions'][] = $m->getId();
            self::assertSame(MissionStatus::DRAFT, $m->getStatus());
        }
    }

    #[WithoutErrorHandler]
    public function test_generate_twice_for_same_period_rejects_duplicate(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        $body = ['siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH];

        $first = $this->postJson($client, $token, '/api/planning/v2/generate', $body);
        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        $firstData = $this->json($first);
        $this->createdIds['versions'][] = $firstData['versionId'];

        $second = $this->postJson($client, $token, '/api/planning/v2/generate', $body);
        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode(), 'A second generate for the same undeployed period must be explicitly rejected, not silently duplicated');
        self::assertSame('CONFLICT', $this->json($second)['error']['code']);

        // Clean up missions created by the first (successful) generate too.
        $this->em->clear();
        $version = $this->em->find(PlanningVersion::class, $firstData['versionId']);
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $version)
            ->getQuery()->getResult();
        foreach ($missions as $m) {
            $this->createdIds['missions'][] = $m->getId();
        }
    }

    // ── Deploy ────────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_deploy_publishes_generated_draft_missions(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon       = $this->makeUser('ROLE_SURGEON');
        $instrumentist = $this->makeUser('ROLE_INSTRUMENTIST');
        $site          = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site, $instrumentist);

        $generateResponse = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $versionId = $this->json($generateResponse)['versionId'];
        $this->createdIds['versions'][] = $versionId;

        $deployResponse = $this->postJson($client, $token, '/api/planning/v2/deploy', [
            'planningVersionId' => $versionId,
        ]);
        $deployBody = $this->json($deployResponse);

        self::assertSame(Response::HTTP_OK, $deployResponse->getStatusCode(), (string) $deployResponse->getContent());
        self::assertArrayHasKey('deploymentId', $deployBody);
        self::assertArrayHasKey('missionCount', $deployBody);
        self::assertGreaterThanOrEqual(1, $deployBody['missionCount']);

        $this->em->clear();
        $version = $this->em->find(PlanningVersion::class, $versionId);
        self::assertSame(PlanningVersionStatus::ACTIVE, $version->getStatus(), 'Deploy must reuse PlanningDeploymentService, which activates the version');

        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $version)
            ->getQuery()->getResult();
        self::assertGreaterThanOrEqual(1, count($missions));
        foreach ($missions as $m) {
            $this->createdIds['missions'][] = $m->getId();
            self::assertSame(MissionStatus::ASSIGNED, $m->getStatus(), 'Mission with a pre-assigned instrumentist must become ASSIGNED on deploy');
        }
    }

    // ── Batch 14.5: previewVersion + override lines ───────────────────────────

    #[WithoutErrorHandler]
    public function test_preview_response_includes_preview_version_and_generated_at(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        $response = $this->postJson($client, $token, '/api/planning/v2/preview', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        $body = $this->json($response);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertArrayHasKey('previewVersion', $body, 'Preview response must include previewVersion');
        self::assertArrayHasKey('generatedAt', $body, 'Preview response must include generatedAt');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['previewVersion'], 'previewVersion must be a 64-char SHA-256 hex string');
        self::assertNotEmpty($body['generatedAt']);
    }

    #[WithoutErrorHandler]
    public function test_generate_with_valid_preview_version_succeeds(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        $target = ['siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH];

        $previewResponse = $this->postJson($client, $token, '/api/planning/v2/preview', $target);
        $previewVersion  = $this->json($previewResponse)['previewVersion'];

        $generateResponse = $this->postJson($client, $token, '/api/planning/v2/generate', array_merge(
            $target, ['previewVersion' => $previewVersion],
        ));
        $generateBody = $this->json($generateResponse);

        self::assertSame(Response::HTTP_OK, $generateResponse->getStatusCode(), (string) $generateResponse->getContent());
        self::assertArrayHasKey('versionId', $generateBody);
        $this->createdIds['versions'][] = $generateBody['versionId'];

        // Track missions for teardown
        $this->em->clear();
        $version  = $this->em->find(\App\Entity\PlanningVersion::class, $generateBody['versionId']);
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $version)
            ->getQuery()->getResult();
        foreach ($missions as $m) {
            $this->createdIds['missions'][] = $m->getId();
        }
    }

    #[WithoutErrorHandler]
    public function test_generate_with_stale_preview_version_returns_409(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        $target = ['siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH];

        $response = $this->postJson($client, $token, '/api/planning/v2/generate', array_merge(
            $target, ['previewVersion' => str_repeat('0', 64)],
        ));
        $body = $this->json($response);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('PREVIEW_EXPIRED', $body['code'], (string) $response->getContent());
    }

    #[WithoutErrorHandler]
    public function test_generate_without_preview_version_skips_stale_check(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site);

        $target   = ['siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH];
        $response = $this->postJson($client, $token, '/api/planning/v2/generate', $target);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $versionId = $this->json($response)['versionId'];
        $this->createdIds['versions'][] = $versionId;

        // Track missions for teardown
        $this->em->clear();
        $version  = $this->em->find(\App\Entity\PlanningVersion::class, $versionId);
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $version)
            ->getQuery()->getResult();
        foreach ($missions as $m) {
            $this->createdIds['missions'][] = $m->getId();
        }
    }

    // ── Security ──────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_preview_rejects_instrumentist_role(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $response = $this->postJson($client, $token, '/api/planning/v2/preview', [
            'siteId' => 1, 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[WithoutErrorHandler]
    public function test_generate_rejects_surgeon_role(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $response = $this->postJson($client, $token, '/api/planning/v2/generate', [
            'siteId' => 1, 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    // ── RC1-A: OPEN Mission Pipeline regression ───────────────────────────────

    /**
     * End-to-end: deploy uncovered V2 missions → OPEN → instrumentist eligible
     * → claim succeeds → ASSIGNED.  Also covers W10-1 role-scoped list.
     *
     * Fixes validated:
     *   P0-1 — deploy creates OPEN missions (not just DRAFT limbo)
     *   P0-2 — eligibleToMe=true returns V2 OPEN missions (leftJoin fix)
     *   P0-2 — instrumentist can GET /api/missions/{id} for V2 OPEN (voter fix)
     *   W10-1 — unscoped list for instrumentist returns only their own missions
     */
    #[WithoutErrorHandler]
    public function test_open_mission_pipeline_v2_deploy_to_claim(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // ── Setup: manager deploys a planning with no pre-assigned instrumentist ──
        ['token' => $managerToken] = $this->authenticate($client, 'ROLE_MANAGER');
        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->addShiftConfig($site, '08:00', '13:00');
        $this->makePost($surgeon, $site, null); // null → no pre-assigned → mission will be OPEN

        // FREELANCER bypasses the site-membership requirement for pool eligibility.
        ['user' => $instrumentist, 'token' => $instrToken] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');
        // authenticate() makes an HTTP request (login) internally, which detaches all
        // entities from $this->em (Symfony resets the EntityManager identity map after each
        // kernel request in the test environment). Re-fetch before mutating, otherwise this
        // flush() silently does nothing and employmentType never reaches the DB.
        $instrumentist = $this->em->find(User::class, $instrumentist->getId());
        $instrumentist->setEmploymentType(EmploymentType::FREELANCER);
        $this->em->flush();

        // ── Generate + Deploy ─────────────────────────────────────────────────
        $generateResponse = $this->postJson($client, $managerToken, '/api/planning/v2/generate', [
            'siteId' => $site->getId(), 'siteGroupId' => null, 'year' => self::YEAR, 'month' => self::MONTH,
        ]);
        self::assertSame(Response::HTTP_OK, $generateResponse->getStatusCode());
        $versionId = $this->json($generateResponse)['versionId'];
        $this->createdIds['versions'][] = $versionId;

        $deployResponse = $this->postJson($client, $managerToken, '/api/planning/v2/deploy', [
            'planningVersionId' => $versionId,
        ]);
        self::assertSame(Response::HTTP_OK, $deployResponse->getStatusCode(), (string) $deployResponse->getContent());

        // Track missions for teardown.
        $this->em->clear();
        $version  = $this->em->find(PlanningVersion::class, $versionId);
        $missions = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.planningVersion = :v')->setParameter('v', $version)
            ->getQuery()->getResult();
        foreach ($missions as $m) {
            $this->createdIds['missions'][] = $m->getId();
        }

        // ── P0-1: verify at least one OPEN mission was created ────────────────
        $openMissions = array_values(array_filter($missions, fn (Mission $m) => $m->getStatus() === MissionStatus::OPEN));
        self::assertNotEmpty($openMissions, 'P0-1: uncovered missions must become OPEN on deploy');
        $openMission = $openMissions[0];
        $missionId   = $openMission->getId();

        // ── P0-2 (list): eligibleToMe=true must return V2 OPEN missions ──────
        $client->request('GET', '/api/missions?eligibleToMe=true', server: $this->auth($instrToken));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $listBody  = $this->json($client->getResponse());
        $listedIds = array_column($listBody['items'], 'id');
        self::assertContains($missionId, $listedIds, 'P0-2: V2 OPEN mission must appear when eligibleToMe=true');

        // ── P0-2 (voter): instrumentist can view the mission detail ───────────
        $client->request('GET', "/api/missions/{$missionId}", server: $this->auth($instrToken));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), 'P0-2 voter: instrumentist must be able to GET V2 OPEN mission detail');

        // ── W10-1: unfiltered list scopes instrumentist to their own missions ─
        // Before claim: instrumentist has no assigned missions → the OPEN mission
        // must NOT appear in an unscoped list (it has no instrumentist yet).
        $client->request('GET', '/api/missions', server: $this->auth($instrToken));
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        $unfilteredIds = array_column($this->json($client->getResponse())['items'], 'id');
        self::assertNotContains($missionId, $unfilteredIds, 'W10-1: OPEN (unclaimed) mission must not appear in instrumentist unscoped list');

        // ── Claim the mission ─────────────────────────────────────────────────
        $claimResponse = $this->postJson($client, $instrToken, "/api/missions/{$missionId}/claim", []);
        self::assertSame(Response::HTTP_OK, $claimResponse->getStatusCode(), 'Claim must succeed: ' . (string) $claimResponse->getContent());

        // ── Verify ASSIGNED status and instrumentist link ─────────────────────
        $this->em->clear();
        $claimedMission = $this->em->find(Mission::class, $missionId);
        self::assertSame(MissionStatus::ASSIGNED, $claimedMission->getStatus(), 'Mission must be ASSIGNED after claim');
        self::assertSame($instrumentist->getId(), $claimedMission->getInstrumentist()?->getId(), 'Claimed mission must be linked to the claiming instrumentist');

        // ── W10-1 post-claim: now instrumentist sees their assigned mission ───
        $client->request('GET', '/api/missions', server: $this->auth($instrToken));
        $postClaimIds = array_column($this->json($client->getResponse())['items'], 'id');
        self::assertContains($missionId, $postClaimIds, 'W10-1: after claim, ASSIGNED mission must appear in instrumentist unscoped list');

        // ── Manager can still see all missions ────────────────────────────────
        $client->request('GET', '/api/missions', server: $this->auth($managerToken));
        $managerIds = array_column($this->json($client->getResponse())['items'], 'id');
        self::assertContains($missionId, $managerIds, 'Manager unscoped list must include all missions regardless of status');
    }
}
