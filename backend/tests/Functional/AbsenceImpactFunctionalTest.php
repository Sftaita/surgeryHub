<?php

namespace App\Tests\Functional;

use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\RecurrenceRule;
use App\Entity\ShiftPeriodConfig;
use App\Entity\SiteMembership;
use App\Entity\SurgeonSchedulePost;
use App\Entity\User;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\RecurrenceFrequency;
use App\Enum\SchedulePrecision;
use App\Enum\ShiftPeriod;
use App\Message\AbsenceImpactNotificationMessage;
use App\Message\AbsenceMissionsReactedMessage;
use App\Message\MissionLifecycleChangedMessage;
use App\Message\SendTemplatedEmailMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * H4 (D-127 / D-130) — who is informed of an absence, at which level, through the real API,
 * the real resolver, the real services and the real handlers (the worker is played in-process
 * for the person-facing messages; the manager summary is not drained — the test database
 * holds hundreds of leaked managers).
 *
 * Fixture: Dr Philippe Schiepers operates every Friday morning (08:00–13:00) at one site;
 * his usual instrumentist is Françoise Detrembleur (SurgeonSchedulePost). Sophie Gillard is
 * another instrumentist of the site. Fridays used: 07, 14, 21 May 2027.
 */
final class AbsenceImpactFunctionalTest extends WebTestCase
{
    private const PASSWORD = 'password123';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $token;
    /** @var array{users: int[], missions: int[], sites: int[], posts: int[]} */
    private array $created = ['users' => [], 'missions' => [], 'sites' => [], 'posts' => []];
    /** @var array<int, true> envelopes already played (the in-memory transport is reset between two requests) */
    private array $seen = [];
    /** @var list<SendTemplatedEmailMessage> every email queued so far, across requests */
    private array $emails = [];
    /** @var list<\Symfony\Component\Messenger\Envelope> every AbsenceImpactNotificationMessage seen */
    private array $notificationEnvelopes = [];

    private Hospital $site;
    private User $schiepers;
    private User $francoise;
    private User $sophie;
    private SurgeonSchedulePost $post;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->transport()->reset();

        $this->site = (new Hospital())->setName('H4 Delta ' . bin2hex(random_bytes(3)));
        $this->em->persist($this->site);
        $this->em->flush();
        $this->created['sites'][] = $this->site->getId();
        $shift = (new ShiftPeriodConfig())->setSite($this->site)->setPeriod(ShiftPeriod::MATIN)
            ->setStartTime(new \DateTimeImmutable('08:00'))->setEndTime(new \DateTimeImmutable('13:00'));
        $this->em->persist($shift);

        $manager = $this->user('Samy', 'Ftaita', 'ROLE_MANAGER');
        $this->schiepers = $this->user('Philippe', 'Schiepers', 'ROLE_SURGEON');
        $this->francoise = $this->member($this->user('Françoise', 'Detrembleur'));
        $this->sophie    = $this->member($this->user('Sophie', 'Gillard'));

        $rule = new RecurrenceRule();
        $rule->setFrequency(RecurrenceFrequency::WEEKLY);
        $rule->setInterval(1);
        $rule->setWeekdays([5]);
        $rule->setAnchorDate(new \DateTimeImmutable('2027-01-01'));
        $rule->setMonthWeeks([]);
        $this->post = new SurgeonSchedulePost();
        $this->post->setSurgeon($this->schiepers);
        $this->post->setSite($this->site);
        $this->post->setType(MissionType::BLOCK);
        $this->post->setPeriod(ShiftPeriod::MATIN);
        $this->post->setRecurrence($rule);
        $this->post->setInstrumentist($this->francoise);
        $this->post->setStartDate(new \DateTimeImmutable('2027-01-01'));
        $this->post->setCreatedBy($manager);
        $this->em->persist($this->post);
        $this->em->flush();
        $this->created['posts'][] = $this->post->getId();

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $manager->getEmail(), 'password' => self::PASSWORD]));
        $this->token = json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->clear();
            $c = $this->conn();
            $u = implode(',', $this->created['users']) ?: '0';
            $p = implode(',', $this->created['posts']) ?: '0';
            $s = implode(',', $this->created['sites']) ?: '0';
            $missions = "SELECT id FROM mission WHERE site_id IN ($s)";
            $absences = "SELECT id FROM absence WHERE user_id IN ($u)";
            $c->executeStatement("DELETE FROM mission_communication WHERE user_id IN ($u)");
            $c->executeStatement("DELETE FROM notification_dispatch WHERE recipient_user_id IN ($u) OR mission_id IN ($missions)");
            $c->executeStatement("DELETE a FROM outbound_notification_attempt a JOIN outbound_notification o ON o.id = a.notification_id WHERE o.recipient_user_id IN ($u)");
            $c->executeStatement("DELETE FROM outbound_notification WHERE recipient_user_id IN ($u)");
            $c->executeStatement("DELETE FROM notification_event WHERE user_id IN ($u) OR mission_id IN ($missions)");
            $c->executeStatement("DELETE d FROM surgeon_absence_communication_delivery d JOIN surgeon_absence_communication sc ON sc.id = d.communication_id WHERE sc.absence_id IN ($absences)");
            $c->executeStatement("DELETE FROM surgeon_absence_communication WHERE absence_id IN ($absences)");
            $c->executeStatement("DELETE FROM released_operating_room_slot WHERE schedule_post_id IN ($p) OR source_absence_id IN ($absences)");
            $c->executeStatement("DELETE FROM planning_occurrence_exception WHERE post_id IN ($p)");
            $c->executeStatement("DELETE FROM planning_alert WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM audit_event WHERE mission_id IN ($missions) OR actor_id IN ($u)");
            $c->executeStatement("DELETE FROM mission_claim WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission_publication WHERE mission_id IN ($missions)");
            $c->executeStatement("DELETE FROM mission WHERE site_id IN ($s)");
            $c->executeStatement("DELETE FROM absence WHERE user_id IN ($u)");
            $c->executeStatement("DELETE FROM surgeon_schedule_post WHERE id IN ($p)");
            $c->executeStatement("DELETE FROM shift_period_config WHERE site_id IN ($s)");
            $c->executeStatement("DELETE FROM site_membership WHERE user_id IN ($u)");
            $c->executeStatement("DELETE FROM notification_preference WHERE user_id IN ($u)");
            $c->executeStatement("DELETE FROM user WHERE id IN ($u)");
            $c->executeStatement("DELETE FROM hospital WHERE id IN ($s)");
        }
        parent::tearDown();
    }

    // ── S3 / S9 — surgeon absent, template only (N1) ────────────────────────

    #[WithoutErrorHandler]
    public function test_s3_s9_surgeon_absent_before_generation_informs_the_usual_instrumentist_once_for_several_fridays(): void
    {
        $absenceId = $this->absence($this->schiepers, '2027-05-07', '2027-05-21');
        $this->drain();

        $dispatches = $this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_CANCELLED');
        self::assertCount(3, $dispatches, 'S9 — one traceable impact per Friday');
        foreach ($dispatches as $d) {
            self::assertSame('USUAL_SCHEDULE', json_decode($d['payload'], true)['level']);
        }
        $emails = $this->emailsTo($this->francoise);
        self::assertCount(1, $emails, 'S9 — ONE email for the three Fridays');
        self::assertSame(['07/05/2027', '14/05/2027', '21/05/2027'], array_column($emails[0]->context['occurrences'], 'occurrenceDate'));
        self::assertSame('Le Dr Philippe Schiepers sera indisponible le 07/05/2027 : votre créneau habituel avec le Dr Philippe Schiepers est impacté.', $emails[0]->context['occurrences'][0]['sentence']);
        self::assertStringNotContainsStringIgnoringCase('annulée', json_encode($emails[0]->context, JSON_UNESCAPED_UNICODE));

        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM mission WHERE site_id = ?', [$this->site->getId()]), 'no fake Mission');
        self::assertSame(3, (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM planning_occurrence_exception WHERE post_id = ? AND type = 'CANCELLED'", [$this->post->getId()]));
        $evaluated = $this->activity('ABSENCE_IMPACT_EVALUATED', $absenceId);
        self::assertSame(3, $evaluated['payload']['counts']['USUAL_SCHEDULE']);
        self::assertSame(1, $evaluated['payload']['recipientsInformed']);
        self::assertSame(3, (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM activity_event WHERE event_type = 'USER_PLANNING_IMPACTED' AND absence_id = ? AND user_id = ?", [$absenceId, $this->francoise->getId()]));
    }

    // ── S10 — Messenger retry ────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_s10_replaying_the_notification_message_sends_nothing_new(): void
    {
        $this->absence($this->schiepers, '2027-05-07', '2027-05-14');
        $this->drain();
        $message = $this->firstNotificationMessage();
        $dispatches = count($this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_CANCELLED'));

        $this->bus()->dispatch($message->with(new ReceivedStamp('async')));
        $this->drain();

        self::assertCount($dispatches, $this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_CANCELLED'));
        self::assertCount(1, $this->emailsTo($this->francoise), 'still ONE email');
    }

    // ── S2 — surgeon absent, DRAFT with Françoise (N2) ──────────────────────

    #[WithoutErrorHandler]
    public function test_s2_surgeon_absent_on_a_draft_tells_the_planned_instrumentist_her_prepared_slot_is_impacted(): void
    {
        $draft = $this->mission($this->francoise, MissionStatus::DRAFT, '2027-05-07');
        $this->absence($this->schiepers, '2027-05-07', '2027-05-07');
        $this->drain();

        $d = $this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_CANCELLED');
        self::assertCount(1, $d, 'N2 only — no N1 for the same occurrence');
        $payload = json_decode($d[0]['payload'], true);
        self::assertSame('DRAFT', $payload['level']);
        self::assertNull($d[0]['mission_id'], 'not attached to the DRAFT: survives its deletion');
        $email = $this->emailsTo($this->francoise)[0];
        self::assertStringContainsString('planning en préparation', $email->context['occurrences'][0]['sentence']);
        self::assertStringNotContainsStringIgnoringCase('annulée', json_encode($email->context, JSON_UNESCAPED_UNICODE));
        self::assertSame('DRAFT', $this->conn()->fetchOne('SELECT status FROM mission WHERE id = ?', [$draft->getId()]), 'neutralised at deploy (D-090), not now');
        self::assertSame('SURGEON_ABSENCE', $this->conn()->fetchOne('SELECT type FROM planning_alert WHERE mission_id = ?', [$draft->getId()]), 'the manager sees it');
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM planning_occurrence_exception WHERE post_id = ?', [$this->post->getId()]));
    }

    // ── S7 — DRAFT differs from the template ────────────────────────────────

    #[WithoutErrorHandler]
    public function test_s7_the_draft_wins_over_the_template(): void
    {
        $this->mission($this->sophie, MissionStatus::DRAFT, '2027-05-07');
        $this->absence($this->schiepers, '2027-05-07', '2027-05-07');
        $this->drain();

        self::assertCount(1, $this->dispatches($this->sophie, 'ABSENCE_OCCURRENCE_CANCELLED'));
        self::assertSame([], $this->dispatches($this->francoise), 'the usual instrumentist is not planned on this occurrence');
    }

    // ── S1 / S8 — surgeon absent, published mission (N3) ────────────────────

    #[WithoutErrorHandler]
    public function test_s1_surgeon_absent_on_a_published_mission_cancels_it_and_tells_the_instrumentist(): void
    {
        $mission = $this->mission($this->francoise, MissionStatus::ASSIGNED, '2027-05-07');
        $absenceId = $this->absence($this->schiepers, '2027-05-07', '2027-05-07');
        $this->drain();

        self::assertSame('CANCELLED', $this->conn()->fetchOne('SELECT status FROM mission WHERE id = ?', [$mission->getId()]));
        $d = $this->dispatches($this->francoise);
        self::assertSame(['ABSENCE_MISSION_CANCELLED'], array_column($d, 'notification_type'), 'one N3 notice, nothing else (no PLANNING_MISSION_CANCELLED, no N1)');
        self::assertSame('PUBLISHED', json_decode($d[0]['payload'], true)['level']);
        self::assertSame(0, (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM activity_event WHERE event_type = 'OPEN_POOL_EVALUATED' AND mission_id = ?", [$mission->getId()]), 'no pool for a cancelled mission');
        $impacted = $this->conn()->fetchAssociative("SELECT * FROM activity_event WHERE event_type = 'USER_PLANNING_IMPACTED' AND mission_id = ?", [$mission->getId()]);
        self::assertSame((int) $this->francoise->getId(), (int) $impacted['user_id']);
        self::assertSame('MISSION_CANCELLED', json_decode($impacted['payload'], true)['consequence']);
        self::assertSame(1, $this->activity('ABSENCE_IMPACT_EVALUATED', $absenceId)['payload']['counts']['MISSION_CANCELLED']);
        self::assertSame([], $this->dispatches($this->schiepers), 'never the absent surgeon about the consequences of his own absence');
    }

    #[WithoutErrorHandler]
    public function test_s8_the_published_mission_wins_over_the_template(): void
    {
        $this->mission($this->sophie, MissionStatus::ASSIGNED, '2027-05-07');
        $this->absence($this->schiepers, '2027-05-07', '2027-05-07');
        $this->drain();

        self::assertSame(['ABSENCE_MISSION_CANCELLED'], array_column($this->dispatches($this->sophie), 'notification_type'));
        self::assertSame([], $this->dispatches($this->francoise));
    }

    // ── S6 / S5 / S4 — instrumentist absent ─────────────────────────────────

    #[WithoutErrorHandler]
    public function test_s6_instrumentist_absent_before_generation_informs_the_usual_surgeon(): void
    {
        $this->absence($this->francoise, '2027-05-07', '2027-05-07');
        $this->drain();

        $d = $this->dispatches($this->schiepers, 'ABSENCE_OCCURRENCE_UNCOVERED');
        self::assertCount(1, $d);
        self::assertSame('USUAL_SCHEDULE', json_decode($d[0]['payload'], true)['level']);
        self::assertSame('Françoise Detrembleur sera indisponible le 07/05/2027 sur votre créneau habituel.', $this->emailsTo($this->schiepers)[0]->context['impacted'][0]['sentence']);
        self::assertSame(0, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM mission WHERE site_id = ?', [$this->site->getId()]), 'no OPEN mission created');
    }

    #[WithoutErrorHandler]
    public function test_s5_instrumentist_absent_on_a_draft_informs_the_surgeon_and_never_the_pool(): void
    {
        $draft = $this->mission($this->francoise, MissionStatus::DRAFT, '2027-05-07');
        $absenceId = $this->absence($this->francoise, '2027-05-07', '2027-05-07');
        $this->drain();

        $d = $this->dispatches($this->schiepers, 'ABSENCE_OCCURRENCE_UNCOVERED');
        self::assertCount(1, $d);
        self::assertSame('DRAFT', json_decode($d[0]['payload'], true)['level']);
        self::assertStringContainsString('doit être recouvert', $this->emailsTo($this->schiepers)[0]->context['impacted'][0]['sentence']);
        self::assertSame('INSTRUMENTIST_ABSENCE', $this->conn()->fetchOne('SELECT type FROM planning_alert WHERE mission_id = ?', [$draft->getId()]));
        self::assertSame(0, (int) $this->conn()->fetchOne("SELECT COUNT(*) FROM activity_event WHERE event_type = 'OPEN_POOL_EVALUATED' AND mission_id = ?", [$draft->getId()]), 'no pool before publication');
        self::assertSame([], $this->dispatches($this->francoise, 'PLANNING_ALERT'), 'the generic alert notice is gone');
        self::assertSame(1, $this->activity('ABSENCE_IMPACT_EVALUATED', $absenceId)['payload']['stillUncovered']);
    }

    #[WithoutErrorHandler]
    public function test_s4_instrumentist_absent_after_publication_opens_the_mission_informs_the_surgeon_and_solicits_the_pool(): void
    {
        $mission = $this->mission($this->francoise, MissionStatus::ASSIGNED, '2027-05-07');
        $this->absence($this->francoise, '2027-05-07', '2027-05-07');
        $this->drain();

        self::assertSame('OPEN', $this->conn()->fetchOne('SELECT status FROM mission WHERE id = ?', [$mission->getId()]));
        self::assertSame(['ABSENCE_SURGEON_MISSION_OPENED'], array_column($this->dispatches($this->schiepers), 'notification_type'));
        $pool = json_decode((string) $this->conn()->fetchOne("SELECT payload FROM activity_event WHERE event_type = 'OPEN_POOL_EVALUATED' AND mission_id = ?", [$mission->getId()]), true);
        self::assertSame('INSTRUMENTIST_ABSENCE', $pool['cause']);
        self::assertCount(1, $this->dispatches($this->sophie, 'OPEN_MISSION_AVAILABLE'), 'H3 pool: the eligible colleague');
        self::assertSame([], $this->dispatches($this->francoise, 'OPEN_MISSION_AVAILABLE'), 'the absent person is not eligible');
        self::assertSame([], $this->dispatches($this->francoise), 'never the absent instrumentist about the consequences of her own absence');
    }

    // ── Update of an absence ────────────────────────────────────────────────

    #[WithoutErrorHandler]
    public function test_shortening_an_absence_never_repeats_unchanged_occurrences_and_reports_the_recovered_ones(): void
    {
        $absenceId = $this->absence($this->francoise, '2027-05-07', '2027-05-21');
        $this->drain();
        self::assertCount(3, $this->dispatches($this->schiepers, 'ABSENCE_OCCURRENCE_UNCOVERED'));

        $this->client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth(), content: json_encode(['dateEnd' => '2027-05-14']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->drain();

        $all = $this->dispatches($this->schiepers, 'ABSENCE_OCCURRENCE_UNCOVERED');
        $restored = array_values(array_filter($all, static fn (array $d) => json_decode($d['payload'], true)['status'] === 'RESTORED'));
        self::assertCount(4, $all, 'the 3 initial impacts + 1 restoration, nothing repeated');
        self::assertCount(1, $restored);
        $emails = $this->emailsTo($this->schiepers);
        self::assertCount(2, $emails, 'one email per operation');
        self::assertSame([], $emails[1]->context['impacted'], 'unchanged Fridays are not re-announced');
        self::assertSame(['21/05/2027'], array_column($emails[1]->context['recovered'], 'occurrenceDate'));
    }

    // ── DRAFT no longer impacted: one correction, only if the impact had been communicated ──

    #[WithoutErrorHandler]
    public function test_a_shortened_absence_corrects_a_communicated_draft_impact_once(): void
    {
        $this->mission($this->francoise, MissionStatus::DRAFT, '2027-05-07');
        $this->mission($this->francoise, MissionStatus::DRAFT, '2027-05-14');
        $absenceId = $this->absence($this->schiepers, '2027-05-07', '2027-05-14');
        $this->drain();
        self::assertCount(2, $this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_CANCELLED'));

        $this->client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth(), content: json_encode(['dateEnd' => '2027-05-07']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->drain();
        $this->bus()->dispatch(end($this->notificationEnvelopes)->with(new ReceivedStamp('async'))); // retry
        $this->drain();

        $corrections = $this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_RESTORED');
        self::assertCount(1, $corrections, 'exactly one correction, retry included');
        $payload = json_decode($corrections[0]['payload'], true);
        self::assertSame('DRAFT', $payload['level']);
        $email = array_values(array_filter($this->emailsTo($this->francoise), static fn ($m) => $m->context['allRestored'] ?? false));
        self::assertCount(1, $email);
        self::assertSame("Le créneau prévu avec le Dr Philippe Schiepers le 14/05/2027 n'est plus concerné par cette absence.", $email[0]->context['occurrences'][0]['sentence']);
        self::assertCount(2, $this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_CANCELLED'), 'the 07/05 impact is not repeated');
    }

    #[WithoutErrorHandler]
    public function test_the_draft_correction_never_resurrects_a_cancelled_published_mission_d104_stays_in_charge(): void
    {
        $published = $this->mission($this->francoise, MissionStatus::ASSIGNED, '2027-05-14');
        $absenceId = $this->absence($this->schiepers, '2027-05-07', '2027-05-14');
        $this->drain();
        self::assertSame('CANCELLED', $this->conn()->fetchOne('SELECT status FROM mission WHERE id = ?', [$published->getId()]));

        $this->client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth(), content: json_encode(['dateEnd' => '2027-05-07']));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->drain();

        self::assertSame([], $this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_RESTORED'),
            'the DRAFT correction (N2) never speaks for a published mission');
        $status = $this->conn()->fetchOne('SELECT status FROM mission WHERE id = ?', [$published->getId()]);
        $restored = $this->dispatches($this->francoise, 'MISSION_RESTORED');
        // D-104 alone decides: restored (revalidated, its last audit is this absence's reaction)
        // AND announced as such — never a silent resurrection, never an N2 wording.
        self::assertSame($status === 'ASSIGNED' ? 1 : 0, count($restored), "status $status");
        self::assertContains($status, ['ASSIGNED', 'CANCELLED']);
    }

    #[WithoutErrorHandler]
    public function test_no_correction_when_the_initial_draft_impact_never_reached_the_person(): void
    {
        $this->em->persist((new \App\Entity\NotificationPreference())->setUser($this->francoise)
            ->setNotificationType(\App\Enum\NotificationType::ABSENCE_OCCURRENCE_CANCELLED)
            ->setInAppEnabled(false)->setEmailEnabled(false)->setPushEnabled(false));
        $this->em->flush();
        $this->mission($this->francoise, MissionStatus::DRAFT, '2027-05-14');
        $absenceId = $this->absence($this->schiepers, '2027-05-07', '2027-05-14');
        $this->drain();

        $this->client->request('PATCH', "/api/absences/{$absenceId}", server: $this->auth(), content: json_encode(['dateEnd' => '2027-05-07']));
        $this->drain();

        self::assertSame([], $this->dispatches($this->francoise, 'ABSENCE_OCCURRENCE_RESTORED'), 'nothing reached her, nothing to correct');
    }

    // ── The free-text reason never leaves the Absence ──────────────────────

    #[WithoutErrorHandler]
    public function test_the_absence_reason_is_never_propagated_to_notifications(): void
    {
        $this->mission($this->francoise, MissionStatus::ASSIGNED, '2027-05-07');
        $this->mission($this->sophie, MissionStatus::DRAFT, '2027-05-14');
        $this->absence($this->schiepers, '2027-05-07', '2027-05-21');
        $this->drain();

        $c = $this->conn();
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM notification_dispatch WHERE payload LIKE ?', ['%' . self::SECRET_REASON . '%']));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM notification_event WHERE payload LIKE ?', ['%' . self::SECRET_REASON . '%']));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM outbound_notification WHERE CONCAT_WS(\'|\', payload, subject, body_text, title) LIKE ?', ['%' . self::SECRET_REASON . '%']));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM activity_event WHERE CONCAT_WS(\'|\', payload, summary) LIKE ?', ['%' . self::SECRET_REASON . '%']));
        foreach ($this->emails as $email) {
            self::assertStringNotContainsString(self::SECRET_REASON, json_encode([$email->subject, $email->context], JSON_UNESCAPED_UNICODE));
        }
        foreach ($this->transport()->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof \App\Message\AbsenceImpactSummaryMessage) {
                self::assertStringNotContainsString(self::SECRET_REASON, serialize($envelope->getMessage()));
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private const SECRET_REASON = 'Motif confidentiel H4-secret';

    private function absence(User $user, string $from, string $to): int
    {
        $this->client->request('POST', '/api/absences', server: $this->auth(), content: json_encode([
            'userId' => $user->getId(), 'dateStart' => $from, 'dateEnd' => $to, 'reason' => self::SECRET_REASON,
        ]));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (int) json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
    }

    /** Plays the worker for the person-facing messages (never the manager summary — see class docblock). */
    private function drain(): void
    {
        do {
            $played = false;
            foreach ($this->transport()->getSent() as $envelope) {
                if (isset($this->seen[spl_object_id($envelope)])) {
                    continue;
                }
                $this->seen[spl_object_id($envelope)] = true;
                $message = $envelope->getMessage();
                if ($message instanceof SendTemplatedEmailMessage) {
                    $this->emails[] = $message;
                } elseif ($message instanceof AbsenceImpactNotificationMessage || $message instanceof AbsenceMissionsReactedMessage || $message instanceof MissionLifecycleChangedMessage || $message instanceof \App\Message\PlanningRestoredAfterAbsenceMessage) {
                    if ($message instanceof AbsenceImpactNotificationMessage) {
                        $this->notificationEnvelopes[] = $envelope;
                    }
                    $this->em->clear();
                    $this->bus()->dispatch($envelope->with(new ReceivedStamp('async')));
                    $played = true;
                }
            }
        } while ($played);
    }

    private function firstNotificationMessage(): \Symfony\Component\Messenger\Envelope
    {
        self::assertNotEmpty($this->notificationEnvelopes, 'no AbsenceImpactNotificationMessage dispatched');

        return $this->notificationEnvelopes[0];
    }

    /** @return list<array<string, mixed>> */
    private function dispatches(User $user, ?string $type = null): array
    {
        return $this->conn()->fetchAllAssociative(
            'SELECT * FROM notification_dispatch WHERE recipient_user_id = ?' . ($type !== null ? ' AND notification_type = ?' : '') . ' ORDER BY id',
            $type !== null ? [$user->getId(), $type] : [$user->getId()],
        );
    }

    /** @return list<SendTemplatedEmailMessage> */
    private function emailsTo(User $user): array
    {
        $this->drain();

        return array_values(array_filter($this->emails, static fn (SendTemplatedEmailMessage $m) => $m->to === $user->getEmail()));
    }

    /** @return array{summary: string, payload: array<string, mixed>} */
    private function activity(string $type, int $absenceId): array
    {
        $row = $this->conn()->fetchAssociative('SELECT summary, payload FROM activity_event WHERE event_type = ? AND absence_id = ? ORDER BY id DESC LIMIT 1', [$type, $absenceId]);
        self::assertNotFalse($row, "$type traced");

        return ['summary' => $row['summary'], 'payload' => json_decode((string) $row['payload'], true)];
    }

    private function mission(?User $instrumentist, MissionStatus $status, string $day): Mission
    {
        $m = (new Mission())->setSite($this->site)->setSurgeon($this->schiepers)->setCreatedBy($this->schiepers)
            ->setType(MissionType::BLOCK)->setSchedulePrecision(SchedulePrecision::EXACT)->setStatus($status)->setInstrumentist($instrumentist)
            ->setStartAt(new \DateTimeImmutable("$day 08:00"))->setEndAt(new \DateTimeImmutable("$day 13:00"));
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }

    private function user(string $first, string $last, string $role = 'ROLE_INSTRUMENTIST'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $u = (new User())->setEmail('h4-' . bin2hex(random_bytes(5)) . '@surgicalhub.test')->setRoles([$role])
            ->setFirstname($first)->setLastname($last)->setActive(true);
        $u->setPassword($hasher->hashPassword($u, self::PASSWORD));
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();

        return $u;
    }

    private function member(User $user): User
    {
        $this->em->persist((new SiteMembership())->setUser($user)->setSite($this->site)->setSiteRole('INSTRUMENTIST'));
        $this->em->flush();

        return $user;
    }

    private function auth(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token];
    }

    private function conn(): Connection
    {
        return $this->em->getConnection();
    }

    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    private function bus(): MessageBusInterface
    {
        return static::getContainer()->get(MessageBusInterface::class);
    }
}
