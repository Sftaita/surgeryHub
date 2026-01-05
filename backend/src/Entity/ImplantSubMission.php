<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\ImplantSubMissionStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_implant_sub_mission', columns: ['mission_id'])])]
#[ORM\HasLifecycleCallbacks]
class ImplantSubMission
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'implantSubMissions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?Mission $mission = null;

    #[ORM\Column(length: 255)]
    #[Groups(['mission:read_manager'])]
    private ?string $firmName = null;

    #[ORM\Column(enumType: ImplantSubMissionStatus::class)]
    #[Groups(['mission:read_manager'])]
    private ?ImplantSubMissionStatus $status = ImplantSubMissionStatus::DRAFT;

    /**
     * @var Collection<int, MaterialLine>
     */
    #[ORM\OneToMany(mappedBy: 'implantSubMission', targetEntity: MaterialLine::class)]
    #[Groups(['mission:read_manager'])]
    private Collection $materialLines;

    public function __construct()
    {
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

    public function getFirmName(): ?string
    {
        return $this->firmName;
    }

    public function setFirmName(string $firmName): static
    {
        $this->firmName = $firmName;

        return $this;
    }

    public function getStatus(): ?ImplantSubMissionStatus
    {
        return $this->status;
    }

    public function setStatus(ImplantSubMissionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return Collection<int, MaterialLine>
     */
    public function getMaterialLines(): Collection
    {
        return $this->materialLines;
    }
}
