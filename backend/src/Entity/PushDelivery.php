<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * D-175 — suivi d'UN envoi push vers UN appareil : accepté par le fournisseur, puis les accusés
 * du service worker (reçu, affichage demandé, affichage en échec, ouvert). Chaque accusé n'est
 * écrit qu'une fois (premier arrivé) : les accusés sont idempotents.
 *
 * « Affichage demandé » = showNotification() a résolu côté navigateur. Ce n'est PAS la preuve
 * qu'Android l'a réellement montré (canal coupé, Ne pas déranger, économiseur…).
 *
 * L'accusé est authentifié par une clé aléatoire propre à cet envoi, transportée uniquement dans
 * le contenu chiffré du push : seule son empreinte SHA-256 est stockée. Aucune donnée patient,
 * aucun endpoint, aucune clé d'abonnement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'push_delivery')]
#[ORM\UniqueConstraint(name: 'uniq_push_delivery_receipt', columns: ['receipt_key_hash'])]
#[ORM\Index(name: 'idx_push_delivery_subscription', columns: ['push_subscription_id'])]
#[ORM\Index(name: 'idx_push_delivery_notification', columns: ['outbound_notification_id'])]
#[ORM\Index(name: 'idx_push_delivery_accepted', columns: ['accepted_at'])]
class PushDelivery
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Rattachée après coup (l'OutboundNotification est écrite après l'envoi) ; null pour un envoi non journalisé. */
    #[ORM\Column(nullable: true)]
    private ?int $outboundNotificationId = null;

    /** Pas de clé étrangère : l'historique survit au désabonnement. */
    #[ORM\Column(nullable: true)]
    private ?int $pushSubscriptionId = null;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 20)]
    private string $provider;

    #[ORM\Column(length: 64)]
    private string $receiptKeyHash;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $notificationType = null;

    #[ORM\Column(nullable: true)]
    private ?int $statusCode = null;

    /** Envoi effectué alors que le service worker savait accuser réception : seul cas pris en compte pour la suspicion. */
    #[ORM\Column(options: ['default' => false])]
    private bool $receiptsExpected = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $acceptedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $displayRequestedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $displayFailedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    public function __construct(string $provider, string $receiptKeyHash, \DateTimeImmutable $acceptedAt)
    {
        $this->provider = $provider;
        $this->receiptKeyHash = $receiptKeyHash;
        $this->acceptedAt = $acceptedAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getOutboundNotificationId(): ?int { return $this->outboundNotificationId; }
    public function getPushSubscriptionId(): ?int { return $this->pushSubscriptionId; }
    public function getUserId(): ?int { return $this->userId; }
    public function getProvider(): string { return $this->provider; }
    public function getReceiptKeyHash(): string { return $this->receiptKeyHash; }
    public function getNotificationType(): ?string { return $this->notificationType; }
    public function getStatusCode(): ?int { return $this->statusCode; }
    public function isReceiptsExpected(): bool { return $this->receiptsExpected; }
    public function getAcceptedAt(): \DateTimeImmutable { return $this->acceptedAt; }
    public function getReceivedAt(): ?\DateTimeImmutable { return $this->receivedAt; }
    public function getDisplayRequestedAt(): ?\DateTimeImmutable { return $this->displayRequestedAt; }
    public function getDisplayFailedAt(): ?\DateTimeImmutable { return $this->displayFailedAt; }
    public function getOpenedAt(): ?\DateTimeImmutable { return $this->openedAt; }
}
