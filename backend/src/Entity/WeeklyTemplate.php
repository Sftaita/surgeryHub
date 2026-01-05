<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\MissionType;
use App\Enum\SchedulePrecision;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_weekly_template_site_day', columns: ['site_id', 'day_of_week'])])]
#[ORM\HasLifecycleCallbacks]
class WeeklyTemplate
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?Hospital $site = null;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?int $dayOfWeek = null;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: 'time_immutable')]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?\DateTimeImmutable $endTime = null;

    #[ORM\Column(enumType: MissionType::class)]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?MissionType $missionType = null;

    #[ORM\Column(enumType: SchedulePrecision::class, options: ['default' => SchedulePrecision::EXACT])]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?SchedulePrecision $schedulePrecision = SchedulePrecision::EXACT;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[Groups(['template:read', 'mission:read_manager'])]
    private ?User $defaultInstrumentist = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Hospital
    {
        return $this->site;
    }

    public function setSite(?Hospital $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(int $dayOfWeek): static
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        return $this->endTime;
    }

    public function setEndTime(\DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;

        return $this;
    }

    public function getMissionType(): ?MissionType
    {
        return $this->missionType;
    }

    public function setMissionType(MissionType $missionType): static
    {
        $this->missionType = $missionType;

        return $this;
    }

    public function getSchedulePrecision(): ?SchedulePrecision
    {
        return $this->schedulePrecision;
    }

    public function setSchedulePrecision(SchedulePrecision $schedulePrecision): static
    {
        $this->schedulePrecision = $schedulePrecision;

        return $this;
    }

    public function getSurgeon(): ?User
    {
        return $this->surgeon;
    }

    public function setSurgeon(User $surgeon): static
    {
        $this->surgeon = $surgeon;

        return $this;
    }

    public function getDefaultInstrumentist(): ?User
    {
        return $this->defaultInstrumentist;
    }

    public function setDefaultInstrumentist(?User $defaultInstrumentist): static
    {
        $this->defaultInstrumentist = $defaultInstrumentist;

        return $this;
    }
}
