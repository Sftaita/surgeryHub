<?php

namespace App\Entity;

use App\Doctrine\Type\UtcDateTimeMicroImmutableType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * H1 (D-126) — one row of the transversal, append-only activity journal.
 *
 * A TRACEABILITY PROJECTION, NEVER A SOURCE OF TRUTH: no business decision may ever read
 * this table (Mission stays the source of truth of its state, AuditEvent the specialized
 * business proof, NotificationEvent the in-app feed, OutboundNotification the delivery).
 *
 * Written exclusively by App\Activity\ActivityJournal through a plain DBAL INSERT inside
 * the business transaction — this entity is a read model: no setters, no association.
 * Every business reference (site, version, mission, absence, user, actor) is a plain
 * integer with NO foreign key, plus a name/label snapshot, so that deleting or
 * deactivating the referenced object can never cascade into the history.
 *
 * Append-only is enforced technically, not by convention:
 *   - App\Activity\AppendOnly\ActivityEventOrmGuard refuses any ORM update/removal;
 *   - App\Activity\AppendOnly\AppendOnlyDriverMiddleware refuses any UPDATE / DELETE /
 *     TRUNCATE / REPLACE statement targeting activity_event at the DBAL level (DQL bulk
 *     statements and raw SQL included). DB triggers were not used: production runs with
 *     binary logging and an app user without SUPER, where CREATE TRIGGER is refused.
 */
#[ORM\Entity]
#[ORM\Table(name: 'activity_event')]
#[ORM\Index(name: 'idx_activity_occurred', columns: ['occurred_at', 'id'])]
#[ORM\Index(name: 'idx_activity_correlation', columns: ['correlation_id'])]
#[ORM\Index(name: 'idx_activity_mission', columns: ['mission_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_activity_user', columns: ['user_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_activity_absence', columns: ['absence_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_activity_planning_version', columns: ['planning_version_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_activity_domain', columns: ['domain', 'occurred_at'])]
#[ORM\Index(name: 'idx_activity_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_activity_actor', columns: ['actor_user_id', 'occurred_at'])]
#[ORM\UniqueConstraint(name: 'uniq_activity_idempotency', columns: ['idempotency_key'])]
class ActivityEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'event_type', length: 64)]
    private string $eventType;

    #[ORM\Column(length: 32)]
    private string $domain;

    #[ORM\Column(name: 'occurred_at', type: UtcDateTimeMicroImmutableType::NAME)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'actor_type', length: 16)]
    private string $actorType;

    #[ORM\Column(name: 'actor_user_id', nullable: true)]
    private ?int $actorUserId = null;

    #[ORM\Column(name: 'actor_name_snapshot', length: 255, nullable: true)]
    private ?string $actorNameSnapshot = null;

    #[ORM\Column(name: 'actor_role_snapshot', length: 32, nullable: true)]
    private ?string $actorRoleSnapshot = null;

    #[ORM\Column(name: 'subject_type', length: 48)]
    private string $subjectType;

    #[ORM\Column(name: 'subject_id', nullable: true)]
    private ?int $subjectId = null;

    #[ORM\Column(name: 'subject_label_snapshot', length: 255, nullable: true)]
    private ?string $subjectLabelSnapshot = null;

    #[ORM\Column(name: 'correlation_id', length: 36, options: ['fixed' => true])]
    private string $correlationId;

    #[ORM\Column(name: 'causation_event_id', nullable: true)]
    private ?int $causationEventId = null;

    #[ORM\Column(name: 'site_id', nullable: true)]
    private ?int $siteId = null;

    #[ORM\Column(name: 'planning_version_id', nullable: true)]
    private ?int $planningVersionId = null;

    #[ORM\Column(name: 'mission_id', nullable: true)]
    private ?int $missionId = null;

    #[ORM\Column(name: 'absence_id', nullable: true)]
    private ?int $absenceId = null;

    /** The person concerned by the event (not necessarily the actor). */
    #[ORM\Column(name: 'user_id', nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 16)]
    private string $outcome;

    #[ORM\Column(length: 500)]
    private string $summary;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(name: 'source_ref', length: 64, nullable: true)]
    private ?string $sourceRef = null;

    #[ORM\Column(name: 'idempotency_key', length: 191, nullable: true)]
    private ?string $idempotencyKey = null;

    #[ORM\Column(name: 'request_id', length: 64, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column(name: 'messenger_message_id', length: 64, nullable: true)]
    private ?string $messengerMessageId = null;

    private function __construct()
    {
    }

    public function getId(): ?int { return $this->id; }
    public function getEventType(): string { return $this->eventType; }
    public function getDomain(): string { return $this->domain; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getActorType(): string { return $this->actorType; }
    public function getActorUserId(): ?int { return $this->actorUserId; }
    public function getActorNameSnapshot(): ?string { return $this->actorNameSnapshot; }
    public function getActorRoleSnapshot(): ?string { return $this->actorRoleSnapshot; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): ?int { return $this->subjectId; }
    public function getSubjectLabelSnapshot(): ?string { return $this->subjectLabelSnapshot; }
    public function getCorrelationId(): string { return $this->correlationId; }
    public function getCausationEventId(): ?int { return $this->causationEventId; }
    public function getSiteId(): ?int { return $this->siteId; }
    public function getPlanningVersionId(): ?int { return $this->planningVersionId; }
    public function getMissionId(): ?int { return $this->missionId; }
    public function getAbsenceId(): ?int { return $this->absenceId; }
    public function getUserId(): ?int { return $this->userId; }
    public function getOutcome(): string { return $this->outcome; }
    public function getSummary(): string { return $this->summary; }
    public function getPayload(): ?array { return $this->payload; }
    public function getSourceRef(): ?string { return $this->sourceRef; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getMessengerMessageId(): ?string { return $this->messengerMessageId; }
}
