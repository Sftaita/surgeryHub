<?php

namespace App\Activity;

use App\Enum\ActivityActorType;
use Symfony\Contracts\Service\ResetInterface;

/**
 * H1 (D-126) — the "current functional operation" of this process, shared by every
 * ActivityEvent written while it is active.
 *
 * One operation = one correlationId: an HTTP request, a console command, or a Messenger
 * message (which INHERITS the correlationId of the operation that dispatched it, through
 * App\Messenger\CorrelationStamp). Started by App\EventListener\CorrelationContextListener
 * (HTTP/console) and App\Messenger\CorrelationMiddleware (worker); reset after each of them
 * and by kernel.reset, so nothing ever leaks from one request/message to the next.
 *
 * correlationId is ALWAYS generated server-side (UUID v7). A client-supplied request id
 * header is only ever kept as the separate, informational requestId — never trusted as a
 * correlationId (that would allow collisions or forged links between operations).
 *
 * Causality is NOT inferred from write order ("the previous event caused this one" would
 * build a false linear chain across siblings). It is only ever recorded from an explicit
 * anchor: the event that introduced a business object in this operation, e.g. the
 * ABSENCE_CREATED event is the anchor of "absence:58", and an AuditEvent whose payload
 * says causedByAbsenceId=58 is linked to it. Anchors travel with CorrelationStamp so a
 * worker-side consequence can still point back to its cause.
 */
final class CorrelationContext implements ResetInterface
{
    /** Hard cap on anchors carried in a context/stamp — a stamp must stay small. */
    private const MAX_ANCHORS = 50;

    private ?string $correlationId = null;
    private ?string $requestId = null;
    private ?string $messengerMessageId = null;
    private ?ActivityActorType $defaultActorType = null;
    /** Explicit cause of the Messenger message being handled, if its dispatcher knew one. */
    private ?int $messageCausationEventId = null;
    /** @var array<string, int> "absence:58" => activity_event id */
    private array $anchors = [];

    public function begin(ActivityActorType $defaultActorType, ?string $requestId = null, ?string $correlationId = null): void
    {
        $this->reset();
        $this->correlationId    = ($correlationId !== null && UuidV7::isValid($correlationId)) ? $correlationId : UuidV7::generate();
        $this->defaultActorType = $defaultActorType;
        $this->requestId        = $requestId;
    }

    /**
     * Lazily starts an implicit operation when nothing began one (e.g. a service invoked
     * directly from a test). The id then lives until the next reset().
     */
    public function correlationId(): string
    {
        return $this->correlationId ??= UuidV7::generate();
    }

    public function isActive(): bool
    {
        return $this->correlationId !== null;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function messengerMessageId(): ?string
    {
        return $this->messengerMessageId;
    }

    public function messageCausationEventId(): ?int
    {
        return $this->messageCausationEventId;
    }

    public function defaultActorType(): ActivityActorType
    {
        return $this->defaultActorType ?? ActivityActorType::SYSTEM;
    }

    public function registerAnchor(string $key, int $eventId): void
    {
        if (count($this->anchors) >= self::MAX_ANCHORS && !isset($this->anchors[$key])) {
            return;
        }
        $this->anchors[$key] = $eventId;
    }

    public function anchor(string $key): ?int
    {
        return $this->anchors[$key] ?? null;
    }

    /** @return array<string, int> */
    public function anchors(): array
    {
        return $this->anchors;
    }

    /**
     * Opaque snapshot, restored with restore() — used by the Messenger middleware to put
     * back exactly what was active before handling a message (a sync-transport message
     * is handled INSIDE the dispatching request's own operation).
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        return [
            'correlationId'      => $this->correlationId,
            'requestId'          => $this->requestId,
            'messengerMessageId' => $this->messengerMessageId,
            'defaultActorType'   => $this->defaultActorType,
            'anchors'            => $this->anchors,
            'messageCausation'   => $this->messageCausationEventId,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(array $snapshot): void
    {
        $this->correlationId      = $snapshot['correlationId'] ?? null;
        $this->requestId          = $snapshot['requestId'] ?? null;
        $this->messengerMessageId = $snapshot['messengerMessageId'] ?? null;
        $this->defaultActorType   = $snapshot['defaultActorType'] ?? null;
        $this->anchors            = $snapshot['anchors'] ?? [];
        $this->messageCausationEventId = $snapshot['messageCausation'] ?? null;
    }

    /**
     * Worker side: adopt the operation carried by a CorrelationStamp.
     *
     * @param array<string, int> $anchors
     */
    public function beginFromMessage(?string $correlationId, array $anchors, ?string $messengerMessageId, ?int $causationEventId = null): void
    {
        $this->begin(ActivityActorType::WORKER, null, $correlationId);
        $this->messengerMessageId      = $messengerMessageId;
        $this->messageCausationEventId = $causationEventId;
        foreach (array_slice($anchors, 0, self::MAX_ANCHORS, true) as $key => $id) {
            if (is_string($key) && is_int($id)) {
                $this->anchors[$key] = $id;
            }
        }
    }

    public function reset(): void
    {
        $this->correlationId      = null;
        $this->requestId          = null;
        $this->messengerMessageId = null;
        $this->defaultActorType   = null;
        $this->anchors            = [];
        $this->messageCausationEventId = null;
    }
}
