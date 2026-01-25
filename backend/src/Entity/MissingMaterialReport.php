<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\MissingMaterialStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_missing_material_mission', columns: ['mission_id']),
    new ORM\Index(name: 'idx_missing_material_intervention', columns: ['mission_intervention_id']),
])]
#[ORM\HasLifecycleCallbacks]
class MissingMaterialReport
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
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissionIntervention $missionIntervention = null;

    #[ORM\Column(length: 120)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $firmName = null;

    #[ORM\Column(length: 255)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $searchText = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $quantity = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $comment = null;

    #[ORM\Column(enumType: MissingMaterialStatus::class, options: ['default' => MissingMaterialStatus::OPEN])]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissingMaterialStatus $status = MissingMaterialStatus::OPEN;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?User $createdBy = null;

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

    public function setMissionIntervention(MissionIntervention $missionIntervention): static
    {
        $this->missionIntervention = $missionIntervention;
        return $this;
    }

    public function getFirmName(): ?string
    {
        return $this->firmName;
    }

    public function setFirmName(string $firmName): static
    {
        $this->firmName = $firmName;
        return $this;
    }

    public function getSearchText(): ?string
    {
        return $this->searchText;
    }

    public function setSearchText(string $searchText): static
    {
        $this->searchText = $searchText;
        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): static
    {
        $this->quantity = $quantity;
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

    public function getStatus(): ?MissingMaterialStatus
    {
        return $this->status;
    }

    public function setStatus(MissingMaterialStatus $status): static
    {
        $this->status = $status;
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
}
