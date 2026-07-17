<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\PricingRuleType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Moteur tarifaire — jamais lu au travers de FirmServiceOffering (voir docs/decisions.md).
 * INTERVENTION_FEE : interventionType obligatoire, materialItem = null.
 * MATERIAL_FEE     : materialItem obligatoire, interventionType = null (isImplant n'entre
 *                    plus en jeu — voir PricingRuleType::MATERIAL_FEE).
 * Anti-chevauchement de dates : voir PricingRuleResolver, appelé par le contrôleur avant
 * toute création/mise à jour — pas une contrainte imposable proprement par une seule
 * contrainte SQL (bornes ouvertes des deux côtés).
 */
#[ORM\Entity]
#[ORM\Table(name: 'pricing_rule')]
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'intervention_type_id', nullable: true)]
    private ?InterventionType $interventionType = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaterialItem $materialItem = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $unitPrice = null;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    /** null = valide depuis toujours (voir docs/decisions.md — choix délibéré, pas un oubli). */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    /** null = sans date de fin. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirm(): ?Firm
    {
        return $this->firm;
    }

    public function setFirm(Firm $firm): static
    {
        $this->firm = $firm;
        return $this;
    }

    public function getRuleType(): ?PricingRuleType
    {
        return $this->ruleType;
    }

    public function setRuleType(PricingRuleType $ruleType): static
    {
        $this->ruleType = $ruleType;
        return $this;
    }

    public function getInterventionType(): ?InterventionType
    {
        return $this->interventionType;
    }

    public function setInterventionType(?InterventionType $interventionType): static
    {
        $this->interventionType = $interventionType;
        return $this;
    }

    public function getMaterialItem(): ?MaterialItem
    {
        return $this->materialItem;
    }

    public function setMaterialItem(?MaterialItem $materialItem): static
    {
        $this->materialItem = $materialItem;
        return $this;
    }

    public function getUnitPrice(): ?string
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(string $unitPrice): static
    {
        $this->unitPrice = $unitPrice;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);
        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;
        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    /** Vrai si $date tombe dans [validFrom, validTo] (bornes nulles = ouvertes). */
    public function coversDate(\DateTimeImmutable $date): bool
    {
        if ($this->validFrom !== null && $date < $this->validFrom) {
            return false;
        }
        if ($this->validTo !== null && $date > $this->validTo) {
            return false;
        }
        return true;
    }

    /** Vrai si les fenêtres de validité de $this et $other se recouvrent (bornes nulles = infinies). */
    public function overlapsWith(self $other): bool
    {
        $aStart = $this->validFrom;
        $aEnd   = $this->validTo;
        $bStart = $other->validFrom;
        $bEnd   = $other->validTo;

        $startsBeforeOtherEnds = $bEnd === null || $aStart === null || $aStart <= $bEnd;
        $endsAfterOtherStarts  = $aEnd === null || $bStart === null || $aEnd >= $bStart;

        return $startsBeforeOtherEnds && $endsAfterOtherStarts;
    }
}
