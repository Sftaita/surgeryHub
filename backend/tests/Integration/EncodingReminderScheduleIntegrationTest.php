<?php

namespace App\Tests\Integration;

use App\Entity\AuditEvent;
use App\Entity\Hospital;
use App\Entity\Mission;
use App\Entity\MissionEncodingReminder;
use App\Entity\MissionExecution;
use App\Entity\NotificationEvent;
use App\Entity\OutboundNotification;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\EncodingReminderStage;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use App\Enum\SchedulePrecision;
use App\Message\SendTemplatedEmailMessage;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\EncodingCompletenessService;
use App\Service\EncodingReminderService;
use App\Service\NotificationService;
use App\Service\NotificationTargetResolver;
use App\Service\OutboundNotificationService;
use App\Service\WebPushService;
use App\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * D-143 — preuve sur base réelle de la relance progressive (scénarios 7 à 15 du lot 10) :
 * canal par échéance (push OU e-mail à J+1/J+2, push + e-mail obligatoire et alerte
 * managers à J+4), repli e-mail contrôlé sur push absent/en échec, centre de
 * notifications, arrêt dès la soumission, aucune relance en double à la même échéance.
 *
 * Le transport push est simulé (MockHandler Guzzle, jamais d'appel réel) ; les e-mails
 * sont capturés sur un bus d'enregistrement (jamais envoyés).
 */
final class EncodingReminderScheduleIntegrationTest extends KernelTestCase
{
    private const TZ = 'Europe/Brussels';

    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $dispatched = [];
    private array $created = ['missions' => [], 'users' => [], 'sites' => []];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->dispatched = [];
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->created['missions'] as $id) {
            $conn->executeStatement('UPDATE outbound_notification SET fallback_of_id = NULL WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE d FROM notification_dispatch_delivery d JOIN notification_dispatch n ON n.id = d.dispatch_id WHERE n.mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM notification_dispatch WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM notification_event WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM outbound_notification WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM audit_event WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM material_line WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM mission_execution WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM mission_encoding_reminder WHERE mission_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM mission WHERE id = ?', [$id]);
        }
        foreach ($this->created['users'] as $id) {
            $conn->executeStatement('DELETE FROM push_subscription WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM notification_event WHERE user_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM user WHERE id = ?', [$id]);
        }
        foreach ($this->created['sites'] as $id) {
            $conn->executeStatement('DELETE FROM hospital WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────

    private function makeUser(string $role, string $firstname = 'Test'): User
    {
        $u = new User();
        $u->setEmail('d143-' . bin2hex(random_bytes(4)) . '@test.com');
        $u->setRoles([$role]);
        $u->setActive(true);
        $u->setFirstname($firstname);
        $u->setLastname('D143');
        $this->em->persist($u);
        $this->em->flush();
        $this->created['users'][] = $u->getId();
        return $u;
    }

    private function subscribe(User $user): void
    {
        $this->em->persist((new PushSubscription())
            ->setUser($user)
            ->setEndpoint('https://fcm.googleapis.com/fcm/send/' . bin2hex(random_bytes(16)))
            ->setPublicKey('BIU71jF27lGRVeJSQ1Bg82JpaC7r71OOff55wBTFM8CAEowOuj1udpNHJOMFm53Hm1FLLAQH5QrAfdCRutwiWuc')
            ->setAuthToken('fEIRI1HhX0nIGF4RXV9-dA')
            ->setContentEncoding('aes128gcm'));
        $this->em->flush();
    }

    /** Mission de bloc finie le 14/07/2026 à 17:30 (Bruxelles) — J+1 = 15/07, J+2 = 16/07, J+4 = 18/07. */
    private function makeMission(User $instrumentist, MissionStatus $status = MissionStatus::IN_PROGRESS, string $localEnd = '2026-07-14 17:30'): Mission
    {
        $site = new Hospital();
        $site->setName('D143-' . bin2hex(random_bytes(3)));
        $this->em->persist($site);
        $this->em->flush();
        $this->created['sites'][] = $site->getId();

        $surgeon = $this->makeUser('ROLE_SURGEON', 'Chirurgien');
        $end = new \DateTimeImmutable($localEnd, new \DateTimeZone(self::TZ));

        $m = new Mission();
        $m->setSite($site);
        $m->setSurgeon($surgeon);
        $m->setCreatedBy($surgeon);
        $m->setInstrumentist($instrumentist);
        $m->setType(MissionType::BLOCK);
        $m->setSchedulePrecision(SchedulePrecision::EXACT);
        $m->setStatus($status);
        $m->setStartAt($end->modify('-9 hours'));
        $m->setEndAt($end);
        $this->em->persist($m);
        $this->em->flush();
        $this->created['missions'][] = $m->getId();
        return $m;
    }

    private function at(string $local): \DateTimeImmutable
    {
        return new \DateTimeImmutable($local, new \DateTimeZone(self::TZ));
    }

    /**
     * Service réel branché sur le VRAI NotificationDispatcher (préférences, traces par canal,
     * idempotence par épisode, activity_event) ; seuls le transport push (MockHandler Guzzle,
     * jamais d'appel réel) et le bus (e-mails capturés, jamais envoyés) sont simulés.
     */
    private function service(MockHandler $push): EncodingReminderService
    {
        $c = self::getContainer();
        $webPush = new WebPushService(
            $this->em,
            new NullLogger(),
            $c->getParameter('app.vapid.public_key'),
            $c->getParameter('app.vapid.private_key'),
            $c->getParameter('app.vapid.subject'),
            ['handler' => HandlerStack::create($push)],
        );
        $bus = new class($this->dispatched) implements MessageBusInterface {
            public function __construct(private array &$sink) {}
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sink[] = $message;
                return new Envelope($message);
            }
        };
        $outbound = new OutboundNotificationService($this->em, $webPush, $c->get(\App\Activity\Recorder\NotificationActivityRecorder::class), $c->get(\App\Notification\MissionCommunicationService::class));
        $dispatcher = new NotificationDispatcher(
            $this->em,
            $c->get(\App\Service\NotificationPreferenceResolver::class),
            $outbound,
            $c->get(\App\Activity\Recorder\NotificationActivityRecorder::class),
            $c->get(\App\Activity\CorrelationContext::class),
            $bus,
            new NullLogger(),
            $c->get(\App\Notification\MissionCommunicationService::class),
            'noreply@test.com',
            'SurgicalHub',
        );

        return new EncodingReminderService(
            $this->em,
            $dispatcher,
            $c->get(NotificationService::class),
            $c->get(AuditService::class),
            new EncodingCompletenessService(),
            $c->get(UserRepository::class),
            new NotificationTargetResolver(),
            new NullLogger(),
            'https://app.test',
            true,
        );
    }

    /** @return list<SendTemplatedEmailMessage> */
    private function emailsTo(User $user): array
    {
        return array_values(array_filter(
            $this->dispatched,
            static fn (object $m) => $m instanceof SendTemplatedEmailMessage && $m->to === $user->getEmail(),
        ));
    }

    /** @return list<OutboundNotification> toutes les livraisons tracées (dispatcher : une par canal) */
    private function outbound(User $user): array
    {
        return $this->em->getRepository(OutboundNotification::class)->findBy(['recipientUser' => $user], ['id' => 'ASC']);
    }

    private function channel(User $user, OutboundNotificationChannel $channel): ?OutboundNotification
    {
        $rows = array_values(array_filter($this->outbound($user), static fn (OutboundNotification $n) => $n->getChannel() === $channel));

        return $rows[count($rows) - 1] ?? null;
    }

    private function reminderRow(Mission $mission, EncodingReminderStage $stage): ?MissionEncodingReminder
    {
        $this->em->clear();
        return $this->em->getRepository(MissionEncodingReminder::class)->findOneBy(['mission' => $mission->getId(), 'stage' => $stage]);
    }

    private function inAppReminders(User $user): array
    {
        return $this->em->getRepository(NotificationEvent::class)->findBy(['user' => $user->getId(), 'eventType' => 'ENCODING_REMINDER']);
    }

    // ── Scénarios ───────────────────────────────────────────────────────────────

    /** Scénario 7 — J+1, push actif : push, sans e-mail. */
    public function test_d1_with_working_push_sends_push_only(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->subscribe($instr);
        $mission = $this->makeMission($instr);

        $result = $this->service(new MockHandler([new Response(201)]))->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 09:05'));

        self::assertSame('sent', $result);
        self::assertSame([], $this->emailsTo($instr), 'jamais d\'e-mail quand le push est livré');
        $push = $this->channel($instr, OutboundNotificationChannel::PUSH);
        self::assertSame(OutboundNotificationStatus::SENT, $push->getStatus());
        self::assertSame('ENCODING_REMINDER', $push->getNotificationType());
        self::assertSame('Gardez votre journée en mémoire !', $push->getTitle());
        $email = $this->channel($instr, OutboundNotificationChannel::EMAIL);
        self::assertSame(OutboundNotificationStatus::SKIPPED, $email->getStatus(), 'e-mail tracé comme non envoyé : couvert par le push');
        self::assertSame('COVERED_BY_PUSH', $email->getSkipReason()?->value);

        $row = $this->reminderRow($mission, EncodingReminderStage::D1);
        self::assertSame(['IN_APP', 'PUSH'], $row->getInstrumentistChannels());
        self::assertNull($row->getEmailReason());
        self::assertCount(1, $this->inAppReminders($instr), 'la relance est dans le centre de notifications');
    }

    /** Scénario 8 — J+1, pas de push : e-mail de secours. */
    public function test_d1_without_push_falls_back_to_email(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission($instr);

        $this->service(new MockHandler([]))->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 09:05'));

        $emails = $this->emailsTo($instr);
        self::assertCount(1, $emails);
        self::assertSame('emails/encoding_reminder_d1.html.twig', $emails[0]->htmlTemplate);
        self::assertSame('https://app.test/app/i/missions/' . $mission->getId() . '/encoding', $emails[0]->context['missionUrl']);

        $push = $this->channel($instr, OutboundNotificationChannel::PUSH);
        $email = $this->channel($instr, OutboundNotificationChannel::EMAIL);
        self::assertSame(OutboundNotificationStatus::SKIPPED, $push->getStatus());
        self::assertSame(OutboundNotificationStatus::QUEUED, $email->getStatus());
        self::assertSame($push->getId(), $email->getFallbackOf()?->getId());
        self::assertSame(OutboundNotificationFallbackReason::NO_SUBSCRIPTION, $email->getFallbackReason());

        $row = $this->reminderRow($mission, EncodingReminderStage::D1);
        self::assertSame(['IN_APP', 'EMAIL'], $row->getInstrumentistChannels());
        self::assertSame('PUSH_NO_SUBSCRIPTION', $row->getEmailReason());
    }

    /** Scénario 9 — J+2, push actif : push, sans e-mail. */
    public function test_d2_with_working_push_sends_push_only(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->subscribe($instr);
        $mission = $this->makeMission($instr);

        $this->service(new MockHandler([new Response(201)]))->processReminder($mission, EncodingReminderStage::D2, $this->at('2026-07-16 10:00'));

        self::assertSame([], $this->emailsTo($instr));
        $push = $this->channel($instr, OutboundNotificationChannel::PUSH);
        self::assertSame(OutboundNotificationStatus::SENT, $push->getStatus());
        self::assertSame('Votre encodage attend sa dernière touche', $push->getTitle());
        self::assertStringContainsString('mission du 14/07/2026', (string) $push->getBodyText());
    }

    /** Scénario 10 — J+2, pas de push : e-mail détaillé. */
    public function test_d2_without_push_sends_the_detailed_email(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Diane');
        $mission = $this->makeMission($instr);

        $this->service(new MockHandler([]))->processReminder($mission, EncodingReminderStage::D2, $this->at('2026-07-16 10:00'));

        $emails = $this->emailsTo($instr);
        self::assertCount(1, $emails);
        self::assertSame('Un petit rappel pour votre mission SurgicalHub', $emails[0]->subject);
        self::assertSame('emails/encoding_reminder_d2.html.twig', $emails[0]->htmlTemplate);
        self::assertSame('Diane', $emails[0]->context['firstname']);
        self::assertSame('14/07/2026', $emails[0]->context['missionDate']);
        self::assertTrue($emails[0]->context['hoursMissing'], 'aucune heure réelle encodée');
    }

    /** Scénario 11 — échec du push : repli e-mail contrôlé et tracé. */
    public function test_push_transport_failure_falls_back_to_email(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $this->subscribe($instr);
        $mission = $this->makeMission($instr);

        $this->service(new MockHandler([new Response(403, [], '{"reason":"BadJwtToken"}')]))
            ->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 09:05'));

        self::assertCount(1, $this->emailsTo($instr));
        self::assertSame(OutboundNotificationStatus::FAILED, $this->channel($instr, OutboundNotificationChannel::PUSH)->getStatus());
        self::assertSame(OutboundNotificationFallbackReason::ALL_FAILED, $this->channel($instr, OutboundNotificationChannel::EMAIL)->getFallbackReason());
        self::assertSame('PUSH_ALL_FAILED', $this->reminderRow($mission, EncodingReminderStage::D1)->getEmailReason());
    }

    /** Scénario 12 — J+4 : push + e-mail obligatoire à l'instrumentiste, in-app + e-mail aux managers. */
    public function test_d4_escalates_to_managers_and_always_emails_the_instrumentist(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST', 'Diane');
        $this->subscribe($instr);
        $manager = $this->makeUser('ROLE_MANAGER', 'Marie');
        $mission = $this->makeMission($instr);
        $service = $this->service(new MockHandler(array_fill(0, 3, new Response(201))));

        // Relances précédentes réellement envoyées : elles doivent figurer dans l'alerte manager.
        $service->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 09:05'));
        $service->processReminder($mission, EncodingReminderStage::D2, $this->at('2026-07-16 09:05'));
        $this->dispatched = [];
        $service->processReminder($mission, EncodingReminderStage::D4, $this->at('2026-07-18 09:05'));

        $instrEmails = $this->emailsTo($instr);
        self::assertCount(1, $instrEmails, 'e-mail J+4 systématique même avec un push livré');
        self::assertSame('emails/encoding_reminder_d4.html.twig', $instrEmails[0]->htmlTemplate);

        $managerEmails = $this->emailsTo($manager);
        self::assertCount(1, $managerEmails);
        self::assertSame('Encodage en attente depuis 4 jours', $managerEmails[0]->subject);
        $ctx = $managerEmails[0]->context;
        self::assertSame('Diane D143', $ctx['instrumentistName']);
        self::assertSame('14/07/2026', $ctx['missionDate']);
        self::assertSame('Chirurgien D143', $ctx['surgeonName']);
        self::assertSame(['Heure de début prestée', 'Heure de fin prestée'], $ctx['missing']);
        self::assertCount(2, $ctx['previousReminders']);
        self::assertSame('https://app.test/app/m/billing/encodings?missionId=' . $mission->getId(), $ctx['trackingUrl']);

        $escalations = $this->em->getRepository(NotificationEvent::class)->findBy(['user' => $manager->getId(), 'eventType' => 'ENCODING_ESCALATION']);
        self::assertCount(1, $escalations);

        $row = $this->reminderRow($mission, EncodingReminderStage::D4);
        self::assertSame(['IN_APP', 'PUSH', 'EMAIL'], $row->getInstrumentistChannels());
        self::assertSame('MANDATORY', $row->getEmailReason());
        self::assertGreaterThanOrEqual(1, $row->getManagersNotified());

        $audits = $this->em->getRepository(AuditEvent::class)->findBy(['mission' => $mission->getId(), 'eventType' => AuditEventType::MISSION_ENCODING_AUTO_REMINDER_SENT]);
        self::assertCount(3, $audits);
    }

    /** Scénarios 13-14 — mission soumise (en attente de validation) : aucune relance. */
    public function test_submitted_mission_is_never_reminded(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission($instr, MissionStatus::SUBMITTED);
        $service = $this->service(new MockHandler([]));

        self::assertNotContains($mission->getId(), array_map(static fn (array $d) => $d['mission']->getId(), $service->findDueReminders($this->at('2026-07-15 09:05'))));
        self::assertSame('skipped', $service->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 09:05')));
        self::assertSame([], $this->outbound($instr));
        self::assertNull($this->reminderRow($mission, EncodingReminderStage::D1));
    }

    /** Soumise entre la sélection et l'envoi : l'état est recontrôlé juste avant. */
    public function test_state_is_rechecked_right_before_sending(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission($instr);
        $service = $this->service(new MockHandler([]));
        $due = $service->findDueReminders($this->at('2026-07-15 09:05'));
        self::assertContains($mission->getId(), array_map(static fn (array $d) => $d['mission']->getId(), $due));

        $this->em->getConnection()->executeStatement("UPDATE mission SET status = 'SUBMITTED' WHERE id = ?", [$mission->getId()]);

        self::assertSame('skipped', $service->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 09:05')));
        self::assertSame([], $this->emailsTo($instr));
    }

    /** Scénario 15 — nouvelle exécution du traitement : aucun doublon. */
    public function test_second_run_at_the_same_stage_sends_nothing(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission($instr);
        $service = $this->service(new MockHandler([]));

        self::assertSame('sent', $service->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 09:05')));
        self::assertSame('skipped', $service->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 09:20')));
        // Un second service (= worker redémarré / nouveau déploiement) ne change rien.
        self::assertSame('skipped', $this->service(new MockHandler([]))->processReminder($mission, EncodingReminderStage::D1, $this->at('2026-07-15 11:00')));

        self::assertCount(1, $this->emailsTo($instr));
        self::assertCount(3, $this->outbound($instr), 'une seule livraison tracée par canal (in-app, push SKIPPED, e-mail), une seule fois');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM notification_dispatch WHERE mission_id = ?', [$mission->getId()]), 'un seul dispatch pour l\'échéance');
        self::assertCount(1, $this->inAppReminders($instr));
    }

    /** Aucune relance avant l'échéance, ni hors fenêtre. */
    public function test_nothing_is_due_before_nine_or_outside_the_windows(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $mission = $this->makeMission($instr);
        $service = $this->service(new MockHandler([]));
        $ids = static fn (array $due) => array_map(static fn (array $d) => $d['mission']->getId(), $due);

        self::assertNotContains($mission->getId(), $ids($service->findDueReminders($this->at('2026-07-15 08:59'))));
        self::assertNotContains($mission->getId(), $ids($service->findDueReminders($this->at('2026-07-17 10:00'))));
        self::assertContains($mission->getId(), $ids($service->findDueReminders($this->at('2026-07-18 09:00'))));
        self::assertSame('skipped', $service->processReminder($mission, EncodingReminderStage::D2, $this->at('2026-07-15 10:00')), 'jamais une échéance qui n\'est pas encore due');
    }

    /** Les messages restent cohérents avec ce qui manque réellement. */
    public function test_situation_reflects_the_real_draft(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $notStarted = $this->makeMission($instr);
        $hoursMissing = $this->makeMission($instr);
        $notSubmitted = $this->makeMission($instr);

        // Brouillon commencé sans horaires complets : début seul (encodage progressif).
        $draft = (new MissionExecution())
            ->setActualStartAt(new \DateTimeImmutable('2026-07-14 08:00', new \DateTimeZone(self::TZ)));
        $draft->setMission($hoursMissing);
        $this->em->persist($draft);

        $execution = (new MissionExecution())
            ->setActualStartAt(new \DateTimeImmutable('2026-07-14 08:00', new \DateTimeZone(self::TZ)))
            ->setActualEndAt(new \DateTimeImmutable('2026-07-14 17:00', new \DateTimeZone(self::TZ)));
        $execution->setMission($notSubmitted);
        $this->em->persist($execution);
        $this->em->flush();

        $service = $this->service(new MockHandler([]));
        foreach ([$notStarted, $hoursMissing, $notSubmitted] as $m) {
            $service->processReminder($m, EncodingReminderStage::D2, $this->at('2026-07-16 09:05'));
        }

        self::assertSame('NOT_STARTED', $this->reminderRow($notStarted, EncodingReminderStage::D2)->getSituation()->value);
        self::assertSame('HOURS_MISSING', $this->reminderRow($hoursMissing, EncodingReminderStage::D2)->getSituation()->value);
        $row = $this->reminderRow($notSubmitted, EncodingReminderStage::D2);
        self::assertSame('NOT_SUBMITTED', $row->getSituation()->value);
        self::assertSame([], $row->getMissingItems(), 'on n\'affirme jamais qu\'il manque du matériel ou une intervention');

        $hoursFlags = array_map(static fn (SendTemplatedEmailMessage $m) => $m->context['hoursMissing'], $this->emailsTo($instr));
        self::assertSame([true, true, false], $hoursFlags);
    }

    /**
     * D-144 — destinataires J+4 : managers/admins actifs et opérationnels uniquement, une
     * seule fois chacun ; ni compte inactif, ni compte technique @surgicalhub.internal, ni
     * autre rôle. In-app et e-mail toujours ensemble, chaque envoi tracé.
     */
    public function test_d4_escalation_targets_only_active_operational_managers_once(): void
    {
        $instr = $this->makeUser('ROLE_INSTRUMENTIST');
        $manager = $this->makeUser('ROLE_MANAGER', 'Marie');
        $admin = $this->makeUser('ROLE_ADMIN', 'Alain');
        $inactive = $this->makeUser('ROLE_MANAGER', 'Ines');
        $inactive->setActive(false);
        $technical = $this->makeUser('ROLE_MANAGER', 'Tech');
        $technical->setEmail('deploytest-' . bin2hex(random_bytes(3)) . '@surgicalhub.internal');
        $surgeonOnly = $this->makeUser('ROLE_SURGEON', 'Sam');
        $this->em->flush();
        $mission = $this->makeMission($instr);

        $this->service(new MockHandler([]))->processReminder($mission, EncodingReminderStage::D4, $this->at('2026-07-18 09:05'));
        // Réexécution : jamais une seconde alerte.
        $this->service(new MockHandler([]))->processReminder($mission, EncodingReminderStage::D4, $this->at('2026-07-18 09:20'));

        $conn = $this->em->getConnection();
        $count = static fn (string $sql, User $u) => (int) $conn->fetchOne($sql, [$u->getId(), $mission->getId()]);
        $dispatchSql = "SELECT COUNT(*) FROM notification_dispatch WHERE recipient_user_id = ? AND mission_id = ? AND notification_type = 'ENCODING_ESCALATION'";
        $inAppSql = "SELECT COUNT(*) FROM notification_event WHERE user_id = ? AND mission_id = ? AND event_type = 'ENCODING_ESCALATION'";

        foreach ([$manager, $admin] as $recipient) {
            self::assertSame(1, $count($dispatchSql, $recipient), 'une seule alerte tracée');
            self::assertSame(1, $count($inAppSql, $recipient), 'notification interne');
            self::assertCount(1, $this->emailsTo($recipient), 'e-mail correspondant');
        }
        foreach ([$inactive, $technical, $surgeonOnly, $instr] as $notRecipient) {
            self::assertSame(0, $count($dispatchSql, $notRecipient));
            self::assertSame([], array_filter($this->emailsTo($notRecipient), static fn ($m) => $m->subject === 'Encodage en attente depuis 4 jours'));
        }
    }
}
