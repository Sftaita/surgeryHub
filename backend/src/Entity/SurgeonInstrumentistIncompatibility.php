<?php

namespace App\Entity;

use App\Enum\IncompatibilityOrigin;
use Doctrine\ORM\Mapping as ORM;

/**
 * D-152 — a lasting incompatibility between a surgeon and an instrumentist: while it is active,
 * the instrumentist is never offered, notified, assignable nor able to claim any of this
 * surgeon's missions (EligibilityReason::SURGEON_INCOMPATIBILITY, MissionEligibilityService).
 *
 * Never deleted: lifting it sets liftedAt/liftedBy once, the row stays as history (who, when,
 * from which path, until when). Written only by SurgeonInstrumentistIncompatibilityService.
 *
 * `active_pair_key` is a STORED generated column (= "surgeonId-instrumentistId" while
 * lifted_at IS NULL, NULL afterwards) with a UNIQUE index: at most one ACTIVE incompatibility
 * per pair, guaranteed by MySQL — a concurrent double creation becomes a 409, never a twin row.
 * The (surgeon_id, instrumentist_id, lifted_at) index is also the range a claim locks
 * (FOR SHARE) to serialise against a concurrent creation (D-152, Décision 4).
 */
#[ORM\Entity]
#[ORM\Table(name: 'surgeon_instrumentist_incompatibility')]
#[ORM\Index(columns: ['surgeon_id', 'instrumentist_id', 'lifted_at'], name: 'idx_sii_pair')]
#[ORM\Index(columns: ['instrumentist_id', 'lifted_at'], name: 'idx_sii_instrumentist')]
#[ORM\Index(columns: ['created_by_id'], name: 'IDX_SII_CREATED_BY')]
#[ORM\Index(columns: ['lifted_by_id'], name: 'IDX_SII_LIFTED_BY')]
#[ORM\Index(columns: ['source_assignment_id'], name: 'IDX_SII_SOURCE_ASSIGNMENT')]
#[ORM\UniqueConstraint(name: 'uniq_sii_active_pair', columns: ['active_pair_key'])]
class SurgeonInstrumentistIncompatibility
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $surgeon;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $instrumentist;

    #[ORM\Column(length: 20, enumType: IncompatibilityOrigin::class)]
    private IncompatibilityOrigin $origin;

    /** The refused affectation, for an incompatibility born from a durable refusal. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MissionAssignment $sourceAssignment;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $liftedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $liftedBy = null;

    /** Read-only: computed by MySQL, see class doc. */
    #[ORM\Column(
        length: 23,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "VARCHAR(23) GENERATED ALWAYS AS (IF(lifted_at IS NULL, CONCAT(surgeon_id, '-', instrumentist_id), NULL)) STORED",
        generated: 'ALWAYS',
    )]
    private ?string $activePairKey = null;

    public function __construct(
        User $surgeon,
        User $instrumentist,
        IncompatibilityOrigin $origin,
        ?User $createdBy,
        \DateTimeImmutable $createdAt,
        ?MissionAssignment $sourceAssignment = null,
    ) {
        $this->surgeon          = $surgeon;
        $this->instrumentist    = $instrumentist;
        $this->origin           = $origin;
        $this->createdBy        = $createdBy;
        $this->createdAt        = $createdAt;
        $this->sourceAssignment = $sourceAssignment;
    }

    /** Written once — the service refuses to lift twice. */
    public function lift(?User $by, \DateTimeImmutable $at): void
    {
        if ($this->liftedAt !== null) {
            throw new \LogicException(sprintf('Incompatibility #%s is already lifted.', $this->id ?? 'new'));
        }
        $this->liftedBy = $by;
        $this->liftedAt = $at;
    }

    public function isActive(): bool { return $this->liftedAt === null; }

    public function getId(): ?int { return $this->id; }
    public function getSurgeon(): User { return $this->surgeon; }
    public function getInstrumentist(): User { return $this->instrumentist; }
    public function getOrigin(): IncompatibilityOrigin { return $this->origin; }
    public function getSourceAssignment(): ?MissionAssignment { return $this->sourceAssignment; }
    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLiftedAt(): ?\DateTimeImmutable { return $this->liftedAt; }
    public function getLiftedBy(): ?User { return $this->liftedBy; }
}
