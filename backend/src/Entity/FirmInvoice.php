<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\FinancialDocumentType;
use App\Enum\InvoiceStatus;
use App\Enum\PaymentDocumentType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_firm_invoice_firm', columns: ['firm_id']),
    new ORM\Index(name: 'idx_firm_invoice_status', columns: ['status']),
    // Lot 6 (D-076) — présents en base depuis Version20260718191717 mais jamais
    // reportés ici (drift pré-existant, hors périmètre du Lot 7) :
    // idx_firm_invoice_corrects (corrects_document_id), idx_firm_invoice_doc_type_status
    // (document_type, status).
    // EPIC Pilotage financier, Lot 7 (D-077) — valeur documentée par période d'émission,
    // filtrée par statut/type/devise (overview/timeseries/by-firm/pipeline).
    new ORM\Index(name: 'idx_firm_invoice_sent_status_type_currency', columns: ['sent_at', 'status', 'document_type', 'currency']),
])]
#[ORM\HasLifecycleCallbacks]
class FirmInvoice implements PayableDocument
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $number = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Firm $firm = null;

    #[ORM\Column(enumType: InvoiceStatus::class, length: 20)]
    private InvoiceStatus $status = InvoiceStatus::DRAFT;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $periodEnd = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $totalAmount = '0.00';

    /**
     * EPIC Exécution & Valorisation, Lot 4 (D-074) — §23 du lot : 1 document = 1 devise,
     * jamais d'agrégation entre devises différentes. 'EUR' par défaut (backfill des
     * documents existants — seule devise utilisée dans ce projet jusqu'ici).
     */
    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    /**
     * §18 du lot — true pour tout document créé avant ce lot ou via le chemin
     * FirmInvoiceService::generate() legacy (calcule lui-même les montants) ; false pour
     * un document créé via createFromEligibleLines() (lignes issues de
     * FinancialCalculationLine). Jamais mélangé au sein d'un même document.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $legacySource = true;

    /**
     * EPIC Exécution & Valorisation, Lot 6 (D-076) — §3 du lot : extension du modèle
     * existant plutôt qu'un agrégat Settlement générique. STANDARD pour tout document
     * racine (legacy et nouveau flux, backfillé par la migration) ; CREDIT_NOTE/
     * DEBIT_NOTE pour un document correctif — voir $correctsDocument.
     */
    #[ORM\Column(name: 'document_type', enumType: FinancialDocumentType::class, length: 20, options: ['default' => 'STANDARD'])]
    private FinancialDocumentType $documentType = FinancialDocumentType::STANDARD;

    /**
     * Renseigné uniquement pour CREDIT_NOTE/DEBIT_NOTE — pointe TOUJOURS vers le
     * document STANDARD racine (§6 du lot : "recommandation initiale : correction
     * toujours rattachée au document STANDARD racine", jamais une correction de
     * correction, simplifie l'audit et le calcul net). NULL pour un document STANDARD.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'corrects_document_id', nullable: true)]
    private ?self $correctsDocument = null;

    /** Email snapshot — adresse principale au moment de l'envoi */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $billingEmailTo = null;

    /** CC snapshot (JSON array) */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $billingEmailCc = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $generatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /** @var Collection<int, FirmInvoiceLine> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: FirmInvoiceLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getNumber(): ?string { return $this->number; }
    public function setNumber(?string $number): static { $this->number = $number; return $this; }

    public function getFirm(): ?Firm { return $this->firm; }
    public function setFirm(?Firm $firm): static { $this->firm = $firm; return $this; }

    public function getStatus(): InvoiceStatus { return $this->status; }
    public function setStatus(InvoiceStatus $status): static { $this->status = $status; return $this; }

    public function getPeriodStart(): ?\DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $periodStart): static { $this->periodStart = $periodStart; return $this; }

    public function getPeriodEnd(): ?\DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $periodEnd): static { $this->periodEnd = $periodEnd; return $this; }

    public function getTotalAmount(): string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtoupper($currency); return $this; }

    public function isLegacySource(): bool { return $this->legacySource; }
    public function setLegacySource(bool $legacySource): static { $this->legacySource = $legacySource; return $this; }

    public function getDocumentType(): FinancialDocumentType { return $this->documentType; }
    public function setDocumentType(FinancialDocumentType $documentType): static { $this->documentType = $documentType; return $this; }

    public function getCorrectsDocument(): ?self { return $this->correctsDocument; }
    public function setCorrectsDocument(?self $correctsDocument): static { $this->correctsDocument = $correctsDocument; return $this; }

    public function getBillingEmailTo(): ?string { return $this->billingEmailTo; }
    public function setBillingEmailTo(?string $billingEmailTo): static { $this->billingEmailTo = $billingEmailTo; return $this; }

    public function getBillingEmailCc(): ?array { return $this->billingEmailCc; }
    public function setBillingEmailCc(?array $billingEmailCc): static { $this->billingEmailCc = $billingEmailCc; return $this; }

    public function getGeneratedAt(): ?\DateTimeImmutable { return $this->generatedAt; }
    public function setGeneratedAt(?\DateTimeImmutable $generatedAt): static { $this->generatedAt = $generatedAt; return $this; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): static { $this->sentAt = $sentAt; return $this; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }

    /** @return Collection<int, FirmInvoiceLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(FirmInvoiceLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setInvoice($this);
        }
        return $this;
    }

    public function isLocked(): bool
    {
        return $this->status !== InvoiceStatus::DRAFT;
    }

    public function getPaymentDocumentType(): PaymentDocumentType
    {
        return PaymentDocumentType::FIRM_INVOICE;
    }
}
