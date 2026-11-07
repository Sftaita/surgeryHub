<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * D-156 — a manager's confirmation, inside one Planning V2 draft, that one instrumentist
 * covers a group of simultaneous BLOC missions of the same surgeon on the same site and day
 * (one multi-room prestation). Only drafts need it: deploy() refuses a version while any
 * multi-room group of its DRAFT missions has no confirmation matching its CURRENT
 * fingerprint (MultiRoomConfirmationService). Published missions and post-deploy changes
 * are never subject to it.
 *
 * The fingerprint covers the instrumentist, surgeon, site, local date and every member's
 * planned times (PrestationGrouping::multiRoomFingerprint()): any change of composition or
 * schedule yields another fingerprint, so an old confirmation simply stops matching — never
 * rewritten, never silently carried over. Rows go with their version (ON DELETE CASCADE).
 */
#[ORM\Entity]
#[ORM\Table(name: 'planning_multi_room_confirmation')]
#[ORM\UniqueConstraint(name: 'uniq_pmrc_version_fingerprint', columns: ['planning_version_id', 'fingerprint'])]
class PlanningMultiRoomConfirmation
{
    /** The manager clicked "Confirmer l'affectation commune". */
    public const SOURCE_CONFIRMED = 'MANAGER_CONFIRMED';
    /** The manager assigned the instrumentist to a mission of the group himself in the editor. */
    public const SOURCE_EXPLICIT_ASSIGNMENT = 'EXPLICIT_ASSIGNMENT';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanningVersion $planningVersion;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    /** Snapshot for traceability (the fingerprint is what is checked). */
    #[ORM\Column(type: 'integer')]
    private int $instrumentistId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $serviceDate;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $missionIds = [];

    #[ORM\Column(length: 24)]
    private string $source;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private User $confirmedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $confirmedAt;

    /** @param list<int> $missionIds */
    public function __construct(PlanningVersion $version, string $fingerprint, int $instrumentistId, \DateTimeImmutable $serviceDate, array $missionIds, string $source, User $confirmedBy)
    {
        $this->planningVersion = $version;
        $this->fingerprint = $fingerprint;
        $this->instrumentistId = $instrumentistId;
        $this->serviceDate = $serviceDate;
        $this->missionIds = $missionIds;
        $this->source = $source;
        $this->confirmedBy = $confirmedBy;
        $this->confirmedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPlanningVersion(): PlanningVersion { return $this->planningVersion; }
    public function getFingerprint(): string { return $this->fingerprint; }
    public function getInstrumentistId(): int { return $this->instrumentistId; }
    public function getServiceDate(): \DateTimeImmutable { return $this->serviceDate; }
    /** @return list<int> */
    public function getMissionIds(): array { return $this->missionIds; }
    public function getSource(): string { return $this->source; }
    public function getConfirmedBy(): User { return $this->confirmedBy; }
    public function getConfirmedAt(): \DateTimeImmutable { return $this->confirmedAt; }
}
