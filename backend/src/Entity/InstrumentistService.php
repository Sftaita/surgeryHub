<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\EmploymentType;
use App\Enum\HoursSource;
use App\Enum\ServiceStatus;
use App\Enum\ServiceType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(indexes: [new ORM\Index(name: 'idx_service_mission', columns: ['mission_id'])])]
#[ORM\HasLifecycleCallbacks]
class InstrumentistService
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'services')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\Column(enumType: ServiceType::class)]
    private ?ServiceType $serviceType = null;

    #[ORM\Column(enumType: EmploymentType::class)]
    private ?EmploymentType $employmentTypeSnapshot = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $hours = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $consultationFeeApplied = null;

    #[ORM\Column(enumType: HoursSource::class, nullable: true)]
    private ?HoursSource $hoursSource = null;

    #[ORM\Column(enumType: ServiceStatus::class)]
    private ?ServiceStatus $status = ServiceStatus::CALCULATED;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $computedAmount = null;

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

    public function getServiceType(): ?ServiceType
    {
        return $this->serviceType;
    }

    public function setServiceType(ServiceType $serviceType): static
    {
        $this->serviceType = $serviceType;

        return $this;
    }

    public function getEmploymentTypeSnapshot(): ?EmploymentType
    {
        return $this->employmentTypeSnapshot;
    }

    public function setEmploymentTypeSnapshot(EmploymentType $employmentTypeSnapshot): static
    {
        $this->employmentTypeSnapshot = $employmentTypeSnapshot;

        return $this;
    }

    public function getHours(): ?string
    {
        return $this->hours;
    }

    public function setHours(?string $hours): static
    {
        $this->hours = $hours;

        return $this;
    }

    public function getConsultationFeeApplied(): ?string
    {
        return $this->consultationFeeApplied;
    }

    public function setConsultationFeeApplied(?string $consultationFeeApplied): static
    {
        $this->consultationFeeApplied = $consultationFeeApplied;

        return $this;
    }

    public function getHoursSource(): ?HoursSource
    {
        return $this->hoursSource;
    }

    public function setHoursSource(?HoursSource $hoursSource): static
    {
        $this->hoursSource = $hoursSource;

        return $this;
    }

    public function getStatus(): ?ServiceStatus
    {
        return $this->status;
    }

    public function setStatus(ServiceStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getComputedAmount(): ?string
    {
        return $this->computedAmount;
    }

    public function setComputedAmount(?string $computedAmount): static
    {
        $this->computedAmount = $computedAmount;

        return $this;
    }
}
