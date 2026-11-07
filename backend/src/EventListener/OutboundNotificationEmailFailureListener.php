<?php

namespace App\EventListener;

use App\Activity\CorrelationContext;
use App\Messenger\CorrelationStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use App\Message\SendTemplatedEmailMessage;
use App\Service\AbsenceCommunicationJournalService;
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
 *
 * Communication des absences chirurgiens, Lot A (D-114) — this listener also covers
 * `SendTemplatedEmailMessage::$absenceCommunicationDeliveryId`, an independent journal with
 * the exact same honesty discipline (FAILED only once retries are exhausted). Extended here
 * rather than duplicated in a second listener class.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final class OutboundNotificationEmailFailureListener
{
    public function __construct(
        private readonly OutboundNotificationService $outboundNotificationService,
        private readonly AbsenceCommunicationJournalService $absenceCommunicationJournalService,
        private readonly CorrelationContext $correlation,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof SendTemplatedEmailMessage) {
            return;
        }

        // H2 (D-128) — the worker already left the message's operation (CorrelationMiddleware
        // restored the previous context in its finally): re-adopt it, so the failure is traced
        // under the same correlation as the email it concerns.
        $stamp    = $event->getEnvelope()->last(CorrelationStamp::class);
        $previous = $this->correlation->export();
        $this->correlation->beginFromMessage(
            $stamp?->correlationId,
            $stamp?->anchors ?? [],
            ($id = $event->getEnvelope()->last(TransportMessageIdStamp::class)?->getId()) !== null ? (string) $id : null,
            $stamp?->causationEventId,
        );

        try {
            $this->record($event, $message);
        } finally {
            $this->correlation->restore($previous);
        }
    }

    private function record(WorkerMessageFailedEvent $event, SendTemplatedEmailMessage $message): void
    {
        if ($message->outboundNotificationId !== null) {
            $this->outboundNotificationService->recordEmailAttempt(
                $message->outboundNotificationId,
                success: false,
                reason: self::normalizeThrowableMessage($event->getThrowable()),
                final: !$event->willRetry(),
            );
        }

        if ($message->absenceCommunicationDeliveryId !== null) {
            $this->absenceCommunicationJournalService->recordDeliveryFailure(
                $message->absenceCommunicationDeliveryId,
                reason: self::normalizeThrowableMessage($event->getThrowable()),
                final: !$event->willRetry(),
            );
        }
    }

    /**
     * Never persist a raw exception message verbatim — a mailer/transport error can embed
     * the SMTP DSN (which may carry a password), internal host names / ports, file paths.
     * H5a (§19): the recorded reason is a CONTROLLED code + French label, readable in the
     * admin history; only our own controlled document errors keep their (safe) wording.
     * Never a stack trace, a credential, a host or a path.
     */
    public static function normalizeThrowableMessage(\Throwable $e): string
    {
        // Messenger wraps the handler's exception: classify the original one.
        while ($e instanceof \Symfony\Component\Messenger\Exception\HandlerFailedException && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }
        $raw = $e->getMessage();

        $reason = match (true) {
            $e instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface
                && str_contains($raw, 'Connection could not be established') => 'SMTP_CONNECTION_FAILED — connexion au serveur d\'envoi impossible',
            $e instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface
                && str_contains($raw, 'Expected response code') => 'SMTP_PROTOCOL_ERROR — réponse inattendue du serveur d\'envoi',
            $e instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface => 'SMTP_TRANSPORT_ERROR — erreur du serveur d\'envoi',
            // DocumentStore — controlled wording, no path ("Document #9 introuvable.").
            $e instanceof \RuntimeException && preg_match('/^Document #\d+ /', $raw) === 1 => 'DOCUMENT_UNAVAILABLE — ' . $raw,
            $e instanceof \Twig\Error\Error => 'TEMPLATE_ERROR — le contenu de l\'email n\'a pas pu être généré',
            default => 'EMAIL_SEND_FAILED — ' . (new \ReflectionClass($e))->getShortName(),
        };

        return substr(preg_replace('#\w+://\S+#', '[redacted]', $reason) ?? 'EMAIL_SEND_FAILED', 0, 200);
    }
}
