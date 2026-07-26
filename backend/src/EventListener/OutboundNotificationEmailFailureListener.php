<?php

namespace App\EventListener;

use App\Message\SendTemplatedEmailMessage;
use App\Service\OutboundNotificationService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * D-084 — records one OutboundNotificationAttempt per failed SendTemplatedEmailMessage
 * handling, and marks the OutboundNotification FAILED only once Messenger has exhausted
 * its retries (`!$event->willRetry()`). A failure that will still retry leaves the
 * notification's status at QUEUED — marking it FAILED early would be dishonest (a status
 * this codebase promises means something precise, see D-084/OutboundNotificationStatus).
 *
 * The success path is NOT handled here — see SendTemplatedEmailMessageHandler, which
 * records the successful attempt itself once `$mailer->send()` returns without throwing.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final class OutboundNotificationEmailFailureListener
{
    public function __construct(
        private readonly OutboundNotificationService $outboundNotificationService,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof SendTemplatedEmailMessage || $message->outboundNotificationId === null) {
            return;
        }

        $this->outboundNotificationService->recordEmailAttempt(
            $message->outboundNotificationId,
            success: false,
            reason: self::normalizeThrowableMessage($event->getThrowable()),
            final: !$event->willRetry(),
        );
    }

    /**
     * Never persist a raw exception message verbatim — a mailer/transport error can
     * embed the SMTP DSN (which may carry a password). Redacts any URI-like substring
     * and truncates.
     */
    private static function normalizeThrowableMessage(\Throwable $e): string
    {
        $message = preg_replace('#\w+://\S+#', '[redacted]', $e->getMessage()) ?? 'error';

        return substr($message, 0, 200);
    }
}
