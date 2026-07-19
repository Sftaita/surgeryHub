<?php

namespace App\Entity;

use App\Enum\CorrectionReasonCode;
use App\Enum\StatementLineType;
use Doctrine\ORM\Mapping as ORM;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — même coexistence legacy/nouveau que
 * FirmInvoiceLine (voir son docblock) : `financialCalculationLine === null` = ligne
 * LEGACY (InstrumentistStatementService::generate(), relit User.hourlyRate/
 * consultationFee et recalcule la durée — chemin conservé inchangé) ;
 * `financialCalculationLine !== null` = ligne NOUVELLE (createFromEligibleLines(),
 * montants copiés depuis FinancialCalculationLine, aucune relecture de User.hourlyRate
 * ni de MissionExecution).
 */
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

    /** Voir FirmInvoiceLine::$financialCalculationLine — même contrat, même UNIQUE (§14). */
    #[ORM\OneToOne(inversedBy: 'instrumentistStatementLine')]
    #[ORM\JoinColumn(name: 'financial_calculation_line_id', nullable: true, unique: true)]
    private ?FinancialCalculationLine $financialCalculationLine = null;

    #[ORM\Column(enumType: StatementLineType::class, length: 20)]
    private ?StatementLineType $lineType = null;

    /**
     * EPIC Exécution & Valorisation, Lot 6 (D-076) — §7 du lot exige descriptionSnapshot
     * sur toute ligne corrective ; ce champ n'existait pas sur ce modèle avant ce lot
     * (contrairement à FirmInvoiceLine, Lot 4) — NULL pour toute ligne STANDARD
     * (existante et future), qui continue de s'appuyer sur lineType/surgeonNameSnapshot/
     * siteNameSnapshot pour l'affichage, comportement inchangé.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $descriptionSnapshot = null;

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

    /** Devise de la ligne — 'EUR' pour toute ligne legacy (voir migration). */
    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    /** Libellé d'unité pour la présentation (ex: "heure", "consultation") — legacy : NULL. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unitSnapshot = null;

    /**
     * Copie intégrale de FinancialCalculationLine.snapshot au moment du rattachement —
     * voir FirmInvoiceLine::$sourceSnapshot. NULL pour les lignes legacy.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sourceSnapshot = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    /** EPIC Exécution & Valorisation, Lot 6 (D-076) — voir FirmInvoiceLine::$reasonCode, même contrat. */
    #[ORM\Column(name: 'reason_code', enumType: CorrectionReasonCode::class, length: 30, nullable: true)]
    private ?CorrectionReasonCode $reasonCode = null;

    /** Voir FirmInvoiceLine::$originalDocumentLine, même contrat (self-FK, sans contrainte unique). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'original_document_line_id', nullable: true)]
    private ?self $originalDocumentLine = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getFinancialCalculationLine(): ?FinancialCalculationLine { return $this->financialCalculationLine; }
    public function setFinancialCalculationLine(?FinancialCalculationLine $financialCalculationLine): static { $this->financialCalculationLine = $financialCalculationLine; return $this; }

    public function getReasonCode(): ?CorrectionReasonCode { return $this->reasonCode; }
    public function setReasonCode(?CorrectionReasonCode $reasonCode): static { $this->reasonCode = $reasonCode; return $this; }

    public function getOriginalDocumentLine(): ?self { return $this->originalDocumentLine; }
    public function setOriginalDocumentLine(?self $originalDocumentLine): static { $this->originalDocumentLine = $originalDocumentLine; return $this; }

    public function getStatement(): ?InstrumentistStatement { return $this->statement; }
    public function setStatement(?InstrumentistStatement $statement): static { $this->statement = $statement; return $this; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(?Mission $mission): static { $this->mission = $mission; return $this; }

    public function getLineType(): ?StatementLineType { return $this->lineType; }
    public function setLineType(StatementLineType $lineType): static { $this->lineType = $lineType; return $this; }

    public function getDescriptionSnapshot(): ?string { return $this->descriptionSnapshot; }
    public function setDescriptionSnapshot(?string $descriptionSnapshot): static { $this->descriptionSnapshot = $descriptionSnapshot; return $this; }

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

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtoupper($currency); return $this; }

    public function getUnitSnapshot(): ?string { return $this->unitSnapshot; }
    public function setUnitSnapshot(?string $unitSnapshot): static { $this->unitSnapshot = $unitSnapshot; return $this; }

    /** @return array<string, mixed>|null */
    public function getSourceSnapshot(): ?array { return $this->sourceSnapshot; }
    /** @param array<string, mixed>|null $sourceSnapshot */
    public function setSourceSnapshot(?array $sourceSnapshot): static { $this->sourceSnapshot = $sourceSnapshot; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }

    /** §18 du lot — legacy = jamais reliée à une FinancialCalculationLine. */
    public function isLegacy(): bool
    {
        return $this->financialCalculationLine === null;
    }
}
