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

    /**
     * null = valide depuis toujours (voir docs/decisions.md D-067 — choix délibéré,
     * pas un oubli ; conservé pour compatibilité avec les règles créées avant D-072).
     * Convention centralisée depuis D-072 : INCLUSIF (le tarif s'applique dès ce jour).
     * PricingRuleVersioningService exige désormais un validFrom explicite pour toute
     * nouvelle règle créée via son API — seul l'ancien chemin d'écriture legacy
     * (FirmBillingController::createRule(), inchangé pour compatibilité) permet encore
     * null.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    /**
     * null = sans date de fin (ouvert). Convention centralisée depuis D-072 : EXCLUSIF
     * — le jour de validTo lui-même n'est déjà plus couvert par cette règle, il
     * appartient à la règle suivante (voir coversDate()/overlapsWith() ci-dessous et
     * D-072 pour l'exemple complet : ancien validTo=2026-07-01, nouveau
     * validFrom=2026-07-01, le 1er juillet n'utilise QUE le nouveau tarif).
     */
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

    /**
     * Vrai si $date tombe dans [validFrom, validTo[ — validFrom INCLUSIF, validTo
     * EXCLUSIF (D-072, convention centralisée). Bornes nulles = ouvertes.
     */
    public function coversDate(\DateTimeImmutable $date): bool
    {
        if ($this->validFrom !== null && $date < $this->validFrom) {
            return false;
        }
        if ($this->validTo !== null && $date >= $this->validTo) {
            return false;
        }
        return true;
    }

    /**
     * Vrai si les fenêtres [validFrom, validTo[ de $this et $other se recouvrent
     * (bornes nulles = infinies). Deux périodes qui se touchent exactement
     * (this.validTo === other.validFrom) ne se chevauchent JAMAIS — D-072 : le jour
     * validTo n'appartient déjà plus à cette règle.
     */
    public function overlapsWith(self $other): bool
    {
        $aStart = $this->validFrom;
        $aEnd   = $this->validTo;
        $bStart = $other->validFrom;
        $bEnd   = $other->validTo;

        $startsBeforeOtherEnds = $bEnd === null || $aStart === null || $aStart < $bEnd;
        $endsAfterOtherStarts  = $aEnd === null || $bStart === null || $aEnd > $bStart;

        return $startsBeforeOtherEnds && $endsAfterOtherStarts;
    }
}
