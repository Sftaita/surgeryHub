<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\NotificationDispatch;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\NotificationEmailPolicy;
use App\Enum\NotificationType;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationStatus;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationIntent;
use App\Service\AuditService;
use App\Service\EncodingReminderService;
use App\Tests\Support\NotificationServiceFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * D-083 — processMission()'s idempotence (atomic claim) and channel report; D-120 — the
 * manual relaunch. H5a: the send is ONE NotificationDispatcher intent (ENCODING_REMINDER_D1,
 * push first, email fallback, no in-app) — channel decisions and records are the
 * dispatcher's; this test drives the dispatcher's outcome to check what the service reports.
 * The eligibility query (findEligibleMissions()) is covered by a real-DB functional test.
 */
class EncodingReminderServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private NotificationDispatcher&MockObject $dispatcher;
    private AuditService&MockObject $auditService;
    private LoggerInterface&MockObject $logger;

    /** Controls what the atomic claim UPDATE reports as affected rows. */
    private int $claimAffectedRows = 1;
    /** @var list<NotificationIntent> */
    private array $intents = [];
    /** Outcome the fake dispatcher reports: channel => status. */
    private array $outcome = ['PUSH' => 'SENT', 'EMAIL' => 'SKIPPED'];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->dispatcher = $this->createMock(NotificationDispatcher::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->claimAffectedRows = 1;
        $this->intents = [];

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $q = $this->createMock(Query::class);
        $q->method('execute')->willReturnCallback(fn () => $this->claimAffectedRows);
        $qb->method('getQuery')->willReturn($q);
        $this->em->method('createQueryBuilder')->willReturn($qb);

        $this->dispatcher->method('dispatch')->willReturnCallback(function (NotificationIntent $intent): NotificationDispatch {
            $this->intents[] = $intent;
            $dispatch = new NotificationDispatch();
            foreach ($this->outcome as $channel => $status) {
                $dispatch->addDelivery((new OutboundNotification())->setChannel(OutboundNotificationChannel::from($channel))->setStatus(OutboundNotificationStatus::from($status)));
            }

            return $dispatch;
        });
    }

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }

    private function makeInstrumentist(): User
    {
        $u = (new User())->setEmail('i' . self::$nextId . '@test.com')->setFirstname('Jane')->setRoles(['ROLE_INSTRUMENTIST']);
        $this->setId($u, self::$nextId++);
        return $u;
    }

    private function makeMission(?User $instrumentist): Mission
    {
        $m = new Mission();
        if ($instrumentist !== null) {
            $m->setInstrumentist($instrumentist);
        }
        $this->setId($m, self::$nextId++);
        return $m;
    }

    private function service(bool $automaticRemindersScheduled = true): EncodingReminderService
    {
        return new EncodingReminderService($this->em, $this->dispatcher, NotificationServiceFactory::create($this), $this->auditService, $this->logger, $automaticRemindersScheduled);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-26 10:00:00', new \DateTimeZone('Europe/Brussels'));
    }

    // ── canal (H5a : décidé par le dispatcher) ───────────────────────────────

    public function test_one_intent_push_first_email_fallback_no_in_app(): void
    {
        $instr = $this->makeInstrumentist();
        $mission = $this->makeMission($instr);

        $this->assertSame('push', $this->service()->processMission($mission, $this->now()));

        $this->assertCount(1, $this->intents);
        $i = $this->intents[0];
        $this->assertSame(NotificationType::ENCODING_REMINDER_D1, $i->type);
        $this->assertSame($instr, $i->recipient);
        $this->assertSame(NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT, $i->emailPolicy);
        $this->assertFalse($i->inApp, 'no in-app item — as before');
        $this->assertSame('encoding_reminder:auto:' . $mission->getId(), $i->episodeRef, 'at most one automatic reminder per mission, retry included');
        $this->assertSame('emails/mission_encoding_reminder.html.twig', $i->email->htmlTemplate);
    }

    public function test_reports_email_when_the_dispatcher_fell_back_to_email(): void
    {
        $this->outcome = ['PUSH' => 'SKIPPED', 'EMAIL' => 'QUEUED'];
        $this->assertSame('email', $this->service()->processMission($this->makeMission($this->makeInstrumentist()), $this->now()));
    }

    public function test_reports_skipped_when_no_channel_carried_it(): void
    {
        $this->outcome = ['PUSH' => 'SKIPPED', 'EMAIL' => 'SKIPPED'];
        $this->assertSame('skipped', $this->service()->processMission($this->makeMission($this->makeInstrumentist()), $this->now()));
    }

    public function test_skips_when_the_atomic_claim_finds_it_already_reserved(): void
    {
        $this->claimAffectedRows = 0;
        $this->assertSame('skipped', $this->service()->processMission($this->makeMission($this->makeInstrumentist()), $this->now()));
        $this->assertSame([], $this->intents);
    }

    public function test_skips_defensively_when_instrumentist_is_somehow_missing(): void
    {
        $this->assertSame('skipped', $this->service()->processMission($this->makeMission(null), $this->now()));
        $this->assertSame([], $this->intents);
    }

    public function test_push_payload_contains_no_patient_data_and_text_is_not_guilt_inducing(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $this->service()->processMission($mission, $this->now());

        $push = $this->intents[0]->push;
        $this->assertSame(['missionId', 'url'], array_keys($push->data));
        $this->assertSame('Encodage à finaliser', $push->title);
        $this->assertStringContainsString('lorsque vous êtes disponible', $push->body);
    }

    // ── D-120 : relance manuelle ─────────────────────────────────────────────

    public function test_manual_reminder_is_a_new_episode_and_records_the_channel_in_the_audit(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $this->outcome = ['PUSH' => 'SKIPPED', 'EMAIL' => 'QUEUED'];
        $actor = $this->makeInstrumentist();
        $this->auditService->expects($this->once())->method('record')
            ->with($mission, $actor, AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT, $this->callback(static fn (array $p) => $p['channel'] === 'email'));

        $this->assertSame('email', $this->service()->sendManualReminder($mission, $actor));
        $this->assertNull($this->intents[0]->episodeRef, 'one episode per manager action (the operation\'s correlation)');
        $this->assertStringContainsString('Un manager vous rappelle', $this->intents[0]->push->body);
        $this->assertSame(NotificationEmailPolicy::MANDATORY, $this->intents[0]->emailPolicy, 'H5a — explicit manager action: the email never depends on preferences');
    }

    public function test_manual_reminder_batch_is_its_episode_and_reports_both_channels(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $this->outcome = ['PUSH' => 'SENT', 'EMAIL' => 'QUEUED'];

        $this->assertSame('push+email', $this->service()->sendManualReminder($mission, $this->makeInstrumentist(), 'batch-1234abcd'));
        $this->assertSame('encoding_reminder:manual:' . $mission->getId() . ':batch-1234abcd', $this->intents[0]->episodeRef, 'a retry of the same action is the same episode');
    }

    public function test_the_automatic_reminder_keeps_the_preference_driven_fallback(): void
    {
        $this->service()->processMission($this->makeMission($this->makeInstrumentist()), $this->now());

        $this->assertSame(NotificationEmailPolicy::FALLBACK_WHEN_PUSH_NOT_SENT, $this->intents[0]->emailPolicy, 'never MANDATORY for the automatic reminder');
    }

    public function test_manual_reminder_never_touches_the_automatic_reminder_guard(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $this->em->expects($this->never())->method('createQueryBuilder');
        $this->service()->sendManualReminder($mission, $this->makeInstrumentist());

        $this->assertNull($mission->getEncodingReminderSentAt());
    }

    public function test_manual_reminder_throws_when_mission_has_no_instrumentist(): void
    {
        $this->expectException(\LogicException::class);
        $this->service()->sendManualReminder($this->makeMission(null), $this->makeInstrumentist());
    }

    // ── D-120 : prochaine relance automatique (calcul pur, aucune requête) ──

    public function test_next_automatic_reminder_at_is_end_at_plus_one_day_8am_brussels(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $mission->setStatus(\App\Enum\MissionStatus::ASSIGNED);
        $mission->setEndAt(new \DateTimeImmutable('2026-07-25 18:00:00', new \DateTimeZone('Europe/Brussels')));

        $this->assertSame(
            '2026-07-26T08:00:00+02:00',
            $this->service()->nextAutomaticReminderAt($mission, new \DateTimeImmutable('2026-07-25 20:00:00', new \DateTimeZone('Europe/Brussels'))),
        );
    }

    public function test_next_automatic_reminder_at_is_null_while_the_d083_schedule_is_not_enabled(): void
    {
        // Prod : la commande D-083 n'est pas planifiée (ENCODING_REMINDER_AUTO_ENABLED=0) —
        // le tiroir manager ne doit jamais annoncer une relance que rien n'enverra.
        $mission = $this->makeMission($this->makeInstrumentist());
        $mission->setStatus(\App\Enum\MissionStatus::ASSIGNED);
        $mission->setEndAt(new \DateTimeImmutable('2026-07-25 18:00:00', new \DateTimeZone('Europe/Brussels')));

        $this->assertNull($this->service(automaticRemindersScheduled: false)->nextAutomaticReminderAt(
            $mission,
            new \DateTimeImmutable('2026-07-25 20:00:00', new \DateTimeZone('Europe/Brussels')),
        ));
    }

    public function test_next_automatic_reminder_at_is_null_once_the_reminder_moment_has_passed(): void
    {
        // findEligibleMissions() ne retient que les missions terminées la veille : passé
        // J+1 08h sans rappel, il n'y en aura plus — jamais une date passée affichée.
        $mission = $this->makeMission($this->makeInstrumentist());
        $mission->setStatus(\App\Enum\MissionStatus::ASSIGNED);
        $mission->setEndAt(new \DateTimeImmutable('2026-07-25 18:00:00', new \DateTimeZone('Europe/Brussels')));

        $this->assertNull($this->service()->nextAutomaticReminderAt(
            $mission,
            new \DateTimeImmutable('2026-07-26 08:00:00', new \DateTimeZone('Europe/Brussels')),
        ));
    }

    public function test_next_automatic_reminder_at_is_null_once_already_sent(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $mission->setStatus(\App\Enum\MissionStatus::ASSIGNED);
        $mission->setEndAt(new \DateTimeImmutable('2026-07-25 18:00:00', new \DateTimeZone('Europe/Brussels')));
        $mission->setEncodingReminderSentAt(new \DateTimeImmutable('2026-07-26 08:00:00'));

        $this->assertNull($this->service()->nextAutomaticReminderAt($mission));
    }

    public function test_next_automatic_reminder_at_is_null_once_submitted(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $mission->setStatus(\App\Enum\MissionStatus::SUBMITTED);
        $mission->setEndAt(new \DateTimeImmutable('2026-07-25 18:00:00', new \DateTimeZone('Europe/Brussels')));
        $mission->setSubmittedAt(new \DateTimeImmutable('2026-07-25 19:00:00'));

        $this->assertNull($this->service()->nextAutomaticReminderAt($mission));
    }

    public function test_next_automatic_reminder_at_is_null_without_instrumentist(): void
    {
        $mission = $this->makeMission(null);
        $mission->setStatus(\App\Enum\MissionStatus::ASSIGNED);
        $mission->setEndAt(new \DateTimeImmutable('2026-07-25 18:00:00', new \DateTimeZone('Europe/Brussels')));

        $this->assertNull($this->service()->nextAutomaticReminderAt($mission));
    }
}
