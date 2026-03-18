<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\PricingRuleType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class PricingRule
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Firm $firm = null;

    #[ORM\Column(enumType: PricingRuleType::class, length: 20)]
    private ?PricingRuleType $ruleType = null;

    /** Matches MissionIntervention.code — used for INTERVENTION_FEE */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $interventionCode = null;

    /** Used for IMPLANT_FEE */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaterialItem $materialItem = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $unitPrice = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int { return $this->id; }

    public function getFirm(): ?Firm { return $this->firm; }
    public function setFirm(?Firm $firm): static { $this->firm = $firm; return $this; }

    public function getRuleType(): ?PricingRuleType { return $this->ruleType; }
    public function setRuleType(PricingRuleType $ruleType): static { $this->ruleType = $ruleType; return $this; }

    public function getInterventionCode(): ?string { return $this->interventionCode; }
    public function setInterventionCode(?string $interventionCode): static { $this->interventionCode = $interventionCode; return $this; }

    public function getMaterialItem(): ?MaterialItem { return $this->materialItem; }
    public function setMaterialItem(?MaterialItem $materialItem): static { $this->materialItem = $materialItem; return $this; }

    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function setUnitPrice(string $unitPrice): static { $this->unitPrice = $unitPrice; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
}
