<?php

namespace App\Messenger;

use App\Activity\CorrelationContext;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * H1 (D-126) — propagates CorrelationContext through Messenger.
 *
 * Dispatch side (no ReceivedStamp): attaches a CorrelationStamp built from the current
 * operation, unless the envelope already carries one (a retry/redelivery keeps the
 * original correlation — never a fresh one).
 *
 * Receive side (ReceivedStamp — the async worker, or the sync:// transport re-dispatch):
 * snapshots the current context, adopts the stamp's operation (a message without stamp,
 * e.g. queued before H1, gets a fresh correlationId of its own — never the previous
 * message's), runs the handlers, then restores the snapshot in a finally block, success
 * or exception. Two successive messages in one long-running worker therefore never share
 * a context.
 */
final class CorrelationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CorrelationContext $context,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) === null) {
            if ($envelope->last(CorrelationStamp::class) === null) {
                $envelope = $envelope->with(new CorrelationStamp(
                    correlationId: $this->context->correlationId(),
                    anchors: $this->context->anchors(),
                ));
            }

            return $stack->next()->handle($envelope, $stack);
        }

        /** @var CorrelationStamp|null $stamp */
        $stamp     = $envelope->last(CorrelationStamp::class);
        $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();
        $previous  = $this->context->export();

        $this->context->beginFromMessage(
            $stamp?->correlationId,
            $stamp?->anchors ?? [],
            $messageId !== null ? (string) $messageId : null,
            $stamp?->causationEventId,
        );

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->context->restore($previous);
        }
    }
}
