<?php

namespace App\Entity;

use App\Enum\ShiftPeriod;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'shift_period_config', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_site_period', columns: ['site_id', 'period'])])]
class ShiftPeriodConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?Hospital $site = null;

    #[ORM\Column(enumType: ShiftPeriod::class, length: 12)]
    #[Groups(['planning:read'])]
    private ShiftPeriod $period;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $endTime;

    /** Deactivate instead of delete — pure site settings, not historical/audited data, but kept for traceability of past configs. */
    #[ORM\Column(type: 'boolean')]
    #[Groups(['planning:read'])]
    private bool $active = true;

    public function getId(): ?int { return $this->id; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(?Hospital $site): static { $this->site = $site; return $this; }

    public function getPeriod(): ShiftPeriod { return $this->period; }
    public function setPeriod(ShiftPeriod $period): static { $this->period = $period; return $this; }

    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function setStartTime(\DateTimeImmutable $startTime): static { $this->startTime = $startTime; return $this; }

    public function getEndTime(): \DateTimeImmutable { return $this->endTime; }
    public function setEndTime(\DateTimeImmutable $endTime): static { $this->endTime = $endTime; return $this; }

    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): static { $this->active = $active; return $this; }
}
