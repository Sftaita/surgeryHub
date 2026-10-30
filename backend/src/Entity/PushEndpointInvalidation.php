<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * D-175 — mémoire des endpoints push que le serveur sait définitivement morts : le fournisseur a
 * répondu 404/410 (PROVIDER_EXPIRED), ou l'appareil a été renouvelé vers un nouvel endpoint
 * (RENEWED, REPLACED). Un navigateur qui renvoie un de ces endpoints n'est jamais réenregistré :
 * il reçoit une demande de renouvellement (renvoyer le même abonnement n'est pas une réparation).
 *
 * Seule l'empreinte SHA-256 de l'endpoint est conservée, jamais l'endpoint ni ses clés.
 */
#[ORM\Entity]
#[ORM\Table(name: 'push_endpoint_invalidation')]
#[ORM\UniqueConstraint(name: 'uniq_push_endpoint_invalidation_hash', columns: ['endpoint_hash'])]
class PushEndpointInvalidation
{
    public const REASON_PROVIDER_EXPIRED = 'PROVIDER_EXPIRED';
    public const REASON_RENEWED = 'RENEWED';
    public const REASON_REPLACED = 'REPLACED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $endpointHash;

    /** Dernier propriétaire connu (aucune clé étrangère : la trace survit à la suppression du compte). */
    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    /** Ligne push_subscription d'origine, à titre de corrélation (pas de clé étrangère). */
    #[ORM\Column(nullable: true)]
    private ?int $pushSubscriptionId = null;

    #[ORM\Column(length: 32)]
    private string $reason;

    #[ORM\Column(nullable: true)]
    private ?int $statusCode = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $invalidatedAt;

    /** Dernière demande de renouvellement servie pour cet endpoint (anti-boucle). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $renewalRequestedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $renewalRequestCount = 0;

    public function __construct(string $endpointHash, string $reason, \DateTimeImmutable $invalidatedAt)
    {
        $this->endpointHash = $endpointHash;
        $this->reason = $reason;
        $this->invalidatedAt = $invalidatedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getEndpointHash(): string { return $this->endpointHash; }

    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $userId): static { $this->userId = $userId; return $this; }

    public function getPushSubscriptionId(): ?int { return $this->pushSubscriptionId; }
    public function setPushSubscriptionId(?int $id): static { $this->pushSubscriptionId = $id; return $this; }

    public function getReason(): string { return $this->reason; }
    public function getStatusCode(): ?int { return $this->statusCode; }
    public function setStatusCode(?int $statusCode): static { $this->statusCode = $statusCode; return $this; }

    public function getProvider(): ?string { return $this->provider; }
    public function setProvider(?string $provider): static { $this->provider = $provider; return $this; }

    public function getInvalidatedAt(): \DateTimeImmutable { return $this->invalidatedAt; }

    public function getRenewalRequestedAt(): ?\DateTimeImmutable { return $this->renewalRequestedAt; }
    public function getRenewalRequestCount(): int { return $this->renewalRequestCount; }

    public function markRenewalRequested(\DateTimeImmutable $at): static
    {
        $this->renewalRequestedAt = $at;
        ++$this->renewalRequestCount;

        return $this;
    }
}
