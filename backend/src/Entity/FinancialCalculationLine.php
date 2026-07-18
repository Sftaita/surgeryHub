<?php

namespace App\Entity;

use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialLineSourceType;
use App\Enum\FinancialLineType;
use Doctrine\ORM\Mapping as ORM;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — une ligne financière figée. Jamais
 * modifiée après création (voir FinancialCalculationService — cette classe n'expose
 * volontairement pas de setters "publics de mutation métier" au-delà de la construction
 * initiale : elle est peuplée une seule fois, jamais relue puis réécrite).
 *
 * `snapshot` porte tout ce que les colonnes typées ne portent pas déjà : nom de la
 * firme/du matériel/de l'intervention/de l'instrumentiste au moment du calcul, bornes
 * de validité de la règle source, etc. — la vérité historique complète, jamais
 * reconstruite en relisant le catalogue actuel (§8 du lot). Les FK vers les entités
 * source (nullable) restent uniquement pour la navigation ; en cas de suppression future
 * d'une entité référencée, le snapshot JSON reste la source de vérité lisible.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'financial_calculation_line',
    indexes: [
        new ORM\Index(name: 'idx_fcl_calculation', columns: ['financial_calculation_id']),
        new ORM\Index(name: 'idx_fcl_beneficiary_firm', columns: ['beneficiary_firm_id']),
        new ORM\Index(name: 'idx_fcl_beneficiary_instrumentist', columns: ['beneficiary_instrumentist_id']),
    ],
)]
class FinancialCalculationLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'financial_calculation_id', nullable: false)]
    private ?FinancialCalculation $financialCalculation = null;

    #[ORM\Column(name: 'beneficiary_type', enumType: FinancialBeneficiaryType::class, length: 20)]
    private ?FinancialBeneficiaryType $beneficiaryType = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'beneficiary_firm_id', nullable: true)]
    private ?Firm $beneficiaryFirm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'beneficiary_instrumentist_id', nullable: true)]
    private ?User $beneficiaryInstrumentist = null;

    #[ORM\Column(name: 'line_type', enumType: FinancialLineType::class, length: 40)]
    private ?FinancialLineType $lineType = null;

    #[ORM\Column(name: 'source_type', enumType: FinancialLineSourceType::class, length: 30)]
    private ?FinancialLineSourceType $sourceType = null;

    /** Navigation uniquement — voir docblock de la classe. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'mission_intervention_id', nullable: true)]
    private ?MissionIntervention $missionIntervention = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'material_line_id', nullable: true)]
    private ?MaterialLine $materialLine = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'pricing_rule_id', nullable: true)]
    private ?PricingRule $pricingRule = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'instrumentist_rate_id', nullable: true)]
    private ?InstrumentistRate $instrumentistRate = null;

    #[ORM\Column(name: 'description_snapshot', length: 500)]
    private ?string $descriptionSnapshot = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private ?string $quantity = null;

    /** Renseignée uniquement pour INSTRUMENTIST_HOURLY — voir §10 du lot. */
    #[ORM\Column(name: 'duration_minutes', type: 'integer', nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(name: 'unit_amount', type: 'decimal', precision: 10, scale: 2)]
    private ?string $unitAmount = null;

    #[ORM\Column(name: 'total_amount', type: 'decimal', precision: 10, scale: 2)]
    private ?string $totalAmount = null;

    #[ORM\Column(length: 3)]
    private ?string $currency = null;

    #[ORM\Column(name: 'effective_at', type: 'date_immutable')]
    private ?\DateTimeImmutable $effectiveAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $snapshot = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getFinancialCalculation(): ?FinancialCalculation { return $this->financialCalculation; }
    public function setFinancialCalculation(FinancialCalculation $financialCalculation): static { $this->financialCalculation = $financialCalculation; return $this; }

    public function getBeneficiaryType(): FinancialBeneficiaryType { return $this->beneficiaryType; }
    public function setBeneficiaryType(FinancialBeneficiaryType $beneficiaryType): static { $this->beneficiaryType = $beneficiaryType; return $this; }

    public function getBeneficiaryFirm(): ?Firm { return $this->beneficiaryFirm; }
    public function setBeneficiaryFirm(?Firm $beneficiaryFirm): static { $this->beneficiaryFirm = $beneficiaryFirm; return $this; }

    public function getBeneficiaryInstrumentist(): ?User { return $this->beneficiaryInstrumentist; }
    public function setBeneficiaryInstrumentist(?User $beneficiaryInstrumentist): static { $this->beneficiaryInstrumentist = $beneficiaryInstrumentist; return $this; }

    public function getLineType(): FinancialLineType { return $this->lineType; }
    public function setLineType(FinancialLineType $lineType): static { $this->lineType = $lineType; return $this; }

    public function getSourceType(): FinancialLineSourceType { return $this->sourceType; }
    public function setSourceType(FinancialLineSourceType $sourceType): static { $this->sourceType = $sourceType; return $this; }

    public function getMissionIntervention(): ?MissionIntervention { return $this->missionIntervention; }
    public function setMissionIntervention(?MissionIntervention $missionIntervention): static { $this->missionIntervention = $missionIntervention; return $this; }

    public function getMaterialLine(): ?MaterialLine { return $this->materialLine; }
    public function setMaterialLine(?MaterialLine $materialLine): static { $this->materialLine = $materialLine; return $this; }

    public function getPricingRule(): ?PricingRule { return $this->pricingRule; }
    public function setPricingRule(?PricingRule $pricingRule): static { $this->pricingRule = $pricingRule; return $this; }

    public function getInstrumentistRate(): ?InstrumentistRate { return $this->instrumentistRate; }
    public function setInstrumentistRate(?InstrumentistRate $instrumentistRate): static { $this->instrumentistRate = $instrumentistRate; return $this; }

    public function getDescriptionSnapshot(): string { return $this->descriptionSnapshot; }
    public function setDescriptionSnapshot(string $descriptionSnapshot): static { $this->descriptionSnapshot = $descriptionSnapshot; return $this; }

    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string $quantity): static { $this->quantity = $quantity; return $this; }

    public function getDurationMinutes(): ?int { return $this->durationMinutes; }
    public function setDurationMinutes(?int $durationMinutes): static { $this->durationMinutes = $durationMinutes; return $this; }

    public function getUnitAmount(): string { return $this->unitAmount; }
    public function setUnitAmount(string $unitAmount): static { $this->unitAmount = $unitAmount; return $this; }

    public function getTotalAmount(): string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $currency): static { $this->currency = strtoupper($currency); return $this; }

    public function getEffectiveAt(): ?\DateTimeImmutable { return $this->effectiveAt; }
    public function setEffectiveAt(\DateTimeImmutable $effectiveAt): static { $this->effectiveAt = $effectiveAt; return $this; }

    /** @return array<string, mixed> */
    public function getSnapshot(): array { return $this->snapshot; }
    /** @param array<string, mixed> $snapshot */
    public function setSnapshot(array $snapshot): static { $this->snapshot = $snapshot; return $this; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
}
