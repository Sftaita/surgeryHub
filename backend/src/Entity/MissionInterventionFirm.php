<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_intervention_firm_intervention', columns: ['mission_intervention_id'])])]
#[ORM\HasLifecycleCallbacks]
class MissionInterventionFirm
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'firms')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissionIntervention $missionIntervention = null;

    #[ORM\Column(length: 255)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $firmName = null;

    /**
     * @var Collection<int, MaterialLine>
     */
    #[ORM\OneToMany(mappedBy: 'missionInterventionFirm', targetEntity: MaterialLine::class)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $materialLines;

    /**
     * @var Collection<int, MaterialItemRequest>
     */
    #[ORM\OneToMany(mappedBy: 'missionInterventionFirm', targetEntity: MaterialItemRequest::class)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $materialItemRequests;

    public function __construct()
    {
        $this->materialLines = new ArrayCollection();
        $this->materialItemRequests = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    /**
     * @return Collection<int, MaterialLine>
     */
    public function getMaterialLines(): Collection
    {
        return $this->materialLines;
    }

    /**
     * @return Collection<int, MaterialItemRequest>
     */
    public function getMaterialItemRequests(): Collection
    {
        return $this->materialItemRequests;
    }
}
