<?php

namespace App\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * H1 (D-126) — carries the dispatching operation's correlation across the Messenger
 * transport, so that every ActivityEvent written by the worker while handling the message
 * keeps the SAME correlationId as the HTTP request / command that dispatched it.
 *
 * $anchors: explicit causal anchors known at dispatch time ("absence:58" => event id) —
 * see CorrelationContext. Never inferred, never a "last event" pointer.
 * $causationEventId: optional explicit cause of the whole message, when the dispatcher
 * knows it.
 */
final class CorrelationStamp implements StampInterface
{
    /** @param array<string, int> $anchors */
    public function __construct(
        public readonly string $correlationId,
        public readonly array $anchors = [],
        public readonly ?int $causationEventId = null,
    ) {
    }
}
