<?php

namespace App\Entity;

use App\Enum\CorrectionReasonCode;
use App\Enum\PricingRuleType;
use Doctrine\ORM\Mapping as ORM;

/**
 * EPIC Exécution & Valorisation, Lot 4 (D-074) — deux chemins de création coexistent
 * (§18 du lot) : `financialCalculationLine === null` = ligne LEGACY (FirmInvoiceService::
 * generate(), calcule elle-même le montant depuis PricingRule — chemin conservé
 * inchangé) ; `financialCalculationLine !== null` = ligne NOUVELLE
 * (FirmInvoiceService::createFromEligibleLines(), montants copiés exactement depuis
 * FinancialCalculationLine, jamais recalculés). Un même document ne mélange jamais les
 * deux (voir FirmInvoice::isLegacySource()).
 */
#[ORM\Entity]
#[ORM\Table(indexes: [
    new ORM\Index(name: 'idx_firm_invoice_line_invoice', columns: ['invoice_id']),
])]
class FirmInvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private ?FirmInvoice $invoice = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    /**
     * Côté propriétaire de la relation 1—0..1 vers la ligne financière figée dont cette
     * ligne provient (§3 du lot) — NULL pour les lignes legacy. UNIQUE en base (§14,
     * anti-double facturation) : une FinancialCalculationLine ne peut jamais appartenir
     * à deux FirmInvoiceLine.
     */
    #[ORM\OneToOne(inversedBy: 'firmInvoiceLine')]
    #[ORM\JoinColumn(name: 'financial_calculation_line_id', nullable: true, unique: true)]
    private ?FinancialCalculationLine $financialCalculationLine = null;

    /**
     * FK anti-doublon pour les lignes INTERVENTION_FEE.
     * Une MissionIntervention ne peut apparaître que dans une seule facture GENERATED+.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?MissionIntervention $missionIntervention = null;

    /**
     * FK anti-doublon pour les lignes MATERIAL_FEE (renommé depuis IMPLANT_FEE, Lot 1).
     * Une MaterialLine ne peut apparaître que dans une seule facture GENERATED+.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?MaterialLine $materialLine = null;

    #[ORM\Column(enumType: PricingRuleType::class, length: 20)]
    private ?PricingRuleType $lineType = null;

    #[ORM\Column(length: 500)]
    private ?string $descriptionSnapshot = null;

    #[ORM\Column(length: 255)]
    private ?string $firmNameSnapshot = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '1.00'])]
    private string $quantity = '1.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $totalAmount = null;

    /** Devise de la ligne — 'EUR' pour toute ligne legacy (voir migration). */
    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $currency = 'EUR';

    /** Libellé d'unité pour la présentation (ex: "pièce", "forfait") — legacy : NULL. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unitSnapshot = null;

    /**
     * Copie intégrale de FinancialCalculationLine.snapshot au moment du rattachement
     * (§5/§8 du lot) — jamais relu depuis le catalogue actuel. NULL pour les lignes
     * legacy (qui portent déjà leur propre firmNameSnapshot/descriptionSnapshot).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $sourceSnapshot = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * EPIC Exécution & Valorisation, Lot 6 (D-076) — §7/§8 du lot : renseigné
     * uniquement sur une ligne d'un document CREDIT_NOTE/DEBIT_NOTE. OTHER exige un
     * commentaire (validé par FinancialCorrectionService — pas de colonne dédiée,
     * $descriptionSnapshot porte le motif en clair sur la ligne corrective).
     */
    #[ORM\Column(name: 'reason_code', enumType: CorrectionReasonCode::class, length: 30, nullable: true)]
    private ?CorrectionReasonCode $reasonCode = null;

    /**
     * Ligne d'origine que cette ligne corrective ajuste — self-FK, volontairement SANS
     * contrainte unique (§7 : une même ligne d'origine peut être référencée par
     * plusieurs corrections successives, ex. deux crédits partiels ; ne signifie
     * jamais qu'une FinancialCalculationLine est "consommée" une deuxième fois — cette
     * sémantique reste exclusive à $financialCalculationLine, Lot 4). NULL pour un
     * ajustement documentaire global sans ligne d'origine précise (ex. ligne oubliée).
     */
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

    public function getInvoice(): ?FirmInvoice { return $this->invoice; }
    public function setInvoice(?FirmInvoice $invoice): static { $this->invoice = $invoice; return $this; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(?Mission $mission): static { $this->mission = $mission; return $this; }

    public function getMissionIntervention(): ?MissionIntervention { return $this->missionIntervention; }
    public function setMissionIntervention(?MissionIntervention $missionIntervention): static { $this->missionIntervention = $missionIntervention; return $this; }

    public function getMaterialLine(): ?MaterialLine { return $this->materialLine; }
    public function setMaterialLine(?MaterialLine $materialLine): static { $this->materialLine = $materialLine; return $this; }

    public function getLineType(): ?PricingRuleType { return $this->lineType; }
    public function setLineType(PricingRuleType $lineType): static { $this->lineType = $lineType; return $this; }

    public function getDescriptionSnapshot(): ?string { return $this->descriptionSnapshot; }
    public function setDescriptionSnapshot(string $descriptionSnapshot): static { $this->descriptionSnapshot = $descriptionSnapshot; return $this; }

    public function getFirmNameSnapshot(): ?string { return $this->firmNameSnapshot; }
    public function setFirmNameSnapshot(string $firmNameSnapshot): static { $this->firmNameSnapshot = $firmNameSnapshot; return $this; }

    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function setUnitPrice(string $unitPrice): static { $this->unitPrice = $unitPrice; return $this; }

    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string $quantity): static { $this->quantity = $quantity; return $this; }

    public function getTotalAmount(): ?string { return $this->totalAmount; }
    public function setTotalAmount(string $totalAmount): static { $this->totalAmount = $totalAmount; return $this; }

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
