<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Message\AbsenceSelfDeclaredMessage;
use App\Message\InstrumentistAbsenceOccurrenceImpactMessage;
use App\MessageHandler\InstrumentistAbsenceOccurrenceImpactMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Complementary lot to SurgeonAbsenceOccurrenceImpactFunctionalTest (Lot 3, D-103) — real-HTTP,
 * real-database coverage for InstrumentistAbsenceOccurrenceImpactService: the symmetric
 * "instrumentist absent before generation" case, which notifies the surgeon(s) whose Posts
 * list this person as the default instrumentist. Deliberately never creates a
 * PlanningOccurrenceException or mutates a SurgeonSchedulePost — purely a heads-up
 * notification, idempotent via range-diffing rather than a stored marker (see the service's
 * class docblock).
 */
final class InstrumentistAbsenceOccurrenceImpactFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'ComplOccurrenceTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'missions' => [], 'users' => [], 'posts' => []];

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
                foreach ($this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')->where('e.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $event) {
                    $this->em->remove($event);
                }
                $this->em->remove($mission);
            }
            $this->em->flush();

            foreach ($this->createdIds['users'] as $id) {
                $user = $this->em->find(User::class, $id);
                if ($user === null) { continue; }
                foreach ($this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')->where('n.user = :u')->setParameter('u', $user)->getQuery()->getResult() as $notification) {
                    $this->em->remove($notification);
                }
                foreach ($this->em->createQueryBuilder()->select('e')->from(AuditEvent::class, 'e')->where('e.actor = :u')->setParameter('u', $user)->getQuery()->getResult() as $event) {
                    $this->em->remove($event);
                }
            }
            $this->em->flush();

            foreach ($this->createdIds['posts'] as $id) {
                $e = $this->em->find(SurgeonSchedulePost::class, $id);
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

    // ── Fixtures (mirrors SurgeonAbsenceOccurrenceImpactFunctionalTest) ─────────

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('complocc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $u->setEmail('complocc-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('ComplOcc Site ' . bin2hex(random_bytes(3)));
        $this->em->persist($h);
        $this->em->flush();
        return $h;
    }

    private function makeShiftConfig(Hospital $site, ShiftPeriod $period, string $start = '08:00', string $end = '13:00'): void
    {
        $c = new ShiftPeriodConfig();
        $c->setSite($site);
        $c->setPeriod($period);
        $c->setStartTime(new \DateTimeImmutable($start));
        $c->setEndTime(new \DateTimeImmutable($end));
        $this->em->persist($c);
        $this->em->flush();
    }

    private function makePost(
        User $surgeon,
        Hospital $site,
        ?User $instrumentist,
        RecurrenceFrequency $frequency,
        array $weekdays,
        \DateTimeImmutable $anchorDate,
        int $interval = 1,
        array $monthWeeks = [],
        string $startDate = '2026-01-01',
    ): SurgeonSchedulePost {
        $rule = new RecurrenceRule();
        $rule->setFrequency($frequency);
        $rule->setInterval($interval);
        $rule->setWeekdays($weekdays);
        $rule->setAnchorDate($anchorDate);
        $rule->setMonthWeeks($monthWeeks);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setInstrumentist($instrumentist);
        $post->setStartDate(new \DateTimeImmutable($startDate));
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();
        return $post;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, string $day): Mission
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
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    private function auditNoticesForAbsence(int $absenceId): array
    {
        $events = $this->em->createQueryBuilder()
            ->select('e')->from(AuditEvent::class, 'e')
            ->where('e.eventType = :t')->setParameter('t', AuditEventType::PLANNING_OCCURRENCE_INSTRUMENTIST_ABSENCE_NOTICE)
            ->getQuery()->getResult();

        return array_values(array_filter($events, fn (AuditEvent $e) => ($e->getPayload()['absenceId'] ?? null) === $absenceId));
    }

    // ── §17.B — Instrumentiste absente, aucun planning généré ─────────────────

    #[WithoutErrorHandler]
    public function test_instrumentist_absence_on_matching_weekday_notifies_the_surgeon(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON', 'Xavier');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST', 'Sophie');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Friday weekly post, anchored 2026-08-07.
        $post = $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-11', 'dateEnd' => '2026-08-18',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;

        $this->em->flush();
        $this->em->clear();

        $notices = $this->auditNoticesForAbsence($absenceId);
        self::assertCount(1, $notices);
        self::assertSame('NEWLY_IMPACTED', $notices[0]->getPayload()['direction']);
        self::assertSame('2026-08-14', $notices[0]->getPayload()['occurrenceDate']);
        self::assertSame($surgeon->getId(), $notices[0]->getPayload()['surgeonId']);
        self::assertSame($instr->getId(), $notices[0]->getPayload()['instrumentistId']);
        self::assertNull($notices[0]->getMission(), 'No Mission must exist yet — purely a heads-up');

        // No Mission was artificially created.
        $missionCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')->from(Mission::class, 'm')
            ->where('m.surgeon = :s')->setParameter('s', $surgeon)
            ->getQuery()->getSingleScalarResult();
        self::assertSame(0, $missionCount);

        // The Post itself is untouched.
        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertSame($instr->getId(), $post->getInstrumentist()->getId());
    }

    // ── §17.G — Absence hors occurrence réelle ─────────────────────────────────

    #[WithoutErrorHandler]
    public function test_absence_not_covering_the_weekday_notifies_nobody(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Friday weekly post — a Monday-Tuesday absence never touches it.
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-11',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;

        $this->em->flush();
        $this->em->clear();

        self::assertCount(0, $this->auditNoticesForAbsence($absenceId));
    }

    // ── §17.F — Mission déjà existante ─────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_occurrence_with_an_existing_mission_is_not_double_treated(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));
        // A Mission already exists for the 14/08 occurrence (already generated).
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-14');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;

        $this->em->flush();
        $this->em->clear();

        self::assertCount(0, $this->auditNoticesForAbsence($absenceId), 'Already handled by AbsenceMissionReactionService — no double treatment');

        // The existing Mission mechanism DID still run (released as usual).
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());
    }

    // ── §17.H — Récurrence interval=2 ──────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_interval_2_recurrence_only_notifies_for_true_occurrences(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Friday, every OTHER week, anchored 2026-08-07 — active weeks: 07/08, 21/08.
        // 14/08 is the SKIPPED off-week and must never be notified.
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'), interval: 2);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-07', 'dateEnd' => '2026-08-21',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;

        $this->em->flush();
        $this->em->clear();

        $notices = $this->auditNoticesForAbsence($absenceId);
        $dates = array_map(fn (AuditEvent $e) => $e->getPayload()['occurrenceDate'], $notices);
        sort($dates);
        self::assertSame(['2026-08-07', '2026-08-21'], $dates);
    }

    // ── §9/C — Instrumentiste sur plusieurs chirurgiens ───────────────────────

    #[WithoutErrorHandler]
    public function test_instrumentist_on_multiple_surgeons_notifies_each_with_only_their_own_dates(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeonA = $this->makeUser('ROLE_SURGEON', 'DrA');
        $surgeonB = $this->makeUser('ROLE_SURGEON', 'DrB');
        $instr    = $this->makeUser('ROLE_INSTRUMENTIST', 'Sophie');
        $site     = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Monday with Dr A, Wednesday with Dr B — both inside the absence window.
        $this->makePost($surgeonA, $site, $instr, RecurrenceFrequency::WEEKLY, [1], new \DateTimeImmutable('2026-08-10'));
        $this->makePost($surgeonB, $site, $instr, RecurrenceFrequency::WEEKLY, [3], new \DateTimeImmutable('2026-08-12'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof InstrumentistAbsenceOccurrenceImpactMessage) {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope, 'Exactly one message dispatched for this absence-processing run');
        self::assertCount(2, $envelope->getMessage()->newlyImpacted, 'One occurrence per surgeon, both carried in the same message');

        static::getContainer()->get(InstrumentistAbsenceOccurrenceImpactMessageHandler::class)
            ->__invoke($envelope->getMessage());
        $this->em->flush();
        $this->em->clear();

        $surgeonA = $this->em->find(User::class, $surgeonA->getId());
        $surgeonB = $this->em->find(User::class, $surgeonB->getId());

        $notifsA = $this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')->where('n.user = :u')->setParameter('u', $surgeonA)->getQuery()->getResult();
        $notifsB = $this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')->where('n.user = :u')->setParameter('u', $surgeonB)->getQuery()->getResult();

        self::assertCount(1, $notifsA, 'Dr A gets exactly one notification, for their own date only');
        self::assertCount(1, $notifsB, 'Dr B gets exactly one notification, for their own date only');
        self::assertSame('2026-08-10', $notifsA[0]->getPayload()['occurrenceDate']);
        self::assertSame('2026-08-12', $notifsB[0]->getPayload()['occurrenceDate']);
    }

    // ── §10/D — Plusieurs Posts, même binôme ──────────────────────────────────

    #[WithoutErrorHandler]
    public function test_multiple_posts_same_pair_consolidate_into_one_notification(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Monday AND Wednesday, same surgeon+instrumentist pair.
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [1], new \DateTimeImmutable('2026-08-10'));
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [3], new \DateTimeImmutable('2026-08-12'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof InstrumentistAbsenceOccurrenceImpactMessage) {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope);

        static::getContainer()->get(InstrumentistAbsenceOccurrenceImpactMessageHandler::class)
            ->__invoke($envelope->getMessage());
        $this->em->flush();
        $this->em->clear();

        $surgeon = $this->em->find(User::class, $surgeon->getId());
        $notifs  = $this->em->createQueryBuilder()->select('n')->from(NotificationEvent::class, 'n')->where('n.user = :u')->setParameter('u', $surgeon)->getQuery()->getResult();
        self::assertCount(2, $notifs, 'Two occurrences, still ONE consolidated recap — not two separate emails');
    }

    // ── §16 (idempotence) — a no-op update never re-notifies ──────────────────

    #[WithoutErrorHandler]
    public function test_updating_the_same_absence_with_identical_dates_does_not_renotify(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->auditNoticesForAbsence($absenceId));

        // PATCH with identical dates (e.g. only the reason changes) — must never re-notify.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14', 'reason' => 'congé',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->auditNoticesForAbsence($absenceId), 'A no-op date update must never duplicate the notice — this is what avoids spam (§14)');
    }

    // ── §16 (grow) — widening the range only notifies the NEW dates ───────────

    #[WithoutErrorHandler]
    public function test_widening_the_range_only_notifies_the_newly_added_dates(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->auditNoticesForAbsence($absenceId));

        // Widen to also cover 21/08.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-21',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $notices = $this->auditNoticesForAbsence($absenceId);
        $newlyImpacted = array_values(array_filter($notices, fn (AuditEvent $e) => $e->getPayload()['direction'] === 'NEWLY_IMPACTED'));
        self::assertCount(2, $newlyImpacted, 'The original 14/08 notice, plus exactly one new notice for 21/08 — never a re-notify of 14/08');
        $dates = array_map(fn (AuditEvent $e) => $e->getPayload()['occurrenceDate'], $newlyImpacted);
        sort($dates);
        self::assertSame(['2026-08-14', '2026-08-21'], $dates);
    }

    // ── §14/§16 (shrink) — narrowing the range signals "no longer impacted" ───

    #[WithoutErrorHandler]
    public function test_shrinking_the_range_signals_no_longer_impacted_for_the_dropped_date(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-07', 'dateEnd' => '2026-08-21',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        self::assertCount(3, $this->auditNoticesForAbsence($absenceId), '07/08, 14/08, 21/08');

        // Shrink so 21/08 falls out of range.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-07', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $notices = $this->auditNoticesForAbsence($absenceId);
        $recovered = array_values(array_filter($notices, fn (AuditEvent $e) => $e->getPayload()['direction'] === 'NO_LONGER_IMPACTED'));
        self::assertCount(1, $recovered);
        self::assertSame('2026-08-21', $recovered[0]->getPayload()['occurrenceDate']);
    }

    // ── §5 (self-service) — instrumentist declaring their own absence also triggers this ──

    #[WithoutErrorHandler]
    public function test_self_service_instrumentist_absence_also_notifies_the_surgeon(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $instr] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;

        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->auditNoticesForAbsence($absenceId));
    }

    // ── Regression (D-107 fix) — self-service instrumentist absence that ONLY impacts a
    // future occurrence must never ALSO trigger the generic "rien ne s'est passé" notice ──

    #[WithoutErrorHandler]
    public function test_self_service_instrumentist_absence_impacting_only_a_future_occurrence_does_not_also_send_the_generic_nothing_happened_notice(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $instr] = $this->authenticate($client, 'ROLE_INSTRUMENTIST');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        // Only a future Post occurrence is impacted — no Mission, no PlanningAlert.
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->auditNoticesForAbsence($absenceId), 'Precondition: the real impact notice DID fire');

        $selfDeclared = array_values(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof AbsenceSelfDeclaredMessage,
        ));
        self::assertCount(0, $selfDeclared, 'Before the D-107 fix, this generic "nothing happened" notice would ALSO fire alongside the real one — contradictory duplicate for the manager');
    }

    // ── Cross-role sanity — a SURGEON absence never triggers this service ─────

    #[WithoutErrorHandler]
    public function test_surgeon_absence_never_triggers_this_service(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;

        $this->em->flush();
        $this->em->clear();

        self::assertCount(0, $this->auditNoticesForAbsence($absenceId));
    }
}
