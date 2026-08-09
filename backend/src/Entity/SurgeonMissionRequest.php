<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\MissionType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lot 5 (D-099) — intention chirurgien, jamais une Mission. Le chirurgien ne reçoit
 * jamais MissionVoter::CREATE ; seul un manager/admin peut convertir une demande
 * acceptée en Mission officielle via SurgeonMissionRequestService::accept(), de façon
 * atomique (status=ACCEPTED et createdMission sont toujours posés ensemble, jamais
 * l'un sans l'autre — voir le service, transaction unique).
 *
 * Domaine dédié — jamais un détournement de MaterialItemRequest/InterventionTypeRequest
 * (référentiel catalogue, pas planning) ni de Mission DECLARED/DRAFT (représentent déjà
 * une Mission réelle, alors qu'ici aucune Mission n'existe tant que la demande n'est pas
 * acceptée). Réutilise en revanche les mêmes bons patterns architecturaux : requester,
 * status simple, review manager, AuditEvent, notification async, Voter dédié.
 *
 * `startAt`/`endAt` utilisent `business_datetime_immutable` (D-066), exactement comme
 * `Mission` — même hydratation Europe/Brussels correcte, jamais le bug UTC historique
 * du Planning V2.
 */
#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_surgeon_mission_request_surgeon', columns: ['surgeon_id']),
    new ORM\Index(name: 'idx_surgeon_mission_request_status', columns: ['status']),
])]
#[ORM\HasLifecycleCallbacks]
class SurgeonMissionRequest
{
    use TimestampableTrait;

    public const STATUS_PENDING  = 'PENDING';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_REJECTED = 'REJECTED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $surgeon = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Hospital $site = null;

    #[ORM\Column(enumType: MissionType::class)]
    private ?MissionType $type = null;

    #[ORM\Column(type: 'business_datetime_immutable')]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(type: 'business_datetime_immutable')]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewComment = null;

    /** Non-null uniquement après ACCEPTED — jamais posé seul sans status=ACCEPTED (atomicité, §3). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'created_mission_id', nullable: true)]
    private ?Mission $createdMission = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSite(): ?Hospital
    {
        return $this->site;
    }

    public function setSite(Hospital $site): static
    {
        $this->site = $site;
        return $this;
    }

    public function getType(): ?MissionType
    {
        return $this->type;
    }

    public function setType(MissionType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getStartAt(): ?\DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt;
        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;
        return $this;
    }

    public function getReviewComment(): ?string
    {
        return $this->reviewComment;
    }

    public function setReviewComment(?string $reviewComment): static
    {
        $this->reviewComment = $reviewComment;
        return $this;
    }

    public function getCreatedMission(): ?Mission
    {
        return $this->createdMission;
    }

    public function setCreatedMission(?Mission $createdMission): static
    {
        $this->createdMission = $createdMission;
        return $this;
    }
}
