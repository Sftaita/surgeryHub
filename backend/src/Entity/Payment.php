<?php

namespace App\Entity;

use App\Enum\PaymentDocumentType;
use App\Enum\PaymentMethod;
use Doctrine\ORM\Mapping as ORM;

/**
 * EPIC Exécution & Valorisation, Lot 5 (D-075) — un événement de paiement, append-only
 * (jamais modifié ni supprimé une fois créé — voir DocumentPaymentService, seul point
 * d'écriture). Ne modifie jamais FinancialCalculation/FinancialCalculationLine ni les
 * montants documentaires (FirmInvoice.totalAmount/InstrumentistStatement.totalAmount
 * restent le montant BRUT, gelé depuis la création du document) — le solde
 * (paidAmount/remainingAmount/PaymentStatus) est toujours dérivé en sommant les
 * Payment existants, jamais stocké.
 *
 * Table unique servant les deux types de document (§3/§4 du lot) — voir
 * PaymentDocumentType pour la raison de l'absence de FK Doctrine directe.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'payment',
    indexes: [
        new ORM\Index(name: 'idx_payment_document', columns: ['document_type', 'document_id']),
    ],
)]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'document_type', enumType: PaymentDocumentType::class, length: 30)]
    private ?PaymentDocumentType $documentType = null;

    #[ORM\Column(name: 'document_id')]
    private ?int $documentId = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(length: 3)]
    private ?string $currency = null;

    /**
     * Date réelle du paiement (relevé bancaire, remise d'espèces...) — toujours
     * date-only : aucune heure précise n'a de sens métier ici, donc aucun risque
     * d'offset à mal étiqueter (D-066 non applicable).
     */
    #[ORM\Column(name: 'paid_at', type: 'date_immutable')]
    private ?\DateTimeImmutable $paidAt = null;

    /** Horodatage serveur de la saisie — toujours new \DateTimeImmutable(), jamais un instant client. */
    #[ORM\Column(name: 'recorded_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $recordedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'recorded_by_id', nullable: false)]
    private ?User $recordedBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(enumType: PaymentMethod::class, length: 20)]
    private ?PaymentMethod $method = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDocumentType(): PaymentDocumentType { return $this->documentType; }
    public function setDocumentType(PaymentDocumentType $documentType): static { $this->documentType = $documentType; return $this; }

    public function getDocumentId(): int { return $this->documentId; }
    public function setDocumentId(int $documentId): static { $this->documentId = $documentId; return $this; }

    public function getAmount(): string { return $this->amount; }
    public function setAmount(string $amount): static { $this->amount = $amount; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtoupper($currency); return $this; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }

    public function getRecordedAt(): ?\DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }

    public function getRecordedBy(): ?User { return $this->recordedBy; }
    public function setRecordedBy(User $recordedBy): static { $this->recordedBy = $recordedBy; return $this; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(?string $reference): static { $this->reference = $reference; return $this; }

    public function getMethod(): PaymentMethod { return $this->method; }
    public function setMethod(PaymentMethod $method): static { $this->method = $method; return $this; }

    public function getComment(): ?string { return $this->comment; }
    public function setComment(?string $comment): static { $this->comment = $comment; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
