<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * EPIC Revue instrumentiste, Lot 3 — ligne d'intervention provisoire de mission.
 *
 * Distincte de `InterventionTypeRequest` (revue de conception : voir docs/decisions.md) :
 * - `InterventionTypeRequest.status` = traitement de la DEMANDE CATALOGUE (PENDING/
 *   RESOLVED/IGNORED) — devenir vis-à-vis du référentiel InterventionType.
 * - `MissionInterventionDraft.status` = devenir de la DÉCLARATION DANS LA MISSION
 *   (OPEN/CONVERTED/MATERIAL_REASSIGNED/KEPT_AS_HISTORY) — où vit le matériel déclaré
 *   par l'instrumentiste.
 * Les deux avancent TOUJOURS ensemble, dans la même transaction, exclusivement via
 * `MissionInterventionDraftService` (pas encore introduit à ce stade — cette classe est
 * un agrégat pur, sans logique de transition branchée). Aucun contrôleur ni autre
 * service ne doit muter `status`/`resolvedMissionIntervention` directement, même si les
 * setters restent publics par cohérence avec les conventions du reste du projet
 * (`InterventionTypeRequest`, `Mission`, etc. suivent le même principe).
 *
 * `label`/`requestedFirmNameSnapshot`/`orderIndex` sont figés à la création, jamais
 * réécrits par la suite — même logique d'instantané que `MissionIntervention::$code`/
 * `$label` (Lot 5, D-068).
 *
 * Jamais supprimée, quel que soit l'aboutissement (CONVERTED, MATERIAL_REASSIGNED,
 * KEPT_AS_HISTORY) : reste une ligne d'audit permanente, comme `MissionIntervention`/
 * `InterventionTypeRequest`.
 */
#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_draft_mission_entity', columns: ['mission_id']),
    new ORM\Index(name: 'idx_draft_status_entity', columns: ['status']),
])]
#[ORM\HasLifecycleCallbacks]
class MissionInterventionDraft
{
    use TimestampableTrait;

    public const STATUS_OPEN               = 'OPEN';
    public const STATUS_CONVERTED          = 'CONVERTED';
    public const STATUS_MATERIAL_REASSIGNED = 'MATERIAL_REASSIGNED';
    public const STATUS_KEPT_AS_HISTORY    = 'KEPT_AS_HISTORY';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'missionInterventionDrafts')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?Mission $mission = null;

    /** Un draft par demande catalogue — créés ensemble, jamais l'un sans l'autre. */
    #[ORM\OneToOne(inversedBy: 'draft')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?InterventionTypeRequest $interventionTypeRequest = null;

    /** Instantané (snapshot), figé à la création — jamais relu depuis le référentiel. */
    #[ORM\Column(length: 255)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $label = null;

    /** Firme demandée par l'instrumentiste — toujours facultative. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'requested_firm_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?Firm $requestedFirm = null;

    /**
     * Instantané du nom de la firme demandée, figé à la création — un simple FK ne
     * fige rien si la firme est renommée ensuite (revue de conception).
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $requestedFirmNameSnapshot = null;

    /** Figé à la création par MissionEntryOrderAllocator (pas encore introduit). */
    #[ORM\Column(type: 'smallint')]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $orderIndex = 0;

    #[ORM\Column(length: 20, options: ['default' => 'OPEN'])]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private string $status = self::STATUS_OPEN;

    /**
     * Renseigné uniquement une fois le statut sorti de OPEN : la vraie intervention où
     * le matériel a été repointé (CONVERTED : nouvelle intervention créée ;
     * MATERIAL_REASSIGNED : intervention existante choisie par le manager). Toujours
     * NULL pour KEPT_AS_HISTORY (aucune destination réelle) et pour OPEN.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'resolved_mission_intervention_id', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissionIntervention $resolvedMissionIntervention = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?User $createdBy = null;

    /**
     * @var Collection<int, MaterialLine>
     */
    #[ORM\OneToMany(mappedBy: 'interventionDraft', targetEntity: MaterialLine::class, orphanRemoval: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private Collection $materialLines;

    /**
     * @var Collection<int, MaterialItemRequest>
     */
    #[ORM\OneToMany(mappedBy: 'interventionDraft', targetEntity: MaterialItemRequest::class, orphanRemoval: false)]
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

    public function getMission(): ?Mission
    {
        return $this->mission;
    }

    public function setMission(Mission $mission): static
    {
        $this->mission = $mission;
        return $this;
    }

    public function getInterventionTypeRequest(): ?InterventionTypeRequest
    {
        return $this->interventionTypeRequest;
    }

    public function setInterventionTypeRequest(InterventionTypeRequest $interventionTypeRequest): static
    {
        $this->interventionTypeRequest = $interventionTypeRequest;
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

    public function getRequestedFirm(): ?Firm
    {
        return $this->requestedFirm;
    }

    public function setRequestedFirm(?Firm $requestedFirm): static
    {
        $this->requestedFirm = $requestedFirm;
        return $this;
    }

    public function getRequestedFirmNameSnapshot(): ?string
    {
        return $this->requestedFirmNameSnapshot;
    }

    public function setRequestedFirmNameSnapshot(?string $requestedFirmNameSnapshot): static
    {
        $this->requestedFirmNameSnapshot = $requestedFirmNameSnapshot;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getResolvedMissionIntervention(): ?MissionIntervention
    {
        return $this->resolvedMissionIntervention;
    }

    public function setResolvedMissionIntervention(?MissionIntervention $resolvedMissionIntervention): static
    {
        $this->resolvedMissionIntervention = $resolvedMissionIntervention;
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
