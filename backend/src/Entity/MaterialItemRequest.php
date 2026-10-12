<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\CatalogueRequestIgnoreReason;
use App\Exception\ConflictingAttachmentTargetsException;
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

    public const STATUS_PENDING  = 'PENDING';
    public const STATUS_RESOLVED = 'RESOLVED';
    public const STATUS_IGNORED  = 'IGNORED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'materialItemRequests')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?Mission $mission = null;

    /**
     * `null` signifie "non rattaché à une intervention précise" — sens inchangé par
     * l'ajout de $interventionDraft ci-dessous. Ne signifie JAMAIS "rattaché à un
     * draft" : voir docblock équivalent sur MaterialLine::$missionIntervention.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?MissionIntervention $missionIntervention = null;

    /**
     * EPIC Revue instrumentiste, Lot 3 — rattachement à une intervention provisoire de
     * mission, en alternative à $missionIntervention, jamais les deux à la fois. Voir
     * docblock équivalent sur MaterialLine::$interventionDraft.
     */
    #[ORM\ManyToOne(inversedBy: 'materialItemRequests')]
    #[ORM\JoinColumn(name: 'intervention_draft_id', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?MissionInterventionDraft $interventionDraft = null;

    #[ORM\Column(length: 255)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?string $label = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?string $referenceCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?string $comment = null;

    /**
     * D-146 bis — firme fabricante choisie par l'instrumentiste parmi les firmes
     * existantes. Exclusive de $proposedFirmName (l'une OU l'autre, exigée à la création
     * depuis D-146 bis). NULL sur les demandes antérieures : information jamais recueillie.
     * Simple proposition : la firme définitive est celle validée par le manager.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'proposed_firm_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?Firm $proposedFirm = null;

    /**
     * D-146 bis — « Ma firme n'est pas dans la liste » : nom libre saisi par
     * l'instrumentiste. Jamais une firme créée automatiquement — le manager la crée (ou
     * en choisit une existante) explicitement lors de la résolution.
     */
    #[ORM\Column(length: 150, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?string $proposedFirmName = null;

    /**
     * D-146 bis — quantité utilisée déclarée par l'instrumentiste (strictement positive,
     * exigée depuis D-146 bis). NULL sur les demandes antérieures — jamais « 1 » inventé.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?string $quantity = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager', 'material_request:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(length: 20, options: ['default' => 'PENDING'])]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager', 'material_request:read'])]
    private ?MaterialItem $materialItem = null;

    /**
     * Correctif workflow Demandes Catalogue (2026-09-04) — posés uniquement sur IGNORED
     * (jamais sur RESOLVED, qui a déjà $materialItem comme trace de la décision). Motif
     * structuré + explication toujours obligatoire côté API — voir
     * MaterialItemRequestService::ignore().
     */
    #[ORM\Column(length: 30, nullable: true, enumType: CatalogueRequestIgnoreReason::class)]
    #[Groups(['mission:read_manager', 'material_request:read'])]
    private ?CatalogueRequestIgnoreReason $ignoreReason = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read_manager', 'material_request:read'])]
    private ?string $ignoreComment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read_manager', 'material_request:read'])]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['mission:read_manager', 'material_request:read'])]
    private ?\DateTimeImmutable $decidedAt = null;

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

    public function getInterventionDraft(): ?MissionInterventionDraft
    {
        return $this->interventionDraft;
    }

    public function setInterventionDraft(?MissionInterventionDraft $interventionDraft): static
    {
        $this->interventionDraft = $interventionDraft;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
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

    public function getProposedFirm(): ?Firm
    {
        return $this->proposedFirm;
    }

    public function setProposedFirm(?Firm $proposedFirm): static
    {
        $this->proposedFirm = $proposedFirm;
        return $this;
    }

    public function getProposedFirmName(): ?string
    {
        return $this->proposedFirmName;
    }

    public function setProposedFirmName(?string $proposedFirmName): static
    {
        $this->proposedFirmName = $proposedFirmName;
        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): static
    {
        $this->quantity = $quantity;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getMaterialItem(): ?MaterialItem
    {
        return $this->materialItem;
    }

    public function setMaterialItem(?MaterialItem $materialItem): static
    {
        $this->materialItem = $materialItem;
        return $this;
    }

    public function getIgnoreReason(): ?CatalogueRequestIgnoreReason
    {
        return $this->ignoreReason;
    }

    public function setIgnoreReason(?CatalogueRequestIgnoreReason $ignoreReason): static
    {
        $this->ignoreReason = $ignoreReason;
        return $this;
    }

    public function getIgnoreComment(): ?string
    {
        return $this->ignoreComment;
    }

    public function setIgnoreComment(?string $ignoreComment): static
    {
        $this->ignoreComment = $ignoreComment;
        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;
        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;
        return $this;
    }

    /**
     * Seul point d'accès à la cible d'attachement — voir docblock de
     * MaterialAttachmentTarget et MaterialLine::attachmentTarget() (même contrat).
     */
    public function attachmentTarget(): ?MaterialAttachmentTarget
    {
        if ($this->missionIntervention !== null && $this->interventionDraft !== null) {
            throw new ConflictingAttachmentTargetsException(sprintf(
                'MaterialItemRequest #%s has both missionIntervention and interventionDraft set — invalid state.',
                $this->id ?? 'new',
            ));
        }

        return $this->missionIntervention ?? $this->interventionDraft;
    }
}
