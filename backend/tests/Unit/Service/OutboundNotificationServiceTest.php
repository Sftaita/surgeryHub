<?php

namespace App\Tests\Unit\Service;

use App\Entity\Mission;
use App\Entity\OutboundNotification;
use App\Entity\User;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use App\Service\OutboundNotificationService;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * D-084 — persistence/aggregation logic of OutboundNotificationService: Push branch
 * status aggregation (SENT/FAILED/SKIPPED from WebPushService::sendToUserWithAttempts()'s
 * detail), email QUEUED→SENT/FAILED lifecycle, fallback reason derivation, and the
 * payload allowlist that's the only thing standing between a caller's raw "data" array
 * and permanent storage.
 */
class OutboundNotificationServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private WebPushService&MockObject $webPushService;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->webPushService = $this->createMock(WebPushService::class);
    }

    private function service(): OutboundNotificationService
    {
        return new OutboundNotificationService($this->em, $this->webPushService);
    }

    private static int $nextId = 1;

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function makeUser(): User
    {
        $u = new User();
        $this->setId($u, self::$nextId++);
        return $u;
    }

    // ── Push : agrégation de statut ─────────────────────────────────────────

    public function test_push_send_with_one_success_is_sent(): void
    {
        $this->webPushService->method('sendToUserWithAttempts')->willReturn([
            'sent' => 1,
            'attempts' => [['provider' => 'FCM', 'success' => true, 'statusCode' => 201, 'reason' => null]],
        ]);
        $this->em->expects($this->exactly(2))->method('persist'); // notification + 1 attempt
        $this->em->expects($this->once())->method('flush');

        $notification = $this->service()->recordPushSend($this->makeUser(), 'ENCODING_REMINDER_D1', 'Titre', 'Corps');

        $this->assertSame(OutboundNotificationStatus::SENT, $notification->getStatus());
        $this->assertSame(OutboundNotificationChannel::PUSH, $notification->getChannel());
        $this->assertNotNull($notification->getSentAt());
        $this->assertSame(1, $notification->getAttemptCount());
    }

    public function test_push_send_with_multiple_subscriptions_one_success_is_still_sent(): void
    {
        $this->webPushService->method('sendToUserWithAttempts')->willReturn([
            'sent' => 1,
            'attempts' => [
                ['provider' => 'FCM', 'success' => false, 'statusCode' => 410, 'reason' => 'expired'],
                ['provider' => 'APPLE', 'success' => true, 'statusCode' => 201, 'reason' => null],
            ],
        ]);

        $notification = $this->service()->recordPushSend($this->makeUser(), 'ENCODING_REMINDER_D1', 'Titre', 'Corps');

        $this->assertSame(OutboundNotificationStatus::SENT, $notification->getStatus());
        $this->assertSame(2, $notification->getAttemptCount());
    }

    public function test_push_send_with_no_subscriptions_is_skipped(): void
    {
        $this->webPushService->method('sendToUserWithAttempts')->willReturn(['sent' => 0, 'attempts' => []]);

        $notification = $this->service()->recordPushSend($this->makeUser(), 'ENCODING_REMINDER_D1', 'Titre', 'Corps');

        $this->assertSame(OutboundNotificationStatus::SKIPPED, $notification->getStatus());
        $this->assertNull($notification->getSentAt());
        $this->assertNull($notification->getFailedAt());
    }

    public function test_push_send_with_all_attempts_failing_is_failed(): void
    {
        $this->webPushService->method('sendToUserWithAttempts')->willReturn([
            'sent' => 0,
            'attempts' => [['provider' => 'APPLE', 'success' => false, 'statusCode' => 403, 'reason' => 'BadJwtToken']],
        ]);

        $notification = $this->service()->recordPushSend($this->makeUser(), 'ENCODING_REMINDER_D1', 'Titre', 'Corps');

        $this->assertSame(OutboundNotificationStatus::FAILED, $notification->getStatus());
        $this->assertNotNull($notification->getFailedAt());
        $this->assertSame('BadJwtToken', $notification->getFailureMessage());
    }

    public function test_push_attempt_never_carries_an_endpoint(): void
    {
        $this->webPushService->method('sendToUserWithAttempts')->willReturn([
            'sent' => 1,
            'attempts' => [['provider' => 'FCM', 'success' => true, 'statusCode' => 201, 'reason' => null]],
        ]);

        $notification = $this->service()->recordPushSend($this->makeUser(), 'ENCODING_REMINDER_D1', 'Titre', 'Corps');
        $attempt = $notification->getAttempts()->first();

        $this->assertSame('FCM', $attempt->getProvider());
        // The entity has no endpoint field at all — this assertion documents that
        // guarantee at the object-graph level, not just "we didn't set one".
        $this->assertFalse(method_exists($attempt, 'getEndpoint'));
    }

    // ── payload nettoyé ──────────────────────────────────────────────────────

    public function test_clean_payload_keeps_only_allowlisted_keys(): void
    {
        $cleaned = OutboundNotificationService::cleanPayload([
            'missionId'        => 42,
            'planningVersionId' => 7,
            'url'              => '/app/i/missions/42',
            'notificationType' => 'ENCODING_REMINDER_D1',
            'patientName'      => 'Jean Dupont',
            'endpoint'         => 'https://fcm.googleapis.com/fcm/send/secret',
            'p256dh'           => 'abc',
        ]);

        $this->assertSame(
            ['missionId' => 42, 'planningVersionId' => 7, 'url' => '/app/i/missions/42', 'notificationType' => 'ENCODING_REMINDER_D1'],
            $cleaned,
        );
    }

    public function test_push_send_persists_only_cleaned_payload(): void
    {
        $this->webPushService->method('sendToUserWithAttempts')->willReturn(['sent' => 1, 'attempts' => [['provider' => 'FCM', 'success' => true, 'statusCode' => 201, 'reason' => null]]]);

        $notification = $this->service()->recordPushSend(
            $this->makeUser(),
            'ENCODING_REMINDER_D1',
            'Titre',
            'Corps',
            ['missionId' => 42, 'url' => '/x', 'patientDiagnosis' => 'confidential'],
        );

        $this->assertSame(['missionId' => 42, 'url' => '/x'], $notification->getPayload());
    }

    // ── email : QUEUED → SENT / FAILED ──────────────────────────────────────

    public function test_record_email_queued_creates_queued_notification_with_fallback_link(): void
    {
        $mission = new Mission();
        $this->setId($mission, 99);
        $pushNotification = (new OutboundNotification())->setChannel(OutboundNotificationChannel::PUSH)->setStatus(OutboundNotificationStatus::SKIPPED);
        $this->setId($pushNotification, 1);

        $notification = $this->service()->recordEmailQueued(
            $this->makeUser(),
            'ENCODING_REMINDER_D1',
            'SurgicalHub — Encodage à finaliser',
            mission: $mission,
            fallbackOf: $pushNotification,
            fallbackReason: OutboundNotificationFallbackReason::NO_SUBSCRIPTION,
        );

        $this->assertSame(OutboundNotificationStatus::QUEUED, $notification->getStatus());
        $this->assertSame(OutboundNotificationChannel::EMAIL, $notification->getChannel());
        $this->assertSame($pushNotification, $notification->getFallbackOf());
        $this->assertTrue($notification->isFallback());
        $this->assertSame(OutboundNotificationFallbackReason::NO_SUBSCRIPTION, $notification->getFallbackReason());
        $this->assertNotNull($notification->getQueuedAt());
    }

    public function test_record_email_attempt_success_marks_sent_and_backfills_body(): void
    {
        $notification = (new OutboundNotification())->setChannel(OutboundNotificationChannel::EMAIL)->setStatus(OutboundNotificationStatus::QUEUED);
        $this->em->method('find')->with(OutboundNotification::class, 42)->willReturn($notification);

        $this->service()->recordEmailAttempt(42, success: true, reason: null, bodyText: 'Bonjour Jane', bodyHtml: '<p>Bonjour Jane</p>');

        $this->assertSame(OutboundNotificationStatus::SENT, $notification->getStatus());
        $this->assertNotNull($notification->getSentAt());
        $this->assertSame('Bonjour Jane', $notification->getBodyText());
        $this->assertSame('<p>Bonjour Jane</p>', $notification->getBodyHtml());
        $this->assertSame(1, $notification->getAttemptCount());
        $this->assertCount(1, $notification->getAttempts());
    }

    public function test_record_email_attempt_transient_failure_leaves_status_queued(): void
    {
        $notification = (new OutboundNotification())->setChannel(OutboundNotificationChannel::EMAIL)->setStatus(OutboundNotificationStatus::QUEUED);
        $this->em->method('find')->willReturn($notification);

        $this->service()->recordEmailAttempt(42, success: false, reason: 'Connection timed out', final: false);

        $this->assertSame(OutboundNotificationStatus::QUEUED, $notification->getStatus(), 'a retry-pending failure must not be marked FAILED yet');
        $this->assertNull($notification->getFailedAt());
        $this->assertSame(1, $notification->getAttemptCount());
    }

    public function test_record_email_attempt_final_failure_marks_failed(): void
    {
        $notification = (new OutboundNotification())->setChannel(OutboundNotificationChannel::EMAIL)->setStatus(OutboundNotificationStatus::QUEUED);
        $this->em->method('find')->willReturn($notification);

        $this->service()->recordEmailAttempt(42, success: false, reason: 'Connection timed out', final: true);

        $this->assertSame(OutboundNotificationStatus::FAILED, $notification->getStatus());
        $this->assertNotNull($notification->getFailedAt());
        $this->assertSame('Connection timed out', $notification->getFailureMessage());
    }

    public function test_record_email_attempt_appends_not_overwrites_across_retries(): void
    {
        $notification = (new OutboundNotification())->setChannel(OutboundNotificationChannel::EMAIL)->setStatus(OutboundNotificationStatus::QUEUED);
        $this->em->method('find')->willReturn($notification);

        $service = $this->service();
        $service->recordEmailAttempt(42, success: false, reason: 'timeout 1', final: false);
        $service->recordEmailAttempt(42, success: false, reason: 'timeout 2', final: false);
        $service->recordEmailAttempt(42, success: true, reason: null, bodyText: 'ok', bodyHtml: '<p>ok</p>');

        $this->assertSame(OutboundNotificationStatus::SENT, $notification->getStatus());
        $this->assertSame(3, $notification->getAttemptCount());
        $this->assertCount(3, $notification->getAttempts());
    }

    // ── fallbackReasonFor() ──────────────────────────────────────────────────

    public function test_fallback_reason_is_no_subscription_when_no_attempts(): void
    {
        $n = (new OutboundNotification())->setStatus(OutboundNotificationStatus::SKIPPED);
        $this->assertSame(OutboundNotificationFallbackReason::NO_SUBSCRIPTION, OutboundNotificationService::fallbackReasonFor($n));
    }

    public function test_fallback_reason_is_expired_when_all_attempts_expired(): void
    {
        $n = new OutboundNotification();
        $n->addAttempt((new \App\Entity\OutboundNotificationAttempt())->setSuccess(false)->setReason('expired'));
        $n->addAttempt((new \App\Entity\OutboundNotificationAttempt())->setSuccess(false)->setReason('expired'));
        $this->assertSame(OutboundNotificationFallbackReason::EXPIRED, OutboundNotificationService::fallbackReasonFor($n));
    }

    public function test_fallback_reason_is_all_failed_when_mixed_or_other_reasons(): void
    {
        $n = new OutboundNotification();
        $n->addAttempt((new \App\Entity\OutboundNotificationAttempt())->setSuccess(false)->setReason('expired'));
        $n->addAttempt((new \App\Entity\OutboundNotificationAttempt())->setSuccess(false)->setReason('BadJwtToken'));
        $this->assertSame(OutboundNotificationFallbackReason::ALL_FAILED, OutboundNotificationService::fallbackReasonFor($n));
    }
}
