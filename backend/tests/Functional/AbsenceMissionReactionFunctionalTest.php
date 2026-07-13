<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\RecurrenceRule;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\SchedulePrecision;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Real-HTTP, real-database coverage for AbsenceMissionReactionService — persistence,
 * idempotency, terminal-status exclusion, batching, and the SurgeonSchedulePost-untouched
 * guarantee. AbsenceControllerTest already covers the ASSIGNED→OPEN and ASSIGNED→CANCELLED
 * base cases plus the "no stale alert" interaction with AbsenceImpactService — this file
 * covers everything else from the feature's §9 test requirements.
 */
final class AbsenceMissionReactionFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'AbsenceReactTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'missions' => [], 'sites' => [], 'users' => [], 'posts' => []];

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

            foreach ($this->createdIds['missions'] as $id) {
                $mission = $this->em->find(Mission::class, $id);
                if ($mission === null) { continue; }
                foreach ($this->em->createQueryBuilder()->select('a')->from(PlanningAlert::class, 'a')->where('a.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $alert) {
                    $this->em->remove($alert);
                }
                foreach ($this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')->where('e.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $event) {
                    $this->em->remove($event);
                }
            }
            $this->em->flush();

            foreach ($this->createdIds['users'] as $id) {
                $user = $this->em->find(User::class, $id);
                if ($user === null) { continue; }
                foreach ($this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')->where('n.user = :u')->setParameter('u', $user)->getQuery()->getResult() as $notification) {
                    $this->em->remove($notification);
                }
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
        $user->setEmail('absreact-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function makeUser(string $role, string $firstname = 'Test'): User
    {
        $u = new User();
        $u->setEmail('absreact-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname($firstname);
        $u->setLastname('User');
        $u->setActive(true);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('AbsReact Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        return $h;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, string $day = '2026-02-09'): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
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

    private function auditEventsForMission(Mission $mission): array
    {
        return $this->em->createQueryBuilder()
            ->select('e')->from(AuditEvent::class, 'e')
            ->where('e.mission = :m')->setParameter('m', $mission)
            ->getQuery()->getResult();
    }

    private function notificationsForUser(User $user): array
    {
        return $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $user)
            ->getQuery()->getResult();
    }

    // ── Multiple overlapping missions, all processed ─────────────────────────

    #[WithoutErrorHandler]
    public function test_instrumentist_absence_over_several_missions_releases_all_of_them(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $m1 = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09');
        $m2 = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-02-10');
        $m3 = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-02-11');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-11',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $absence['id'];

        $this->em->flush();
        $this->em->clear();

        foreach ([$m1, $m2, $m3] as $mission) {
            $reloaded = $this->em->find(Mission::class, $mission->getId());
            self::assertSame(MissionStatus::OPEN, $reloaded->getStatus());
            self::assertNull($reloaded->getInstrumentist());
        }
    }

    // ── Out-of-period mission untouched ──────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_mission_outside_the_absence_period_is_unchanged(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $inPeriod  = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09');
        $outPeriod = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-05-20');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $inPeriodReloaded  = $this->em->find(Mission::class, $inPeriod->getId());
        $outPeriodReloaded = $this->em->find(Mission::class, $outPeriod->getId());
        self::assertSame(MissionStatus::OPEN, $inPeriodReloaded->getStatus());
        self::assertSame(MissionStatus::ASSIGNED, $outPeriodReloaded->getStatus(), 'A mission outside the absence period must never be touched');
        self::assertSame($instr->getId(), $outPeriodReloaded->getInstrumentist()->getId());
    }

    // ── Terminal statuses never retreated ────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_already_cancelled_or_closed_missions_are_never_retreated(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $cancelled = $this->makeMission($surgeon, $instr, $site, MissionStatus::CANCELLED, '2026-02-09');
        $closed    = $this->makeMission($surgeon, $instr, $site, MissionStatus::CLOSED, '2026-02-09');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        self::assertSame(MissionStatus::CANCELLED, $this->em->find(Mission::class, $cancelled->getId())->getStatus());
        self::assertSame(MissionStatus::CLOSED, $this->em->find(Mission::class, $closed->getId())->getStatus());
    }

    // ── AuditEvent + NotificationEvent persistence proof ─────────────────────

    #[WithoutErrorHandler]
    public function test_release_creates_audit_event_with_reason_and_dispatches_notification_events_for_correct_recipients(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $unrelated = $this->makeUser('ROLE_INSTRUMENTIST'); // must receive nothing
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED);

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $mission = $this->em->find(Mission::class, $mission->getId());
        $events  = $this->auditEventsForMission($mission);
        self::assertCount(1, $events);
        self::assertSame(AuditEventType::MISSION_RELEASED_TO_POOL, $events[0]->getEventType());
        self::assertStringContainsString('Absence instrumentiste', $events[0]->getPayload()['reason']);

        // In test env, the "async" transport is in-memory — nothing is consumed by a real
        // worker. Fetch the queued AbsenceMissionsReactedMessage and run it through the real
        // handler synchronously, exactly as the worker would, for true end-to-end proof of
        // NotificationEvent persistence (per §9 "Persistance réelle").
        $reactedEnvelope = null;
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof \App\Message\AbsenceMissionsReactedMessage) {
                $reactedEnvelope = $envelope;
            }
        }
        self::assertNotNull($reactedEnvelope, 'AbsenceMissionsReactedMessage must have been dispatched');
        static::getContainer()->get(\App\MessageHandler\AbsenceMissionsReactedMessageHandler::class)
            ->__invoke($reactedEnvelope->getMessage());

        $this->em->flush();
        $this->em->clear();

        $instr     = $this->em->find(User::class, $instr->getId());
        $unrelated = $this->em->find(User::class, $unrelated->getId());
        self::assertNotEmpty($this->notificationsForUser($instr), 'The removed instrumentist must receive an in-app notification');
        self::assertEmpty($this->notificationsForUser($unrelated), 'An unrelated instrumentist must receive nothing');
    }

    // ── Idempotency: repeated processing of the same absence ────────────────

    #[WithoutErrorHandler]
    public function test_repeated_update_with_unchanged_dates_does_not_re_release_or_duplicate_audit(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09', 'reason' => 'v1',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absence = $this->json($client->getResponse());
        $this->createdIds['absences'][] = $absence['id'];

        $this->em->flush();
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());
        self::assertCount(1, $this->auditEventsForMission($mission));

        // Re-PATCH the SAME date range twice (only the reason text changes) — the mission is
        // already OPEN/instrumentist-null, so it no longer matches the overlap query at all.
        foreach (['v2', 'v3'] as $reason) {
            $client->request('PATCH', "/api/absences/{$absence['id']}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
                'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09', 'reason' => $reason,
            ]));
            self::assertSame(200, $client->getResponse()->getStatusCode());
        }

        $this->em->flush();
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());
        self::assertCount(1, $this->auditEventsForMission($mission), 'Re-processing the same absence must never create a second AuditEvent for a mission already handled');
    }

    // ── Batching: one AbsenceMissionsReactedMessage per processing run ───────

    #[WithoutErrorHandler]
    public function test_one_absence_reacted_message_is_dispatched_per_absence_creation_covering_every_mission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09');
        $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-02-10');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-10',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $reacted = array_values(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof \App\Message\AbsenceMissionsReactedMessage,
        ));
        self::assertCount(1, $reacted, 'Exactly one AbsenceMissionsReactedMessage per absence-processing run, never one per mission');
        self::assertCount(2, $reacted[0]->getMessage()->missions);
    }

    // ── SurgeonSchedulePost is never touched ─────────────────────────────────

    #[WithoutErrorHandler]
    public function test_surgeon_schedule_post_is_completely_unchanged_by_absence_processing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED);

        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([1]);
        $rule->setAnchorDate(new \DateTimeImmutable('2026-02-02'));

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setInstrumentist($instr);
        $post->setStartDate(new \DateTimeImmutable('2026-02-01'));
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();

        $beforeSnapshot = [
            'surgeonId'       => $post->getSurgeon()->getId(),
            'siteId'          => $post->getSite()->getId(),
            'type'            => $post->getType()->value,
            'period'          => $post->getPeriod()->value,
            'instrumentistId' => $post->getInstrumentist()?->getId(),
            'startDate'       => $post->getStartDate()->format('Y-m-d'),
            'active'          => $post->isActive(),
        ];

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        // Confirm the mission WAS actually released (the feature did run) ...
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());

        // ... while the recurring post definition is byte-for-byte identical.
        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertSame($beforeSnapshot, [
            'surgeonId'       => $post->getSurgeon()->getId(),
            'siteId'          => $post->getSite()->getId(),
            'type'            => $post->getType()->value,
            'period'          => $post->getPeriod()->value,
            'instrumentistId' => $post->getInstrumentist()?->getId(),
            'startDate'       => $post->getStartDate()->format('Y-m-d'),
            'active'          => $post->isActive(),
        ], 'SurgeonSchedulePost must be completely untouched by absence-driven mission mutation');
    }
}
