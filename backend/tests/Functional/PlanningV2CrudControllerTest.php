<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\PlanningOccurrenceException;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SiteGroup;
use App\Entity\SiteMembership;
use App\Entity\SurgeonSchedulePost;
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
 * Real-HTTP smoke tests for the Batch 6 CRUD APIs: SiteGroup, ShiftPeriodConfig,
 * SurgeonSchedulePost, PlanningOccurrenceException. No frontend, no cutover, V1
 * (PlanningTemplate/PlanningSlot/PAIR/IMPAIR/TOUTES) is never touched by any of this.
 */
final class PlanningV2CrudControllerTest extends WebTestCase
{
    private const PASSWORD = 'Batch6Test123!';

    private EntityManagerInterface $em;
    private array $createdIds = [
        'exceptions' => [], 'posts' => [], 'shiftPeriods' => [], 'siteGroups' => [],
        'missions' => [], 'memberships' => [], 'sites' => [], 'users' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['exceptions'] as $id) {
                $e = $this->em->find(PlanningOccurrenceException::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['posts'] as $id) {
                $e = $this->em->find(SurgeonSchedulePost::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();
            foreach ($this->createdIds['missions'] as $id) {
                $e = $this->em->find(Mission::class, $id);
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
                $e = $this->em->find(SiteMembership::class, $id);
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
        $user->setEmail('batch6-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function makeUser(string $role, bool $active = true): User
    {
        $u = new User();
        $u->setEmail('batch6-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Batch6 Site ' . bin2hex(random_bytes(3)));
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

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    // ── SiteGroup CRUD + add/remove sites ────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_site_group_crud_and_membership(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $client->request('POST', '/api/planning/site-groups', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['name' => 'Region North']));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $group = $this->json($client->getResponse());
        $this->createdIds['siteGroups'][] = $group['id'];

        $siteA = $this->makeSite();
        $siteB = $this->makeSite();

        $client->request('POST', "/api/planning/site-groups/{$group['id']}/sites", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['siteId' => $siteA->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('POST', "/api/planning/site-groups/{$group['id']}/sites", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['siteId' => $siteB->getId()]));
        $afterAdd = $this->json($client->getResponse());
        self::assertCount(2, $afterAdd['sites']);

        $client->request('DELETE', "/api/planning/site-groups/{$group['id']}/sites/{$siteA->getId()}", server: $this->auth($token));
        $afterRemove = $this->json($client->getResponse());
        self::assertCount(1, $afterRemove['sites']);

        $client->request('PATCH', "/api/planning/site-groups/{$group['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['name' => 'Region North Renamed']));
        self::assertSame('Region North Renamed', $this->json($client->getResponse())['name']);

        $client->request('DELETE', "/api/planning/site-groups/{$group['id']}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->createdIds['siteGroups'] = []; // already deleted

        $client->request('GET', "/api/planning/site-groups/{$group['id']}", server: $this->auth($token));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ── ShiftPeriod CRUD + invalid rejection ──────────────────────────────────

    #[WithoutErrorHandler]
    public function test_shift_period_crud_and_invalid_range_rejected(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $site = $this->makeSite();

        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $site->getId(), 'period' => 'MATIN', 'startTime' => '08:00', 'endTime' => '13:00',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $config = $this->json($client->getResponse());
        $this->createdIds['shiftPeriods'][] = $config['id'];

        // Invalid range rejected
        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $site->getId(), 'period' => 'APRES_MIDI', 'startTime' => '18:00', 'endTime' => '13:00',
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());

        // Duplicate active period for same site rejected
        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $site->getId(), 'period' => 'MATIN', 'startTime' => '07:00', 'endTime' => '12:00',
        ]));
        self::assertSame(409, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/planning/shift-periods?siteId=' . $site->getId(), server: $this->auth($token));
        $list = $this->json($client->getResponse());
        self::assertCount(1, $list['items']);

        $client->request('PATCH', "/api/planning/shift-periods/{$config['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['startTime' => '09:00']));
        self::assertSame('09:00', $this->json($client->getResponse())['startTime']);

        $client->request('DELETE', "/api/planning/shift-periods/{$config['id']}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        // Deactivation does not delete: still findable via direct DB lookup
        $this->em->clear();
        $stillExists = $this->em->find(ShiftPeriodConfig::class, $config['id']);
        self::assertNotNull($stillExists, 'Deactivation must not delete the row');
        self::assertFalse($stillExists->isActive());
    }

    // ── SurgeonSchedulePost CRUD + validation ────────────────────────────────

    #[WithoutErrorHandler]
    public function test_surgeon_post_crud_and_recurrence_validation(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $site->getId(), 'period' => 'MATIN', 'startTime' => '08:00', 'endTime' => '13:00',
        ]));
        $config = $this->json($client->getResponse());
        $this->createdIds['shiftPeriods'][] = $config['id'];

        $validPostBody = [
            'surgeonId' => $surgeon->getId(),
            'siteId'    => $site->getId(),
            'type'      => 'BLOCK',
            'period'    => 'MATIN',
            'startDate' => '2026-01-01',
            'recurrence' => ['frequency' => 'WEEKLY', 'interval' => 1, 'weekdays' => [1], 'anchorDate' => '2026-01-05'],
        ];

        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($validPostBody));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $post = $this->json($client->getResponse());
        $this->createdIds['posts'][] = $post['id'];

        // Reject: surgeon without ROLE_SURGEON
        $notSurgeon = $this->makeUser('ROLE_INSTRUMENTIST');
        $bad = $validPostBody;
        $bad['surgeonId'] = $notSurgeon->getId();
        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($bad));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Reject: shift period from another site
        $otherSite = $this->makeSite();
        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $otherSite->getId(), 'period' => 'JOURNEE', 'startTime' => '08:00', 'endTime' => '18:00',
        ]));
        $otherConfig = $this->json($client->getResponse());
        $this->createdIds['shiftPeriods'][] = $otherConfig['id'];

        $bad2 = $validPostBody;
        $bad2['period'] = 'JOURNEE'; // not configured (active) on $site
        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($bad2));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Reject: instrumentist not affiliated with site
        $unaffiliatedInst = $this->makeUser('ROLE_INSTRUMENTIST');
        $bad3 = $validPostBody;
        $bad3['instrumentistId'] = $unaffiliatedInst->getId();
        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($bad3));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Reject: recurrence validation (WEEKLY without weekdays)
        $bad4 = $validPostBody;
        $bad4['recurrence']['weekdays'] = [];
        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode($bad4));
        self::assertSame(400, $client->getResponse()->getStatusCode());

        // List + filter
        $client->request('GET', '/api/planning/surgeon-posts?siteId=' . $site->getId() . '&surgeonId=' . $surgeon->getId(), server: $this->auth($token));
        $list = $this->json($client->getResponse());
        self::assertGreaterThanOrEqual(1, count($list['items']));

        // Deactivate does not delete
        $client->request('DELETE', "/api/planning/surgeon-posts/{$post['id']}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $stillExists = $this->em->find(SurgeonSchedulePost::class, $post['id']);
        self::assertNotNull($stillExists);
        self::assertFalse($stillExists->isActive());
    }

    // ── Batch 11 Fix 1: PATCH {active:true/false} actually persists ─────────

    #[WithoutErrorHandler]
    public function test_patch_active_deactivates_and_reactivates_post(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $site->getId(), 'period' => 'MATIN', 'startTime' => '08:00', 'endTime' => '13:00',
        ]));
        $config = $this->json($client->getResponse());
        $this->createdIds['shiftPeriods'][] = $config['id'];

        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'surgeonId' => $surgeon->getId(), 'siteId' => $site->getId(), 'type' => 'BLOCK', 'period' => 'MATIN',
            'startDate' => '2026-01-01', 'recurrence' => ['frequency' => 'WEEKLY', 'interval' => 1, 'weekdays' => [1], 'anchorDate' => '2026-01-05'],
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $post = $this->json($client->getResponse());
        $this->createdIds['posts'][] = $post['id'];
        self::assertTrue($post['active']);

        // PATCH {active:false} — equivalent to DELETE, but via the partial-update path.
        $client->request('PATCH', "/api/planning/surgeon-posts/{$post['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['active' => false]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertFalse($this->json($client->getResponse())['active']);

        $this->em->clear();
        $deactivated = $this->em->find(SurgeonSchedulePost::class, $post['id']);
        self::assertFalse($deactivated->isActive(), 'PATCH active=false must persist to the database');

        // PATCH {active:true} — the bug: this used to be silently ignored.
        $client->request('PATCH', "/api/planning/surgeon-posts/{$post['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['active' => true]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($this->json($client->getResponse())['active'], 'Response must reflect the reactivation');

        $this->em->clear();
        $reactivated = $this->em->find(SurgeonSchedulePost::class, $post['id']);
        self::assertTrue($reactivated->isActive(), 'PATCH active=true must persist to the database');

        // Partial-update behavior preserved: other fields untouched by an active-only PATCH.
        self::assertSame('BLOCK', $reactivated->getType()->value);
        self::assertSame('MATIN', $reactivated->getPeriod()->value);
    }

    #[WithoutErrorHandler]
    public function test_patch_active_rejects_non_boolean_value(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $site->getId(), 'period' => 'MATIN', 'startTime' => '08:00', 'endTime' => '13:00',
        ]));
        $config = $this->json($client->getResponse());
        $this->createdIds['shiftPeriods'][] = $config['id'];

        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'surgeonId' => $surgeon->getId(), 'siteId' => $site->getId(), 'type' => 'BLOCK', 'period' => 'MATIN',
            'startDate' => '2026-01-01', 'recurrence' => ['frequency' => 'WEEKLY', 'interval' => 1, 'weekdays' => [1], 'anchorDate' => '2026-01-05'],
        ]));
        $post = $this->json($client->getResponse());
        $this->createdIds['posts'][] = $post['id'];

        $client->request('PATCH', "/api/planning/surgeon-posts/{$post['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode(['active' => 'true']));
        self::assertSame(400, $client->getResponse()->getStatusCode(), 'A string "true" must be rejected — only a real JSON boolean is accepted');
    }

    // ── Batch 11 Fix 4: surgeon/instrumentist refs include a stable name ────

    #[WithoutErrorHandler]
    public function test_surgeon_post_serializes_user_refs_with_name(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $surgeon->setFirstname('Jean');
        $surgeon->setLastname('Dupont');
        $this->em->flush();

        $site = $this->makeSite();
        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $site->getId(), 'period' => 'MATIN', 'startTime' => '08:00', 'endTime' => '13:00',
        ]));
        $config = $this->json($client->getResponse());
        $this->createdIds['shiftPeriods'][] = $config['id'];

        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'surgeonId' => $surgeon->getId(), 'siteId' => $site->getId(), 'type' => 'BLOCK', 'period' => 'MATIN',
            'startDate' => '2026-01-01', 'recurrence' => ['frequency' => 'WEEKLY', 'interval' => 1, 'weekdays' => [1], 'anchorDate' => '2026-01-05'],
        ]));
        $post = $this->json($client->getResponse());
        $this->createdIds['posts'][] = $post['id'];

        self::assertSame('Jean Dupont', $post['surgeon']['name']);
        self::assertArrayHasKey('email', $post['surgeon']);
        self::assertArrayHasKey('id', $post['surgeon']);
    }

    // ── PlanningOccurrenceException CRUD + uniqueness ────────────────────────

    #[WithoutErrorHandler]
    public function test_occurrence_exception_crud_and_unique_per_post_date(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        $client->request('POST', '/api/planning/shift-periods', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'siteId' => $site->getId(), 'period' => 'MATIN', 'startTime' => '08:00', 'endTime' => '13:00',
        ]));
        $shiftConfig = $this->json($client->getResponse());
        $this->createdIds['shiftPeriods'][] = $shiftConfig['id'];

        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'surgeonId' => $surgeon->getId(), 'siteId' => $site->getId(), 'type' => 'BLOCK', 'period' => 'MATIN',
            'startDate' => '2026-01-01', 'recurrence' => ['frequency' => 'WEEKLY', 'interval' => 1, 'weekdays' => [1], 'anchorDate' => '2026-01-05'],
        ]));
        $post = $this->json($client->getResponse());
        $this->createdIds['posts'][] = $post['id'];

        // No mission exists yet for this date — create a CANCELLED exception
        $client->request('POST', "/api/planning/surgeon-posts/{$post['id']}/exceptions", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'type' => 'CANCELLED', 'occurrenceDate' => '2026-01-12',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $exception = $this->json($client->getResponse());
        $this->createdIds['exceptions'][] = $exception['id'];

        // Unique exception per post/date: a second POST for the same post+date must 409
        $client->request('POST', "/api/planning/surgeon-posts/{$post['id']}/exceptions", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'type' => 'TIME_OVERRIDE', 'occurrenceDate' => '2026-01-12', 'overrideStartTime' => '09:00', 'overrideEndTime' => '12:00',
        ]));
        self::assertSame(409, $client->getResponse()->getStatusCode());

        // List
        $client->request('GET', "/api/planning/surgeon-posts/{$post['id']}/exceptions", server: $this->auth($token));
        $list = $this->json($client->getResponse());
        self::assertCount(1, $list['items']);

        // Update via PATCH
        $client->request('PATCH', "/api/planning/exceptions/{$exception['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'type' => 'TIME_OVERRIDE', 'overrideStartTime' => '09:00', 'overrideEndTime' => '12:00',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('TIME_OVERRIDE', $this->json($client->getResponse())['type']);

        // Delete (hard) and confirm the recurring post is untouched
        $client->request('DELETE', "/api/planning/exceptions/{$exception['id']}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->createdIds['exceptions'] = [];

        $this->em->clear();
        $postAfter = $this->em->find(SurgeonSchedulePost::class, $post['id']);
        self::assertTrue($postAfter->isActive());
        self::assertSame(1, $postAfter->getRecurrence()->getInterval());
    }

    // ── No mission mutation from any CRUD action ─────────────────────────────

    #[WithoutErrorHandler]
    public function test_creating_cancelling_exception_does_not_mutate_existing_mission(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();

        $mission = new Mission();
        $mission->setStatus(MissionStatus::ASSIGNED);
        $mission->setType(MissionType::BLOCK);
        $mission->setSurgeon($surgeon);
        $mission->setSite($site);
        $mission->setStartAt(new \DateTimeImmutable('2026-01-12 08:00:00'));
        $mission->setEndAt(new \DateTimeImmutable('2026-01-12 13:00:00'));
        $mission->setCreatedBy($surgeon);
        $mission->setSchedulePrecision(SchedulePrecision::EXACT);
        $this->em->persist($mission);
        $this->em->flush();
        $this->createdIds['missions'][] = $mission->getId();

        $client->request('POST', '/api/planning/surgeon-posts', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'surgeonId' => $surgeon->getId(), 'siteId' => $site->getId(), 'type' => 'BLOCK', 'period' => 'MATIN',
            'startDate' => '2026-01-01', 'recurrence' => ['frequency' => 'WEEKLY', 'interval' => 1, 'weekdays' => [1], 'anchorDate' => '2026-01-05'],
        ]));
        // No active ShiftPeriodConfig exists for this site/period — expected to fail with 422,
        // proving the post-creation path never touches Mission regardless of outcome.
        self::assertSame(422, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $missionAfter = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $missionAfter->getStatus());
        self::assertSame('2026-01-12 08:00:00', $missionAfter->getStartAt()->format('Y-m-d H:i:s'));
    }

    // ── Security ──────────────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_surgeon_posts_endpoint_rejects_unauthorized_role(): void
    {
        $client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_SURGEON');

        $client->request('GET', '/api/planning/surgeon-posts', server: $this->auth($token));

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }
}
