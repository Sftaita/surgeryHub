<?php

namespace App\Entity;

use App\Enum\StatementLineType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_stmt_line_statement', columns: ['statement_id']),
])]
class InstrumentistStatementLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?InstrumentistStatement $statement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\Column(enumType: StatementLineType::class, length: 20)]
    private ?StatementLineType $lineType = null;

    /** Durée réelle de la mission en minutes (BLOC uniquement) */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationMinutesRaw = null;

    /** Durée arrondie au quart d'heure supérieur (BLOC uniquement) */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationMinutesRounded = null;

    /** Tarif horaire ou frais consultation au moment de la génération */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $rateSnapshot = null;

    /** Heures pour BLOC (ex: "1.75"), nombre de consultations pour CONSULTATION */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private ?string $quantity = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $totalAmount = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $surgeonNameSnapshot = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteNameSnapshot = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $missionDateSnapshot = null;

    public function getId(): ?int { return $this->id; }

    public function getStatement(): ?InstrumentistStatement { return $this->statement; }
    public function setStatement(?InstrumentistStatement $statement): static { $this->statement = $statement; return $this; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(?Mission $mission): static { $this->mission = $mission; return $this; }

    public function getLineType(): ?StatementLineType { return $this->lineType; }
    public function setLineType(StatementLineType $lineType): static { $this->lineType = $lineType; return $this; }

    public function getDurationMinutesRaw(): ?int { return $this->durationMinutesRaw; }
    public function setDurationMinutesRaw(?int $durationMinutesRaw): static { $this->durationMinutesRaw = $durationMinutesRaw; return $this; }

    public function getDurationMinutesRounded(): ?int { return $this->durationMinutesRounded; }
    public function setDurationMinutesRounded(?int $durationMinutesRounded): static { $this->durationMinutesRounded = $durationMinutesRounded; return $this; }

    public function getRateSnapshot(): ?string { return $this->rateSnapshot; }
    public function setRateSnapshot(string $rateSnapshot): static { $this->rateSnapshot = $rateSnapshot; return $this; }

    public function getQuantity(): ?string { return $this->quantity; }
    public function setQuantity(string $quantity): static { $this->quantity = $quantity; return $this; }

    public function getTotalAmount(): ?string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }

    public function getSurgeonNameSnapshot(): ?string { return $this->surgeonNameSnapshot; }
    public function setSurgeonNameSnapshot(?string $surgeonNameSnapshot): static { $this->surgeonNameSnapshot = $surgeonNameSnapshot; return $this; }

    public function getSiteNameSnapshot(): ?string { return $this->siteNameSnapshot; }
    public function setSiteNameSnapshot(?string $siteNameSnapshot): static { $this->siteNameSnapshot = $siteNameSnapshot; return $this; }

    public function getMissionDateSnapshot(): ?\DateTimeImmutable { return $this->missionDateSnapshot; }
    public function setMissionDateSnapshot(?\DateTimeImmutable $missionDateSnapshot): static { $this->missionDateSnapshot = $missionDateSnapshot; return $this; }
}
