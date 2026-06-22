<?php

namespace App\Entity;

use App\Enum\PlanningAlertStatus;
use App\Enum\PlanningAlertType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Index(columns: ['status'], name: 'idx_planning_alert_status')]
#[ORM\Index(columns: ['absence_id'], name: 'idx_planning_alert_absence')]
#[ORM\Index(columns: ['mission_id', 'type'], name: 'idx_planning_alert_mission_type')]
class PlanningAlert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\Column(enumType: PlanningAlertType::class, length: 24)]
    #[Groups(['planning:read'])]
    private PlanningAlertType $type;

    /**
     * Cause of the alert when it originates from an absence (SURGEON_ABSENCE,
     * INSTRUMENTIST_ABSENCE, REASSIGNMENT_REQUIRED). Null for alert types that are not
     * absence-driven (SURGEON_CONFLICT, INSTRUMENTIST_CONFLICT, OCCURRENCE_CANCELLED).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['planning:read'])]
    private ?Absence $absence = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?Mission $mission = null;

    #[ORM\Column(enumType: PlanningAlertStatus::class, length: 16)]
    #[Groups(['planning:read'])]
    private PlanningAlertStatus $status = PlanningAlertStatus::OPEN;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $detectedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['planning:read'])]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Why the alert was resolved/ignored/auto-resolved — required for traceability, never a silent state change. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['planning:read'])]
    private ?string $resolutionNote = null;

    /** Snapshot of mission state at detection time, for audit/UI display even if the mission later changes again. */
    #[ORM\Column(type: 'json')]
    #[Groups(['planning:read'])]
    private array $snapshotJson = [];

    public function __construct()
    {
        $this->detectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getType(): PlanningAlertType { return $this->type; }
    public function setType(PlanningAlertType $type): static { $this->type = $type; return $this; }

    public function getAbsence(): ?Absence { return $this->absence; }
    public function setAbsence(?Absence $absence): static { $this->absence = $absence; return $this; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(Mission $mission): static { $this->mission = $mission; return $this; }

    public function getStatus(): PlanningAlertStatus { return $this->status; }
    public function setStatus(PlanningAlertStatus $status): static { $this->status = $status; return $this; }

    public function getDetectedAt(): \DateTimeImmutable { return $this->detectedAt; }

    public function getResolvedBy(): ?User { return $this->resolvedBy; }
    public function setResolvedBy(?User $resolvedBy): static { $this->resolvedBy = $resolvedBy; return $this; }

    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static { $this->resolvedAt = $resolvedAt; return $this; }

    public function getResolutionNote(): ?string { return $this->resolutionNote; }
    public function setResolutionNote(?string $resolutionNote): static { $this->resolutionNote = $resolutionNote; return $this; }

    public function getSnapshotJson(): array { return $this->snapshotJson; }
    public function setSnapshotJson(array $snapshotJson): static { $this->snapshotJson = $snapshotJson; return $this; }

    public function isOpenOrAcknowledged(): bool
    {
        return $this->status === PlanningAlertStatus::OPEN || $this->status === PlanningAlertStatus::ACKNOWLEDGED;
    }

    public function acknowledge(User $by): static
    {
        $this->status = PlanningAlertStatus::ACKNOWLEDGED;
        $this->resolvedBy = $by;
        return $this;
    }

    /** Resolution is always explicit and traceable — never a silent deletion. */
    public function resolve(?User $by, string $note): static
    {
        $this->status         = PlanningAlertStatus::RESOLVED;
        $this->resolvedBy      = $by;
        $this->resolvedAt      = new \DateTimeImmutable();
        $this->resolutionNote  = $note;
        return $this;
    }

    public function ignore(User $by, string $note): static
    {
        $this->status         = PlanningAlertStatus::IGNORED;
        $this->resolvedBy      = $by;
        $this->resolvedAt      = new \DateTimeImmutable();
        $this->resolutionNote  = $note;
        return $this;
    }
}
