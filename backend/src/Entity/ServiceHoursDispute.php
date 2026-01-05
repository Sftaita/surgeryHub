<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\DisputeReasonCode;
use App\Enum\DisputeStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

// Unique constraint combined with status ensures only one OPEN dispute exists per service; service layer should enforce status transition semantics.
#[ORM\Entity]
#[ORM\Table(
    name: 'service_hours_dispute',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_service_status', columns: ['service_id', 'status'])],
    indexes: [
        new ORM\Index(name: 'idx_dispute_mission', columns: ['mission_id']),
        new ORM\Index(name: 'idx_dispute_service', columns: ['service_id']),
    ]
)]
#[ORM\HasLifecycleCallbacks]
class ServiceHoursDispute
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['dispute:read', 'dispute:read_manager'])]
    private ?InstrumentistService $service = null;

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

    public function getService(): ?InstrumentistService
    {
        return $this->service;
    }

    public function setService(InstrumentistService $service): static
    {
        $this->service = $service;

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
