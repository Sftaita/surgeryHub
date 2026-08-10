<?php

namespace App\Entity;

use App\Enum\OccurrenceExceptionSource;
use App\Enum\OccurrenceExceptionType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'planning_occurrence_exception', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_post_occurrence_date', columns: ['post_id', 'occurrence_date'])])]
#[ORM\Index(columns: ['occurrence_date'], name: 'idx_occurrence_exception_date')]
class PlanningOccurrenceException
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?SurgeonSchedulePost $post = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $occurrenceDate;

    #[ORM\Column(enumType: OccurrenceExceptionType::class, length: 24)]
    #[Groups(['planning:read'])]
    private OccurrenceExceptionType $type;

    /** MOVED only: the date the occurrence is relocated to. The original occurrenceDate is suppressed. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $overrideDate = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['planning:read'])]
    private ?User $overrideInstrumentist = null;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $overrideStartTime = null;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $overrideEndTime = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * D-103 (Lot 3) — provenance: a manager's deliberate manual action (default, and the
     * only origin before this lot), or an automatic neutralization caused by a surgeon
     * absence recorded before any Mission existed for this occurrence.
     */
    #[ORM\Column(enumType: OccurrenceExceptionSource::class, length: 16, options: ['default' => 'MANAGER'])]
    #[Groups(['planning:read'])]
    private OccurrenceExceptionSource $source = OccurrenceExceptionSource::MANAGER;

    /** Set only when source = SURGEON_ABSENCE. ON DELETE SET NULL — a hard-deleted Absence never blocks or erases this row's history (same convention as PlanningAlert.absence). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'source_absence_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['planning:read'])]
    private ?Absence $sourceAbsence = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getPost(): ?SurgeonSchedulePost { return $this->post; }
    public function setPost(SurgeonSchedulePost $post): static { $this->post = $post; return $this; }

    public function getOccurrenceDate(): \DateTimeImmutable { return $this->occurrenceDate; }
    public function setOccurrenceDate(\DateTimeImmutable $occurrenceDate): static { $this->occurrenceDate = $occurrenceDate; return $this; }

    public function getType(): OccurrenceExceptionType { return $this->type; }
    public function setType(OccurrenceExceptionType $type): static { $this->type = $type; return $this; }

    public function getOverrideDate(): ?\DateTimeImmutable { return $this->overrideDate; }
    public function setOverrideDate(?\DateTimeImmutable $overrideDate): static { $this->overrideDate = $overrideDate; return $this; }

    /** The date on which this occurrence actually happens, after applying the exception. */
    public function effectiveDate(): \DateTimeImmutable
    {
        return $this->overrideDate ?? $this->occurrenceDate;
    }

    public function getOverrideInstrumentist(): ?User { return $this->overrideInstrumentist; }
    public function setOverrideInstrumentist(?User $overrideInstrumentist): static { $this->overrideInstrumentist = $overrideInstrumentist; return $this; }

    public function getOverrideStartTime(): ?\DateTimeImmutable { return $this->overrideStartTime; }
    public function setOverrideStartTime(?\DateTimeImmutable $overrideStartTime): static { $this->overrideStartTime = $overrideStartTime; return $this; }

    public function getOverrideEndTime(): ?\DateTimeImmutable { return $this->overrideEndTime; }
    public function setOverrideEndTime(?\DateTimeImmutable $overrideEndTime): static { $this->overrideEndTime = $overrideEndTime; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getSource(): OccurrenceExceptionSource { return $this->source; }
    public function setSource(OccurrenceExceptionSource $source): static { $this->source = $source; return $this; }

    public function getSourceAbsence(): ?Absence { return $this->sourceAbsence; }
    public function setSourceAbsence(?Absence $sourceAbsence): static { $this->sourceAbsence = $sourceAbsence; return $this; }
}
