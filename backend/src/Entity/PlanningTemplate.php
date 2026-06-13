<?php

namespace App\Entity;

use App\Enum\PlanningTemplateType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
class PlanningTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\Column(enumType: PlanningTemplateType::class, length: 7)]
    #[Groups(['planning:read'])]
    private PlanningTemplateType $type;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Groups(['planning:read'])]
    private ?string $label = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private Hospital $site;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, PlanningSlot> */
    #[ORM\OneToMany(mappedBy: 'template', targetEntity: PlanningSlot::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['planning:read'])]
    private Collection $slots;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->slots = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getType(): PlanningTemplateType { return $this->type; }
    public function setType(PlanningTemplateType $type): static { $this->type = $type; return $this; }

    public function getLabel(): ?string { return $this->label; }
    public function setLabel(?string $label): static { $this->label = $label; return $this; }

    public function getSite(): Hospital { return $this->site; }
    public function setSite(Hospital $site): static { $this->site = $site; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /** @return Collection<int, PlanningSlot> */
    public function getSlots(): Collection { return $this->slots; }

    public function addSlot(PlanningSlot $slot): static
    {
        if (!$this->slots->contains($slot)) {
            $this->slots->add($slot);
            $slot->setTemplate($this);
        }
        return $this;
    }

    public function removeSlot(PlanningSlot $slot): static
    {
        if ($this->slots->removeElement($slot)) {
            if ($slot->getTemplate() === $this) {
                $slot->setTemplate($this); // cannot set null — orphanRemoval handles deletion
            }
        }
        return $this;
    }
}
