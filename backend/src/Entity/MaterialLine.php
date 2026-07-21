<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Exception\ConflictingAttachmentTargetsException;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_material_line_mission', columns: ['mission_id']),
])]
#[ORM\HasLifecycleCallbacks]
class MaterialLine
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?Mission $mission = null;

    /**
     * `null` signifie "non rattaché à une intervention précise" (facturation, cf.
     * FinancialCalculationLine) — sens inchangé par l'ajout de $interventionDraft
     * ci-dessous. Ne signifie JAMAIS "rattaché à un draft" : le rattachement à un draft
     * est exclusivement porté par $interventionDraft, jamais déduit de l'absence de
     * missionIntervention. Les deux champs ne sont jamais renseignés simultanément
     * (invariant appliqué en service, pas en contrainte DB — voir MissionInterventionDraftService).
     */
    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissionIntervention $missionIntervention = null;

    /**
     * EPIC Revue instrumentiste, Lot 3 — rattachement à une intervention provisoire de
     * mission (ligne hors catalogue en attente de résolution manager), en alternative à
     * $missionIntervention ci-dessus, jamais les deux à la fois. Consommateurs métier :
     * passer par attachmentTarget() (introduit avec MaterialAttachmentTarget, pas encore
     * à ce stade), jamais lire ce champ directement en dehors de l'entité/du résolveur dédié.
     */
    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    #[ORM\JoinColumn(name: 'intervention_draft_id', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MissionInterventionDraft $interventionDraft = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?MaterialItem $item = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $quantity = '1.00';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['mission:read', 'mission:read_manager'])]
    private ?string $comment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['mission:read_manager'])]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(inversedBy: 'materialLines')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['mission:read_manager'])]
    private ?ImplantSubMission $implantSubMission = null;

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

    public function getItem(): ?MaterialItem
    {
        return $this->item;
    }

    public function setItem(MaterialItem $item): static
    {
        $this->item = $item;
        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(?string $quantity): static
    {
        if ($quantity !== null) {
            $this->quantity = $quantity;
        }
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

    public function getImplantSubMission(): ?ImplantSubMission
    {
        return $this->implantSubMission;
    }

    public function setImplantSubMission(?ImplantSubMission $implantSubMission): static
    {
        $this->implantSubMission = $implantSubMission;
        return $this;
    }

    /**
     * Seul point d'accès à la cible d'attachement — voir docblock de
     * MaterialAttachmentTarget. missionIntervention/interventionDraft ne doivent plus
     * être lus directement par de la logique métier en dehors de cette méthode.
     */
    public function attachmentTarget(): ?MaterialAttachmentTarget
    {
        if ($this->missionIntervention !== null && $this->interventionDraft !== null) {
            throw new ConflictingAttachmentTargetsException(sprintf(
                'MaterialLine #%s has both missionIntervention and interventionDraft set — invalid state.',
                $this->id ?? 'new',
            ));
        }

        return $this->missionIntervention ?? $this->interventionDraft;
    }
}
