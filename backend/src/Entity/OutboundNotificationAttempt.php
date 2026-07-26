<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * D-084 — une ligne par tentative réelle de transport (une par subscription Push pour un
 * envoi donné, une par essai/retry Messenger pour un email). Append-only : jamais modifié
 * après création, jamais supprimé indépendamment de son OutboundNotification parent.
 *
 * N'écrit jamais un endpoint Push complet — `endpointHost` seul (ex: "fcm.googleapis.com"),
 * jamais p256dh/auth/JWT. `reason` est une raison normalisée, jamais un message
 * d'exception brut.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_outbound_notification_attempt_notification', columns: ['notification_id']),
])]
class OutboundNotificationAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'attempts')]
    #[ORM\JoinColumn(name: 'notification_id', nullable: false, onDelete: 'CASCADE')]
    private ?OutboundNotification $notification = null;

    #[ORM\Column]
    private int $attemptNumber = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private bool $success = false;

    #[ORM\Column(nullable: true)]
    private ?int $statusCode = null;

    /** Raison normalisée (ex: "BadJwtToken", "expired", "no_subscription") — jamais un message d'exception brut. */
    #[ORM\Column(nullable: true)]
    private ?string $reason = null;

    /** ex: "FCM", "APPLE", "OTHER" (Push) ou "SMTP" (email) — jamais dérivé d'un endpoint complet. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function initializeCreatedAt(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->startedAt ??= $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }

    public function getNotification(): ?OutboundNotification { return $this->notification; }
    public function setNotification(?OutboundNotification $notification): static { $this->notification = $notification; return $this; }

    public function getAttemptNumber(): int { return $this->attemptNumber; }
    public function setAttemptNumber(int $attemptNumber): static { $this->attemptNumber = $attemptNumber; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static { $this->finishedAt = $finishedAt; return $this; }

    public function isSuccess(): bool { return $this->success; }
    public function setSuccess(bool $success): static { $this->success = $success; return $this; }

    public function getStatusCode(): ?int { return $this->statusCode; }
    public function setStatusCode(?int $statusCode): static { $this->statusCode = $statusCode; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $this->reason = $reason; return $this; }

    public function getProvider(): ?string { return $this->provider; }
    public function setProvider(?string $provider): static { $this->provider = $provider; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
