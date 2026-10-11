<?php

namespace App\Entity;

use App\Enum\NotificationDispatchMode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * H2 (D-128) — the DECISION to notify one person about one subject: who, which type,
 * about what, in which operation (correlationId), and which channels the
 * NotificationPreferenceResolver granted at that moment (and whether that came from an
 * explicit preference or the type's default).
 *
 * The three levels of a notification are kept separate:
 *   NotificationDispatch     — intention / decision (this entity), one per recipient and subject;
 *   OutboundNotification     — one delivery per channel (IN_APP / EMAIL / PUSH), with its own
 *                              honest status (QUEUED / SENT / FAILED / SKIPPED + reason);
 *   NotificationEvent        — the in-app feed item itself (seen / not seen).
 *
 * N:N with OutboundNotification (notification_dispatch_delivery): in BATCH mode several
 * dispatches of one operation share ONE grouped email — each mission keeps its own
 * dispatch (traceability per subject) while the recipient gets a single email.
 *
 * idempotencyKey (UNIQUE) = type + recipient + subject + episode: a Messenger retry of the
 * same event finds the existing dispatch and only completes the channels still missing.
 *
 * Recipient and mission references are SET NULL on delete, with a name snapshot — the
 * decision record outlives the objects it was about.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_dispatch')]
#[ORM\Index(name: 'idx_notification_dispatch_recipient', columns: ['recipient_user_id', 'created_at'])]
#[ORM\Index(name: 'idx_notification_dispatch_mission', columns: ['mission_id'])]
#[ORM\Index(name: 'idx_notification_dispatch_correlation', columns: ['correlation_id'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_dispatch_idempotency', columns: ['idempotency_key'])]
class NotificationDispatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'recipient_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $recipient = null;

    #[ORM\Column(name: 'recipient_name_snapshot', length: 255, nullable: true)]
    private ?string $recipientNameSnapshot = null;

    #[ORM\Column(name: 'notification_type', length: 100)]
    private string $notificationType;

    #[ORM\ManyToOne(targetEntity: Mission::class)]
    #[ORM\JoinColumn(name: 'mission_id', nullable: true, onDelete: 'SET NULL')]
    private ?Mission $mission = null;

    #[ORM\Column(name: 'subject_type', length: 48, nullable: true)]
    private ?string $subjectType = null;

    #[ORM\Column(name: 'subject_id', nullable: true)]
    private ?int $subjectId = null;

    /** Business episode this decision belongs to (e.g. one lifecycle transition). */
    #[ORM\Column(name: 'episode_ref', length: 150, nullable: true)]
    private ?string $episodeRef = null;

    #[ORM\Column(name: 'correlation_id', length: 36, options: ['fixed' => true])]
    private string $correlationId;

    #[ORM\Column(length: 16, enumType: NotificationDispatchMode::class)]
    private NotificationDispatchMode $mode = NotificationDispatchMode::ISOLATED;

    /** @var array{inApp: bool, email: bool, push: bool, source: string} */
    #[ORM\Column(name: 'channels_resolved', type: Types::JSON)]
    private array $channelsResolved = [];

    /** Operational facts only, allow-listed by the caller's NotificationIntent — no patient data. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    /**
     * H4 (D-130) — MissionCommunicationSpec::toArray(): the missions this notification
     * presents to its recipient as hers, once a channel actually reaches her.
     *
     * @var array{source: string, role: string, missionIds: list<int>}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $communicates = null;

    #[ORM\Column(name: 'idempotency_key', length: 191)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, OutboundNotification> */
    #[ORM\ManyToMany(targetEntity: OutboundNotification::class)]
    #[ORM\JoinTable(name: 'notification_dispatch_delivery')]
    #[ORM\JoinColumn(name: 'dispatch_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'outbound_notification_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $deliveries;

    public function __construct()
    {
        $this->deliveries = new ArrayCollection();
        $this->createdAt  = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRecipient(): ?User { return $this->recipient; }
    public function setRecipient(?User $recipient): static { $this->recipient = $recipient; return $this; }

    public function getRecipientNameSnapshot(): ?string { return $this->recipientNameSnapshot; }
    public function setRecipientNameSnapshot(?string $name): static { $this->recipientNameSnapshot = $name; return $this; }

    public function getNotificationType(): string { return $this->notificationType; }
    public function setNotificationType(string $type): static { $this->notificationType = $type; return $this; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(?Mission $mission): static { $this->mission = $mission; return $this; }

    public function getSubjectType(): ?string { return $this->subjectType; }
    public function getSubjectId(): ?int { return $this->subjectId; }
    public function setSubject(?string $type, ?int $id): static { $this->subjectType = $type; $this->subjectId = $id; return $this; }

    public function getEpisodeRef(): ?string { return $this->episodeRef; }
    public function setEpisodeRef(?string $episodeRef): static { $this->episodeRef = $episodeRef; return $this; }

    public function getCorrelationId(): string { return $this->correlationId; }
    public function setCorrelationId(string $correlationId): static { $this->correlationId = $correlationId; return $this; }

    public function getMode(): NotificationDispatchMode { return $this->mode; }
    public function setMode(NotificationDispatchMode $mode): static { $this->mode = $mode; return $this; }

    /** @return array{inApp: bool, email: bool, push: bool, source: string} */
    public function getChannelsResolved(): array { return $this->channelsResolved; }
    /** @param array{inApp: bool, email: bool, push: bool, source: string} $channels */
    public function setChannelsResolved(array $channels): static { $this->channelsResolved = $channels; return $this; }

    public function getPayload(): ?array { return $this->payload; }
    public function setPayload(?array $payload): static { $this->payload = $payload; return $this; }

    public function getCommunicates(): ?array { return $this->communicates; }
    public function setCommunicates(?array $communicates): static { $this->communicates = $communicates; return $this; }

    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function setIdempotencyKey(string $key): static { $this->idempotencyKey = $key; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, OutboundNotification> */
    public function getDeliveries(): Collection { return $this->deliveries; }

    public function addDelivery(OutboundNotification $delivery): static
    {
        if (!$this->deliveries->contains($delivery)) {
            $this->deliveries->add($delivery);
        }

        return $this;
    }
}
