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
#[ORM\HasLifecycleCallbacks]
class Mission
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?int $id = null;

    #[ORM\Column(enumType: MissionStatus::class)]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private MissionStatus $status = MissionStatus::DRAFT;

    #[ORM\Column(enumType: SchedulePrecision::class)]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private SchedulePrecision $schedulePrecision = SchedulePrecision::EXACT;

    #[ORM\Column(enumType: MissionType::class)]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?MissionType $type = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager', 'rating:read', 'export:read'])]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?User $instrumentist = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager', 'export:read'])]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(inversedBy: 'missions')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?Hospital $site = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $encodingLockedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $invoiceGeneratedAt = null;

    // ✅ Lot B1 — mission_declared fields (modèle uniquement)
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $declaredAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?string $declaredComment = null;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionClaim::class, orphanRemoval: true)]
    private Collection $claims;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionPublication::class, orphanRemoval: true)]
    private Collection $publications;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: InstrumentistService::class, orphanRemoval: true)]
    private Collection $services;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: SurgeonRatingByInstrumentist::class, orphanRemoval: true)]
    private Collection $surgeonRatings;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: InstrumentistRating::class, orphanRemoval: true)]
    private Collection $instrumentistRatings;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: ImplantSubMission::class, orphanRemoval: true)]
    private Collection $implantSubMissions;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionIntervention::class, orphanRemoval: true)]
    private Collection $interventions;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MaterialLine::class, orphanRemoval: true)]
    private Collection $materialLines;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MaterialItemRequest::class, orphanRemoval: true)]
    private Collection $materialItemRequests;

    public function __construct()
    {
        $this->claims = new ArrayCollection();
        $this->publications = new ArrayCollection();
        $this->services = new ArrayCollection();
        $this->surgeonRatings = new ArrayCollection();
        $this->instrumentistRatings = new ArrayCollection();
        $this->implantSubMissions = new ArrayCollection();
        $this->interventions = new ArrayCollection();
        $this->materialLines = new ArrayCollection();
        $this->materialItemRequests = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getStatus(): MissionStatus { return $this->status; }
    public function setStatus(MissionStatus $status): static { $this->status = $status; return $this; }

    public function getSchedulePrecision(): SchedulePrecision { return $this->schedulePrecision; }
    public function setSchedulePrecision(SchedulePrecision $schedulePrecision): static { $this->schedulePrecision = $schedulePrecision; return $this; }

    public function getType(): ?MissionType { return $this->type; }
    public function setType(MissionType $type): static { $this->type = $type; return $this; }

    public function getSurgeon(): ?User { return $this->surgeon; }
    public function setSurgeon(User $surgeon): static { $this->surgeon = $surgeon; return $this; }

    public function getInstrumentist(): ?User { return $this->instrumentist; }
    public function setInstrumentist(?User $instrumentist): static { $this->instrumentist = $instrumentist; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(Hospital $site): static { $this->site = $site; return $this; }

    public function getStartAt(): ?\DateTimeImmutable { return $this->startAt; }
    public function setStartAt(\DateTimeImmutable $startAt): static { $this->startAt = $startAt; return $this; }

    public function getEndAt(): ?\DateTimeImmutable { return $this->endAt; }
    public function setEndAt(\DateTimeImmutable $endAt): static { $this->endAt = $endAt; return $this; }

    public function getSubmittedAt(): ?\DateTimeImmutable { return $this->submittedAt; }
    public function setSubmittedAt(?\DateTimeImmutable $submittedAt): static { $this->submittedAt = $submittedAt; return $this; }

    public function getEncodingLockedAt(): ?\DateTimeImmutable { return $this->encodingLockedAt; }
    public function setEncodingLockedAt(?\DateTimeImmutable $encodingLockedAt): static { $this->encodingLockedAt = $encodingLockedAt; return $this; }

    public function getInvoiceGeneratedAt(): ?\DateTimeImmutable { return $this->invoiceGeneratedAt; }
    public function setInvoiceGeneratedAt(?\DateTimeImmutable $invoiceGeneratedAt): static { $this->invoiceGeneratedAt = $invoiceGeneratedAt; return $this; }

    public function getDeclaredAt(): ?\DateTimeImmutable { return $this->declaredAt; }
    public function setDeclaredAt(?\DateTimeImmutable $declaredAt): static { $this->declaredAt = $declaredAt; return $this; }

    public function getDeclaredComment(): ?string { return $this->declaredComment; }
    public function setDeclaredComment(?string $declaredComment): static { $this->declaredComment = $declaredComment; return $this; }

    public function isEncodingLocked(): bool
    {
        return $this->encodingLockedAt !== null || $this->invoiceGeneratedAt !== null;
    }

    /** @return Collection<int, MissionIntervention> */
    public function getInterventions(): Collection { return $this->interventions; }

    /** @return Collection<int, MaterialLine> */
    public function getMaterialLines(): Collection { return $this->materialLines; }

    /** @return Collection<int, MaterialItemRequest> */
    public function getMaterialItemRequests(): Collection { return $this->materialItemRequests; }

    /** @return Collection<int, ImplantSubMission> */
    public function getImplantSubMissions(): Collection { return $this->implantSubMissions; }

    /** @return Collection<int, MissionClaim> */
    public function getClaims(): Collection { return $this->claims; }

    /** @return Collection<int, MissionPublication> */
    public function getPublications(): Collection { return $this->publications; }

    /** @return Collection<int, InstrumentistService> */
    public function getServices(): Collection { return $this->services; }

    /** @return Collection<int, SurgeonRatingByInstrumentist> */
    public function getSurgeonRatings(): Collection { return $this->surgeonRatings; }

    /** @return Collection<int, InstrumentistRating> */
    public function getInstrumentistRatings(): Collection { return $this->instrumentistRatings; }
}