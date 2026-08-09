<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lot 6 (D-100) — signalement chirurgien d'une anomalie sur l'encodage de SA Mission.
 * Domaine dédié, jamais un détournement de MaterialItemRequest (référentiel catalogue,
 * concept métier différent). V1 minimal : le chirurgien crée, seul manager/admin
 * résout — aucune capacité de résolution instrumentiste dans ce lot.
 *
 * Résoudre ≠ corriger l'encodage : `resolve()` marque uniquement le signalement comme
 * traité. La correction réelle de l'encodage, si nécessaire, continue d'utiliser les
 * workflows existants (édition instrumentiste, reject/reopen manager) — jamais couplée
 * automatiquement à cette résolution (§17).
 */
#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_encoding_anomaly_report_mission', columns: ['mission_id']),
    new ORM\Index(name: 'idx_encoding_anomaly_report_status', columns: ['status']),
])]
#[ORM\HasLifecycleCallbacks]
class EncodingAnomalyReport
{
    use TimestampableTrait;

    public const TYPE_INTERVENTION_MISSING   = 'INTERVENTION_MISSING';
    public const TYPE_INTERVENTION_INCORRECT = 'INTERVENTION_INCORRECT';
    public const TYPE_MATERIAL_INCORRECT     = 'MATERIAL_INCORRECT';
    public const TYPE_HOURS_INCORRECT        = 'HOURS_INCORRECT';
    public const TYPE_OTHER                  = 'OTHER';

    public const TYPES = [
        self::TYPE_INTERVENTION_MISSING,
        self::TYPE_INTERVENTION_INCORRECT,
        self::TYPE_MATERIAL_INCORRECT,
        self::TYPE_HOURS_INCORRECT,
        self::TYPE_OTHER,
    ];

    public const STATUS_OPEN     = 'OPEN';
    public const STATUS_RESOLVED = 'RESOLVED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $reporter = null;

    #[ORM\Column(length: 30)]
    private ?string $type = null;

    /** Toujours requis (§13 — encourager une description concrète, quel que soit le type). */
    #[ORM\Column(type: 'text')]
    private ?string $comment = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resolutionComment = null;

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

    public function getReporter(): ?User
    {
        return $this->reporter;
    }

    public function setReporter(User $reporter): static
    {
        $this->reporter = $reporter;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(string $comment): static
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

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function setResolvedBy(?User $resolvedBy): static
    {
        $this->resolvedBy = $resolvedBy;
        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public function getResolutionComment(): ?string
    {
        return $this->resolutionComment;
    }

    public function setResolutionComment(?string $resolutionComment): static
    {
        $this->resolutionComment = $resolutionComment;
        return $this;
    }
}
