<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Matériel suggéré d'une prestation — accélère l'encodage, ne le limite jamais.
 * Suppression toujours physique : aucune incidence historique (voir docs/decisions.md).
 *
 * `firm` duplique volontairement `firmServiceOffering.firm` : c'est ce qui permet à la
 * migration de poser une contrainte FK composée (firm_id, material_item_id) vers
 * material_item(firm_id, id), garantissant en base — pas seulement côté service — qu'un
 * matériel suggéré appartient toujours à la même firme que sa prestation.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'suggested_material',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_suggested_material_offering_item', columns: ['firm_service_offering_id', 'material_item_id']),
    ],
)]
#[ORM\HasLifecycleCallbacks]
class SuggestedMaterial
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['offering:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'suggestedMaterials')]
    #[ORM\JoinColumn(name: 'firm_service_offering_id', nullable: false)]
    private ?FirmServiceOffering $firmServiceOffering = null;

    /** Dénormalisé depuis firmServiceOffering.firm — jamais réglé indépendamment (voir service). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'firm_id', nullable: false)]
    private ?Firm $firm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_item_id', nullable: false)]
    #[Groups(['offering:read'])]
    private ?MaterialItem $materialItem = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['offering:read'])]
    private int $displayOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirmServiceOffering(): ?FirmServiceOffering
    {
        return $this->firmServiceOffering;
    }

    public function setFirmServiceOffering(FirmServiceOffering $firmServiceOffering): static
    {
        $this->firmServiceOffering = $firmServiceOffering;
        return $this;
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

    public function getMaterialItem(): ?MaterialItem
    {
        return $this->materialItem;
    }

    public function setMaterialItem(MaterialItem $materialItem): static
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
}
