<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\RecurrenceRule;
use App\Entity\ReleasedOperatingRoomSlot;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\PlanningAlertType;
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

    /**
     * In test env the "async" transport (MissionLifecycleChangedMessage AND
     * AbsenceMissionsReactedMessage both route there) is Symfony's InMemoryTransport — nothing
     * consumes it automatically, exactly like the pre-existing
     * test_release_creates_audit_event_with_reason_and_dispatches_notification_events_for_correct_recipients
     * pattern above. Drains every message currently queued and runs each through its real
     * handler synchronously, exactly as a worker would, so NotificationEvent persistence can be
     * asserted end-to-end. Must be called after $this->em->clear() so handler-side entity loads
     * are fresh.
     */
    private function processQueuedAsyncMessages(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $envelopes = $transport->getSent();
        $transport->reset();

        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof \App\Message\AbsenceMissionsReactedMessage) {
                static::getContainer()->get(\App\MessageHandler\AbsenceMissionsReactedMessageHandler::class)->__invoke($message);
            } elseif ($message instanceof \App\Message\MissionLifecycleChangedMessage) {
                static::getContainer()->get(\App\MessageHandler\MissionLifecycleChangedMessageHandler::class)->__invoke($message);
            }
        }

        $this->em->flush();
        $this->em->clear();
    }

    // ── CAS B (D-117) helpers ─────────────────────────────────────────────────

    private function makeMissionAt(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, string $day, string $startTime, string $endTime): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable("{$day} {$startTime}:00"));
        $m->setEndAt(new \DateTimeImmutable("{$day} {$endTime}:00"));
        $m->setCreatedBy($surgeon);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    /** @return NotificationEvent[] */
    private function notificationsOfType(User $user, string $eventType): array
    {
        return array_values(array_filter(
            $this->notificationsForUser($user),
            static fn (NotificationEvent $n) => $n->getEventType() === $eventType,
        ));
    }

    /** @return AuditEvent[] */
    private function auditEventsOfType(Mission $mission, AuditEventType $type): array
    {
        return array_values(array_filter(
            $this->auditEventsForMission($mission),
            static fn (AuditEvent $e) => $e->getEventType() === $type,
        ));
    }

    /**
     * Persists an Absence row directly (bypassing AbsenceController/AbsenceMissionReactionService
     * entirely) — used when the test needs a person to already BE absent for an eligibility
     * check (isAbsentOn()), without triggering that person's OWN absence reaction (e.g. B3:
     * the freed instrumentist happens to be absent too, on a day unrelated to what this test
     * is actually exercising).
     */
    private function persistAbsenceDirect(User $user, string $dateStart, string $dateEnd): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setCreatedBy($user);
        $a->setDateStart(new \DateTimeImmutable($dateStart));
        $a->setDateEnd(new \DateTimeImmutable($dateEnd));
        $this->em->persist($a);
        $this->em->flush();
        $this->createdIds['absences'][] = $a->getId();
        return $a;
    }

    /** A real Monday comfortably in the future — SurgeonAbsenceBlockOccurrenceResolver (and
     *  therefore ReleasedOperatingRoomSlotService) deliberately skips any occurrence date
     *  already in the past (`$date < $today`), unlike every other CAS B fixture in this file
     *  which uses a fixed past date (irrelevant for AbsenceMissionReactionService itself, which
     *  has no such guard). */
    private function nextMonday(): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable('today');
        $daysUntilMonday = (8 - (int) $today->format('N')) % 7;
        $daysUntilMonday = $daysUntilMonday === 0 ? 7 : $daysUntilMonday;
        return $today->modify("+{$daysUntilMonday} days")->modify('+14 days');
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
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
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
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
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
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
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
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
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
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
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

    // ══════════════════════════════════════════════════════════════════════════
    // CAS B (D-117) — surgeon absence on a published mission: search a compatible
    // same-day/same-site OPEN mission for the freed instrumentist before treating
    // them as genuinely released.
    // ══════════════════════════════════════════════════════════════════════════

    #[WithoutErrorHandler]
    public function test_b1_reassigns_freed_instrumentist_to_compatible_open_mission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON', 'Absent');
        $surgeon2 = $this->makeUser('ROLE_SURGEON', 'Cible');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST', 'Freed');
        $site     = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $missionB = $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();

        $reloadedA = $this->em->find(Mission::class, $missionA->getId());
        $reloadedB = $this->em->find(Mission::class, $missionB->getId());

        self::assertSame(MissionStatus::CANCELLED, $reloadedA->getStatus());
        self::assertNull($reloadedA->getInstrumentist());
        self::assertSame(MissionStatus::ASSIGNED, $reloadedB->getStatus());
        self::assertSame($instr->getId(), $reloadedB->getInstrumentist()?->getId());

        $reassignEvents = $this->auditEventsOfType($reloadedB, AuditEventType::MISSION_REASSIGNED_POST_DEPLOY);
        self::assertCount(1, $reassignEvents);
        $payload = $reassignEvents[0]->getPayload();
        self::assertSame($missionA->getId(), $payload['reassignedFromMissionId'] ?? null, 'Mission A → Instr X → Mission B must be readable from B\'s own AuditEvent payload');
        self::assertNotNull($payload['causedByAbsenceId'] ?? null);
        self::assertSame($instr->getId(), $payload['toInstrumentistId'] ?? null);

        $cancelEvents = $this->auditEventsOfType($reloadedA, AuditEventType::MISSION_CANCELLED_POST_DEPLOY);
        self::assertCount(1, $cancelEvents);
        self::assertSame($instr->getId(), $cancelEvents[0]->getPayload()['fromInstrumentistId'] ?? null, 'Mission A\'s own audit must still record who was on it before cancellation');
    }

    #[WithoutErrorHandler]
    public function test_b2_no_compatible_target_releases_instrumentist(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $reloadedA = $this->em->find(Mission::class, $missionA->getId());
        self::assertSame(MissionStatus::CANCELLED, $reloadedA->getStatus());
        self::assertNull($reloadedA->getInstrumentist(), 'No compatible OPEN mission exists — the instrumentist must be genuinely released, never left dangling on the cancelled mission');
    }

    #[WithoutErrorHandler]
    public function test_b3_absent_freed_instrumentist_is_never_reassigned(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $missionB = $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');

        // The freed instrumentist is themself absent that exact day, for an unrelated reason —
        // persisted directly so THIS absence's own reaction (which would release missionA a
        // second, irrelevant way) never runs; only its effect on eligibility matters here.
        $this->persistAbsenceDirect($instr, '2026-02-09', '2026-02-09');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $reloadedA = $this->em->find(Mission::class, $missionA->getId());
        $reloadedB = $this->em->find(Mission::class, $missionB->getId());

        self::assertSame(MissionStatus::CANCELLED, $reloadedA->getStatus());
        self::assertNull($reloadedA->getInstrumentist());
        self::assertSame(MissionStatus::OPEN, $reloadedB->getStatus(), 'ABSENT must block the automatic reassignment under STRICT_ASSIGNMENT');
        self::assertNull($reloadedB->getInstrumentist());
    }

    #[WithoutErrorHandler]
    public function test_b4_open_mission_on_another_site_is_never_used(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site1    = $this->makeSite();
        $site2    = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site1, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $missionB = $this->makeMissionAt($surgeon2, null, $site2, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $reloadedA = $this->em->find(Mission::class, $missionA->getId());
        $reloadedB = $this->em->find(Mission::class, $missionB->getId());

        self::assertSame(MissionStatus::CANCELLED, $reloadedA->getStatus());
        self::assertNull($reloadedA->getInstrumentist());
        self::assertSame(MissionStatus::OPEN, $reloadedB->getStatus(), 'Cross-site auto-reassignment is explicitly out of scope for this lot');
        self::assertNull($reloadedB->getInstrumentist());
    }

    #[WithoutErrorHandler]
    public function test_b5_schedule_conflict_with_another_active_mission_blocks_reassignment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $surgeon3 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $missionB = $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');
        // $instr is already legitimately busy elsewhere at the exact same slot as B — a real,
        // pre-existing (fixture-constructed) commitment independent of this absence.
        $missionC = $this->makeMissionAt($surgeon3, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $reloadedA = $this->em->find(Mission::class, $missionA->getId());
        $reloadedB = $this->em->find(Mission::class, $missionB->getId());
        $reloadedC = $this->em->find(Mission::class, $missionC->getId());

        self::assertSame(MissionStatus::CANCELLED, $reloadedA->getStatus());
        self::assertSame(MissionStatus::OPEN, $reloadedB->getStatus(), 'SCHEDULE_CONFLICT against C must block B under STRICT_ASSIGNMENT — never an illegal double booking');
        self::assertNull($reloadedB->getInstrumentist());
        self::assertSame(MissionStatus::ASSIGNED, $reloadedC->getStatus(), 'C itself must stay completely untouched');
        self::assertSame($instr->getId(), $reloadedC->getInstrumentist()?->getId());
    }

    #[WithoutErrorHandler]
    public function test_b6_covers_two_successive_non_overlapping_open_missions(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $surgeon3 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '18:00');
        $missionB = $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '12:00');
        $missionC = $this->makeMissionAt($surgeon3, null, $site, MissionStatus::OPEN, '2026-02-09', '13:00', '18:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $reloadedB = $this->em->find(Mission::class, $missionB->getId());
        $reloadedC = $this->em->find(Mission::class, $missionC->getId());

        self::assertSame(MissionStatus::ASSIGNED, $reloadedB->getStatus());
        self::assertSame($instr->getId(), $reloadedB->getInstrumentist()?->getId());
        self::assertSame(MissionStatus::ASSIGNED, $reloadedC->getStatus());
        self::assertSame($instr->getId(), $reloadedC->getInstrumentist()?->getId(), 'The same freed instrumentist may cover two real, non-overlapping successive missions');

        // Exactly one combined notification — never one per target mission.
        $this->processQueuedAsyncMessages();
        $instrReloaded = $this->em->find(User::class, $instr->getId());
        $notifications = $this->notificationsOfType($instrReloaded, NotificationType::ABSENCE_INSTRUMENTIST_REASSIGNED->value);
        self::assertCount(1, $notifications);
        $payload = $notifications[0]->getPayload();
        self::assertCount(2, $payload['reassignedTo'] ?? [], 'Both successive reassignments must be listed in the single recap');
    }

    #[WithoutErrorHandler]
    public function test_b7_two_overlapping_open_missions_only_one_gets_assigned(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $surgeon3 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '18:00');
        $missionD = $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');
        $missionE = $this->makeMissionAt($surgeon3, null, $site, MissionStatus::OPEN, '2026-02-09', '10:00', '15:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $reloadedD = $this->em->find(Mission::class, $missionD->getId());
        $reloadedE = $this->em->find(Mission::class, $missionE->getId());

        $assignedCount = (int) ($reloadedD->getInstrumentist()?->getId() === $instr->getId())
            + (int) ($reloadedE->getInstrumentist()?->getId() === $instr->getId());
        self::assertSame(1, $assignedCount, 'D and E overlap each other — exactly one must be assigned, never both');

        $stillOpen = $reloadedD->getInstrumentist() === null ? $reloadedD : $reloadedE;
        self::assertSame(MissionStatus::OPEN, $stillOpen->getStatus());
    }

    #[WithoutErrorHandler]
    public function test_b8_several_freed_instrumentists_same_day_no_collision(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $target1  = $this->makeUser('ROLE_SURGEON');
        $target2  = $this->makeUser('ROLE_SURGEON');
        $instr1   = $this->makeUser('ROLE_INSTRUMENTIST');
        $instr2   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $missionA1 = $this->makeMissionAt($surgeon1, $instr1, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $missionA2 = $this->makeMissionAt($surgeon2, $instr2, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $missionB1 = $this->makeMissionAt($target1, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');
        $missionB2 = $this->makeMissionAt($target2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');

        foreach ([$surgeon1, $surgeon2] as $surgeon) {
            $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
                'userId' => $surgeon->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
            ]));
            self::assertSame(201, $client->getResponse()->getStatusCode());
            $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        }

        $this->em->clear();
        $reloadedB1 = $this->em->find(Mission::class, $missionB1->getId());
        $reloadedB2 = $this->em->find(Mission::class, $missionB2->getId());

        $assignees = array_filter([$reloadedB1->getInstrumentist()?->getId(), $reloadedB2->getInstrumentist()?->getId()]);
        self::assertCount(2, $assignees, 'Both OPEN missions must have been claimed — one per freed instrumentist');
        self::assertSame(2, count(array_unique($assignees)), 'The two freed instrumentists must never both land on the same mission');
        self::assertSame([$instr1->getId(), $instr2->getId()], self::sortedInts($assignees), 'Exactly instr1 and instr2, no one else, no duplicate');
    }

    #[WithoutErrorHandler]
    public function test_b9_replaying_the_same_absence_reaction_never_duplicates_anything(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $missionB = $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;

        $this->processQueuedAsyncMessages();
        $reloadedBBefore = $this->em->find(Mission::class, $missionB->getId());
        self::assertSame($instr->getId(), $reloadedBBefore->getInstrumentist()?->getId());

        // Replay: call AbsenceMissionReactionService::onAbsenceUpdated() directly a second time
        // for the exact same absence — this isolates CAS B's own idempotence claim (never
        // double-cancel/double-reassign/double-notify) from AbsenceImpactReconciliationService's
        // separate reconcileForUpdate() path, which the PATCH /api/absences endpoint also
        // triggers and which has its own pre-existing, unrelated bug: it does not check whether
        // the date range actually shrank before attempting restoration, so even a genuine no-op
        // PATCH makes it try to restore mission A — a real defect, reported separately, but not
        // what this test is about.
        $absence = $this->em->find(Absence::class, $absenceId);
        $manager = $this->makeUser('ROLE_MANAGER');
        static::getContainer()->get(\App\Service\AbsenceMissionReactionService::class)
            ->onAbsenceUpdated($absence, $manager);
        $this->em->flush();

        $this->processQueuedAsyncMessages();
        $reloadedA = $this->em->find(Mission::class, $missionA->getId());
        $reloadedB = $this->em->find(Mission::class, $missionB->getId());
        $instrReloaded = $this->em->find(User::class, $instr->getId());

        self::assertSame(MissionStatus::CANCELLED, $reloadedA->getStatus());
        self::assertSame(MissionStatus::ASSIGNED, $reloadedB->getStatus());
        self::assertSame($instr->getId(), $reloadedB->getInstrumentist()?->getId());

        self::assertCount(1, $this->auditEventsOfType($reloadedA, AuditEventType::MISSION_CANCELLED_POST_DEPLOY), 'No second cancellation audit event');
        self::assertCount(1, $this->auditEventsOfType($reloadedB, AuditEventType::MISSION_REASSIGNED_POST_DEPLOY), 'No second reassignment audit event');
        self::assertCount(1, $this->notificationsOfType($instrReloaded, NotificationType::ABSENCE_INSTRUMENTIST_REASSIGNED->value), 'No duplicate notification on replay');
    }

    #[WithoutErrorHandler]
    public function test_b10_deleting_the_absence_after_reassignment_never_double_books(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $missionB = $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $reloadedB = $this->em->find(Mission::class, $missionB->getId());
        self::assertSame($instr->getId(), $reloadedB->getInstrumentist()?->getId(), 'setup sanity: reassignment must have happened before we test its reconciliation');

        // Suppression de l'absence — X est désormais légitimement affecté à B.
        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        // absence removed server-side — not tracked in createdIds, nothing left to clean up for it.

        $this->em->clear();
        $reloadedA = $this->em->find(Mission::class, $missionA->getId());
        $reloadedBAfter = $this->em->find(Mission::class, $missionB->getId());

        self::assertSame(MissionStatus::OPEN, $reloadedA->getStatus(), 'SCHEDULE_CONFLICT with B (which genuinely overlaps A\'s original slot) must block restoring X onto A — never ASSIGNED');
        self::assertNull($reloadedA->getInstrumentist());
        self::assertSame(MissionStatus::ASSIGNED, $reloadedBAfter->getStatus(), 'X must remain exactly where CAS B legitimately put them');
        self::assertSame($instr->getId(), $reloadedBAfter->getInstrumentist()?->getId());

        $alerts = $this->em->createQueryBuilder()
            ->select('a')->from(PlanningAlert::class, 'a')
            ->where('a.mission = :m')->andWhere('a.type = :t')
            ->setParameter('m', $reloadedA)->setParameter('t', PlanningAlertType::REASSIGNMENT_REQUIRED)
            ->getQuery()->getResult();
        self::assertNotEmpty($alerts, 'A manager must be pointed at A for manual arbitration — it is no longer auto-restorable');
        foreach ($alerts as $alert) { $this->em->remove($alert); }
        $this->em->flush();
    }

    #[WithoutErrorHandler]
    public function test_b11_reassignment_notifications_are_exactly_instrumentist_plus_target_surgeon(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');
        $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, '2026-02-09', '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->processQueuedAsyncMessages();
        $instrReloaded   = $this->em->find(User::class, $instr->getId());
        $surgeon2Reloaded = $this->em->find(User::class, $surgeon2->getId());

        $reassigned = $this->notificationsOfType($instrReloaded, NotificationType::ABSENCE_INSTRUMENTIST_REASSIGNED->value);
        self::assertCount(1, $reassigned, 'exactly 1 notification for the reassigned instrumentist');
        $payload = $reassigned[0]->getPayload();
        self::assertArrayNotHasKey('patientName', $payload);
        self::assertArrayNotHasKey('patient', $payload);

        self::assertCount(0, $this->notificationsOfType($instrReloaded, NotificationType::ABSENCE_MISSION_CANCELLED->value), 'never both — the generic release notice must be suppressed');

        $covered = $this->notificationsOfType($surgeon2Reloaded, NotificationType::SURGEON_POST_COVERED->value);
        self::assertCount(1, $covered, 'exactly 1 notification for the target mission\'s surgeon');

        $genericReassignedToInstr = $this->notificationsOfType($instrReloaded, NotificationType::PLANNING_MISSION_REASSIGNED->value);
        self::assertCount(0, $genericReassignedToInstr, 'the generic free-pipeline notice to the instrumentist must be suppressed — ABSENCE_INSTRUMENTIST_REASSIGNED replaces it, never both');
    }

    #[WithoutErrorHandler]
    public function test_b12_release_without_reassignment_notifies_only_the_instrumentist(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();

        $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, '2026-02-09', '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => '2026-02-09', 'dateEnd' => '2026-02-09',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->processQueuedAsyncMessages();
        $instrReloaded = $this->em->find(User::class, $instr->getId());

        $cancelled = $this->notificationsOfType($instrReloaded, NotificationType::ABSENCE_MISSION_CANCELLED->value);
        self::assertCount(1, $cancelled, 'exactly 1 release notification');
        $payload = $cancelled[0]->getPayload();
        self::assertSame('2026-02-09', (\DateTimeImmutable::createFromFormat('d/m/Y', $payload['date'] ?? ''))?->format('Y-m-d'));
        self::assertArrayNotHasKey('patientName', $payload);

        self::assertCount(0, $this->notificationsOfType($instrReloaded, NotificationType::ABSENCE_INSTRUMENTIST_REASSIGNED->value), 'no target was found — never send the reassignment notice');
    }

    #[WithoutErrorHandler]
    public function test_b13_released_operating_room_slot_is_unaffected_by_reassignment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon1 = $this->makeUser('ROLE_SURGEON');
        $surgeon2 = $this->makeUser('ROLE_SURGEON');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST');
        $site     = $this->makeSite();
        $monday   = $this->nextMonday();
        $day      = $monday->format('Y-m-d');

        $shiftConfig = new ShiftPeriodConfig();
        $shiftConfig->setSite($site);
        $shiftConfig->setPeriod(ShiftPeriod::MATIN);
        $shiftConfig->setStartTime(new \DateTimeImmutable('08:00'));
        $shiftConfig->setEndTime(new \DateTimeImmutable('13:00'));
        $this->em->persist($shiftConfig);

        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([1]);
        $rule->setAnchorDate($monday);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon1);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setStartDate($monday);
        $post->setEndDate($monday); // single occurrence, keeps the fixture minimal
        $post->setCreatedBy($surgeon1);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();

        $missionA = $this->makeMissionAt($surgeon1, $instr, $site, MissionStatus::ASSIGNED, $day, '08:00', '13:00');
        $missionB = $this->makeMissionAt($surgeon2, null, $site, MissionStatus::OPEN, $day, '08:00', '13:00');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon1->getId(), 'dateStart' => $day, 'dateEnd' => $day,
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $reloadedB = $this->em->find(Mission::class, $missionB->getId());
        self::assertSame($instr->getId(), $reloadedB->getInstrumentist()?->getId(), 'setup sanity: the reassignment must have happened');

        $slots = $this->em->createQueryBuilder()
            ->select('s')->from(ReleasedOperatingRoomSlot::class, 's')
            ->where('s.postId = :postId')
            ->setParameter('postId', $post->getId())
            ->getQuery()->getResult();

        self::assertCount(1, $slots, 'exactly one slot, unaffected by the fact that the freed instrumentist was reassigned elsewhere');
        /** @var ReleasedOperatingRoomSlot $slot */
        $slot = $slots[0];
        self::assertSame('08:00', $slot->getStartTime()?->format('H:i'), 'the slot must reflect the FULL original period — never shrunk to reflect what the reassigned instrumentist is actually using');
        self::assertSame('13:00', $slot->getEndTime()?->format('H:i'));

        $shiftConfigId = $shiftConfig->getId();
        $this->em->remove($slot);
        $this->em->remove($this->em->find(ShiftPeriodConfig::class, $shiftConfigId));
        $this->em->flush();
    }

    /** @param int[] $ids @return int[] */
    private static function sortedInts(array $ids): array
    {
        sort($ids);
        return array_values($ids);
    }
}
