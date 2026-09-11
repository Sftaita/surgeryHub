<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A manager's explicit, persisted authorization to deploy despite one specific
 * CROSS_SITE_CONFLICT (D-091) — never a blanket bypass. Scoped to exactly one pair of
 * missions, canonicalized as ($missionLow, $missionHigh) by id (mirrors the anchor
 * convention PlanningConflictDetectionService::applySync() already uses for alerts), so a
 * given pair can never accumulate more than one active waiver.
 *
 * Only ever created by MissionConflictWaiverService::authorize(), which enforces the one
 * shape a waiver may cover: same surgeon AND same instrumentist AND same site on both
 * missions (the "surgeon running two rooms of the same site with one floating
 * instrumentist" case) — never a true cross-site double-booking, and never an ABSENCE
 * conflict (which has no second mission to pair against in the first place).
 *
 * $site/$surgeon/$instrumentist and the four *At columns are a SNAPSHOT of both missions'
 * state at authorization time, not a live join — MissionConflictWaiverService::
 * findActiveWaiver() compares them against the missions' CURRENT state on every deploy-time
 * revalidation and invalidates (never silently ignores) a waiver the moment any of them
 * drifts, so a later edit to either mission's instrumentist/surgeon/site/schedule always
 * makes the conflict block again until re-authorized.
 */
// No unique DB constraint on (missionLow, missionHigh): a pair can accumulate several rows
// over time (one per authorize()/invalidate() cycle) — full history, never overwritten.
// MissionConflictWaiverService is what enforces "at most one ACTIVE row per pair" at the
// application level, invalidating any existing active row before inserting a new one.
#[ORM\Entity]
#[ORM\Table(name: 'mission_conflict_waiver')]
#[ORM\Index(columns: ['mission_low_id', 'mission_high_id'], name: 'idx_mission_conflict_waiver_pair')]
class MissionConflictWaiver
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $missionLow = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $missionHigh = null;

    /** Snapshot — the shared site/surgeon/instrumentist id both missions had at authorization time. */
    #[ORM\Column(type: 'integer')]
    private int $siteId;

    #[ORM\Column(type: 'integer')]
    private int $surgeonId;

    #[ORM\Column(type: 'integer')]
    private int $instrumentistId;

    // business_datetime_immutable, not the plain datetime_immutable — these are snapshots
    // of Mission::startAt/endAt (D-066), which hydrate as Europe/Brussels wall-clock, not
    // the container's UTC default. Comparing a plain-typed read against a
    // business-typed read of the identical stored digits produces two DateTimeImmutable
    // instances tagged with different offsets — same digits, different actual instants —
    // so MissionConflictWaiverService::matchesCurrentState()'s `==` check would silently
    // and permanently fail even for a freshly-created, untouched waiver. Same underlying
    // DATETIME column either way (see BusinessDateTimeImmutableType's own docblock).
    #[ORM\Column(type: 'business_datetime_immutable')]
    private \DateTimeImmutable $missionLowStartAt;

    #[ORM\Column(type: 'business_datetime_immutable')]
    private \DateTimeImmutable $missionLowEndAt;

    #[ORM\Column(type: 'business_datetime_immutable')]
    private \DateTimeImmutable $missionHighStartAt;

    #[ORM\Column(type: 'business_datetime_immutable')]
    private \DateTimeImmutable $missionHighEndAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $authorizedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $authorizedAt;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $reason = null;

    /**
     * Never deleted once created (audit trail — see docblock). Null while active; set the
     * moment findActiveWaiver() detects either mission drifted from the snapshot above, or
     * when a fresh authorize() call replaces a still-active waiver for the same pair.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $invalidatedAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $invalidatedReason = null;

    public function getId(): ?int { return $this->id; }

    public function getMissionLow(): ?Mission { return $this->missionLow; }
    public function setMissionLow(Mission $mission): static { $this->missionLow = $mission; return $this; }

    public function getMissionHigh(): ?Mission { return $this->missionHigh; }
    public function setMissionHigh(Mission $mission): static { $this->missionHigh = $mission; return $this; }

    public function getSiteId(): int { return $this->siteId; }
    public function setSiteId(int $siteId): static { $this->siteId = $siteId; return $this; }

    public function getSurgeonId(): int { return $this->surgeonId; }
    public function setSurgeonId(int $surgeonId): static { $this->surgeonId = $surgeonId; return $this; }

    public function getInstrumentistId(): int { return $this->instrumentistId; }
    public function setInstrumentistId(int $instrumentistId): static { $this->instrumentistId = $instrumentistId; return $this; }

    public function getMissionLowStartAt(): \DateTimeImmutable { return $this->missionLowStartAt; }
    public function setMissionLowStartAt(\DateTimeImmutable $dt): static { $this->missionLowStartAt = $dt; return $this; }

    public function getMissionLowEndAt(): \DateTimeImmutable { return $this->missionLowEndAt; }
    public function setMissionLowEndAt(\DateTimeImmutable $dt): static { $this->missionLowEndAt = $dt; return $this; }

    public function getMissionHighStartAt(): \DateTimeImmutable { return $this->missionHighStartAt; }
    public function setMissionHighStartAt(\DateTimeImmutable $dt): static { $this->missionHighStartAt = $dt; return $this; }

    public function getMissionHighEndAt(): \DateTimeImmutable { return $this->missionHighEndAt; }
    public function setMissionHighEndAt(\DateTimeImmutable $dt): static { $this->missionHighEndAt = $dt; return $this; }

    public function getAuthorizedBy(): ?User { return $this->authorizedBy; }
    public function setAuthorizedBy(User $user): static { $this->authorizedBy = $user; return $this; }

    public function getAuthorizedAt(): \DateTimeImmutable { return $this->authorizedAt; }
    public function setAuthorizedAt(\DateTimeImmutable $dt): static { $this->authorizedAt = $dt; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): static { $this->reason = $reason; return $this; }

    public function getInvalidatedAt(): ?\DateTimeImmutable { return $this->invalidatedAt; }

    public function getInvalidatedReason(): ?string { return $this->invalidatedReason; }

    public function isActive(): bool { return $this->invalidatedAt === null; }

    public function invalidate(string $reason): static
    {
        $this->invalidatedAt     = new \DateTimeImmutable();
        $this->invalidatedReason = $reason;
        return $this;
    }
}
