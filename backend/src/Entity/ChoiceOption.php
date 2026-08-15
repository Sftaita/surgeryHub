<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Une option d'un RequiredChoiceGroup — label métier libre (jamais un enum en dur, voir
 * docblock de RequiredChoiceGroup) + rattachement facultatif à un MaterialItem (cohérence
 * de l'encodage, exclusivité mutuelle des options d'un même groupe — voir
 * MaterialChoiceExclusivityService). Le forfait firme associé n'est jamais un champ ici :
 * il vit exclusivement dans PricingRule.choiceOption (moteur financier unique, voir
 * docs/decisions.md).
 *
 * Jamais de suppression physique une fois référencée par une PricingRule ou une
 * MissionIntervention.selectedChoiceOption (historique financier/encodage) — voir
 * FirmOfferingChoiceGroupController::deleteOption(). `active=false` la retire des choix
 * proposés à l'instrumentiste sans rien casser rétroactivement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'choice_option')]
#[ORM\HasLifecycleCallbacks]
class ChoiceOption
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['offering:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'options')]
    #[ORM\JoinColumn(name: 'required_choice_group_id', nullable: false)]
    private ?RequiredChoiceGroup $group = null;

    #[ORM\Column(length: 255)]
    #[Groups(['offering:read'])]
    private ?string $label = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_item_id', nullable: true)]
    #[Groups(['offering:read'])]
    private ?MaterialItem $materialItem = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['offering:read'])]
    private int $displayOrder = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['offering:read'])]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGroup(): ?RequiredChoiceGroup
    {
        return $this->group;
    }

    public function setGroup(RequiredChoiceGroup $group): static
    {
        $this->group = $group;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = trim($label);
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

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): static
    {
        $this->displayOrder = $displayOrder;
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
}
