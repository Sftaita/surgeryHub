<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_push_subscription_endpoint', columns: ['endpoint'])], indexes: [new ORM\Index(name: 'idx_push_subscription_user', columns: ['user_id'])])]
#[ORM\UniqueConstraint(name: 'uniq_push_subscription_device_token', columns: ['device_token_hash'])]
#[ORM\HasLifecycleCallbacks]
class PushSubscription
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 500)]
    private ?string $endpoint = null;

    #[ORM\Column(length: 255)]
    private ?string $publicKey = null;

    #[ORM\Column(length: 255)]
    private ?string $authToken = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $contentEncoding = null;

    // D-175 — état de fiabilité de l'appareil. Colonnes techniques, jamais journalisées (seuls
    // l'utilisateur et le renouvellement de l'endpoint le sont, PushSubscriptionDescriber).

    /** Version du service worker déclarée à la dernière synchronisation (null : jamais synchronisé). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $swVersion = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /** Premier instant où le service worker a déclaré savoir envoyer des accusés : seuls les envois postérieurs comptent. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $receiptsCapableAt = null;

    /** Dernier envoi accepté par le fournisseur push (2xx). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAcceptedAt = null;

    /** Dernier accusé « reçu par le service worker ». */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastReceiptAt = null;

    /** Envois acceptés depuis le dernier accusé, comptés seulement après receiptsCapableAt. */
    #[ORM\Column(options: ['default' => 0])]
    private int $acceptedSinceReceipt = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstUnacknowledgedAt = null;

    /** Détection d'échec silencieux (journalisée PUSH_SUBSCRIPTION_SUSPECTED) ; effacée au premier accusé. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $suspectedAt = null;

    /** Dernière demande de renouvellement adressée au navigateur (anti-boucle). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $renewalRequestedAt = null;

    /**
     * SHA-256 du jeton d'appareil (D-175) : identifiant dédié qui permet au seul service worker,
     * sans session, de remplacer l'endpoint de CET appareil (pushsubscriptionchange). Jamais en clair.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceTokenHash = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    public function setPublicKey(string $publicKey): static
    {
        $this->publicKey = $publicKey;

        return $this;
    }

    public function getAuthToken(): ?string
    {
        return $this->authToken;
    }

    public function setAuthToken(string $authToken): static
    {
        $this->authToken = $authToken;

        return $this;
    }

    public function getContentEncoding(): ?string
    {
        return $this->contentEncoding;
    }

    public function setContentEncoding(?string $contentEncoding): static
    {
        $this->contentEncoding = $contentEncoding;

        return $this;
    }

    public function getSwVersion(): ?string { return $this->swVersion; }
    public function setSwVersion(?string $swVersion): static { $this->swVersion = $swVersion; return $this; }

    public function getLastSyncedAt(): ?\DateTimeImmutable { return $this->lastSyncedAt; }
    public function setLastSyncedAt(?\DateTimeImmutable $at): static { $this->lastSyncedAt = $at; return $this; }

    public function getReceiptsCapableAt(): ?\DateTimeImmutable { return $this->receiptsCapableAt; }
    public function setReceiptsCapableAt(?\DateTimeImmutable $at): static { $this->receiptsCapableAt = $at; return $this; }

    public function getLastAcceptedAt(): ?\DateTimeImmutable { return $this->lastAcceptedAt; }
    public function getLastReceiptAt(): ?\DateTimeImmutable { return $this->lastReceiptAt; }
    public function getAcceptedSinceReceipt(): int { return $this->acceptedSinceReceipt; }
    public function getFirstUnacknowledgedAt(): ?\DateTimeImmutable { return $this->firstUnacknowledgedAt; }

    public function getSuspectedAt(): ?\DateTimeImmutable { return $this->suspectedAt; }

    public function getRenewalRequestedAt(): ?\DateTimeImmutable { return $this->renewalRequestedAt; }
    public function setRenewalRequestedAt(?\DateTimeImmutable $at): static { $this->renewalRequestedAt = $at; return $this; }

    public function getDeviceTokenHash(): ?string { return $this->deviceTokenHash; }
    public function setDeviceTokenHash(?string $hash): static { $this->deviceTokenHash = $hash; return $this; }

    /** Motif du renouvellement en cours (RENEWED, REPLACED), lu par PushSubscriptionDescriber ; non persisté. */
    private ?string $renewalReason = null;

    public function getRenewalReason(): ?string { return $this->renewalReason; }
    public function setRenewalReason(?string $reason): static { $this->renewalReason = $reason; return $this; }

    /** Remet l'état de fiabilité à zéro : nouvel endpoint (renouvellement) = nouvel historique. */
    public function resetReliability(): static
    {
        $this->lastAcceptedAt = null;
        $this->lastReceiptAt = null;
        $this->acceptedSinceReceipt = 0;
        $this->firstUnacknowledgedAt = null;
        $this->suspectedAt = null;

        return $this;
    }
}
