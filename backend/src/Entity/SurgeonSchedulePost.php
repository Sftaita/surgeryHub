<?php

namespace App\Entity;

use App\Enum\MissionType;
use App\Enum\ShiftPeriod;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Index(columns: ['surgeon_id', 'start_date'], name: 'idx_post_surgeon_start')]
#[ORM\Index(columns: ['site_id'], name: 'idx_post_site')]
class SurgeonSchedulePost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?Hospital $site = null;

    #[ORM\Column(enumType: MissionType::class, length: 20)]
    #[Groups(['planning:read'])]
    private MissionType $type;

    #[ORM\Column(enumType: ShiftPeriod::class, length: 12)]
    #[Groups(['planning:read'])]
    private ShiftPeriod $period;

    #[ORM\Embedded(class: RecurrenceRule::class)]
    #[Groups(['planning:read'])]
    private RecurrenceRule $recurrence;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['planning:read'])]
    private ?User $instrumentist = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $createdAt;

    /** Soft pause without deleting recurrence history. */
    #[ORM\Column(type: 'boolean')]
    #[Groups(['planning:read'])]
    private bool $active = true;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->recurrence = new RecurrenceRule();
    }

    public function getId(): ?int { return $this->id; }

    public function getSurgeon(): ?User { return $this->surgeon; }
    public function setSurgeon(User $surgeon): static { $this->surgeon = $surgeon; return $this; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(Hospital $site): static { $this->site = $site; return $this; }

    public function getType(): MissionType { return $this->type; }
    public function setType(MissionType $type): static { $this->type = $type; return $this; }

    public function getPeriod(): ShiftPeriod { return $this->period; }
    public function setPeriod(ShiftPeriod $period): static { $this->period = $period; return $this; }

    public function getRecurrence(): RecurrenceRule { return $this->recurrence; }
    public function setRecurrence(RecurrenceRule $recurrence): static { $this->recurrence = $recurrence; return $this; }

    public function getInstrumentist(): ?User { return $this->instrumentist; }
    public function setInstrumentist(?User $instrumentist): static { $this->instrumentist = $instrumentist; return $this; }

    public function getStartDate(): \DateTimeImmutable { return $this->startDate; }
    public function setStartDate(\DateTimeImmutable $startDate): static { $this->startDate = $startDate; return $this; }

    public function getEndDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function setEndDate(?\DateTimeImmutable $endDate): static { $this->endDate = $endDate; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
}
