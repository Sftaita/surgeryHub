<?php

namespace App\Entity;

use App\Enum\PlanningDeploymentStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
class PlanningDeployment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['planning:read'])]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $periodTo;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['planning:read'])]
    private ?Hospital $site = null;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['planning:read'])]
    private \DateTimeImmutable $deployedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['planning:read'])]
    private ?User $deployedBy = null;

    #[ORM\Column(enumType: PlanningDeploymentStatus::class, length: 16)]
    #[Groups(['planning:read'])]
    private PlanningDeploymentStatus $status = PlanningDeploymentStatus::PENDING;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['planning:read'])]
    private ?\DateTimeImmutable $completedAt = null;

    /** Stacktrace or error message logged when the async worker fails. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorLog = null;

    public function __construct()
    {
        $this->deployedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getPeriodFrom(): \DateTimeImmutable { return $this->periodFrom; }
    public function setPeriodFrom(\DateTimeImmutable $periodFrom): static { $this->periodFrom = $periodFrom; return $this; }

    public function getPeriodTo(): \DateTimeImmutable { return $this->periodTo; }
    public function setPeriodTo(\DateTimeImmutable $periodTo): static { $this->periodTo = $periodTo; return $this; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(?Hospital $site): static { $this->site = $site; return $this; }

    public function getDeployedAt(): \DateTimeImmutable { return $this->deployedAt; }

    public function getDeployedBy(): ?User { return $this->deployedBy; }
    public function setDeployedBy(User $deployedBy): static { $this->deployedBy = $deployedBy; return $this; }

    public function getStatus(): PlanningDeploymentStatus { return $this->status; }
    public function setStatus(PlanningDeploymentStatus $status): static { $this->status = $status; return $this; }

    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(\DateTimeImmutable $startedAt): static { $this->startedAt = $startedAt; return $this; }

    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(\DateTimeImmutable $completedAt): static { $this->completedAt = $completedAt; return $this; }

    public function getErrorLog(): ?string { return $this->errorLog; }
    public function setErrorLog(?string $errorLog): static { $this->errorLog = $errorLog; return $this; }
}
