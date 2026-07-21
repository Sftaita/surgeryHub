<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\MaterialLineBillingState;
use App\Exception\InvalidDraftResolutionStateException;
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
class MissionInterventionDraft implements MaterialAttachmentTarget
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

    /**
     * `int` non-nullable (contrat MaterialAttachmentTarget) : lève si appelée avant
     * persist()/flush() — voir même raisonnement sur MissionIntervention::getId().
     */
    public function getId(): int
    {
        return $this->id ?? throw new \LogicException('MissionInterventionDraft::getId() called before the entity was persisted.');
    }

    /** Voir docblock de getId() — mission_id est NOT NULL en base. */
    public function getMission(): Mission
    {
        return $this->mission ?? throw new \LogicException('MissionInterventionDraft::getMission() called before a Mission was set.');
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

    /** Jamais null après construction : défaut 0, setter non-nullable — voir $orderIndex. */
    public function getOrderIndex(): int
    {
        return $this->orderIndex ?? 0;
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

    // ── MaterialAttachmentTarget ────────────────────────────────────────

    public function acceptsNewMaterial(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * CONVERTED/MATERIAL_REASSIGNED redirigent vers resolvedMissionIntervention — son
     * absence dans ces deux statuts est un état invalide (revue de conception : "ne
     * retourne jamais une redirection incohérente"), détecté explicitement plutôt que
     * de retourner silencieusement null comme si c'était KEPT_AS_HISTORY.
     */
    public function redirectTarget(): ?MaterialAttachmentTarget
    {
        return match ($this->status) {
            self::STATUS_CONVERTED, self::STATUS_MATERIAL_REASSIGNED => $this->resolvedMissionIntervention
                ?? throw new InvalidDraftResolutionStateException(sprintf(
                    'MissionInterventionDraft #%d has status %s but no resolvedMissionIntervention — invalid state.',
                    $this->getId(),
                    $this->status,
                )),
            default => null, // OPEN, KEPT_AS_HISTORY
        };
    }

    /**
     * OPEN et CONVERTED/MATERIAL_REASSIGNED partagent REQUEST_PENDING : dans les deux
     * derniers statuts, le draft n'est plus jamais interrogé directement en pratique
     * (le matériel a été repointé vers la cible redirigée, voir redirectTarget()) — la
     * valeur reflète que ce target précis n'est, lui, jamais CATALOGUED. Seul
     * KEPT_AS_HISTORY est définitivement HISTORY_ONLY (terminal, aucune cible réelle).
     *
     * DÉCISION EXPLICITE (revue de conception) : CONVERTED/MATERIAL_REASSIGNED ne
     * retournent JAMAIS CATALOGUED, même si on les interroge directement. Après une
     * transition réussie, un draft dans l'un de ces deux statuts ne devrait plus porter
     * aucune MaterialLine/MaterialItemRequest (tout a été repointé vers
     * resolvedMissionIntervention, dans la même transaction que la transition). Si une
     * ligne résiduelle existe malgré tout ici — bug de repointage, données corrompues,
     * transition interrompue — cette ligne DOIT rester exclue du moteur financier plutôt
     * que d'être traitée comme CATALOGUED par accident. N'ajoutez JAMAIS de cas
     * `self::STATUS_CONVERTED, self::STATUS_MATERIAL_REASSIGNED =>
     * MaterialLineBillingState::CATALOGUED` ici : la facturation d'un draft ne doit
     * jamais passer par cette méthode, uniquement par la MissionIntervention réelle vers
     * laquelle le matériel a été repointé (voir FinancialCalculationService, qui ne
     * traite que les lignes dont billingEligibility() === CATALOGUED).
     */
    public function billingEligibility(): MaterialLineBillingState
    {
        return match ($this->status) {
            self::STATUS_KEPT_AS_HISTORY => MaterialLineBillingState::HISTORY_ONLY,
            default => MaterialLineBillingState::REQUEST_PENDING,
        };
    }
}
