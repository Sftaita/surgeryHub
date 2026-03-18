<?php

namespace App\Entity;

use App\Enum\MissionType;
use App\Enum\SlotPeriod;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
class PlanningSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'slots')]
    #[ORM\JoinColumn(nullable: false)]
    private ?PlanningTemplate $template = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['planning:read'])]
    private int $dayOfWeek;

    #[ORM\Column(enumType: SlotPeriod::class, length: 2)]
    #[Groups(['planning:read'])]
    private SlotPeriod $period;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $endTime;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['planning:read'])]
    private ?Hospital $site = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $surgeon = null;

    #[ORM\Column(enumType: MissionType::class, length: 20)]
    #[Groups(['planning:read'])]
    private MissionType $missionType;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['planning:read'])]
    private ?User $instrumentist = null;

    public function getId(): ?int { return $this->id; }

    public function getTemplate(): ?PlanningTemplate { return $this->template; }
    public function setTemplate(PlanningTemplate $template): static { $this->template = $template; return $this; }

    public function getDayOfWeek(): int { return $this->dayOfWeek; }
    public function setDayOfWeek(int $dayOfWeek): static { $this->dayOfWeek = $dayOfWeek; return $this; }

    public function getPeriod(): SlotPeriod { return $this->period; }
    public function setPeriod(SlotPeriod $period): static { $this->period = $period; return $this; }

    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function setStartTime(\DateTimeImmutable $startTime): static { $this->startTime = $startTime; return $this; }

    public function getEndTime(): \DateTimeImmutable { return $this->endTime; }
    public function setEndTime(\DateTimeImmutable $endTime): static { $this->endTime = $endTime; return $this; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(?Hospital $site): static { $this->site = $site; return $this; }

    public function getSurgeon(): ?User { return $this->surgeon; }
    public function setSurgeon(User $surgeon): static { $this->surgeon = $surgeon; return $this; }

    public function getMissionType(): MissionType { return $this->missionType; }
    public function setMissionType(MissionType $missionType): static { $this->missionType = $missionType; return $this; }

    public function getInstrumentist(): ?User { return $this->instrumentist; }
    public function setInstrumentist(?User $instrumentist): static { $this->instrumentist = $instrumentist; return $this; }
}
