<?php

namespace App\Entity;

use App\Enum\PricingRuleType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_firm_invoice_line_invoice', columns: ['invoice_id']),
])]
class FirmInvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?FirmInvoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    /**
     * FK anti-doublon pour les lignes INTERVENTION_FEE.
     * Une MissionIntervention ne peut apparaître que dans une seule facture GENERATED+.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?MissionIntervention $missionIntervention = null;

    /**
     * FK anti-doublon pour les lignes MATERIAL_FEE (renommé depuis IMPLANT_FEE, Lot 1).
     * Une MaterialLine ne peut apparaître que dans une seule facture GENERATED+.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaterialLine $materialLine = null;

    #[ORM\Column(enumType: PricingRuleType::class, length: 20)]
    private ?PricingRuleType $lineType = null;

    #[ORM\Column(length: 500)]
    private ?string $descriptionSnapshot = null;

    #[ORM\Column(length: 255)]
    private ?string $firmNameSnapshot = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '1.00'])]
    private string $quantity = '1.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $totalAmount = null;

    public function getId(): ?int { return $this->id; }

    public function getInvoice(): ?FirmInvoice { return $this->invoice; }
    public function setInvoice(?FirmInvoice $invoice): static { $this->invoice = $invoice; return $this; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(?Mission $mission): static { $this->mission = $mission; return $this; }

    public function getMissionIntervention(): ?MissionIntervention { return $this->missionIntervention; }
    public function setMissionIntervention(?MissionIntervention $missionIntervention): static { $this->missionIntervention = $missionIntervention; return $this; }

    public function getMaterialLine(): ?MaterialLine { return $this->materialLine; }
    public function setMaterialLine(?MaterialLine $materialLine): static { $this->materialLine = $materialLine; return $this; }

    public function getLineType(): ?PricingRuleType { return $this->lineType; }
    public function setLineType(PricingRuleType $lineType): static { $this->lineType = $lineType; return $this; }

    public function getDescriptionSnapshot(): ?string { return $this->descriptionSnapshot; }
    public function setDescriptionSnapshot(string $descriptionSnapshot): static { $this->descriptionSnapshot = $descriptionSnapshot; return $this; }

    public function getFirmNameSnapshot(): ?string { return $this->firmNameSnapshot; }
    public function setFirmNameSnapshot(string $firmNameSnapshot): static { $this->firmNameSnapshot = $firmNameSnapshot; return $this; }

    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function setUnitPrice(string $unitPrice): static { $this->unitPrice = $unitPrice; return $this; }

    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string $quantity): static { $this->quantity = $quantity; return $this; }

    public function getTotalAmount(): ?string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }
}
