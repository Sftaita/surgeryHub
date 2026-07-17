<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * "Prestation" à l'écran — accélérateur de saisie, jamais une source de vérité
 * financière. Le moteur financier (PricingRule / PricingRuleResolver) ne lit
 * jamais cette entité ni ses matériels suggérés — voir docs/decisions.md.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'firm_service_offering',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_offering_firm_intervention_type', columns: ['firm_id', 'intervention_type_id']),
    ],
)]
#[ORM\HasLifecycleCallbacks]
class FirmServiceOffering
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['offering:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['offering:read'])]
    private ?Firm $firm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'intervention_type_id', nullable: false)]
    #[Groups(['offering:read'])]
    private ?InterventionType $interventionType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['offering:read'])]
    private ?string $label = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['offering:read'])]
    private bool $active = true;

    /** @var Collection<int, SuggestedMaterial> */
    #[ORM\OneToMany(mappedBy: 'firmServiceOffering', targetEntity: SuggestedMaterial::class, orphanRemoval: true)]
    #[ORM\OrderBy(['displayOrder' => 'ASC'])]
    #[Groups(['offering:read'])]
    private Collection $suggestedMaterials;

    public function __construct()
    {
        $this->suggestedMaterials = new ArrayCollection();
    }

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

    public function getInterventionType(): ?InterventionType
    {
        return $this->interventionType;
    }

    public function setInterventionType(InterventionType $interventionType): static
    {
        $this->interventionType = $interventionType;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label !== null ? trim($label) : null;
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

    /** @return Collection<int, SuggestedMaterial> */
    public function getSuggestedMaterials(): Collection
    {
        return $this->suggestedMaterials;
    }
}
