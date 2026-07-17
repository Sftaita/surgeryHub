<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(
    name: 'material_item',
    uniqueConstraints: [
        // Compagne de la FK composée posée par la migration sur suggested_material
        // (firm_id, material_item_id) -> material_item(firm_id, id) : nécessite un
        // index unique sur (firm_id, id) en plus de celui, déjà unique seul, sur id.
        new ORM\UniqueConstraint(name: 'uniq_material_item_firm_reference', columns: ['firm_id', 'reference_code']),
        new ORM\UniqueConstraint(name: 'uniq_material_item_firm_id', columns: ['firm_id', 'id']),
    ],
)]
#[ORM\HasLifecycleCallbacks]
class MaterialItem
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?Firm $firm = null;

    #[ORM\Column(length: 100)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $referenceCode = null;

    #[ORM\Column(length: 255)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $label = null;

    #[ORM\Column(length: 50)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $unit = null;

    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private bool $isImplant = false;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private bool $active = true;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $implantType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $material = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirm(): ?Firm
    {
        return $this->firm;
    }

    /**
     * IMPORTANT : la firme d'un matériel devient immuable dès qu'une MaterialLine
     * réelle le référence — ce n'est pas imposé ici (l'entité reste un simple setter)
     * mais dans MaterialCatalogService::update(), seul point d'entrée applicatif.
     * Voir docs/decisions.md — évite qu'une ré-affectation silencieuse ne fausse
     * rétroactivement la firme d'un matériel déjà encodé mais pas encore facturé.
     */
    public function setFirm(Firm $firm): static
    {
        $this->firm = $firm;
        return $this;
    }

    public function getReferenceCode(): ?string
    {
        return $this->referenceCode;
    }

    public function setReferenceCode(string $referenceCode): static
    {
        $this->referenceCode = $referenceCode;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    public function isImplant(): bool
    {
        return $this->isImplant;
    }

    public function setIsImplant(bool $isImplant): static
    {
        $this->isImplant = $isImplant;
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

    public function getImplantType(): ?string { return $this->implantType; }
    public function setImplantType(?string $v): static { $this->implantType = $v; return $this; }

    public function getMaterial(): ?string { return $this->material; }
    public function setMaterial(?string $v): static { $this->material = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v; return $this; }
}
