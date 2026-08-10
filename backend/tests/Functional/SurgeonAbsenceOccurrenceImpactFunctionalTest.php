<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningOccurrenceException;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\OccurrenceExceptionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Message\SurgeonAbsenceOccurrencesNeutralizedMessage;
use App\MessageHandler\SurgeonAbsenceOccurrencesNeutralizedMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 3 (D-103) — real-HTTP, real-database coverage for
 * SurgeonAbsenceOccurrenceImpactService: detects future SurgeonSchedulePost occurrences
 * inside a surgeon absence window that have NO Mission generated yet, and records the
 * neutralization as a PlanningOccurrenceException (source = SURGEON_ABSENCE) without ever
 * mutating the Post/RecurrenceRule.
 */
final class SurgeonAbsenceOccurrenceImpactFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'Lot3OccurrenceTest123!';

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
            foreach ($this->createdIds['posts'] as $id) {
                foreach ($this->em->createQueryBuilder()->select('e')->from(PlanningOccurrenceException::class, 'e')->where('e.post = :p')->setParameter('p', $id)->getQuery()->getResult() as $exception) {
                    $this->em->remove($exception);
                }
            }
            $this->em->flush();

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

    // ── Fixtures ───────────────────────────────────────────────────────────────

    private function authenticate($client, string $role): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('lot3occ-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $u->setEmail('lot3occ-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Lot3Occ Site ' . bin2hex(random_bytes(3)));
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

    private function exceptionsForPost(SurgeonSchedulePost $post): array
    {
        return $this->em->createQueryBuilder()
            ->select('e')->from(PlanningOccurrenceException::class, 'e')
            ->where('e.post = :p')->setParameter('p', $post)
            ->orderBy('e.occurrenceDate', 'ASC')
            ->getQuery()->getResult();
    }

    // ── §23 — Post hebdomadaire simple ────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_weekly_post_absence_on_matching_weekday_creates_neutralization(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Friday (ISO 5) weekly post, anchored 2026-08-07 (a Friday).
        $post = $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $exceptions = $this->exceptionsForPost($post);
        self::assertCount(1, $exceptions);
        self::assertSame('2026-08-14', $exceptions[0]->getOccurrenceDate()->format('Y-m-d'));
        self::assertSame(OccurrenceExceptionType::CANCELLED, $exceptions[0]->getType());
        self::assertSame(OccurrenceExceptionSource::SURGEON_ABSENCE, $exceptions[0]->getSource());
        self::assertNotNull($exceptions[0]->getSourceAbsence());
    }

    // ── §23 — Post hors absence ────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_post_not_matching_the_weekday_is_not_neutralized(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Monday (ISO 1) weekly post — the absence week's Friday must not match it.
        $post = $this->makePost($surgeon, $site, null, RecurrenceFrequency::WEEKLY, [1], new \DateTimeImmutable('2026-08-03'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(0, $this->exceptionsForPost($post));
    }

    // ── §23 — Post avec instrumentiste par défaut ─────────────────────────────

    #[WithoutErrorHandler]
    public function test_default_instrumentist_is_recorded_as_potentially_impacted(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST', 'Sophie');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        $post = $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $auditEvents = $this->em->createQueryBuilder()
            ->select('e')->from(AuditEvent::class, 'e')
            ->where('e.eventType = :t')->setParameter('t', AuditEventType::PLANNING_OCCURRENCE_CANCELLED_DUE_TO_SURGEON_ABSENCE)
            ->getQuery()->getResult();

        $match = null;
        foreach ($auditEvents as $evt) {
            if (($evt->getPayload()['postId'] ?? null) === $post->getId()) {
                $match = $evt;
                break;
            }
        }
        self::assertNotNull($match, 'AuditEvent must be recorded for this neutralization');
        self::assertNull($match->getMission());
        self::assertSame($instr->getId(), $match->getPayload()['instrumentistId']);
        self::assertSame('Sophie User', $match->getPayload()['instrumentistName']);
    }

    // ── §23 — Post sans instrumentiste ────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_post_without_default_instrumentist_is_neutralized_with_null_instrumentist(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        $post = $this->makePost($surgeon, $site, null, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $exceptions = $this->exceptionsForPost($post);
        self::assertCount(1, $exceptions, 'The manager must still see the impact even with no default instrumentist');
    }

    // ── §23 — Plusieurs occurrences (multi-jours) ─────────────────────────────

    #[WithoutErrorHandler]
    public function test_multi_week_absence_neutralizes_every_matching_occurrence_with_one_grouped_message(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Friday weekly post — 07/08 and 14/08 are both Fridays inside the absence window.
        $post = $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-01', 'dateEnd' => '2026-08-16',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $exceptions = $this->exceptionsForPost($post);
        self::assertCount(2, $exceptions);
        self::assertSame(['2026-08-07', '2026-08-14'], array_map(fn ($e) => $e->getOccurrenceDate()->format('Y-m-d'), $exceptions));

        $neutralized = array_values(array_filter(
            $transport->getSent(),
            fn ($envelope) => $envelope->getMessage() instanceof SurgeonAbsenceOccurrencesNeutralizedMessage,
        ));
        self::assertCount(1, $neutralized, 'Exactly one SurgeonAbsenceOccurrencesNeutralizedMessage per absence-processing run, never one per occurrence');
        self::assertCount(2, $neutralized[0]->getMessage()->occurrences);
    }

    // ── §23 — Mission déjà existante ──────────────────────────────────────────

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

        $post = $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));
        // A Mission already exists for the 14/08 occurrence (already generated).
        $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-14');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(0, $this->exceptionsForPost($post), 'Already handled by AbsenceMissionReactionService — no double treatment');

        // The existing mission mechanism DID still run (Mission cancelled as usual).
        $mission = $this->em->createQueryBuilder()
            ->select('m')->from(Mission::class, 'm')
            ->where('m.surgeon = :s')->setParameter('s', $surgeon)
            ->getQuery()->getOneOrNullResult();
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());
    }

    // ── §23 — Idempotence ──────────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_updating_the_same_absence_twice_does_not_duplicate_neutralizations(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        $post = $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->createdIds['absences'][] = $absenceId;

        // PATCH the same absence with the exact same dates — a no-op update that should
        // still re-run the reaction pipeline (per AbsenceController::update()).
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(1, $this->exceptionsForPost($post), 'Re-running the same absence must never duplicate the neutralization');
    }

    // ── §23 — Recurrence interval=2 ────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_interval_2_recurrence_only_neutralizes_the_true_occurrences(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Friday, every OTHER week, anchored 2026-08-07 — active weeks: 07/08, 21/08, ...
        // 14/08 is the SKIPPED off-week and must never be neutralized.
        $post = $this->makePost($surgeon, $site, null, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'), interval: 2);

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-07', 'dateEnd' => '2026-08-21',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $exceptions = $this->exceptionsForPost($post);
        self::assertSame(['2026-08-07', '2026-08-21'], array_map(fn ($e) => $e->getOccurrenceDate()->format('Y-m-d'), $exceptions));
    }

    // ── §23 — Monthly recurrence ───────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_monthly_recurrence_occurrence_is_neutralized(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Monthly: 2nd Friday of the month. 2026-08-14 is the 2nd Friday of August 2026.
        $post = $this->makePost(
            $surgeon, $site, null, RecurrenceFrequency::MONTHLY, [5], new \DateTimeImmutable('2026-01-09'),
            interval: 1, monthWeeks: [2],
        );

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-01', 'dateEnd' => '2026-08-31',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $exceptions = $this->exceptionsForPost($post);
        self::assertCount(1, $exceptions);
        self::assertSame('2026-08-14', $exceptions[0]->getOccurrenceDate()->format('Y-m-d'));
    }

    // ── §23 — Existing manual CANCELLED exception ─────────────────────────────

    #[WithoutErrorHandler]
    public function test_existing_manual_cancelled_exception_is_never_overwritten(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        $post = $this->makePost($surgeon, $site, null, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        // Manager manually cancels the 14/08 occurrence BEFORE the absence is ever recorded.
        $manual = new PlanningOccurrenceException();
        $manual->setPost($post);
        $manual->setOccurrenceDate(new \DateTimeImmutable('2026-08-14'));
        $manual->setType(OccurrenceExceptionType::CANCELLED);
        $manual->setCreatedBy($manager);
        // source defaults to MANAGER — never set explicitly, matching every pre-Lot-3 row.
        $this->em->persist($manual);
        $this->em->flush();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $exceptions = $this->exceptionsForPost($post);
        self::assertCount(1, $exceptions, 'No duplicate exception must be created');
        self::assertSame(OccurrenceExceptionSource::MANAGER, $exceptions[0]->getSource(), 'Provenance must never be overwritten');
        self::assertNull($exceptions[0]->getSourceAbsence());
    }

    // ── Self-service surgeon absence also triggers this (§15, most realistic path) ──

    #[WithoutErrorHandler]
    public function test_self_service_surgeon_absence_also_neutralizes_future_occurrences(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $surgeon] = $this->authenticate($client, 'ROLE_SURGEON');

        $site = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, null, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences/mine', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        $this->em->flush();
        $this->em->clear();

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(1, $this->exceptionsForPost($post));
    }

    // ── §10 — Preview surfaces the neutralized occurrence with an explanation ────

    #[WithoutErrorHandler]
    public function test_preview_shows_the_neutralized_occurrence_as_skipped_instead_of_disappearing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON', 'Jean');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST', 'Sophie');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $client->request('POST', '/api/planning/v2/preview', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'year' => 2026, 'month' => 8, 'siteId' => $site->getId(),
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $preview = $this->json($client->getResponse());

        $line = null;
        foreach ($preview['lines'] as $l) {
            if ($l['date'] === '2026-08-14' && $l['postId'] === $post->getId()) {
                $line = $l;
                break;
            }
        }
        self::assertNotNull($line, 'The occurrence must still appear in the preview, not silently disappear');
        self::assertSame('SKIPPED', $line['status']);
    }

    // ── §24 — Notifications: instrumentist concerned gets exactly one recap ──────

    #[WithoutErrorHandler]
    public function test_default_instrumentist_receives_exactly_one_grouped_notification_and_unrelated_instrumentist_receives_none(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon   = $this->makeUser('ROLE_SURGEON');
        $instr     = $this->makeUser('ROLE_INSTRUMENTIST', 'Sophie');
        $unrelated = $this->makeUser('ROLE_INSTRUMENTIST', 'Marc');
        $site      = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-01', 'dateEnd' => '2026-08-16',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SurgeonAbsenceOccurrencesNeutralizedMessage) {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope);

        static::getContainer()->get(SurgeonAbsenceOccurrencesNeutralizedMessageHandler::class)
            ->__invoke($envelope->getMessage());
        $this->em->flush();
        $this->em->clear();

        $instr     = $this->em->find(User::class, $instr->getId());
        $unrelated = $this->em->find(User::class, $unrelated->getId());

        $instrNotifications = $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $instr)
            ->getQuery()->getResult();
        // Two occurrences neutralized (07/08 + 14/08), one in-app NotificationEvent each,
        // grouped under a single message/email — never one message per occurrence (§12).
        self::assertCount(2, $instrNotifications);
        foreach ($instrNotifications as $n) {
            self::assertNull($n->getMission(), 'No Mission exists for this event by design');
            self::assertArrayNotHasKey('patient', $n->getPayload() ?? []);
            self::assertArrayNotHasKey('patientName', $n->getPayload() ?? []);
        }

        $unrelatedNotifications = $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $unrelated)
            ->getQuery()->getResult();
        self::assertCount(0, $unrelatedNotifications, 'An instrumentist not on this Post must receive nothing');

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(2, $this->exceptionsForPost($post));
    }

    // ── §13 — Manager receives a summary of every impacted occurrence ────────────

    #[WithoutErrorHandler]
    public function test_manager_receives_a_summary_notification_covering_every_neutralized_occurrence(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $this->makePost($surgeon, $site, $instr, RecurrenceFrequency::WEEKLY, [5], new \DateTimeImmutable('2026-08-07'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-01', 'dateEnd' => '2026-08-16',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof SurgeonAbsenceOccurrencesNeutralizedMessage) {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope);
        self::assertContains($manager->getId(), $envelope->getMessage()->recipientManagerIds);

        static::getContainer()->get(SurgeonAbsenceOccurrencesNeutralizedMessageHandler::class)
            ->__invoke($envelope->getMessage());
        $this->em->flush();
        $this->em->clear();

        $manager = $this->em->find(User::class, $manager->getId());
        $managerNotifications = $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $manager)
            ->getQuery()->getResult();
        self::assertCount(2, $managerNotifications, 'One notification per neutralized occurrence for the manager summary');
    }
}
