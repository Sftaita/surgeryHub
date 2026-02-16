<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_material_line_mission', columns: ['mission_id']),
])]
#[ORM\HasLifecycleCallbacks]
class MaterialLine
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?Mission $mission = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissionIntervention $missionIntervention = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MaterialItem $item = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $quantity = '1.00';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read_manager'])]
    private ?ImplantSubMission $implantSubMission = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMission(): ?Mission
    {
        return $this->mission;
    }

    public function setMission(Mission $mission): static
    {
        $this->mission = $mission;
        return $this;
    }

    public function getMissionIntervention(): ?MissionIntervention
    {
        return $this->missionIntervention;
    }

    public function setMissionIntervention(?MissionIntervention $missionIntervention): static
    {
        $this->missionIntervention = $missionIntervention;
        return $this;
    }

    public function getItem(): ?MaterialItem
    {
        return $this->item;
    }

    public function setItem(MaterialItem $item): static
    {
        $this->item = $item;
        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): static
    {
        if ($quantity !== null) {
            $this->quantity = $quantity;
        }
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getImplantSubMission(): ?ImplantSubMission
    {
        return $this->implantSubMission;
    }

    public function setImplantSubMission(?ImplantSubMission $implantSubMission): static
    {
        $this->implantSubMission = $implantSubMission;
        return $this;
    }
}
