<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\InvoiceStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_stmt_instrumentist', columns: ['instrumentist_id']),
    new ORM\Index(name: 'idx_stmt_status', columns: ['status']),
])]
#[ORM\HasLifecycleCallbacks]
class InstrumentistStatement
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $instrumentist = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $periodYear = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $periodMonth = null;

    #[ORM\Column(enumType: InvoiceStatus::class, length: 20)]
    private InvoiceStatus $status = InvoiceStatus::GENERATED;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $totalAmount = '0.00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instrumentistNameSnapshot = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instrumentistEmailSnapshot = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /** @var Collection<int, InstrumentistStatementLine> */
    #[ORM\OneToMany(mappedBy: 'statement', targetEntity: InstrumentistStatementLine::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getInstrumentist(): ?User { return $this->instrumentist; }
    public function setInstrumentist(?User $instrumentist): static { $this->instrumentist = $instrumentist; return $this; }

    public function getPeriodYear(): ?int { return $this->periodYear; }
    public function setPeriodYear(int $periodYear): static { $this->periodYear = $periodYear; return $this; }

    public function getPeriodMonth(): ?int { return $this->periodMonth; }
    public function setPeriodMonth(int $periodMonth): static { $this->periodMonth = $periodMonth; return $this; }

    public function getStatus(): InvoiceStatus { return $this->status; }
    public function setStatus(InvoiceStatus $status): static { $this->status = $status; return $this; }

    public function getTotalAmount(): string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }

    public function getInstrumentistNameSnapshot(): ?string { return $this->instrumentistNameSnapshot; }
    public function setInstrumentistNameSnapshot(?string $name): static { $this->instrumentistNameSnapshot = $name; return $this; }

    public function getInstrumentistEmailSnapshot(): ?string { return $this->instrumentistEmailSnapshot; }
    public function setInstrumentistEmailSnapshot(?string $email): static { $this->instrumentistEmailSnapshot = $email; return $this; }

    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(?\DateTimeImmutable $sentAt): static { $this->sentAt = $sentAt; return $this; }

    public function getPaidAt(): ?\DateTimeImmutable { return $this->paidAt; }
    public function setPaidAt(?\DateTimeImmutable $paidAt): static { $this->paidAt = $paidAt; return $this; }

    /** @return Collection<int, InstrumentistStatementLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(InstrumentistStatementLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setStatement($this);
        }
        return $this;
    }
}
