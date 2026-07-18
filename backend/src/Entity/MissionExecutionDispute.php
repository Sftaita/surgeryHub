<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\DisputeReasonCode;
use App\Enum\DisputeStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lot 1 (Exécution & Valorisation) — renommage de ServiceHoursDispute, workflow
 * inchangé : le chirurgien concerné par la mission conteste le réalisé déclaré, le
 * manager traite et résout. Voir MissionExecutionVoter pour les permissions exactes
 * (inchangées) et MissionExecutionService pour la mécanique (inchangée).
 *
 * Contrainte combinée avec le statut : une seule contestation OPEN à la fois par
 * MissionExecution (appliquée en code, voir MissionExecutionService::openDispute()).
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'mission_execution_dispute',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_execution_status', columns: ['mission_execution_id', 'status'])],
    indexes: [
        new ORM\Index(name: 'idx_execution_dispute_mission', columns: ['mission_id']),
        new ORM\Index(name: 'idx_execution_dispute_execution', columns: ['mission_execution_id']),
    ]
)]
#[ORM\HasLifecycleCallbacks]
class MissionExecutionDispute
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['dispute:read', 'dispute:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['dispute:read', 'dispute:read_manager'])]
    private ?Mission $mission = null;

    #[ORM\ManyToOne(inversedBy: 'disputes')]
    #[ORM\JoinColumn(name: 'mission_execution_id', nullable: false)]
    #[Groups(['dispute:read', 'dispute:read_manager'])]
    private ?MissionExecution $missionExecution = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['dispute:read', 'dispute:read_manager'])]
    private ?User $raisedBy = null;

    #[ORM\Column(enumType: DisputeReasonCode::class)]
    #[Groups(['dispute:read', 'dispute:read_manager'])]
    private ?DisputeReasonCode $reasonCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['dispute:read', 'dispute:read_manager'])]
    private ?string $comment = null;

    #[ORM\Column(enumType: DisputeStatus::class)]
    #[Groups(['dispute:read', 'dispute:read_manager'])]
    private ?DisputeStatus $status = DisputeStatus::OPEN;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['dispute:read_manager'])]
    private ?string $resolutionComment = null;

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

    public function getMissionExecution(): ?MissionExecution
    {
        return $this->missionExecution;
    }

    public function setMissionExecution(MissionExecution $missionExecution): static
    {
        $this->missionExecution = $missionExecution;
        return $this;
    }

    public function getRaisedBy(): ?User
    {
        return $this->raisedBy;
    }

    public function setRaisedBy(User $raisedBy): static
    {
        $this->raisedBy = $raisedBy;
        return $this;
    }

    public function getReasonCode(): ?DisputeReasonCode
    {
        return $this->reasonCode;
    }

    public function setReasonCode(DisputeReasonCode $reasonCode): static
    {
        $this->reasonCode = $reasonCode;
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

    public function getStatus(): ?DisputeStatus
    {
        return $this->status;
    }

    public function setStatus(DisputeStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getResolutionComment(): ?string
    {
        return $this->resolutionComment;
    }

    public function setResolutionComment(?string $resolutionComment): static
    {
        $this->resolutionComment = $resolutionComment;
        return $this;
    }
}
