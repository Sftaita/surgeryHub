<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_mission_site_start', columns: ['site_id', 'start_at']),
    new ORM\Index(name: 'idx_mission_site_status', columns: ['site_id', 'status']),
])]
#[ORM\HasLifecycleCallbacks]
class Mission
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read', 'export:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'missions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read', 'export:read'])]
    private ?Hospital $site = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(enumType: SchedulePrecision::class, options: ['default' => SchedulePrecision::EXACT])]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?SchedulePrecision $schedulePrecision = SchedulePrecision::EXACT;

    #[ORM\Column(enumType: MissionType::class)]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?MissionType $type = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager', 'rating:read', 'export:read'])]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'rating:read', 'export:read'])]
    private ?User $instrumentist = null;

    #[ORM\Column(enumType: MissionStatus::class, options: ['default' => MissionStatus::DRAFT])]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?MissionStatus $status = MissionStatus::DRAFT;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?User $createdBy = null;

    /**
     * @var Collection<int, MissionPublication>
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionPublication::class, cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['mission:read_manager'])]
    private Collection $publications;

    #[ORM\OneToOne(mappedBy: 'mission', cascade: ['persist', 'remove'])]
    #[Groups(['mission:read_manager'])]
    private ?MissionClaim $claim = null;

    /**
     * @var Collection<int, MissionIntervention>
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionIntervention::class, cascade: ['persist', 'remove'])]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $interventions;

    /**
     * @var Collection<int, MaterialLine>
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MaterialLine::class, cascade: ['remove'])]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $materialLines;

    /**
     * @var Collection<int, InstrumentistService>
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: InstrumentistService::class, cascade: ['remove'])]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $services;

    /**
     * @var Collection<int, InstrumentistRating>
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: InstrumentistRating::class)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $instrumentistRatings;

    /**
     * @var Collection<int, SurgeonRatingByInstrumentist>
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: SurgeonRatingByInstrumentist::class)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $surgeonRatings;

    /**
     * @var Collection<int, ImplantSubMission>
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: ImplantSubMission::class, cascade: ['persist', 'remove'])]
    #[Groups(['mission:read_manager'])]
    private Collection $implantSubMissions;

    public function __construct()
    {
        $this->publications = new ArrayCollection();
        $this->interventions = new ArrayCollection();
        $this->materialLines = new ArrayCollection();
        $this->services = new ArrayCollection();
        $this->instrumentistRatings = new ArrayCollection();
        $this->surgeonRatings = new ArrayCollection();
        $this->implantSubMissions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Hospital
    {
        return $this->site;
    }

    public function setSite(Hospital $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(?\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function getSchedulePrecision(): ?SchedulePrecision
    {
        return $this->schedulePrecision;
    }

    public function setSchedulePrecision(SchedulePrecision $schedulePrecision): static
    {
        $this->schedulePrecision = $schedulePrecision;

        return $this;
    }

    public function getType(): ?MissionType
    {
        return $this->type;
    }

    public function setType(MissionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSurgeon(): ?User
    {
        return $this->surgeon;
    }

    public function setSurgeon(User $surgeon): static
    {
        $this->surgeon = $surgeon;

        return $this;
    }

    public function getInstrumentist(): ?User
    {
        return $this->instrumentist;
    }

    public function setInstrumentist(?User $instrumentist): static
    {
        $this->instrumentist = $instrumentist;

        return $this;
    }

    public function getStatus(): ?MissionStatus
    {
        return $this->status;
    }

    public function setStatus(MissionStatus $status): static
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

    /**
     * @return Collection<int, MissionPublication>
     */
    public function getPublications(): Collection
    {
        return $this->publications;
    }

    public function addPublication(MissionPublication $publication): static
    {
        if (!$this->publications->contains($publication)) {
            $this->publications->add($publication);
            $publication->setMission($this);
        }

        return $this;
    }

    public function removePublication(MissionPublication $publication): static
    {
        if ($this->publications->removeElement($publication)) {
            if ($publication->getMission() === $this) {
                $publication->setMission(null);
            }
        }

        return $this;
    }

    public function getClaim(): ?MissionClaim
    {
        return $this->claim;
    }

    public function setClaim(?MissionClaim $claim): static
    {
        $this->claim = $claim;
        if ($claim && $claim->getMission() !== $this) {
            $claim->setMission($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, MissionIntervention>
     */
    public function getInterventions(): Collection
    {
        return $this->interventions;
    }

    public function addIntervention(MissionIntervention $intervention): static
    {
        if (!$this->interventions->contains($intervention)) {
            $this->interventions->add($intervention);
            $intervention->setMission($this);
        }

        return $this;
    }

    public function removeIntervention(MissionIntervention $intervention): static
    {
        if ($this->interventions->removeElement($intervention)) {
            if ($intervention->getMission() === $this) {
                $intervention->setMission(null);
            }
        }

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
     * @return Collection<int, InstrumentistService>
     */
    public function getServices(): Collection
    {
        return $this->services;
    }

    /**
     * @return Collection<int, InstrumentistRating>
     */
    public function getInstrumentistRatings(): Collection
    {
        return $this->instrumentistRatings;
    }

    /**
     * @return Collection<int, SurgeonRatingByInstrumentist>
     */
    public function getSurgeonRatings(): Collection
    {
        return $this->surgeonRatings;
    }

    /**
     * @return Collection<int, ImplantSubMission>
     */
    public function getImplantSubMissions(): Collection
    {
        return $this->implantSubMissions;
    }
}
