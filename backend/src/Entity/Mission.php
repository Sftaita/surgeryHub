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
#[ORM\Index(columns: ['updated_at'], name: 'IDX_MISSION_UPDATED_AT')]
#[ORM\Index(columns: ['start_at', 'end_at'], name: 'idx_mission_start_end')]
// EPIC Pilotage financier, Lot 7 (D-077) — période d'activité filtrée par statut
// (overview/pipeline "missions VALIDATED sans calcul").
#[ORM\Index(columns: ['start_at', 'status'], name: 'idx_mission_start_status')]
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

    // D-066: business_datetime_immutable, not datetime_immutable — see
    // App\Doctrine\Type\BusinessDateTimeImmutableType. Same underlying DATETIME column;
    // only the PHP-side hydration is timezone-correct (Europe/Brussels, not container UTC).
    #[ORM\Column(type: 'business_datetime_immutable')]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: 'business_datetime_immutable')]
    #[Groups(['mission:read', 'mission:read_manager', 'service:read', 'service:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $encodingLockedAt = null;

    /** Lot 7 (D-070) — instant du POST .../encoding/start le plus récent (rafraîchi si
     *  un reject/reopen renvoie la mission en ENCODING_IN_PROGRESS puis qu'elle redémarre). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $encodingStartedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $invoiceGeneratedAt = null;

    /** D-083 — marque le rappel d'encodage D+1 comme envoyé ; au plus un par mission. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $encodingReminderSentAt = null;

    // ✅ Lot B1 — mission_declared fields (modèle uniquement)
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?\DateTimeImmutable $declaredAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?string $declaredComment = null;

    /**
     * Lot 7 (D-070) suite — justification instrumentiste requise à la clôture si aucune
     * ligne de matériel active n'a été encodée (voir
     * MissionEncodingWorkflowService::complete()). Conservé pour traçabilité même après
     * une resoumission ultérieure avec matériel — ne jamais effacer automatiquement,
     * seulement conditionner son affichage manager à $submittedWithoutMaterial.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?string $noMaterialComment = null;

    /**
     * Fige, à CHAQUE clôture (complete()), si CETTE soumission avait 0 ligne de matériel
     * active. Sans ce flag distinct du commentaire, impossible de savoir après une
     * resoumission avec matériel si $noMaterialComment est encore une justification
     * active ou un vestige d'une soumission précédente — jamais dérivé après coup d'un
     * comptage live (qui peut devenir inaccessible une fois la mission verrouillée).
     */
    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'export:read'])]
    private ?bool $submittedWithoutMaterial = null;

    #[ORM\ManyToOne(inversedBy: 'missions')]
    #[ORM\JoinColumn(nullable: true)]
    private ?PlanningVersion $planningVersion = null;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionClaim::class, orphanRemoval: true)]
    private Collection $claims;

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionPublication::class, orphanRemoval: true)]
    private Collection $publications;

    /**
     * Lot 1 (Exécution & Valorisation) — le RÉALISÉ, distinct de ce bloc "planifié".
     * 0..1 : peut ne pas encore exister (voir MissionExecutionService::resolveEffectiveDuration()
     * pour le repli sur le planifié dans ce cas).
     */
    #[ORM\OneToOne(mappedBy: 'mission', targetEntity: MissionExecution::class, orphanRemoval: true)]
    private ?MissionExecution $execution = null;

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

    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: InterventionTypeRequest::class, orphanRemoval: true)]
    private Collection $interventionTypeRequests;

    /**
     * EPIC Revue instrumentiste, Lot 3 — orphanRemoval=false : un MissionInterventionDraft
     * n'est jamais supprimé au retrait de la collection (jamais un chemin de code qui le
     * fait de toute façon), même principe que FinancialCalculation (Lot 3, D-073).
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionInterventionDraft::class, orphanRemoval: false)]
    private Collection $missionInterventionDrafts;

    /** Lot 7 (D-070) — commentaires manager historisés (reject/reopen), jamais perdus. */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: MissionEncodingComment::class, orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $encodingComments;

    /**
     * EPIC Exécution & Valorisation, Lot 3 (D-073) — 1—0..n : plusieurs calculs
     * successifs possibles (recalcul avant verrouillage). orphanRemoval=false : un
     * FinancialCalculation append-only n'est jamais supprimé au retrait de la
     * collection (jamais un chemin de code qui le fait de toute façon).
     */
    #[ORM\OneToMany(mappedBy: 'mission', targetEntity: FinancialCalculation::class, orphanRemoval: false)]
    #[ORM\OrderBy(['version' => 'ASC'])]
    private Collection $financialCalculations;

    public function __construct()
    {
        $this->claims = new ArrayCollection();
        $this->publications = new ArrayCollection();
        $this->surgeonRatings = new ArrayCollection();
        $this->instrumentistRatings = new ArrayCollection();
        $this->implantSubMissions = new ArrayCollection();
        $this->interventions = new ArrayCollection();
        $this->materialLines = new ArrayCollection();
        $this->materialItemRequests = new ArrayCollection();
        $this->interventionTypeRequests = new ArrayCollection();
        $this->missionInterventionDrafts = new ArrayCollection();
        $this->encodingComments = new ArrayCollection();
        $this->financialCalculations = new ArrayCollection();
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

    public function getEncodingStartedAt(): ?\DateTimeImmutable { return $this->encodingStartedAt; }
    public function setEncodingStartedAt(?\DateTimeImmutable $encodingStartedAt): static { $this->encodingStartedAt = $encodingStartedAt; return $this; }

    public function getInvoiceGeneratedAt(): ?\DateTimeImmutable { return $this->invoiceGeneratedAt; }
    public function setInvoiceGeneratedAt(?\DateTimeImmutable $invoiceGeneratedAt): static { $this->invoiceGeneratedAt = $invoiceGeneratedAt; return $this; }

    public function getEncodingReminderSentAt(): ?\DateTimeImmutable { return $this->encodingReminderSentAt; }
    public function setEncodingReminderSentAt(?\DateTimeImmutable $encodingReminderSentAt): static { $this->encodingReminderSentAt = $encodingReminderSentAt; return $this; }

    public function getDeclaredAt(): ?\DateTimeImmutable { return $this->declaredAt; }
    public function setDeclaredAt(?\DateTimeImmutable $declaredAt): static { $this->declaredAt = $declaredAt; return $this; }

    public function getDeclaredComment(): ?string { return $this->declaredComment; }
    public function setDeclaredComment(?string $declaredComment): static { $this->declaredComment = $declaredComment; return $this; }

    public function getNoMaterialComment(): ?string { return $this->noMaterialComment; }
    public function setNoMaterialComment(?string $noMaterialComment): static { $this->noMaterialComment = $noMaterialComment; return $this; }

    public function isSubmittedWithoutMaterial(): ?bool { return $this->submittedWithoutMaterial; }
    public function setSubmittedWithoutMaterial(?bool $submittedWithoutMaterial): static { $this->submittedWithoutMaterial = $submittedWithoutMaterial; return $this; }

    public function getPlanningVersion(): ?PlanningVersion { return $this->planningVersion; }
    public function setPlanningVersion(?PlanningVersion $planningVersion): static { $this->planningVersion = $planningVersion; return $this; }

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

    /** @return Collection<int, InterventionTypeRequest> */
    public function getInterventionTypeRequests(): Collection { return $this->interventionTypeRequests; }

    /** @return Collection<int, MissionInterventionDraft> */
    public function getMissionInterventionDrafts(): Collection { return $this->missionInterventionDrafts; }

    /** @return Collection<int, MissionEncodingComment> */
    public function getEncodingComments(): Collection { return $this->encodingComments; }

    /** @return Collection<int, ImplantSubMission> */
    public function getImplantSubMissions(): Collection { return $this->implantSubMissions; }

    /** @return Collection<int, MissionClaim> */
    public function getClaims(): Collection { return $this->claims; }

    /** @return Collection<int, MissionPublication> */
    public function getPublications(): Collection { return $this->publications; }

    public function getExecution(): ?MissionExecution { return $this->execution; }

    /** @return Collection<int, FinancialCalculation> */
    public function getFinancialCalculations(): Collection { return $this->financialCalculations; }
    public function setExecution(?MissionExecution $execution): static { $this->execution = $execution; return $this; }

    /** @return Collection<int, SurgeonRatingByInstrumentist> */
    public function getSurgeonRatings(): Collection { return $this->surgeonRatings; }

    /** @return Collection<int, InstrumentistRating> */
    public function getInstrumentistRatings(): Collection { return $this->instrumentistRatings; }
}