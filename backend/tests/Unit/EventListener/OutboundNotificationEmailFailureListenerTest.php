<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\OutboundNotificationEmailFailureListener;
use App\Message\SendTemplatedEmailMessage;
use App\Service\AbsenceCommunicationJournalService;
use App\Service\OutboundNotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * D-084 — the listener, not the handler, decides FAILED vs "still retrying": Messenger
 * re-invokes the handler fresh on each retry (no in-handler retry-state access exists in
 * this codebase), so only WorkerMessageFailedEvent::willRetry() can tell the two apart.
 */
class OutboundNotificationEmailFailureListenerTest extends TestCase
{
    private OutboundNotificationService&MockObject $outboundNotificationService;
    private AbsenceCommunicationJournalService&MockObject $absenceCommunicationJournalService;

    protected function setUp(): void
    {
        $this->outboundNotificationService = $this->createMock(OutboundNotificationService::class);
        $this->absenceCommunicationJournalService = $this->createMock(AbsenceCommunicationJournalService::class);
    }

    private function listener(): OutboundNotificationEmailFailureListener
    {
        return new OutboundNotificationEmailFailureListener($this->outboundNotificationService, $this->absenceCommunicationJournalService);
    }

    private function makeEvent(SendTemplatedEmailMessage $message, \Throwable $error, bool $willRetry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'async', $error);
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    public function test_records_a_failed_attempt_but_leaves_status_queued_when_retry_will_happen(): void
    {
        $message = new SendTemplatedEmailMessage('to@x.test', 'S', 'from@x.test', 'F', 'tpl.html.twig', [], null, 777);

        $this->outboundNotificationService->expects($this->once())
            ->method('recordEmailAttempt')
            ->with(777, false, $this->anything(), false);

        $this->listener()->__invoke($this->makeEvent($message, new \RuntimeException('Connection timed out'), willRetry: true));
    }

    public function test_marks_failed_final_when_retries_are_exhausted(): void
    {
        $message = new SendTemplatedEmailMessage('to@x.test', 'S', 'from@x.test', 'F', 'tpl.html.twig', [], null, 777);

        $this->outboundNotificationService->expects($this->once())
            ->method('recordEmailAttempt')
            ->with(777, false, $this->anything(), true);

        $this->listener()->__invoke($this->makeEvent($message, new \RuntimeException('Connection timed out'), willRetry: false));
    }

    public function test_ignores_messages_without_an_outbound_notification_id(): void
    {
        $message = new SendTemplatedEmailMessage('to@x.test', 'S', 'from@x.test', 'F', 'tpl.html.twig', [], null, null);

        $this->outboundNotificationService->expects($this->never())->method('recordEmailAttempt');
        $this->absenceCommunicationJournalService->expects($this->never())->method('recordDeliveryFailure');

        $this->listener()->__invoke($this->makeEvent($message, new \RuntimeException('x'), willRetry: false));
    }

    public function test_ignores_non_email_messages(): void
    {
        $other = new class {
        };

        $this->outboundNotificationService->expects($this->never())->method('recordEmailAttempt');
        $this->absenceCommunicationJournalService->expects($this->never())->method('recordDeliveryFailure');

        $event = new WorkerMessageFailedEvent(new Envelope($other), 'async', new \RuntimeException('x'));
        $this->listener()->__invoke($event);
    }

    /**
     * Communication des absences chirurgiens, Lot A (D-114) — même discipline honnête que
     * OutboundNotification : FAILED seulement une fois les retries épuisés.
     */
    public function test_records_absence_communication_delivery_failure_but_leaves_scheduled_when_retry_will_happen(): void
    {
        $message = new SendTemplatedEmailMessage('to@x.test', 'S', 'from@x.test', 'F', 'tpl.html.twig', [], null, null, 42);

        $this->outboundNotificationService->expects($this->never())->method('recordEmailAttempt');
        $this->absenceCommunicationJournalService->expects($this->once())
            ->method('recordDeliveryFailure')
            ->with(42, $this->anything(), false);

        $this->listener()->__invoke($this->makeEvent($message, new \RuntimeException('Connection timed out'), willRetry: true));
    }

    public function test_marks_absence_communication_delivery_failed_final_when_retries_are_exhausted(): void
    {
        $message = new SendTemplatedEmailMessage('to@x.test', 'S', 'from@x.test', 'F', 'tpl.html.twig', [], null, null, 42);

        $this->absenceCommunicationJournalService->expects($this->once())
            ->method('recordDeliveryFailure')
            ->with(42, $this->anything(), true);

        $this->listener()->__invoke($this->makeEvent($message, new \RuntimeException('Connection timed out'), willRetry: false));
    }

    public function test_never_persists_a_raw_dsn_looking_string_in_the_reason(): void
    {
        $message = new SendTemplatedEmailMessage('to@x.test', 'S', 'from@x.test', 'F', 'tpl.html.twig', [], null, 777);

        $capturedReason = null;
        $this->outboundNotificationService->method('recordEmailAttempt')
            ->willReturnCallback(function (int $id, bool $success, ?string $reason) use (&$capturedReason): void {
                $capturedReason = $reason;
            });

        $error = new \RuntimeException('Connection could not be established with host smtp://user:s3cr3t@smtp.hostinger.com:587: timed out');
        $this->listener()->__invoke($this->makeEvent($message, $error, willRetry: false));

        $this->assertIsString($capturedReason);
        $this->assertStringNotContainsString('s3cr3t', $capturedReason);
        $this->assertStringNotContainsString('smtp://', $capturedReason);
    }
}
