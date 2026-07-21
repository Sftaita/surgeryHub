<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lot 5 (D-068) — "Demande de nouveau type d'intervention", miroir structurel de
 * MaterialItemRequest pour le référentiel InterventionType : quand aucun type actif ne
 * correspond au besoin de l'instrumentiste, il soumet cette demande (PENDING) au lieu
 * d'un texte libre accepté comme s'il était catalogué. Aucune MissionIntervention n'est
 * créée tant que la demande n'est pas résolue par un manager (voir
 * InterventionTypeRequestManagerController::resolve()) — contrairement à
 * MaterialItemRequest, il n'y a pas de missionIntervention à référencer ici : par
 * construction, aucune intervention ne peut exister sans type valide après ce lot, donc
 * la demande précède toujours la création de l'intervention.
 */
#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_intervention_type_request_mission', columns: ['mission_id']),
    new ORM\Index(name: 'idx_intervention_type_request_created_by', columns: ['created_by_id']),
    new ORM\Index(name: 'idx_intervention_type_request_status', columns: ['status']),
])]
#[ORM\HasLifecycleCallbacks]
class InterventionTypeRequest
{
    use TimestampableTrait;

    public const STATUS_PENDING  = 'PENDING';
    public const STATUS_RESOLVED = 'RESOLVED';
    public const STATUS_IGNORED  = 'IGNORED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['intervention_type_request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'interventionTypeRequests')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['intervention_type_request:read'])]
    private ?Mission $mission = null;

    #[ORM\Column(length: 255)]
    #[Groups(['intervention_type_request:read'])]
    private ?string $label = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['intervention_type_request:read'])]
    private ?string $suggestedCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['intervention_type_request:read'])]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['intervention_type_request:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(length: 20, options: ['default' => 'PENDING'])]
    #[Groups(['intervention_type_request:read'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'resolved_intervention_type_id', nullable: true)]
    #[Groups(['intervention_type_request:read'])]
    private ?InterventionType $resolvedInterventionType = null;

    /**
     * EPIC Revue instrumentiste, Lot 3 — côté inverse du draft créé avec cette demande
     * (toujours 1:1, créés ensemble). Simple lecture de navigation ; aucune mutation de
     * statut ne doit passer par ce champ, voir MissionInterventionDraft.
     */
    #[ORM\OneToOne(mappedBy: 'interventionTypeRequest', targetEntity: MissionInterventionDraft::class)]
    #[Groups(['intervention_type_request:read'])]
    private ?MissionInterventionDraft $draft = null;

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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getSuggestedCode(): ?string
    {
        return $this->suggestedCode;
    }

    public function setSuggestedCode(?string $suggestedCode): static
    {
        $this->suggestedCode = $suggestedCode;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getResolvedInterventionType(): ?InterventionType
    {
        return $this->resolvedInterventionType;
    }

    public function setResolvedInterventionType(?InterventionType $resolvedInterventionType): static
    {
        $this->resolvedInterventionType = $resolvedInterventionType;
        return $this;
    }

    public function getDraft(): ?MissionInterventionDraft
    {
        return $this->draft;
    }

    /**
     * Doctrine ne synchronise pas automatiquement le côté inverse d'une OneToOne à
     * partir du côté possédant (MissionInterventionDraft::$interventionTypeRequest) —
     * même raisonnement que MissionEncodingWorkflowService::addComment() pour les
     * collections inverses. Appelé uniquement par MissionInterventionDraftService::
     * createForRequest(), jamais directement ailleurs.
     */
    public function setDraft(MissionInterventionDraft $draft): static
    {
        $this->draft = $draft;
        return $this;
    }
}
