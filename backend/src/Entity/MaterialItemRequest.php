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

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'materialItemRequests')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissionIntervention $missionIntervention = null;

    #[ORM\ManyToOne(inversedBy: 'materialItemRequests')]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissionInterventionFirm $missionInterventionFirm = null;

    #[ORM\Column(length: 255)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $label = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $referenceCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $comment = null;

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

    public function setMissionIntervention(?MissionIntervention $missionIntervention): static
    {
        $this->missionIntervention = $missionIntervention;
        return $this;
    }

    public function getMissionInterventionFirm(): ?MissionInterventionFirm
    {
        return $this->missionInterventionFirm;
    }

    public function setMissionInterventionFirm(?MissionInterventionFirm $missionInterventionFirm): static
    {
        $this->missionInterventionFirm = $missionInterventionFirm;
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
}
