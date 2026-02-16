<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
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
    #[ORM\JoinColumn(nullable: true)]
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirm(): ?Firm
    {
        return $this->firm;
    }

    public function setFirm(?Firm $firm): static
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
}
