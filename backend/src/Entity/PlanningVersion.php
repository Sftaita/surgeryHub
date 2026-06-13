<?php

namespace App\Entity;

use App\Enum\PlanningVersionStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'planning_version')]
class PlanningVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Hospital $site = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodEnd;

    /** Sequential version number per (site, period). */
    #[ORM\Column(type: 'integer')]
    private int $versionNumber = 1;

    #[ORM\Column(enumType: PlanningVersionStatus::class, length: 16)]
    private PlanningVersionStatus $status = PlanningVersionStatus::DRAFT;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $generatedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deployedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /** JSON summary computed at deploy time: {created, updated, skipped, missions: {total, assigned, open, unassigned}}. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $summaryJson = null;

    #[ORM\OneToMany(mappedBy: 'planningVersion', targetEntity: Mission::class)]
    private Collection $missions;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
        $this->missions    = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getSite(): ?Hospital { return $this->site; }
    public function setSite(?Hospital $site): static { $this->site = $site; return $this; }

    public function getPeriodStart(): \DateTimeImmutable { return $this->periodStart; }
    public function setPeriodStart(\DateTimeImmutable $periodStart): static { $this->periodStart = $periodStart; return $this; }

    public function getPeriodEnd(): \DateTimeImmutable { return $this->periodEnd; }
    public function setPeriodEnd(\DateTimeImmutable $periodEnd): static { $this->periodEnd = $periodEnd; return $this; }

    public function getVersionNumber(): int { return $this->versionNumber; }
    public function setVersionNumber(int $versionNumber): static { $this->versionNumber = $versionNumber; return $this; }

    public function getStatus(): PlanningVersionStatus { return $this->status; }
    public function setStatus(PlanningVersionStatus $status): static { $this->status = $status; return $this; }

    public function getGeneratedBy(): ?User { return $this->generatedBy; }
    public function setGeneratedBy(User $generatedBy): static { $this->generatedBy = $generatedBy; return $this; }

    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }

    public function getDeployedAt(): ?\DateTimeImmutable { return $this->deployedAt; }
    public function setDeployedAt(\DateTimeImmutable $deployedAt): static { $this->deployedAt = $deployedAt; return $this; }

    public function getArchivedAt(): ?\DateTimeImmutable { return $this->archivedAt; }
    public function setArchivedAt(\DateTimeImmutable $archivedAt): static { $this->archivedAt = $archivedAt; return $this; }

    public function getSummaryJson(): ?array { return $this->summaryJson; }
    public function setSummaryJson(?array $summaryJson): static { $this->summaryJson = $summaryJson; return $this; }

    /** @return Collection<int, Mission> */
    public function getMissions(): Collection { return $this->missions; }
}
