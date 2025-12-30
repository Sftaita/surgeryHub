<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_intervention_mission', columns: ['mission_id'])])]
#[ORM\HasLifecycleCallbacks]
class MissionIntervention
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'interventions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\Column(length: 100)]
    private ?string $code = null;

    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column(type: 'smallint')]
    private ?int $orderIndex = 0;

    /**
     * @var Collection<int, MissionInterventionFirm>
     */
    #[ORM\OneToMany(mappedBy: 'missionIntervention', targetEntity: MissionInterventionFirm::class, cascade: ['persist', 'remove'])]
    private Collection $firms;

    /**
     * @var Collection<int, MaterialLine>
     */
    #[ORM\OneToMany(mappedBy: 'missionIntervention', targetEntity: MaterialLine::class)]
    private Collection $materialLines;

    public function __construct()
    {
        $this->firms = new ArrayCollection();
        $this->materialLines = new ArrayCollection();
    }

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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

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

    public function getOrderIndex(): ?int
    {
        return $this->orderIndex;
    }

    public function setOrderIndex(int $orderIndex): static
    {
        $this->orderIndex = $orderIndex;

        return $this;
    }

    /**
     * @return Collection<int, MissionInterventionFirm>
     */
    public function getFirms(): Collection
    {
        return $this->firms;
    }

    /**
     * @return Collection<int, MaterialLine>
     */
    public function getMaterialLines(): Collection
    {
        return $this->materialLines;
    }
}
