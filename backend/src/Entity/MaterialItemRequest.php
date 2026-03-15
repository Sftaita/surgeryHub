<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_material_item_request_mission', columns: ['mission_id']),
    new ORM\Index(name: 'idx_material_item_request_created_by', columns: ['created_by_id']),
])]
#[ORM\HasLifecycleCallbacks]
class MaterialItemRequest
{
    use TimestampableTrait;

    public const STATUS_PENDING  = 'PENDING';
    public const STATUS_RESOLVED = 'RESOLVED';
    public const STATUS_IGNORED  = 'IGNORED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'materialItemRequests')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?MissionIntervention $missionIntervention = null;

    #[ORM\Column(length: 255)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?string $label = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?string $referenceCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager', 'material_request:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(length: 20, options: ['default' => 'PENDING'])]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?MaterialItem $materialItem = null;

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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getReferenceCode(): ?string
    {
        return $this->referenceCode;
    }

    public function setReferenceCode(?string $referenceCode): static
    {
        $this->referenceCode = $referenceCode;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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
}
