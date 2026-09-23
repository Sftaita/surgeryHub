<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\OutboundNotificationAttempt;
use App\Entity\User;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use App\Service\AuditService;
use App\Service\EncodingReminderService;
use App\Service\NotificationService;
use App\Service\OutboundNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * D-083 — covers processMission()'s channel selection (Push prioritaire, repli email
 * uniquement si Push n'est pas livrable) and idempotence (réclamation atomique). The
 * eligibility query itself (findEligibleMissions()) is DQL-heavy and covered by a real-DB
 * functional test instead (EncodingReminderServiceEligibilityTest) — mocking QueryBuilder
 * can't meaningfully verify WHERE-clause correctness.
 *
 * D-084 — Push/email dispatch itself now goes through OutboundNotificationService
 * (traced history); this test mocks that service and controls the OutboundNotification
 * it returns to drive the push-vs-email branch, same as WebPushServiceTest already
 * covers the transport-level detail.
 */
class EncodingReminderServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private OutboundNotificationService&MockObject $outboundNotificationService;
    private NotificationService&MockObject $notificationService;
    private AuditService&MockObject $auditService;
    private LoggerInterface&MockObject $logger;

    /** Controls what the atomic claim UPDATE reports as affected rows. */
    private int $claimAffectedRows = 1;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->outboundNotificationService = $this->createMock(OutboundNotificationService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->claimAffectedRows = 1;

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
    }

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeInstrumentist(): User
    {
        $u = new User();
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

    /** A real OutboundNotification (not a mock) with a chosen status, as recordPushSend() would return. */
    private function makePushNotification(OutboundNotificationStatus $status, bool $withExpiredAttempt = false): OutboundNotification
    {
        $n = (new OutboundNotification())->setChannel(OutboundNotificationChannel::PUSH)->setStatus($status);
        if ($withExpiredAttempt) {
            $n->addAttempt((new OutboundNotificationAttempt())->setSuccess(false)->setReason('expired'));
        }
        $this->setId($n, self::$nextId++);
        return $n;
    }

    private function service(): EncodingReminderService
    {
        return new EncodingReminderService($this->em, $this->outboundNotificationService, $this->notificationService, $this->auditService, $this->logger);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-26 10:00:00', new \DateTimeZone('Europe/Brussels'));
    }

    // ── canal ────────────────────────────────────────────────────────────────

    public function test_sends_push_only_when_deliverable_no_email(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->outboundNotificationService->expects($this->once())
            ->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');

        $result = $this->service()->processMission($mission, $this->now());

        $this->assertSame('push', $result);
    }

    public function test_falls_back_to_email_when_push_is_skipped(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SKIPPED));
        $this->notificationService->expects($this->once())
            ->method('missionEncodingReminderNotifyInstrumentist')
            ->with($mission, $this->isInstanceOf(OutboundNotification::class), OutboundNotificationFallbackReason::NO_SUBSCRIPTION);

        $result = $this->service()->processMission($mission, $this->now());

        $this->assertSame('email', $result);
    }

    public function test_falls_back_to_email_when_all_push_attempts_fail(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::FAILED));
        $this->notificationService->expects($this->once())->method('missionEncodingReminderNotifyInstrumentist');

        $this->assertSame('email', $this->service()->processMission($mission, $this->now()));
    }

    public function test_fallback_reason_is_expired_when_all_attempts_expired(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::FAILED, withExpiredAttempt: true));
        $this->notificationService->expects($this->once())
            ->method('missionEncodingReminderNotifyInstrumentist')
            ->with($mission, $this->isInstanceOf(OutboundNotification::class), OutboundNotificationFallbackReason::EXPIRED);

        $this->service()->processMission($mission, $this->now());
    }

    public function test_never_sends_push_and_email_together(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');

        $this->service()->processMission($mission, $this->now());
    }

    // ── idempotence ─────────────────────────────────────────────────────────

    public function test_skips_when_the_atomic_claim_finds_it_already_reserved(): void
    {
        $this->claimAffectedRows = 0; // a concurrent run already claimed this mission
        $mission = $this->makeMission($this->makeInstrumentist());

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');

        $this->assertSame('skipped', $this->service()->processMission($mission, $this->now()));
    }

    public function test_second_call_for_the_same_mission_sends_nothing_more(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));

        $service = $this->service();
        $first = $service->processMission($mission, $this->now());

        $this->claimAffectedRows = 0; // simulates the row now having encodingReminderSentAt set
        $second = $service->processMission($mission, $this->now());

        $this->assertSame('push', $first);
        $this->assertSame('skipped', $second);
    }

    public function test_two_distinct_missions_are_processed_independently(): void
    {
        $missionA = $this->makeMission($this->makeInstrumentist());
        $missionB = $this->makeMission($this->makeInstrumentist());

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));

        $service = $this->service();
        $resultA = $service->processMission($missionA, $this->now());
        $resultB = $service->processMission($missionB, $this->now());

        $this->assertSame('push', $resultA);
        $this->assertSame('push', $resultB);
    }

    public function test_skips_defensively_when_instrumentist_is_somehow_missing(): void
    {
        $mission = $this->makeMission(null);

        $this->outboundNotificationService->expects($this->never())->method('recordPushSend');
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');

        $this->assertSame('skipped', $this->service()->processMission($mission, $this->now()));
    }

    // ── contenu ─────────────────────────────────────────────────────────────

    public function test_push_payload_contains_no_patient_data(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $missionId = $mission->getId();

        $captured = [];
        $this->outboundNotificationService->method('recordPushSend')
            ->willReturnCallback(function (User $user, string $type, string $title, string $body, array $data) use (&$captured): OutboundNotification {
                $captured = ['title' => $title, 'body' => $body, 'data' => $data];
                return $this->makePushNotification(OutboundNotificationStatus::SENT);
            });

        $this->service()->processMission($mission, $this->now());

        $this->assertSame('Encodage à finaliser', $captured['title']);
        $this->assertStringNotContainsStringIgnoringCase('patient', $captured['body']);
        $this->assertSame(['missionId', 'url'], array_keys($captured['data']));
        $this->assertSame($missionId, $captured['data']['missionId']);
        $this->assertSame("/app/i/missions/{$missionId}", $captured['data']['url']);
    }

    public function test_message_text_is_human_and_not_guilt_inducing(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());

        $captured = null;
        $this->outboundNotificationService->method('recordPushSend')
            ->willReturnCallback(function (User $user, string $type, string $title, string $body) use (&$captured): OutboundNotification {
                $captured = $body;
                return $this->makePushNotification(OutboundNotificationStatus::SENT);
            });

        $this->service()->processMission($mission, $this->now());

        $this->assertStringNotContainsStringIgnoringCase('retard', $captured);
        $this->assertStringNotContainsStringIgnoringCase('urgent', $captured);
        $this->assertStringContainsString('lorsque vous êtes disponible', $captured);
    }

    // ── D-120 : relance manuelle ────────────────────────────────────────────

    public function test_manual_reminder_sends_push_and_records_audit(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $actor = $this->makeInstrumentist();

        $this->outboundNotificationService->expects($this->once())
            ->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));
        $this->notificationService->expects($this->never())->method('missionEncodingReminderNotifyInstrumentist');
        $this->auditService->expects($this->once())
            ->method('record')
            ->with($mission, $actor, \App\Enum\AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT, $this->callback(
                fn (array $payload) => $payload['channel'] === 'push' && $payload['actorId'] === $actor->getId(),
            ));

        $this->assertSame('push', $this->service()->sendManualReminder($mission, $actor));
    }

    public function test_manual_reminder_falls_back_to_email_and_still_records_audit(): void
    {
        $mission = $this->makeMission($this->makeInstrumentist());
        $actor = $this->makeInstrumentist();

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::FAILED));
        $this->notificationService->expects($this->once())->method('missionEncodingReminderNotifyInstrumentist');
        $this->auditService->expects($this->once())
            ->method('record')
            ->with($mission, $actor, \App\Enum\AuditEventType::MISSION_ENCODING_MANUAL_REMINDER_SENT, $this->callback(
                fn (array $payload) => $payload['channel'] === 'email',
            ));

        $this->assertSame('email', $this->service()->sendManualReminder($mission, $actor));
    }

    public function test_manual_reminder_never_touches_the_automatic_reminder_guard(): void
    {
        // sendManualReminder() must not run the atomic encodingReminderSentAt claim UPDATE
        // (processMission()'s guard) — a manual send is independent of the automatic pipeline.
        $mission = $this->makeMission($this->makeInstrumentist());
        $actor = $this->makeInstrumentist();

        $this->outboundNotificationService->method('recordPushSend')
            ->willReturn($this->makePushNotification(OutboundNotificationStatus::SENT));

        $this->service()->sendManualReminder($mission, $actor);

        $this->assertNull($mission->getEncodingReminderSentAt());
    }

    public function test_manual_reminder_throws_when_mission_has_no_instrumentist(): void
    {
        $mission = $this->makeMission(null);
        $actor = $this->makeInstrumentist();

        $this->expectException(\LogicException::class);

        $this->service()->sendManualReminder($mission, $actor);
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
