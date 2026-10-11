<?php

namespace App\Activity;

use App\Entity\User;
use App\Enum\ActivityActorType;
use App\Enum\ActivityDomain;
use App\Enum\ActivityOutcome;

/**
 * H1 (D-126) — one event to write, as built by a domain recorder. Plain data: the
 * ActivityJournal validates it, filters the payload, snapshots the actor and resolves
 * correlation/causation; it never interprets business meaning.
 *
 * $eventType: an ActivityEventType, AuditEventType or UserAuditEventType case (see
 * ActivityEventTypeRegistry). $domain is required for mirrored types (an
 * ActivityEventType knows its own).
 *
 * Causality: $causationEventId when the caller knows the cause explicitly; otherwise the
 * first of $causeAnchors found in the current CorrelationContext (e.g. "absence:58");
 * otherwise the explicit cause carried by the Messenger message being handled; otherwise
 * null — never "the previous event". $anchorKey registers THIS event as the anchor of a
 * business object for later events of the same operation.
 */
final class ActivityRecord
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $causeAnchors
     */
    public function __construct(
        public readonly \BackedEnum $eventType,
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?string $subjectLabel,
        public readonly string $summary,
        public readonly array $payload = [],
        public readonly ?User $actor = null,
        public readonly ?ActivityActorType $actorType = null,
        public readonly ?ActivityDomain $domain = null,
        public readonly ?int $siteId = null,
        public readonly ?int $planningVersionId = null,
        public readonly ?int $missionId = null,
        public readonly ?int $absenceId = null,
        public readonly ?int $userId = null,
        public readonly ActivityOutcome $outcome = ActivityOutcome::SUCCESS,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $sourceRef = null,
        public readonly ?int $causationEventId = null,
        public readonly array $causeAnchors = [],
        public readonly ?string $anchorKey = null,
        public readonly ?\DateTimeImmutable $occurredAt = null,
    ) {
    }
}
