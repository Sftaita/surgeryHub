<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\NotificationEvent;
use App\Entity\NotificationPreference;
use App\Entity\PlanningAlert;
use App\Entity\PlanningOccurrenceException;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\RecurrenceFrequency;
use App\Enum\ShiftPeriod;
use App\Message\AbsenceImpactSummaryMessage;
use App\Message\SendBillingEmailMessage;
use App\MessageHandler\AbsenceImpactSummaryMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Lot 5 (D-105) — real-HTTP, real-database coverage for the consolidated manager recap:
 * exactly one AbsenceImpactSummaryMessage per absence-processing run, never two independent
 * manager emails for the same event, never an email without real impact, no patient data.
 */
final class AbsenceImpactSummaryFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'Lot5SummaryTest123!';

    private EntityManagerInterface $em;
    private array $createdIds = ['absences' => [], 'missions' => [], 'users' => [], 'posts' => [], 'preferences' => []];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            foreach ($this->createdIds['preferences'] as $id) {
                $e = $this->em->find(NotificationPreference::class, $id);
                if ($e !== null) { $this->em->remove($e); }
            }
            $this->em->flush();

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
        $user->setEmail('lot5sum-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $u->setEmail('lot5sum-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
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
        $h->setName('Lot5Sum Site ' . bin2hex(random_bytes(3)));
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

    private function makePost(User $surgeon, Hospital $site, ?User $instrumentist, array $weekdays, \DateTimeImmutable $anchorDate): SurgeonSchedulePost
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
        $post->setActive(true);
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

    private function setEmailPreference(User $user, bool $enabled): void
    {
        $pref = new NotificationPreference();
        $pref->setUser($user)->setNotificationType(NotificationType::ABSENCE_IMPACT_SUMMARY)
            ->setInAppEnabled(true)->setEmailEnabled($enabled)->setPushEnabled(false);
        $this->em->persist($pref);
        $this->em->flush();
        $this->createdIds['preferences'][] = $pref->getId();
    }

    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /** @return AbsenceImpactSummaryMessage[] */
    private function summaryMessages(InMemoryTransport $transport): array
    {
        $messages = [];
        foreach ($transport->getSent() as $e) {
            if ($e->getMessage() instanceof AbsenceImpactSummaryMessage) {
                $messages[] = $e->getMessage();
            }
        }
        return $messages;
    }

    /**
     * Scoped to a specific recipient email — the real dev database this suite runs against
     * accumulates managers/admins across the whole session's functional test history, so
     * findManagersAndAdmins(true) legitimately returns far more than just this test's own
     * fixtures. Counting the raw transport is not meaningful; only per-recipient counts are.
     *
     * @return SendBillingEmailMessage[]
     */
    private function emailsTo(InMemoryTransport $transport, string $email): array
    {
        $emails = [];
        foreach ($transport->getSent() as $e) {
            $msg = $e->getMessage();
            if ($msg instanceof SendBillingEmailMessage && $msg->to === $email) {
                $emails[] = $msg;
            }
        }
        return $emails;
    }

    // ── §16 — Chirurgien : 2 missions + 2 occurrences + 1 instrumentiste ─────────

    #[WithoutErrorHandler]
    public function test_surgeon_absence_with_missions_and_occurrences_produces_exactly_one_manager_email_with_all_four_impacts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instrA  = $this->makeUser('ROLE_INSTRUMENTIST', 'Alice');
        $instrB  = $this->makeUser('ROLE_INSTRUMENTIST', 'Bruno');
        $site    = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        // Two already-generated missions inside the absence window — will be CANCELLED.
        $this->makeMission($surgeon, $instrA, $site, MissionStatus::ASSIGNED, '2026-09-04');
        $this->makeMission($surgeon, $instrA, $site, MissionStatus::OPEN, '2026-09-11');

        // A recurring post whose default instrumentist is Bruno — Tuesdays, deliberately a
        // different weekday than the two Friday missions above so their dates never collide
        // (a colliding date would count as "already generated" and be skipped, §14) — two
        // future occurrences (09-01, 09-08) inside the window, no Mission generated yet.
        $this->makePost($surgeon, $site, $instrB, [2], new \DateTimeImmutable('2026-09-01'));

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-09-01', 'dateEnd' => '2026-09-11',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $messages = $this->summaryMessages($transport);
        self::assertCount(1, $messages, 'Exactly one AbsenceImpactSummaryMessage for the whole create() call');
        $message = $messages[0];
        self::assertCount(2, $message->missionsCancelled);
        self::assertCount(2, $message->futureOccurrencesCancelled);
        self::assertCount(0, $message->missionsReleased);
        self::assertSame('CREATED', $message->action);
        self::assertSame('SURGEON', $message->absentUserRole);

        static::getContainer()->get(AbsenceImpactSummaryMessageHandler::class)->__invoke($message);
        $this->em->flush();
        $this->em->clear();

        $manager = $this->em->find(User::class, $manager->getId());
        $notifications = $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $manager)
            ->getQuery()->getResult();
        self::assertCount(1, $notifications, 'Exactly one manager notification for the whole absence event, not one per category');
        self::assertSame('ABSENCE_IMPACT_SUMMARY', $notifications[0]->getEventType());

        $emails = $this->emailsTo($transport, $manager->getEmail());
        self::assertCount(1, $emails, 'Exactly one manager email');
        $email = $emails[0];
        self::assertSame('emails/absence_manager_impact_summary.html.twig', $email->htmlTemplate);

        // No patient data anywhere in the email context — only date/time/site/surgeon/instrumentist/status fields.
        $flat = json_encode($email->context);
        self::assertStringNotContainsString('patient', strtolower($flat));
    }

    // ── §16 — Instrumentiste : 2 missions ASSIGNED → 2 relâchées ──────────────────

    #[WithoutErrorHandler]
    public function test_instrumentist_absence_with_two_assigned_missions_produces_one_action_required_email(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();

        $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-09-04');
        $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-09-05');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $instr->getId(), 'dateStart' => '2026-09-01', 'dateEnd' => '2026-09-10',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $messages = $this->summaryMessages($transport);
        self::assertCount(1, $messages);
        $message = $messages[0];
        self::assertCount(2, $message->missionsReleased, '2 missions relâchées OPEN');
        self::assertCount(0, $message->missionsCancelled);

        static::getContainer()->get(AbsenceImpactSummaryMessageHandler::class)->__invoke($message);
        $this->em->flush();
        $this->em->clear();

        $manager = $this->em->find(User::class, $manager->getId());
        $emails = $this->emailsTo($transport, $manager->getEmail());
        self::assertCount(1, $emails);
        $email = $emails[0];
        self::assertStringContainsString('Planning à couvrir', $email->subject, 'Instrumentist absence releasing missions must read as action-required, not just informational');
        self::assertSame(2, $email->context['actionRequiredCount']);
    }

    // ── §16 — Restauration : 1 ASSIGNED + 1 OPEN + 1 occurrence ───────────────────

    #[WithoutErrorHandler]
    public function test_deleting_absence_restores_one_assigned_one_open_and_one_occurrence_in_a_single_summary(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $stillEligible = $this->makeUser('ROLE_INSTRUMENTIST', 'Eligible');
        $stillAbsent   = $this->makeUser('ROLE_INSTRUMENTIST', 'Absent');
        $site = $this->makeSite();
        $this->makeShiftConfig($site, ShiftPeriod::MATIN);

        $this->makeMission($surgeon, $stillEligible, $site, MissionStatus::ASSIGNED, '2026-09-04');
        $this->makeMission($surgeon, $stillAbsent, $site, MissionStatus::ASSIGNED, '2026-09-05');
        // Fridays: 09-04 (collides with the mission above, so §14 skips it — never double-treated),
        // 09-11 (the one occurrence actually neutralized), 09-18 (kept OUTSIDE the absence range below).
        $this->makePost($surgeon, $site, null, [5], new \DateTimeImmutable('2026-09-04'));

        // A second, still-active absence keeps $stillAbsent unavailable even after A is deleted.
        $blocker = $this->makeAbsence($stillAbsent, $manager, '2026-09-05', '2026-09-05');

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-09-01', 'dateEnd' => '2026-09-11',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $absenceId = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('DELETE', "/api/absences/{$absenceId}", server: $this->auth($token));
        self::assertSame(204, $client->getResponse()->getStatusCode());
        $this->em->flush();
        $this->em->clear();

        $messages = $this->summaryMessages($transport);
        self::assertCount(1, $messages, 'Exactly one consolidated recap for the whole delete()');
        $message = $messages[0];
        self::assertCount(1, $message->missionsRestoredAssigned);
        self::assertCount(1, $message->missionsRestoredOpen);
        self::assertCount(1, $message->futureOccurrencesRestored);
        self::assertSame('DELETED', $message->action);

        static::getContainer()->get(AbsenceImpactSummaryMessageHandler::class)->__invoke($message);
        $this->em->flush();
        $this->em->clear();

        $manager = $this->em->find(User::class, $manager->getId());
        $emails = $this->emailsTo($transport, $manager->getEmail());
        self::assertCount(1, $emails);
        $email = $emails[0];
        self::assertSame(1, $email->context['actionRequiredCount'], 'The OPEN restore must land under Action requise, not Impact automatique');
        self::assertCount(1, $email->context['missionsRestoredAssigned']);
        self::assertCount(1, $email->context['missionsRestoredOpen']);
        self::assertCount(1, $email->context['futureOccurrencesRestored']);

        $this->em->remove($this->em->find(Absence::class, $blocker->getId()));
        $this->em->flush();
    }

    // ── §16 — Aucun impact → aucun email ───────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_absence_with_no_real_impact_dispatches_no_summary_message(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-10-01', 'dateEnd' => '2026-10-05',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];

        self::assertCount(0, $this->summaryMessages($transport), 'No mission, no post, no restoration — no consolidated email must be dispatched');
    }

    // ── §16 — Préférences (désactivé / activé) ─────────────────────────────────

    #[WithoutErrorHandler]
    public function test_manager_with_email_disabled_gets_no_email_but_still_gets_in_app(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');
        $this->setEmailPreference($manager, enabled: false);

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-09-04');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-09-01', 'dateEnd' => '2026-09-10',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $messages = $this->summaryMessages($transport);
        self::assertCount(1, $messages);
        static::getContainer()->get(AbsenceImpactSummaryMessageHandler::class)->__invoke($messages[0]);
        $this->em->flush();
        $this->em->clear();

        $manager = $this->em->find(User::class, $manager->getId());
        $emails = $this->emailsTo($transport, $manager->getEmail());
        self::assertCount(0, $emails, 'Email channel disabled — must not dispatch SendBillingEmailMessage');

        $notifications = $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $manager)
            ->getQuery()->getResult();
        self::assertCount(1, $notifications, 'In-app is a separate channel and stays on');
    }

    // ── §16 — Plusieurs managers, chacun reçoit son résumé ─────────────────────

    #[WithoutErrorHandler]
    public function test_multiple_managers_each_receive_exactly_one_summary_per_their_own_preferences(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager1] = $this->authenticate($client, 'ROLE_MANAGER');
        ['user' => $manager2] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-09-04');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-09-01', 'dateEnd' => '2026-09-10',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $messages = $this->summaryMessages($transport);
        self::assertCount(1, $messages);
        self::assertContains($manager1->getId(), $messages[0]->recipientManagerIds);
        self::assertContains($manager2->getId(), $messages[0]->recipientManagerIds);

        static::getContainer()->get(AbsenceImpactSummaryMessageHandler::class)->__invoke($messages[0]);
        $this->em->flush();
        $this->em->clear();

        self::assertCount(1, $this->emailsTo($transport, $manager1->getEmail()), 'manager1 gets exactly its own email');
        self::assertCount(1, $this->emailsTo($transport, $manager2->getEmail()), 'manager2 gets exactly its own email');
    }

    // ── §16 — Retry Messenger : garantie actuelle documentée ───────────────────

    /**
     * No idempotency key/outbox exists for this handler (same as Lot 3/4's handlers before
     * it — see docs/decisions.md D-105). Replaying the same message re-persists a second
     * NotificationEvent and re-dispatches a second email. This test documents that CURRENT
     * guarantee rather than papering over it — Messenger's retry_strategy only re-invokes on
     * failure, and this handler's own per-recipient try/catch means a failure for one
     * manager never blocks the others, but a full replay of the whole message is not
     * deduplicated. Building an outbox/idempotency-key mechanism was explicitly out of scope
     * for this lot (§15 of the spec).
     */
    #[WithoutErrorHandler]
    public function test_replaying_the_same_message_is_not_deduplicated_documented_current_behavior(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        ['token' => $token, 'user' => $manager] = $this->authenticate($client, 'ROLE_MANAGER');

        $surgeon = $this->makeUser('ROLE_SURGEON');
        $instr   = $this->makeUser('ROLE_INSTRUMENTIST');
        $site    = $this->makeSite();
        $this->makeMission($surgeon, $instr, $site, MissionStatus::ASSIGNED, '2026-09-04');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $transport->reset();

        $client->request('POST', '/api/absences', server: $this->auth($token, ['CONTENT_TYPE' => 'application/json']), content: json_encode([
            'userId' => $surgeon->getId(), 'dateStart' => '2026-09-01', 'dateEnd' => '2026-09-10',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->createdIds['absences'][] = $this->json($client->getResponse())['id'];
        $this->em->flush();
        $this->em->clear();

        $messages = $this->summaryMessages($transport);
        self::assertCount(1, $messages);
        $handler = static::getContainer()->get(AbsenceImpactSummaryMessageHandler::class);

        $handler->__invoke($messages[0]);
        $this->em->flush();
        $handler->__invoke($messages[0]);
        $this->em->flush();
        $this->em->clear();

        $manager = $this->em->find(User::class, $manager->getId());
        $notifications = $this->em->createQueryBuilder()
            ->select('n')->from(NotificationEvent::class, 'n')
            ->where('n.user = :u')->setParameter('u', $manager)
            ->getQuery()->getResult();
        self::assertCount(2, $notifications, 'Documented current behavior: replaying the message is NOT deduplicated');

        $emails = $this->emailsTo($transport, $manager->getEmail());
        self::assertCount(2, $emails, 'Documented current behavior: replaying the message dispatches a second email too');
    }
}
