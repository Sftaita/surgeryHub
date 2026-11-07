<?php

namespace App\Entity;

use App\Enum\NotificationDeliverySkipReason;
use App\Enum\OutboundNotificationChannel;
use App\Enum\OutboundNotificationFallbackReason;
use App\Enum\OutboundNotificationStatus;
use App\Repository\OutboundNotificationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * D-084 — trace persistante d'UNE communication sortante (Push OU email, jamais les
 * deux sur la même ligne — un repli email crée une seconde ligne distincte, liée via
 * fallbackOf). Le contenu (title/subject/bodyText/bodyHtml/payload) est un snapshot :
 * il reste lisible même si le template source change plus tard. `payload` est toujours
 * nettoyé avant persistance (liste blanche, jamais un objet arbitraire — voir
 * OutboundNotificationService::cleanPayload()).
 *
 * N'écrit jamais : endpoint Push complet, p256dh, auth, JWT, clé VAPID, secret SMTP,
 * mot de passe, donnée patient. `failureMessage`/`failureCode` sont des raisons
 * normalisées, jamais un message d'exception brut susceptible de contenir un secret.
 */
#[ORM\Entity(repositoryClass: OutboundNotificationRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_outbound_notification_created_at', columns: ['created_at']),
    new ORM\Index(name: 'idx_outbound_notification_recipient', columns: ['recipient_user_id']),
    new ORM\Index(name: 'idx_outbound_notification_channel', columns: ['channel']),
    new ORM\Index(name: 'idx_outbound_notification_status', columns: ['status']),
    new ORM\Index(name: 'idx_outbound_notification_type', columns: ['notification_type']),
    new ORM\Index(name: 'idx_outbound_notification_mission', columns: ['mission_id']),
])]
#[ORM\Index(name: 'idx_outbound_notification_correlation', columns: ['correlation_id'])]
#[ORM\UniqueConstraint(name: 'uniq_outbound_notification_group_key', columns: ['group_key'])]
class OutboundNotification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    /**
     * H5b (D-132) — nullable: the common delivery layer also carries emails to addresses that
     * are not SurgicalHub users (firm billing address, D-114 block management): no fake User,
     * the address in recipientAddress and a readable recipientLabel instead.
     */
    #[ORM\JoinColumn(name: 'recipient_user_id', nullable: true)]
    private ?User $recipientUser = null;

    /** H5b (D-132) — who the delivery is for when it is not a user ("Firme X — facturation", "Gestion du bloc — CHU"). */
    #[ORM\Column(name: 'recipient_label', length: 120, nullable: true)]
    private ?string $recipientLabel = null;

    #[ORM\Column(enumType: OutboundNotificationChannel::class)]
    private ?OutboundNotificationChannel $channel = null;

    /** Identifiant métier libre, ex: "ENCODING_REMINDER_D1" — même convention que NotificationEvent::eventType. */
    #[ORM\Column(length: 100)]
    private ?string $notificationType = null;

    #[ORM\Column(enumType: OutboundNotificationStatus::class)]
    private OutboundNotificationStatus $status = OutboundNotificationStatus::QUEUED;

    #[ORM\Column(nullable: true)]
    private ?string $title = null;

    #[ORM\Column(nullable: true)]
    private ?string $subject = null;

    /**
     * H5a — the address the email was really handed to (EMAIL rows only). Differs from the
     * recipient's current address when it was explicit (email change: previous address) or
     * when the address changed since. Null for in-app / push and for pre-H5a rows.
     */
    #[ORM\Column(name: 'recipient_address', length: 180, nullable: true)]
    private ?string $recipientAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyText = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyHtml = null;

    /** Toujours nettoyé (liste blanche) avant d'arriver ici — jamais un payload arbitraire. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'mission_id', nullable: true, onDelete: 'SET NULL')]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'planning_version_id', nullable: true, onDelete: 'SET NULL')]
    private ?PlanningVersion $planningVersion = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $queuedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $failedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $failureCode = null;

    /** Raison normalisée uniquement — jamais un message d'exception brut. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureMessage = null;

    #[ORM\Column(nullable: true)]
    private ?string $providerMessageId = null;

    #[ORM\Column]
    private int $attemptCount = 0;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'fallback_of_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $fallbackOf = null;

    #[ORM\Column(enumType: OutboundNotificationFallbackReason::class, nullable: true)]
    private ?OutboundNotificationFallbackReason $fallbackReason = null;

    /** H2 (D-128) — why this channel was deliberately not used (status SKIPPED only). */
    #[ORM\Column(name: 'skip_reason', length: 32, nullable: true, enumType: NotificationDeliverySkipReason::class)]
    private ?NotificationDeliverySkipReason $skipReason = null;

    /** H2 (D-128) — correlation of the operation that produced this delivery (activity_event). */
    #[ORM\Column(name: 'correlation_id', length: 36, nullable: true, options: ['fixed' => true])]
    private ?string $correlationId = null;

    /**
     * H2 (D-128) — set only for a grouped delivery (NotificationDispatchMode::BATCH): one
     * email / push per (type, recipient, channel, correlation), shared by every dispatch of
     * the operation. UNIQUE: a Messenger retry finds it instead of creating a second email.
     */
    #[ORM\Column(name: 'group_key', length: 191, nullable: true)]
    private ?string $groupKey = null;

    /** H2 (D-128) — the in-app NotificationEvent this IN_APP delivery created. */
    #[ORM\ManyToOne(targetEntity: NotificationEvent::class)]
    #[ORM\JoinColumn(name: 'notification_event_id', nullable: true, onDelete: 'SET NULL')]
    private ?NotificationEvent $notificationEvent = null;

    /** @var Collection<int, OutboundNotificationAttempt> */
    #[ORM\OneToMany(targetEntity: OutboundNotificationAttempt::class, mappedBy: 'notification', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['attemptNumber' => 'ASC'])]
    private Collection $attempts;

    public function __construct()
    {
        $this->attempts = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initializeCreatedAt(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getRecipientUser(): ?User { return $this->recipientUser; }
    public function setRecipientUser(?User $recipientUser): static { $this->recipientUser = $recipientUser; return $this; }
    public function getRecipientLabel(): ?string { return $this->recipientLabel; }
    public function setRecipientLabel(?string $label): static { $this->recipientLabel = $label !== null ? mb_substr($label, 0, 120) : null; return $this; }

    public function getChannel(): ?OutboundNotificationChannel { return $this->channel; }
    public function setChannel(OutboundNotificationChannel $channel): static { $this->channel = $channel; return $this; }

    public function getNotificationType(): ?string { return $this->notificationType; }
    public function setNotificationType(string $notificationType): static { $this->notificationType = $notificationType; return $this; }

    public function getStatus(): OutboundNotificationStatus { return $this->status; }
    public function setStatus(OutboundNotificationStatus $status): static { $this->status = $status; return $this; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = $title; return $this; }

    public function getRecipientAddress(): ?string { return $this->recipientAddress; }
    public function setRecipientAddress(?string $address): static { $this->recipientAddress = $address !== null ? mb_substr($address, 0, 180) : null; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $subject): static { $this->subject = $subject; return $this; }

    public function getBodyText(): ?string { return $this->bodyText; }
    public function setBodyText(?string $bodyText): static { $this->bodyText = $bodyText; return $this; }

    public function getBodyHtml(): ?string { return $this->bodyHtml; }
    public function setBodyHtml(?string $bodyHtml): static { $this->bodyHtml = $bodyHtml; return $this; }

    public function getPayload(): ?array { return $this->payload; }
    public function setPayload(?array $payload): static { $this->payload = $payload; return $this; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(?Mission $mission): static { $this->mission = $mission; return $this; }

    public function getPlanningVersion(): ?PlanningVersion { return $this->planningVersion; }
    public function setPlanningVersion(?PlanningVersion $planningVersion): static { $this->planningVersion = $planningVersion; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    public function getQueuedAt(): ?\DateTimeImmutable { return $this->queuedAt; }
    public function setQueuedAt(?\DateTimeImmutable $queuedAt): static { $this->queuedAt = $queuedAt; return $this; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): static { $this->sentAt = $sentAt; return $this; }

    public function getFailedAt(): ?\DateTimeImmutable { return $this->failedAt; }
    public function setFailedAt(?\DateTimeImmutable $failedAt): static { $this->failedAt = $failedAt; return $this; }

    public function getFailureCode(): ?string { return $this->failureCode; }
    public function setFailureCode(?string $failureCode): static { $this->failureCode = $failureCode; return $this; }

    public function getFailureMessage(): ?string { return $this->failureMessage; }
    public function setFailureMessage(?string $failureMessage): static { $this->failureMessage = $failureMessage; return $this; }

    public function getProviderMessageId(): ?string { return $this->providerMessageId; }
    public function setProviderMessageId(?string $providerMessageId): static { $this->providerMessageId = $providerMessageId; return $this; }

    public function getAttemptCount(): int { return $this->attemptCount; }
    public function setAttemptCount(int $attemptCount): static { $this->attemptCount = $attemptCount; return $this; }
    public function incrementAttemptCount(): static { $this->attemptCount++; return $this; }

    public function getFallbackOf(): ?self { return $this->fallbackOf; }
    public function setFallbackOf(?self $fallbackOf): static { $this->fallbackOf = $fallbackOf; return $this; }

    public function getFallbackReason(): ?OutboundNotificationFallbackReason { return $this->fallbackReason; }
    public function setFallbackReason(?OutboundNotificationFallbackReason $fallbackReason): static { $this->fallbackReason = $fallbackReason; return $this; }

    public function isFallback(): bool { return $this->fallbackOf !== null; }

    public function getSkipReason(): ?NotificationDeliverySkipReason { return $this->skipReason; }
    public function setSkipReason(?NotificationDeliverySkipReason $skipReason): static { $this->skipReason = $skipReason; return $this; }

    public function getCorrelationId(): ?string { return $this->correlationId; }
    public function setCorrelationId(?string $correlationId): static { $this->correlationId = $correlationId; return $this; }

    public function getGroupKey(): ?string { return $this->groupKey; }
    public function setGroupKey(?string $groupKey): static { $this->groupKey = $groupKey; return $this; }

    public function getNotificationEvent(): ?NotificationEvent { return $this->notificationEvent; }
    public function setNotificationEvent(?NotificationEvent $notificationEvent): static { $this->notificationEvent = $notificationEvent; return $this; }

    /** @return Collection<int, OutboundNotificationAttempt> */
    public function getAttempts(): Collection { return $this->attempts; }

    public function addAttempt(OutboundNotificationAttempt $attempt): static
    {
        if (!$this->attempts->contains($attempt)) {
            $this->attempts->add($attempt);
            $attempt->setNotification($this);
        }

        return $this;
    }
}
