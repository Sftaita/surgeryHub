<?php

namespace App\Entity;

use App\Enum\PlanningVersionScopeSource;
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

    /**
     * CAS D (D-115) — snapshot of PlanningGeneratorServiceV2::computePreviewVersion() at the
     * moment generate() created this DRAFT. Compared against a freshly-computed hash when the
     * draft is reopened, purely to surface "the model changed since" — never to block the
     * reopen or silently replace what's persisted. Null for versions created before this lot.
     */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $previewHash = null;

    /**
     * D-115bis — informational only, never the source of truth for reopen(). Which
     * SiteGroup this draft names, purely for display ("Groupe : Bloc Ouest" instead of the
     * generic "Tous sites" fallback) and so the frontend's site/group selector can
     * re-select the right entry when reopening. ON DELETE SET NULL: deleting the SiteGroup
     * later must never break this historical draft — see $scopeSiteIds, which is what
     * reopen() actually relies on.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'site_group_id', nullable: true, onDelete: 'SET NULL')]
    private ?SiteGroup $siteGroup = null;

    /**
     * D-115bis — snapshot of the exact Hospital ids in scope at generate() time, for a
     * site-group ($site === null) draft. Frozen forever: SiteGroupMembership is mutable, so
     * re-resolving the group's *current* membership at reopen time would let a later
     * membership change retroactively alter an old draft's scope — this is what makes
     * reopen() stable across time instead. Null for a single-site draft ($site already
     * gives the one id there) and, for a draft created before this migration whose backfill
     * had zero persisted Missions to reconstruct from, the one residual case where reopen()
     * still refuses (nothing to reconstruct).
     *
     * @var int[]|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $scopeSiteIds = null;

    /**
     * D-115bis follow-up — provenance of $scopeSiteIds. Null for a single-site draft (where
     * $scopeSiteIds itself is irrelevant). For a group-scoped draft: SNAPSHOT (captured live
     * at generate() time — certain) vs. RECONSTRUCTED (inferred after the fact from
     * persisted Missions — never certified complete, blocks every mutating action until a
     * manager reviews it via confirmScope()) vs. CONFIRMED (a RECONSTRUCTED scope a manager
     * has explicitly reviewed/corrected). Never silently promoted from RECONSTRUCTED to
     * CONFIRMED — only PlanningDraftService::confirmScope() can do that.
     */
    #[ORM\Column(type: 'string', length: 16, enumType: PlanningVersionScopeSource::class, nullable: true)]
    private ?PlanningVersionScopeSource $scopeSource = null;

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

    public function getPreviewHash(): ?string { return $this->previewHash; }
    public function setPreviewHash(?string $previewHash): static { $this->previewHash = $previewHash; return $this; }

    public function getSiteGroup(): ?SiteGroup { return $this->siteGroup; }
    public function setSiteGroup(?SiteGroup $siteGroup): static { $this->siteGroup = $siteGroup; return $this; }

    /** @return int[]|null */
    public function getScopeSiteIds(): ?array { return $this->scopeSiteIds; }
    /** @param int[]|null $scopeSiteIds */
    public function setScopeSiteIds(?array $scopeSiteIds): static { $this->scopeSiteIds = $scopeSiteIds; return $this; }

    public function getScopeSource(): ?PlanningVersionScopeSource { return $this->scopeSource; }
    public function setScopeSource(?PlanningVersionScopeSource $scopeSource): static { $this->scopeSource = $scopeSource; return $this; }

    /** @return Collection<int, Mission> */
    public function getMissions(): Collection { return $this->missions; }
}
