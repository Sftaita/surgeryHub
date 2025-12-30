<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

// Consultation missions must not have material lines; enforce in service layer and add DB check if supported by the chosen platform.
#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_material_line_mission', columns: ['mission_id'])])]
#[ORM\HasLifecycleCallbacks]
class MaterialLine
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    private ?MissionIntervention $missionIntervention = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    private ?MissionInterventionFirm $missionInterventionFirm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?MaterialItem $item = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $quantity = '1.00';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
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

    public function getMissionInterventionFirm(): ?MissionInterventionFirm
    {
        return $this->missionInterventionFirm;
    }

    public function setMissionInterventionFirm(?MissionInterventionFirm $missionInterventionFirm): static
    {
        $this->missionInterventionFirm = $missionInterventionFirm;

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

    public function setQuantity(string $quantity): static
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
