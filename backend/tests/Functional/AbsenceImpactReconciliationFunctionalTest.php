<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\PlanningAlert;
use App\Entity\PlanningOccurrenceException;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\OccurrenceExceptionSource;
use App\Enum\OccurrenceExceptionType;
use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Message\PlanningRestoredAfterAbsenceMessage;
use App\MessageHandler\PlanningRestoredAfterAbsenceMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 4 (D-104) — real-HTTP, real-database coverage for AbsenceImpactReconciliationService:
 * restoring what an absence had previously neutralized/mutated, once that absence is
 * deleted or shortened, only when it's still safe to do so.
 */
final class AbsenceImpactReconciliationFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'Lot4ReconcileTest123!';

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
                foreach ($this->em->createQueryBuilder()->select('a')->from(PlanningAlert::class, 'a')->where('a.mission = :m')->setParameter('m', $mission)->getQuery()->getResult() as $alert) {
                    $this->em->remove($alert);
                }
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
                foreach ($this->em->createQueryBuilder()->select('a')->from(Absence::class, 'a')->where('a.user = :u')->setParameter('u', $user)->getQuery()->getResult() as $extra) {
                    $this->em->remove($extra);
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
        $user->setEmail('lot4rec-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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

    private function makeUser(string $role, string $firstname = 'Test', bool $active = true): User
    {
        $u = new User();
        $u->setEmail('lot4rec-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setFirstname($firstname);
        $u->setLastname('User');
        $u->setActive($active);
        $this->em->persist($u);
        $this->em->flush();
        $this->createdIds['users'][] = $u->getId();
        return $u;
    }

    private function makeSite(): Hospital
    {
        $h = new Hospital();
        $h->setName('Lot4Rec Site ' . bin2hex(random_bytes(3)));
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

    private function makePost(User $surgeon, Hospital $site, ?User $instrumentist, array $weekdays, \DateTimeImmutable $anchorDate, bool $active = true): SurgeonSchedulePost
    {
        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays($weekdays);
        $rule->setAnchorDate($anchorDate);
        $rule->setMonthWeeks([]);

        $post = new SurgeonSchedulePost();
        $post->setSurgeon($surgeon);
        $post->setSite($site);
        $post->setType(MissionType::BLOCK);
        $post->setPeriod(ShiftPeriod::MATIN);
        $post->setRecurrence($rule);
        $post->setInstrumentist($instrumentist);
        $post->setStartDate(new \DateTimeImmutable('2026-01-01'));
        $post->setActive($active);
        $post->setCreatedBy($surgeon);
        $this->em->persist($post);
        $this->em->flush();
        $this->createdIds['posts'][] = $post->getId();
        return $post;
    }

    private function makeMission(User $surgeon, ?User $instrumentist, Hospital $site, MissionStatus $status, string $day, string $start = '08:00:00', string $end = '13:00:00'): Mission
    {
        $m = new Mission();
        $m->setStatus($status);
        $m->setType(MissionType::BLOCK);
        $m->setSurgeon($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setSite($site);
        $m->setStartAt(new \DateTimeImmutable("{$day} {$start}"));
        $m->setEndAt(new \DateTimeImmutable("{$day} {$end}"));
        $m->setCreatedBy($surgeon);
        $this->em->persist($m);
        $this->em->flush();
        $this->createdIds['missions'][] = $m->getId();
        return $m;
    }

    private function makeAbsence(User $user, User $createdBy, string $start, string $end): Absence
    {
        $a = new Absence();
        $a->setUser($user);
        $a->setDateStart(new \DateTimeImmutable($start));
        $a->setDateEnd(new \DateTimeImmutable($end));
        $a->setCreatedBy($createdBy);
        $this->em->persist($a);
        $this->em->flush();
        return $a;
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

    private function alertsForMission(Mission $mission): array
    {
        return $this->em->createQueryBuilder()
            ->select('a')->from(PlanningAlert::class, 'a')
            ->where('a.mission = :m')->setParameter('m', $mission)
            ->getQuery()->getResult();
    }

    // ── §29 — occurrence future : suppression simple ─────────────────────────

    #[WithoutErrorHandler]
    public function test_deleting_the_absence_restores_a_neutralized_future_occurrence(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, null, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(1, $this->exceptionsForPost($post));

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(0, $this->exceptionsForPost($post), 'The occurrence must be restored (exception removed) once nothing justifies it anymore');
    }

    // ── §29 — deux absences chevauchantes ─────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_overlapping_absences_only_restore_the_occurrence_once_both_are_gone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, null, [5], new \DateTimeImmutable('2026-08-07'));

        // Absence A: 10/08 -> 15/08 (covers the 14/08 Friday occurrence)
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-15',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceAId = $this->json($client->getResponse())['id'];

        // Absence B: 14/08 -> 20/08 (also covers 14/08)
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-20',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceBId = $this->json($client->getResponse())['id'];

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(1, $this->exceptionsForPost($post), 'Only one exception per occurrence — B finds it already neutralized');
        $originalSourceAbsenceId = $this->exceptionsForPost($post)[0]->getSourceAbsence()?->getId();
        self::assertSame($absenceAId, $originalSourceAbsenceId, 'The first absence to neutralize it owns the causal link');

        // Delete A — B still covers 14/08, must NOT be restored.
        $client->request('DELETE', "/api/absences/{$absenceAId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(1, $this->exceptionsForPost($post), 'B still covers the date — must stay neutralized');

        // Delete B — nothing left to justify it, now restored.
        $client->request('DELETE', "/api/absences/{$absenceBId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(0, $this->exceptionsForPost($post), 'No absence covers it anymore — must be restored');
    }

    // ── §29/§30 — deux absences chevauchantes sur une Mission déjà matérialisée ──

    #[WithoutErrorHandler]
    public function test_overlapping_absences_on_a_materialized_mission_only_restore_once_both_are_gone(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-14');

        // Absence A: 10/08 -> 15/08 (covers the mission's 14/08).
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-15',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceAId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());

        // Absence B: 14/08 -> 20/08 (also covers 14/08). The mission is already CANCELLED,
        // so B's own reaction pass finds nothing to mutate (out of ASSIGNED/OPEN scope) —
        // the mission's AuditEvent trail still points at A alone.
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-20',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceBId = $this->json($client->getResponse())['id'];

        // Delete A — B still covers 14/08 for this surgeon, must NOT be restored even
        // though A is the absence the mission's own AuditEvent trail references.
        $client->request('DELETE', "/api/absences/{$absenceAId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus(), 'B still covers the surgeon on this date — must stay CANCELLED');

        // Delete B — nothing left to justify it; restored according to current eligibility.
        $client->request('DELETE', "/api/absences/{$absenceBId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus(), 'No absence covers the surgeon anymore — restorable, old instrumentist still eligible');
        self::assertSame($instr->getId(), $mission->getInstrumentist()?->getId());
    }

    // ── §29 — annulation manager reste ────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_manual_manager_cancellation_is_never_touched_by_reconciliation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, null, [5], new \DateTimeImmutable('2026-08-07'));

        $manual = new PlanningOccurrenceException();
        $manual->setPost($post);
        $manual->setOccurrenceDate(new \DateTimeImmutable('2026-08-14'));
        $manual->setType(OccurrenceExceptionType::CANCELLED);
        $manual->setCreatedBy($manager);
        $this->em->persist($manual);
        $this->em->flush();

        // An absence covering a DIFFERENT date, then deleted — reconciliation must never
        // touch the unrelated manual exception on 14/08.
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-21', 'dateEnd' => '2026-08-21',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $exceptions = $this->exceptionsForPost($post);
        self::assertCount(1, $exceptions, 'The manual exception must still exist');
        self::assertSame(OccurrenceExceptionSource::MANAGER, $exceptions[0]->getSource());
    }

    // ── §30 — Mission restaurable ASSIGNED ────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_cancelled_mission_is_restored_to_assigned_when_old_instrumentist_still_eligible(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST', 'Sophie');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-14');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        self::assertSame($instr->getId(), $mission->getInstrumentist()?->getId());

        // Real restoration notification dispatched.
        $envelope = null;
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof PlanningRestoredAfterAbsenceMessage) {
                $envelope = $e;
            }
        }
        self::assertNotNull($envelope);
        self::assertCount(1, $envelope->getMessage()->restoredMissions);
        self::assertSame('ASSIGNED', $envelope->getMessage()->restoredMissions[0]['restoredStatus']);
    }

    // ── §30 — instrumentiste devenu absent ────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_cancelled_mission_is_restored_to_open_when_old_instrumentist_is_now_absent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-14');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $surgeonAbsenceId = $this->json($client->getResponse())['id'];

        // The old instrumentist becomes absent on that same date in the meantime. Re-fetch
        // both — the login/request cycle above detaches previously-loaded entities.
        $instrReloaded = $this->em->find(User::class, $instr->getId());
        $managerReloaded = $this->em->find(User::class, $manager->getId());
        $this->makeAbsence($instrReloaded, $managerReloaded, '2026-08-14', '2026-08-14');

        $client->request('DELETE', "/api/absences/{$surgeonAbsenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $mission->getStatus(), 'Restored, but uncovered — never left CANCELLED');
        self::assertNull($mission->getInstrumentist());

        $alerts = $this->alertsForMission($mission);
        $active = array_values(array_filter($alerts, fn ($a) => $a->getStatus() === PlanningAlertStatus::OPEN));
        self::assertNotEmpty($active, 'A REASSIGNMENT_REQUIRED alert must exist');
        self::assertSame(PlanningAlertType::REASSIGNMENT_REQUIRED, $active[0]->getType());
    }

    // ── §30 — Mission modifiée par manager après annulation ───────────────────

    #[WithoutErrorHandler]
    public function test_manager_replacement_after_cancellation_is_never_overwritten(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-14');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());

        // Manager independently reactivates it a different way — a brand-new mission for
        // the same slot, standing in for "the manager already handled this by hand".
        // Simplest proxy of "manager touched the CANCELLED mission itself" without a
        // dedicated un-cancel endpoint: directly assert the mission is left CANCELLED when
        // reconciliation finds a foreign AuditEvent instead. We simulate that by writing a
        // manager-driven AuditEvent onto the mission (mirrors what any future manual action
        // would do) so its most-recent event no longer matches the absence's own.
        $auditService = static::getContainer()->get(\App\Service\AuditService::class);
        $manager = $this->em->find(User::class, $this->createdIds['users'][0]);
        $auditService->record($mission, $manager, \App\Enum\AuditEventType::MISSION_TIME_CHANGED_POST_DEPLOY, ['note' => 'manual manager touch']);
        $this->em->flush();

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus(), 'A manager touch after the cancellation must block any automatic restoration');
    }

    // ── §31 — release puis suppression absence (instrumentiste) ──────────────

    #[WithoutErrorHandler]
    public function test_released_mission_is_restored_to_the_same_instrumentist_once_their_absence_is_deleted(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-14');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());
        self::assertNull($mission->getInstrumentist());

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        self::assertSame($instr->getId(), $mission->getInstrumentist()?->getId());
    }

    // ── §31 — réassignation intermédiaire : Diane reste ───────────────────────

    #[WithoutErrorHandler]
    public function test_manager_reassignment_after_release_is_never_overwritten_by_reconciliation(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $sophie  = $this->makeUser('ROLE_INSTRUMENTIST', 'Sophie');
        $diane   = $this->makeUser('ROLE_INSTRUMENTIST', 'Diane');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $sophie, $site, MissionStatus::ASSIGNED, '2026-08-14');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $sophie->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::OPEN, $mission->getStatus());

        // Manager assigns Diane instead — via MissionPostDeployService directly (the same
        // service any manager-facing assign/reassign controller action ultimately calls),
        // to exercise the scenario deterministically without depending on a specific route.
        $missionPostDeployService = static::getContainer()->get(\App\Service\MissionPostDeployService::class);
        $manager = $this->em->find(User::class, $this->createdIds['users'][0]);
        $missionPostDeployService->assign($mission, $manager, $diane->getId());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame($diane->getId(), $mission->getInstrumentist()?->getId(), 'Precondition: Diane must be assigned before we delete Sophie\'s absence');

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus());
        self::assertSame($diane->getId(), $mission->getInstrumentist()?->getId(), 'Diane must remain — Sophie must never be silently restored over a manager decision');
    }

    // ── §32 — réduction d'absence ──────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_shrinking_the_absence_restores_only_the_dates_that_fell_out_of_the_new_range(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, null, [5], new \DateTimeImmutable('2026-08-07'));

        // 01/08 -> 16/08 covers both 07/08 and 14/08 (Fridays).
        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-01', 'dateEnd' => '2026-08-16',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(2, $this->exceptionsForPost($post));

        // Shrink to 10/08 -> 16/08 — 07/08 falls out, 14/08 stays covered.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-16',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        $remaining = $this->exceptionsForPost($post);
        self::assertCount(1, $remaining, '07/08 must be restored, 14/08 must remain neutralized');
        self::assertSame('2026-08-14', $remaining[0]->getOccurrenceDate()->format('Y-m-d'));
    }

    // ── §35 — idempotence de la réconciliation ────────────────────────────────

    #[WithoutErrorHandler]
    public function test_reconciliation_is_idempotent_across_repeated_no_op_updates(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $site = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, null, [5], new \DateTimeImmutable('2026-08-07'));

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        // Two no-op updates (same dates) in a row — must never restore/duplicate anything,
        // since 14/08 is still covered by the (unchanged) absence range.
        for ($i = 0; $i < 2; $i++) {
            $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
                'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-14',
            ]));
            self::assertSame(200, $client->getResponse()->getStatusCode());
        }

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(1, $this->exceptionsForPost($post), 'Still covered — must remain neutralized, no duplication');

        $restoredMessages = array_values(array_filter(
            $transport->getSent(),
            fn ($e) => $e->getMessage() instanceof PlanningRestoredAfterAbsenceMessage,
        ));
        self::assertCount(0, $restoredMessages, 'No-op reconciliation must never dispatch a restoration notification');
    }

    // ── §35 — restauration réelle notifie exactement une fois ─────────────────

    #[WithoutErrorHandler]
    public function test_real_restoration_dispatches_exactly_one_grouped_notification_message(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);
        $post = $this->makePost($surgeon, $site, $instr, [5], new \DateTimeImmutable('2026-08-07'));
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-21');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-14', 'dateEnd' => '2026-08-21',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $post = $this->em->find(SurgeonSchedulePost::class, $post->getId());
        self::assertCount(1, $this->exceptionsForPost($post));
        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $restoredMessages = array_values(array_filter(
            $transport->getSent(),
            fn ($e) => $e->getMessage() instanceof PlanningRestoredAfterAbsenceMessage,
        ));
        self::assertCount(1, $restoredMessages, 'Exactly one combined message covering both the occurrence and the mission');
        $message = $restoredMessages[0]->getMessage();
        self::assertCount(1, $message->restoredOccurrences);
        self::assertCount(1, $message->restoredMissions);

        // Run the handler end-to-end to prove real NotificationEvent persistence.
        static::getContainer()->get(PlanningRestoredAfterAbsenceMessageHandler::class)->__invoke($message);
        $this->em->flush();
        $this->em->clear();

        $instr = $this->em->find(User::class, $instr->getId());
        $notifications = $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $instr)
            ->getQuery()->getResult();
        self::assertNotEmpty($notifications, 'The instrumentist must receive at least one restoration notification');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // reconcileForUpdate() — fix for the pre-existing bug found during CAS B (D-117):
    // reconcileSurgeonMissions()/reconcileInstrumentistMissions() never checked whether a
    // candidate mission's date was still covered by the absence's own CURRENT (already
    // updated) range — only whether some OTHER absence covered it. A no-op PATCH or a pure
    // expansion therefore incorrectly restored an already-CANCELLED/OPEN mission. All six
    // tests below use a materialized Mission (never covered by the pre-existing
    // occurrence-only shrink/idempotence tests above, §32/§35, which only ever exercised
    // reconcileOccurrences() — already correct).
    // ══════════════════════════════════════════════════════════════════════════

    private function auditEventsForMission(Mission $mission): array
    {
        return $this->em->createQueryBuilder()
            ->select('e')->from(AuditEvent::class, 'e')
            ->where('e.mission = :m')->setParameter('m', $mission)
            ->getQuery()->getResult();
    }

    #[WithoutErrorHandler]
    public function test_reason_only_patch_with_omitted_dates_never_restores_a_still_covered_mission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-12');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-14', 'reason' => 'v1',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());

        // dateStart/dateEnd keys entirely omitted — AbsenceController::update() keeps the
        // Absence's existing dates unchanged, only `reason` is parsed/applied.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'reason' => 'v2',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus(), 'Still fully covered by the unchanged absence — must never be restored');
        self::assertCount(1, $this->auditEventsForMission($mission), 'No restore-then-recancel dance — exactly the original cancellation event');
    }

    #[WithoutErrorHandler]
    public function test_explicit_same_dates_patch_never_restores_a_still_covered_mission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-12');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());

        // dateStart/dateEnd keys present but resent with their exact current values — a
        // different code path than the previous test (the parse branch DOES run) but the
        // same net no-op range.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus(), 'Still fully covered by the unchanged absence — must never be restored');
        self::assertCount(1, $this->auditEventsForMission($mission));
    }

    #[WithoutErrorHandler]
    public function test_pushing_the_start_date_later_restores_only_the_mission_that_fell_out(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $missionFallsOut  = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-10');
        $missionStaysCovered = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-13');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $missionFallsOut = $this->em->find(Mission::class, $missionFallsOut->getId());
        $missionStaysCovered = $this->em->find(Mission::class, $missionStaysCovered->getId());
        self::assertSame(MissionStatus::CANCELLED, $missionFallsOut->getStatus());
        self::assertSame(MissionStatus::CANCELLED, $missionStaysCovered->getStatus());

        // Shrink from the front: 10/08-11/08 fall out, 12/08-14/08 remain covered.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-12', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->em->clear();
        $missionFallsOut = $this->em->find(Mission::class, $missionFallsOut->getId());
        $missionStaysCovered = $this->em->find(Mission::class, $missionStaysCovered->getId());
        self::assertSame(MissionStatus::ASSIGNED, $missionFallsOut->getStatus(), '10/08 fell out of the shrunk range — must be restored');
        self::assertSame($instr->getId(), $missionFallsOut->getInstrumentist()?->getId());
        self::assertSame(MissionStatus::CANCELLED, $missionStaysCovered->getStatus(), '13/08 is still covered — must remain cancelled');
    }

    #[WithoutErrorHandler]
    public function test_pulling_the_end_date_earlier_restores_only_the_mission_that_fell_out(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $missionStaysCovered = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-11');
        $missionFallsOut  = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-14');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $missionFallsOut = $this->em->find(Mission::class, $missionFallsOut->getId());
        $missionStaysCovered = $this->em->find(Mission::class, $missionStaysCovered->getId());
        self::assertSame(MissionStatus::CANCELLED, $missionFallsOut->getStatus());
        self::assertSame(MissionStatus::CANCELLED, $missionStaysCovered->getStatus());

        // Shrink from the back: 12/08-14/08 fall out, 10/08-11/08 remain covered.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-11',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->em->clear();
        $missionFallsOut = $this->em->find(Mission::class, $missionFallsOut->getId());
        $missionStaysCovered = $this->em->find(Mission::class, $missionStaysCovered->getId());
        self::assertSame(MissionStatus::ASSIGNED, $missionFallsOut->getStatus(), '14/08 fell out of the shrunk range — must be restored');
        self::assertSame($instr->getId(), $missionFallsOut->getInstrumentist()?->getId());
        self::assertSame(MissionStatus::CANCELLED, $missionStaysCovered->getStatus(), '11/08 is still covered — must remain cancelled');
    }

    #[WithoutErrorHandler]
    public function test_expanding_the_range_never_restores_anything(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-12');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-11', 'dateEnd' => '2026-08-13',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());

        // Pure expansion on both sides — nothing ever falls out of coverage.
        $client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'dateStart' => '2026-08-08', 'dateEnd' => '2026-08-16',
        ]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus(), 'Pure expansion never removes coverage — must never be restored');
        self::assertCount(1, $this->auditEventsForMission($mission));
    }

    #[WithoutErrorHandler]
    public function test_deleting_the_absence_still_restores_the_mission_exactly_as_before_the_fix(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $mission = $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-08-12');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-08-10', 'dateEnd' => '2026-08-14',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::CANCELLED, $mission->getStatus());

        // D-104's deletion path passes isDeletion: true — the new "still covered by the
        // absence's own current range" guard must never apply there (there is no "current
        // range" once the absence itself is gone); untouched by this fix.
        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->em->clear();
        $mission = $this->em->find(Mission::class, $mission->getId());
        self::assertSame(MissionStatus::ASSIGNED, $mission->getStatus(), 'Deletion reconciliation must be unaffected by the update-path fix');
        self::assertSame($instr->getId(), $mission->getInstrumentist()?->getId());
    }
}
