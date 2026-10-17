<?php

namespace App\Entity;

use App\Enum\MissionAssignmentEndReason;
use App\Enum\MissionAssignmentSource;
use Doctrine\ORM\Mapping as ORM;

/**
 * D-150 — one row per affectation of an instrumentist to a published Mission: who, since
 * when, from which business source, and — once it is over — when, why and by whom it ended.
 *
 * Unlike MissionClaim (history of pool claims only, never a business rule — D-117), this is
 * the business record of the affectation: its own timestamp (assignedAt) is what later rules
 * rely on, never the mission's creation date.
 *
 * Written ONLY by MissionAssignmentService. Rows are never deleted; the end fields are
 * written once. A DRAFT mission (planning being prepared) never has a row: an affectation
 * starts when the mission is published.
 *
 * `active_mission_key` is a STORED generated column (= mission_id while ended_at IS NULL,
 * NULL afterwards) carrying a UNIQUE index: the database itself guarantees at most one
 * open affectation per mission.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mission_assignment')]
#[ORM\Index(columns: ['mission_id', 'ended_at'], name: 'idx_mission_assignment_mission')]
#[ORM\Index(columns: ['instrumentist_id', 'assigned_at'], name: 'idx_mission_assignment_instrumentist')]
#[ORM\Index(columns: ['assigned_by_id'], name: 'IDX_MISSION_ASSIGNMENT_ASSIGNED_BY')]
#[ORM\Index(columns: ['ended_by_id'], name: 'IDX_MISSION_ASSIGNMENT_ENDED_BY')]
#[ORM\UniqueConstraint(name: 'uniq_mission_assignment_active', columns: ['active_mission_key'])]
class MissionAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Mission $mission;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $instrumentist;

    #[ORM\Column(length: 40, enumType: MissionAssignmentSource::class)]
    private MissionAssignmentSource $source;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $assignedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedBy;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(length: 40, nullable: true, enumType: MissionAssignmentEndReason::class)]
    private ?MissionAssignmentEndReason $endReason = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $endedBy = null;

    /** Read-only: computed by MySQL, see class doc. */
    #[ORM\Column(
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'INT GENERATED ALWAYS AS (IF(ended_at IS NULL, mission_id, NULL)) STORED',
        generated: 'ALWAYS',
    )]
    private ?int $activeMissionKey = null;

    public function __construct(
        Mission $mission,
        User $instrumentist,
        MissionAssignmentSource $source,
        ?User $assignedBy,
        \DateTimeImmutable $assignedAt,
    ) {
        $this->mission       = $mission;
        $this->instrumentist = $instrumentist;
        $this->source        = $source;
        $this->assignedBy    = $assignedBy;
        $this->assignedAt    = $assignedAt;
    }

    /** Written once — MissionAssignmentService refuses to end an affectation twice. */
    public function end(MissionAssignmentEndReason $reason, ?User $endedBy, \DateTimeImmutable $endedAt): void
    {
        if ($this->endedAt !== null) {
            throw new \LogicException(sprintf('MissionAssignment #%s has already ended.', $this->id ?? 'new'));
        }
        $this->endReason = $reason;
        $this->endedBy   = $endedBy;
        $this->endedAt   = $endedAt;
    }

    public function isActive(): bool { return $this->endedAt === null; }

    public function getId(): ?int { return $this->id; }
    public function getMission(): Mission { return $this->mission; }
    public function getInstrumentist(): User { return $this->instrumentist; }
    public function getSource(): MissionAssignmentSource { return $this->source; }
    public function getAssignedAt(): \DateTimeImmutable { return $this->assignedAt; }
    public function getAssignedBy(): ?User { return $this->assignedBy; }
    public function getEndedAt(): ?\DateTimeImmutable { return $this->endedAt; }
    public function getEndReason(): ?MissionAssignmentEndReason { return $this->endReason; }
    public function getEndedBy(): ?User { return $this->endedBy; }
}
