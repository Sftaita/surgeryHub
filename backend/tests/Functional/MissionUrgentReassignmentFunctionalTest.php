<?php

namespace App\Tests\Functional;

use App\Entity\Absence;
use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionExecution;
use App\Entity\NotificationDispatch;
use App\Entity\NotificationPreference;
use App\Entity\OutboundNotification;
use App\Entity\SiteMembership;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EmploymentType;
use App\Enum\MissionAssignmentSource;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Enum\SchedulePrecision;
use App\EventListener\OutboundNotificationEmailFailureListener;
use App\Message\MissionReassignmentNotificationMessage;
use App\Message\SendTemplatedEmailMessage;
use App\MessageHandler\MissionReassignmentNotificationMessageHandler;
use App\MessageHandler\SendTemplatedEmailMessageHandler;
use App\Service\MissionAssignmentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * D-181 — urgent reassignment of published missions, end to end (HTTP → services → DB →
 * Messenger → NotificationDispatcher → queued emails). Nothing leaves the test: emails stay
 * SendTemplatedEmailMessage in the in-memory transport (assertEmailCount(0)); the SMTP failure
 * is simulated with a mailer that throws.
 *
 * "Now" is frozen on 2026-11-03 10:00 Europe/Brussels; the fixtures live on that day and later.
 */
final class MissionUrgentReassignmentFunctionalTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const PASSWORD = 'Reassign181!';
    private const TZ = 'Europe/Brussels';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Hospital $site;
    private User $manager;
    private User $surgeon;
    private User $ole;
    private User $jean;
    private User $lea;
    private string $token;

    protected function setUp(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-03 10:00:00', new \DateTimeZone(self::TZ)));
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->site = (new Hospital())->setName('D181-' . bin2hex(random_bytes(3)));
        $this->em->persist($this->site);
        $this->manager = $this->user('ROLE_MANAGER', 'Mia', 'Manager');
        $this->surgeon = $this->user('ROLE_SURGEON', 'Paul', 'Durand');
        $this->ole = $this->user('ROLE_INSTRUMENTIST', 'Ole', 'Salve');
        $this->jean = $this->user('ROLE_INSTRUMENTIST', 'Jean', 'Martin');
        $this->lea = $this->user('ROLE_INSTRUMENTIST', 'Léa', 'Petit');
        $this->em->flush();
        $this->token = $this->login($this->manager);
        $this->transport()->reset();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function user(string $role, string $first, string $last): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('d181-' . strtolower($first) . '-' . bin2hex(random_bytes(4)) . '@surgicalhub.test');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($first);
        $u->setLastname($last);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);

        return $u;
    }

    private function login(User $user): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $user->getEmail(), 'password' => self::PASSWORD]));
        $data = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        self::assertArrayHasKey('token', $data, (string) $this->client->getResponse()->getContent());

        return $data['token'];
    }

    private function mission(?User $instrumentist, MissionStatus $status = MissionStatus::ASSIGNED, string $day = '2026-11-03', string $start = '08:00', string $end = '13:00', ?User $surgeon = null): Mission
    {
        $tz = new \DateTimeZone(self::TZ);
        $m = (new Mission())
            ->setType(MissionType::BLOCK)
            ->setSite($this->site)
            ->setSurgeon($surgeon ?? $this->surgeon)
            ->setCreatedBy($this->manager)
            ->setSchedulePrecision(SchedulePrecision::EXACT)
            ->setStartAt(new \DateTimeImmutable("$day $start:00", $tz))
            ->setEndAt(new \DateTimeImmutable("$day $end:00", $tz))
            ->setStatus($status);
        $this->em->persist($m);
        if ($instrumentist !== null) {
            // As published: the affectation history exists (D-150).
            static::getContainer()->get(MissionAssignmentService::class)->assign($m, $instrumentist, MissionAssignmentSource::PLANNING_DEPLOYMENT, $this->manager);
        }
        $this->em->flush();

        return $m;
    }

    private function encodeHours(Mission $m, int $minutes): void
    {
        $execution = (new MissionExecution())->setMission($m)->setActualStartAt($m->getStartAt())->setActualDurationMinutes($minutes);
        $m->setExecution($execution);
        $this->em->persist($execution);
        $this->em->flush();
    }

    private function request(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . ($token ?? $this->token)],
            content: $body !== null ? json_encode($body) : null);

        return [$this->client->getResponse()->getStatusCode(), json_decode((string) $this->client->getResponse()->getContent(), true) ?? []];
    }

    private function reassign(array $items, ?string $comment = null): array
    {
        return $this->request('POST', '/api/missions/reassignments', ['items' => $items, 'comment' => $comment]);
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    /** Runs the operation's notification message(s) like the worker would; returns the emails queued. */
    private function processNotifications(int $times = 1): array
    {
        $messages = array_values(array_filter(array_map(static fn (Envelope $e) => $e->getMessage(), $this->transport()->getSent()),
            static fn ($m) => $m instanceof MissionReassignmentNotificationMessage));
        $handler = static::getContainer()->get(MissionReassignmentNotificationMessageHandler::class);
        for ($i = 0; $i < $times; $i++) {
            foreach ($messages as $message) {
                $handler($message); // $times > 1 = Messenger retry of the very same message
            }
        }
        $this->em->flush();

        return $this->queuedEmails();
    }

    /** @return list<SendTemplatedEmailMessage> */
    private function queuedEmails(): array
    {
        return array_values(array_filter(array_map(static fn (Envelope $e) => $e->getMessage(), $this->transport()->getSent()),
            static fn ($m) => $m instanceof SendTemplatedEmailMessage && $m->htmlTemplate === 'emails/mission_reassignment_notice.html.twig'));
    }

    private function emailTo(User $user): SendTemplatedEmailMessage
    {
        $mine = array_values(array_filter($this->queuedEmails(), static fn (SendTemplatedEmailMessage $m) => $m->to === $user->getEmail()));
        self::assertCount(1, $mine, 'exactly one email for ' . $user->getFirstname());

        return $mine[0];
    }

    private function reload(Mission $m): Mission
    {
        $this->em->clear();

        return $this->em->find(Mission::class, $m->getId());
    }

    // ── 1–3 : future, same day, start already passed ─────────────────────────

    public function test_1_future_published_mission_is_reassigned(): void
    {
        $m = $this->mission($this->ole, day: '2026-11-20');

        [$status, $body] = $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId(), 'expectedInstrumentistId' => $this->ole->getId()]]);

        self::assertSame(200, $status, json_encode($body));
        self::assertSame('DONE', $body['results'][0]['status']);
        $m = $this->reload($m);
        self::assertSame($this->jean->getId(), $m->getInstrumentist()->getId());
        self::assertSame(MissionStatus::ASSIGNED, $m->getStatus());
        self::assertNotEmpty($body['operationId']);
        self::assertEqualsCanonicalizing(['PREVIOUS_INSTRUMENTIST', 'NEW_INSTRUMENTIST', 'SURGEON'], array_merge(...array_column($body['recipients'], 'roles')));
    }

    public function test_2_mission_of_the_day_not_started_yet_is_reassigned(): void
    {
        $m = $this->mission($this->ole, start: '14:00', end: '18:00');

        [$status, $body] = $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]]);

        self::assertSame(200, $status);
        self::assertSame('DONE', $body['results'][0]['status'], json_encode($body));
        self::assertSame($this->jean->getId(), $this->reload($m)->getInstrumentist()->getId());
    }

    public function test_3_mission_whose_start_has_passed_without_encoding_is_reassigned_and_keeps_its_status(): void
    {
        // app:missions:start-due already flipped it to IN_PROGRESS at 08:00 (D-064).
        $m = $this->mission($this->ole, MissionStatus::IN_PROGRESS);

        [, $detail] = $this->request('GET', '/api/missions/' . $m->getId());
        self::assertContains('reassign', $detail['allowedActions']);
        self::assertSame(['DIRECT', 'RELAY'], $detail['reassignment']['modes']);

        [$status, $body] = $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]]);

        self::assertSame(200, $status);
        self::assertSame('DONE', $body['results'][0]['status'], json_encode($body));
        $m = $this->reload($m);
        self::assertSame($this->jean->getId(), $m->getInstrumentist()->getId());
        self::assertSame(MissionStatus::IN_PROGRESS, $m->getStatus());
    }

    // ── 4 : relay of an encoded mission ──────────────────────────────────────

    public function test_4_relay_of_an_encoded_mission_loses_nothing(): void
    {
        $m = $this->mission($this->ole, MissionStatus::ENCODING_IN_PROGRESS);
        $this->encodeHours($m, 120);

        // A direct replacement would take her encoding along: refused.
        [, $refused] = $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]]);
        self::assertSame('MISSION_HAS_ENCODING_DATA', $refused['results'][0]['error']['code']);
        [, $detail] = $this->request('GET', '/api/missions/' . $m->getId());
        self::assertSame(['RELAY'], $detail['reassignment']['modes']);

        [$status, $body] = $this->reassign([[
            'missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId(),
            'mode' => 'RELAY', 'relayAt' => '2026-11-03T10:30:00+01:00', 'expectedInstrumentistId' => $this->ole->getId(),
        ]], 'Ole rentre chez elle');

        self::assertSame(200, $status);
        self::assertSame('DONE', $body['results'][0]['status'], json_encode($body));
        $relayId = $body['results'][0]['relayMissionId'];

        $this->em->clear();
        $original = $this->em->find(Mission::class, $m->getId());
        $relay = $this->em->find(Mission::class, $relayId);
        self::assertSame($this->ole->getId(), $original->getInstrumentist()->getId(), 'her mission, her encoding');
        self::assertSame(120, $original->getExecution()->getActualDurationMinutes());
        self::assertSame(MissionStatus::ENCODING_IN_PROGRESS, $original->getStatus());
        self::assertSame('10:30', $original->getEndAt()->format('H:i'));
        self::assertSame($m->getId(), $relay->getRelayOf()->getId());
        self::assertSame($this->jean->getId(), $relay->getInstrumentist()->getId());
        self::assertSame('10:30', $relay->getStartAt()->format('H:i'));
        self::assertSame('13:00', $relay->getEndAt()->format('H:i'));
        self::assertNull($relay->getExecution());
        // Planned minutes are not counted twice: 150 + 150 = the original 300.
        self::assertSame(300, (int) (($original->getEndAt()->getTimestamp() - $original->getStartAt()->getTimestamp()) + ($relay->getEndAt()->getTimestamp() - $relay->getStartAt()->getTimestamp())) / 60);

        [, $detail] = $this->request('GET', '/api/missions/' . $m->getId());
        self::assertSame([$relayId], $detail['relay']['relayMissionIds']);
    }

    // ── 5–7 : eligibility and protected statuses ─────────────────────────────

    public function test_5_an_absent_instrumentist_is_refused(): void
    {
        $this->em->persist((new SiteMembership())->setUser($this->jean)->setSite($this->site)->setSiteRole('INSTRUMENTIST'));
        $m = $this->mission($this->ole, day: '2026-11-20');
        $absence = (new Absence())->setUser($this->jean)->setCreatedBy($this->manager)
            ->setDateStart(new \DateTimeImmutable('2026-11-20'))->setDateEnd(new \DateTimeImmutable('2026-11-20'));
        $this->em->persist($absence);
        $this->em->flush();

        [, $candidates] = $this->request('GET', '/api/missions/' . $m->getId() . '/eligible-instrumentists');
        $jean = array_values(array_filter($candidates['candidates'], fn ($c) => $c['id'] === $this->jean->getId()))[0] ?? null;
        self::assertNotNull($jean);
        self::assertFalse($jean['selectable']);
        self::assertContains('ABSENT', $jean['reasons']);

        [, $body] = $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]]);
        self::assertSame('FAILED', $body['results'][0]['status']);
        self::assertSame('INSTRUMENTIST_INCOMPATIBLE', $body['results'][0]['error']['code']);
        self::assertContains('ABSENT', $body['results'][0]['error']['reasons']);
        self::assertSame($this->ole->getId(), $this->reload($m)->getInstrumentist()->getId());
        self::assertSame([], array_filter(array_map(static fn (Envelope $e) => $e->getMessage(), $this->transport()->getSent()), static fn ($x) => $x instanceof MissionReassignmentNotificationMessage), 'nothing changed: nobody is told');
    }

    public function test_6_a_schedule_conflict_is_refused(): void
    {
        $m = $this->mission($this->ole, day: '2026-11-20');
        $this->mission($this->jean, day: '2026-11-20', start: '09:00', end: '11:00', surgeon: $this->user('ROLE_SURGEON', 'Eva', 'Autre'));

        [, $body] = $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]]);

        self::assertSame('FAILED', $body['results'][0]['status']);
        self::assertContains('SCHEDULE_CONFLICT', $body['results'][0]['error']['reasons']);
        self::assertSame($this->ole->getId(), $this->reload($m)->getInstrumentist()->getId());
    }

    public function test_7_submitted_validated_and_closed_missions_are_protected(): void
    {
        $cases = [];
        foreach ([[MissionStatus::SUBMITTED, 'REASSIGNMENT_ENCODING_SUBMITTED'], [MissionStatus::VALIDATED, 'REASSIGNMENT_ENCODING_VALIDATED'], [MissionStatus::CLOSED, 'REASSIGNMENT_MISSION_CLOSED']] as [$missionStatus, $code]) {
            $cases[] = [$this->mission($this->ole, $missionStatus, day: '2026-11-02'), $code];
        }
        foreach ($cases as [$m, $code]) {

            [, $detail] = $this->request('GET', '/api/missions/' . $m->getId());
            self::assertNotContains('reassign', $detail['allowedActions']);
            self::assertSame($code, $detail['reassignment']['blockedReason']['code']);
            self::assertNotEmpty($detail['reassignment']['blockedReason']['message']);

            [$status, $body] = $this->request('POST', '/api/missions/' . $m->getId() . '/reassign', ['instrumentistId' => $this->jean->getId()]);
            self::assertSame(409, $status);
            self::assertSame($code, $body['error']['code'] ?? null, json_encode($body));
            self::assertSame($this->ole->getId(), (int) $this->em->getConnection()->fetchOne('SELECT instrumentist_id FROM mission WHERE id = ?', [$m->getId()]));
        }
    }

    // ── 8–11 : multiple reassignment, grouped emails, retries ─────────────────

    public function test_8_to_11_sick_all_day_three_missions_two_replacements_grouped_emails_no_duplicates(): void
    {
        $otherSurgeon = $this->user('ROLE_SURGEON', 'Eva', 'Lambert');
        $this->em->flush();
        $morning = $this->mission($this->ole, day: '2026-11-04', start: '08:00', end: '12:00');
        $afternoon = $this->mission($this->ole, day: '2026-11-04', start: '13:00', end: '17:00');
        $consult = $this->mission($this->ole, day: '2026-11-04', start: '17:30', end: '19:00', surgeon: $otherSurgeon);
        // Léa already works 17:00–20:00 elsewhere: the third change must fail, alone.
        $this->mission($this->lea, day: '2026-11-04', start: '17:00', end: '20:00', surgeon: $this->user('ROLE_SURGEON', 'Zoé', 'Tiers'));

        [$status, $body] = $this->reassign([
            ['missionId' => $morning->getId(), 'instrumentistId' => $this->jean->getId(), 'expectedInstrumentistId' => $this->ole->getId()],
            ['missionId' => $afternoon->getId(), 'instrumentistId' => $this->lea->getId(), 'expectedInstrumentistId' => $this->ole->getId()],
            ['missionId' => $consult->getId(), 'instrumentistId' => $this->lea->getId(), 'expectedInstrumentistId' => $this->ole->getId()],
        ], 'Ole malade');

        // 8 — each mission checked on its own; the failure is reported, the others are done.
        self::assertSame(200, $status);
        self::assertSame(['DONE', 'DONE', 'FAILED'], array_column($body['results'], 'status'), json_encode($body));
        self::assertSame($this->jean->getId(), $this->reload($morning)->getInstrumentist()->getId());
        self::assertSame($this->lea->getId(), $this->em->find(Mission::class, $afternoon->getId())->getInstrumentist()->getId());
        self::assertSame($this->ole->getId(), $this->em->find(Mission::class, $consult->getId())->getInstrumentist()->getId());
        $messages = array_values(array_filter(array_map(static fn (Envelope $e) => $e->getMessage(), $this->transport()->getSent()), static fn ($x) => $x instanceof MissionReassignmentNotificationMessage));
        self::assertCount(1, $messages, 'one notification message per operation');
        self::assertCount(2, $messages[0]->changes, 'only the real changes');

        // 9–10 — the three categories, one email per person for the whole operation.
        $emails = $this->processNotifications();
        self::assertCount(4, $emails, 'Ole, Jean, Léa, Dr Durand — once each');

        $ole = $this->emailTo($this->ole);
        self::assertSame('SurgicalHub – Modification de votre planning', $ole->subject);
        self::assertCount(2, $ole->context['removed']);
        self::assertSame([], $ole->context['added']);

        $jean = $this->emailTo($this->jean);
        self::assertSame('SurgicalHub – Nouvelle mission attribuée', $jean->subject);
        self::assertSame('08:00', $jean->context['added'][0]['startTime']);
        self::assertSame('12:00', $jean->context['added'][0]['endTime']);

        $surgeon = $this->emailTo($this->surgeon);
        self::assertSame("SurgicalHub – Changement d'instrumentiste pour votre programme", $surgeon->subject);
        self::assertCount(2, $surgeon->context['surgeon']);
        self::assertSame('Ole Salve', $surgeon->context['surgeon'][0]['fromInstrumentistName']);
        self::assertSame(['Jean Martin', 'Léa Petit'], array_column($surgeon->context['surgeon'], 'toInstrumentistName'));
        self::assertStringStartsWith('Bonjour Docteur Durand', $surgeon->context['greeting']);

        // Never the internal comment, never a reason, anywhere in what is sent.
        foreach ($emails as $email) {
            self::assertStringNotContainsString('malade', json_encode($email->context, JSON_UNESCAPED_UNICODE));
            $html = static::getContainer()->get('twig')->render($email->htmlTemplate, $email->context);
            self::assertStringNotContainsString('malade', $html);
        }
        self::assertNull($this->em->getRepository(NotificationDispatch::class)->findOneBy(['notificationType' => 'MISSION_REASSIGNMENT_NOTICE', 'recipient' => $otherSurgeon]),
            'the surgeon of the failed change is not told anything');

        // 11 — a Messenger retry of the same message sends nothing more.
        $this->processNotifications(2);
        self::assertCount(4, $this->queuedEmails());

        // 15 — nothing actually left through the mailer during the whole test.
        self::assertEmailCount(0);
    }

    // ── 12 : history ─────────────────────────────────────────────────────────

    public function test_12_history_tells_who_was_assigned_before_and_after(): void
    {
        $m = $this->mission($this->ole, day: '2026-11-20');
        $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]], 'Remplacement maladie');
        $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->lea->getId()]]);

        $events = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $m->getId(), 'eventType' => AuditEventType::MISSION_REASSIGNED_POST_DEPLOY], ['id' => 'ASC']);
        self::assertCount(2, $events);
        $first = $events[0]->getPayload();
        self::assertSame([$this->ole->getId(), $this->jean->getId()], [$first['fromInstrumentistId'], $first['toInstrumentistId']]);
        self::assertSame('Remplacement maladie', $first['internalComment']);
        self::assertSame($this->manager->getId(), $first['actorId']);
        self::assertSame([$this->jean->getId(), $this->lea->getId()], [$events[1]->getPayload()['fromInstrumentistId'], $events[1]->getPayload()['toInstrumentistId']]);

        [$status, $audit] = $this->request('GET', '/api/missions/' . $m->getId() . '/audit');
        self::assertSame(200, $status);
        self::assertGreaterThanOrEqual(2, count(array_filter($audit['items'] ?? $audit, static fn ($e) => ($e['eventType'] ?? null) === 'MISSION_REASSIGNED_POST_DEPLOY')));
        self::assertStringContainsString('Remplacement maladie', json_encode($audit, JSON_UNESCAPED_UNICODE));

        // The mission's surgeon reads the history too — never the manager's internal comment.
        [$status, $surgeonAudit] = $this->request('GET', '/api/missions/' . $m->getId() . '/audit', token: $this->login($this->surgeon));
        self::assertSame(200, $status);
        self::assertStringNotContainsString('Remplacement maladie', json_encode($surgeonAudit, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('Jean Martin', json_encode($surgeonAudit, JSON_UNESCAPED_UNICODE));
    }

    // ── 13 : the three plannings ─────────────────────────────────────────────

    public function test_13_the_three_plannings_show_the_new_assignment(): void
    {
        $m = $this->mission($this->ole, day: '2026-11-20');
        $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]]);
        $this->em->clear();

        [$s, $asJean] = $this->request('GET', '/api/missions/' . $m->getId(), token: $this->login($this->jean));
        self::assertSame(200, $s);
        self::assertSame($this->jean->getId(), $asJean['instrumentist']['id']);

        [$s, $asOle] = $this->request('GET', '/api/missions/' . $m->getId(), token: $this->login($this->ole));
        self::assertSame(200, $s, 'her former affectation stays readable');
        self::assertNull($asOle['instrumentist']);
        self::assertSame('ENDED', $asOle['myAssignmentStatus']);

        [$s, $asSurgeon] = $this->request('GET', '/api/missions/' . $m->getId(), token: $this->login($this->surgeon));
        self::assertSame(200, $s);
        self::assertSame($this->jean->getId(), $asSurgeon['instrumentist']['id']);

        [, $manager] = $this->request('GET', '/api/missions?from=2026-11-20&to=2026-11-21&siteId=' . $this->site->getId());
        $row = array_values(array_filter($manager['items'] ?? $manager['data'] ?? $manager, static fn ($i) => is_array($i) && ($i['id'] ?? null) === $m->getId()))[0] ?? null;
        self::assertNotNull($row);
        self::assertSame($this->jean->getId(), $row['instrumentist']['id']);
    }

    // ── 14 : SMTP failure, real status for the manager ───────────────────────

    public function test_14_smtp_failure_never_loses_the_reassignment_and_the_manager_sees_the_real_status(): void
    {
        $m = $this->mission($this->ole, day: '2026-11-20');
        // Dr Durand turned the email off: the manager must not believe he was told by email.
        $pref = (new NotificationPreference())->setUser($this->surgeon)->setNotificationType(NotificationType::MISSION_REASSIGNMENT_NOTICE)
            ->setInAppEnabled(true)->setEmailEnabled(false)->setPushEnabled(false);
        $this->em->persist($pref);
        $this->em->flush();

        [, $body] = $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]]);
        $this->processNotifications();
        $toJean = $this->emailTo($this->jean);

        $failingMailer = $this->createMock(MailerInterface::class);
        $failingMailer->method('send')->willThrowException(new TransportException('Connection refused'));
        $handler = new SendTemplatedEmailMessageHandler(
            $failingMailer,
            static::getContainer()->get('twig'),
            static::getContainer()->get('logger'),
            static::getContainer()->get(\App\Service\OutboundNotificationService::class),
            static::getContainer()->get(\App\Service\AbsenceCommunicationJournalService::class),
            static::getContainer()->get(\App\Notification\DocumentStore::class),
            static::getContainer()->get(\App\Notification\HtmlToTextRenderer::class),
        );
        $exception = null;
        try {
            $handler($toJean);
        } catch (TransportException $e) {
            $exception = $e;
        }
        self::assertNotNull($exception);
        // Retries exhausted → the worker's failure listener records the failure.
        static::getContainer()->get(OutboundNotificationEmailFailureListener::class)(new WorkerMessageFailedEvent(new Envelope($toJean), 'async', $exception));

        self::assertSame($this->jean->getId(), $this->reload($m)->getInstrumentist()->getId(), 'the reassignment stays');

        [$status, $op] = $this->request('GET', '/api/missions/reassignment-operations/' . $body['operationId']);
        self::assertSame(200, $status);
        $byUser = array_column($op['recipients'], null, 'userId');
        self::assertSame('FAILED', $byUser[$this->jean->getId()]['email']['status']);
        self::assertSame('SKIPPED', $byUser[$this->surgeon->getId()]['email']['status']);
        self::assertSame('DISABLED_BY_PREFERENCE', $byUser[$this->surgeon->getId()]['email']['skipReason']);
        self::assertSame('SENT', $byUser[$this->surgeon->getId()]['inApp']['status']);
        self::assertSame('QUEUED', $byUser[$this->ole->getId()]['email']['status']);

        // An instrumentist never reads an operation's statuses.
        [$forbidden] = $this->request('GET', '/api/missions/reassignment-operations/' . $body['operationId'], token: $this->login($this->ole));
        self::assertSame(403, $forbidden);
    }

    // ── 16 : two managers on the same mission ────────────────────────────────

    public function test_16_two_managers_changing_the_same_mission_never_overwrite_each_other_silently(): void
    {
        $m = $this->mission($this->ole, day: '2026-11-20');
        $secondManager = $this->user('ROLE_MANAGER', 'Max', 'Second');
        $this->em->flush();
        $secondToken = $this->login($secondManager);

        // Both opened the dialog while Ole was assigned; the first one confirms.
        [, $first] = $this->reassign([['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId(), 'expectedInstrumentistId' => $this->ole->getId()]]);
        self::assertSame('DONE', $first['results'][0]['status']);

        [$status, $second] = $this->request('POST', '/api/missions/reassignments', ['items' => [[
            'missionId' => $m->getId(), 'instrumentistId' => $this->lea->getId(), 'expectedInstrumentistId' => $this->ole->getId(),
        ]]], $secondToken);
        self::assertSame(200, $status);
        self::assertSame('FAILED', $second['results'][0]['status']);
        self::assertSame('REASSIGNMENT_ASSIGNMENT_CHANGED', $second['results'][0]['error']['code']);
        self::assertSame($this->jean->getId(), $this->reload($m)->getInstrumentist()->getId());

        // Same guard on the single-mission endpoint.
        [$single, $err] = $this->request('POST', '/api/missions/' . $m->getId() . '/reassign', ['instrumentistId' => $this->lea->getId(), 'expectedInstrumentistId' => $this->ole->getId()], $secondToken);
        self::assertSame(409, $single);
        self::assertSame('REASSIGNMENT_ASSIGNMENT_CHANGED', $err['error']['code'] ?? null);
    }

    public function test_an_instrumentist_cannot_reassign(): void
    {
        $m = $this->mission($this->ole, day: '2026-11-20');

        [, $body] = $this->request('POST', '/api/missions/reassignments', ['items' => [['missionId' => $m->getId(), 'instrumentistId' => $this->jean->getId()]]], $this->login($this->jean));

        self::assertSame('FORBIDDEN', $body['results'][0]['error']['code']);
        self::assertSame($this->ole->getId(), $this->reload($m)->getInstrumentist()->getId());
    }
}
