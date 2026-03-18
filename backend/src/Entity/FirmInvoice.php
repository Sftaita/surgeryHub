<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\InvoiceStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_firm_invoice_firm', columns: ['firm_id']),
    new ORM\Index(name: 'idx_firm_invoice_status', columns: ['status']),
])]
#[ORM\HasLifecycleCallbacks]
class FirmInvoice
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
}
