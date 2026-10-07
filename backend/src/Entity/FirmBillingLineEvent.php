<?php

namespace App\Entity;

use App\Enum\FirmBillingLineEventType;
use Doctrine\ORM\Mapping as ORM;

/**
 * D-134 — journal APPEND-ONLY du parcours documentaire d'une ligne de facturation firme
 * (une intervention ou un matériel). Source de l'historique affiché dans la worklist,
 * indépendante des FirmInvoiceLine (qui disparaissent à l'annulation d'une facture
 * GENERATED) : une ligne « libre » garde ainsi tout son passé.
 *
 * Immuable : aucun setter, colonnes scalaires sans clé étrangère (la suppression d'une
 * facture, d'un calcul ou d'un utilisateur ne peut ni effacer ni modifier un événement),
 * snapshots des valeurs utiles au moment du fait. Toute tentative de mise à jour ou de
 * suppression via l'ORM lève une exception (PreUpdate / PreRemove).
 */
#[ORM\Entity]
#[ORM\Table(name: 'firm_billing_line_event', indexes: [
    new ORM\Index(name: 'idx_fble_source', columns: ['source_type', 'source_id', 'occurred_at']),
    new ORM\Index(name: 'idx_fble_invoice', columns: ['invoice_id']),
])]
#[ORM\HasLifecycleCallbacks]
class FirmBillingLineEvent
{
    public const SOURCE_INTERVENTION = 'INTERVENTION';
    public const SOURCE_MATERIAL = 'MATERIAL';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $sourceType;

    #[ORM\Column]
    private int $sourceId;

    #[ORM\Column(nullable: true)]
    private ?int $financialCalculationLineId;

    #[ORM\Column]
    private int $missionId;

    #[ORM\Column(nullable: true)]
    private ?int $firmId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $firmNameSnapshot;

    #[ORM\Column(nullable: true)]
    private ?int $invoiceId;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $invoiceNumberSnapshot;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $invoiceStatusSnapshot;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $amountSnapshot;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currencySnapshot;

    #[ORM\Column(length: 40, enumType: FirmBillingLineEventType::class)]
    private FirmBillingLineEventType $eventType;

    #[ORM\Column(nullable: true)]
    private ?int $actorId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actorNameSnapshot;

    /** Instant serveur (new \DateTimeImmutable()), jamais une saisie client. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** Détails complémentaires (ex. montant d'un paiement, brouillon d'origine d'un déplacement). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $details;

    public function __construct(
        string $sourceType,
        int $sourceId,
        int $missionId,
        FirmBillingLineEventType $eventType,
        ?int $financialCalculationLineId = null,
        ?int $firmId = null,
        ?string $firmNameSnapshot = null,
        ?int $invoiceId = null,
        ?string $invoiceNumberSnapshot = null,
        ?string $invoiceStatusSnapshot = null,
        ?string $amountSnapshot = null,
        ?string $currencySnapshot = null,
        ?int $actorId = null,
        ?string $actorNameSnapshot = null,
        ?array $details = null,
        ?\DateTimeImmutable $occurredAt = null,
    ) {
        if (!in_array($sourceType, [self::SOURCE_INTERVENTION, self::SOURCE_MATERIAL], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown firm billing line source type "%s".', $sourceType));
        }
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->missionId = $missionId;
        $this->eventType = $eventType;
        $this->financialCalculationLineId = $financialCalculationLineId;
        $this->firmId = $firmId;
        $this->firmNameSnapshot = $firmNameSnapshot;
        $this->invoiceId = $invoiceId;
        $this->invoiceNumberSnapshot = $invoiceNumberSnapshot;
        $this->invoiceStatusSnapshot = $invoiceStatusSnapshot;
        $this->amountSnapshot = $amountSnapshot;
        $this->currencySnapshot = $currencySnapshot;
        $this->actorId = $actorId;
        $this->actorNameSnapshot = $actorNameSnapshot;
        $this->details = $details;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function preventUpdate(): void
    {
        throw new \LogicException('FirmBillingLineEvent is append-only: an event can never be modified.');
    }

    #[ORM\PreRemove]
    public function preventRemove(): void
    {
        throw new \LogicException('FirmBillingLineEvent is append-only: an event can never be deleted.');
    }

    public function getId(): ?int { return $this->id; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceId(): int { return $this->sourceId; }
    public function getFinancialCalculationLineId(): ?int { return $this->financialCalculationLineId; }
    public function getMissionId(): int { return $this->missionId; }
    public function getFirmId(): ?int { return $this->firmId; }
    public function getFirmNameSnapshot(): ?string { return $this->firmNameSnapshot; }
    public function getInvoiceId(): ?int { return $this->invoiceId; }
    public function getInvoiceNumberSnapshot(): ?string { return $this->invoiceNumberSnapshot; }
    public function getInvoiceStatusSnapshot(): ?string { return $this->invoiceStatusSnapshot; }
    public function getAmountSnapshot(): ?string { return $this->amountSnapshot; }
    public function getCurrencySnapshot(): ?string { return $this->currencySnapshot; }
    public function getEventType(): FirmBillingLineEventType { return $this->eventType; }
    public function getActorId(): ?int { return $this->actorId; }
    public function getActorNameSnapshot(): ?string { return $this->actorNameSnapshot; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function getDetails(): ?array { return $this->details; }
}
