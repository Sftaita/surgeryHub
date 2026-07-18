<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialCurrencyPolicy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — cœur déterministe de la valorisation
 * financière. Fige la valeur économique d'une mission à une date donnée
 * (`effectiveAt`) : montants, devises, règles sources, tout est snapshoté sur les
 * lignes (`FinancialCalculationLine`) — une modification future d'un tarif ne peut
 * jamais changer un calcul déjà produit.
 *
 * Append-only : jamais réécrit en place une fois `CALCULATED`. Une nouvelle
 * valorisation crée une nouvelle version (`FinancialCalculationService::recalculate()`)
 * — l'ancien calcul passe `SUPERSEDED`, jamais supprimé. Relation `Mission` 1 — 0..n
 * (plusieurs calculs successifs possibles dans le temps, notamment en cas de recalcul
 * avant verrouillage).
 *
 * Seul `FinancialCalculationService` construit/mute cette entité — jamais un contrôleur.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'financial_calculation',
    uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_financial_calculation_mission_version', columns: ['mission_id', 'version'])],
    indexes: [
        new ORM\Index(name: 'idx_financial_calculation_mission_status', columns: ['mission_id', 'status']),
    ],
)]
#[ORM\HasLifecycleCallbacks]
class FinancialCalculation
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'financialCalculations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Mission $mission = null;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(enumType: FinancialCalculationStatus::class, length: 20)]
    private FinancialCalculationStatus $status = FinancialCalculationStatus::CALCULATED;

    /**
     * Date retenue pour la résolution des tarifs (§9 du lot) — jamais now(). Voir
     * FinancialCalculationService::resolveEffectiveAt().
     */
    #[ORM\Column(name: 'effective_at', type: 'date_immutable')]
    private ?\DateTimeImmutable $effectiveAt = null;

    #[ORM\Column(name: 'currency_policy', enumType: FinancialCurrencyPolicy::class, length: 40)]
    private FinancialCurrencyPolicy $currencyPolicy = FinancialCurrencyPolicy::PER_CURRENCY_NO_CONVERSION;

    #[ORM\Column(name: 'calculated_at', type: 'datetime_immutable')]
    private ?\DateTimeImmutable $calculatedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'calculated_by_id', nullable: true)]
    private ?User $calculatedBy = null;

    #[ORM\Column(name: 'approved_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'approved_by_id', nullable: true)]
    private ?User $approvedBy = null;

    #[ORM\Column(name: 'locked_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    #[ORM\Column(name: 'cancelled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'cancelled_by_id', nullable: true)]
    private ?User $cancelledBy = null;

    #[ORM\Column(name: 'superseded_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $supersededAt = null;

    /** Le NOUVEAU calcul qui a remplacé celui-ci — renseigné uniquement sur l'ancien (SUPERSEDED). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'superseded_by_calculation_id', nullable: true)]
    private ?self $supersededByCalculation = null;

    /** @var Collection<int, FinancialCalculationLine> */
    #[ORM\OneToMany(mappedBy: 'financialCalculation', targetEntity: FinancialCalculationLine::class, cascade: ['persist'], orphanRemoval: false)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getMission(): ?Mission { return $this->mission; }
    public function setMission(Mission $mission): static { $this->mission = $mission; return $this; }

    public function getVersion(): int { return $this->version; }
    public function setVersion(int $version): static { $this->version = $version; return $this; }

    public function getStatus(): FinancialCalculationStatus { return $this->status; }
    public function setStatus(FinancialCalculationStatus $status): static { $this->status = $status; return $this; }

    public function getEffectiveAt(): ?\DateTimeImmutable { return $this->effectiveAt; }
    public function setEffectiveAt(\DateTimeImmutable $effectiveAt): static { $this->effectiveAt = $effectiveAt; return $this; }

    public function getCurrencyPolicy(): FinancialCurrencyPolicy { return $this->currencyPolicy; }
    public function setCurrencyPolicy(FinancialCurrencyPolicy $currencyPolicy): static { $this->currencyPolicy = $currencyPolicy; return $this; }

    public function getCalculatedAt(): ?\DateTimeImmutable { return $this->calculatedAt; }
    public function setCalculatedAt(\DateTimeImmutable $calculatedAt): static { $this->calculatedAt = $calculatedAt; return $this; }

    public function getCalculatedBy(): ?User { return $this->calculatedBy; }
    public function setCalculatedBy(?User $calculatedBy): static { $this->calculatedBy = $calculatedBy; return $this; }

    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static { $this->approvedAt = $approvedAt; return $this; }

    public function getApprovedBy(): ?User { return $this->approvedBy; }
    public function setApprovedBy(?User $approvedBy): static { $this->approvedBy = $approvedBy; return $this; }

    public function getLockedAt(): ?\DateTimeImmutable { return $this->lockedAt; }
    public function setLockedAt(?\DateTimeImmutable $lockedAt): static { $this->lockedAt = $lockedAt; return $this; }

    public function getCancelledAt(): ?\DateTimeImmutable { return $this->cancelledAt; }
    public function setCancelledAt(?\DateTimeImmutable $cancelledAt): static { $this->cancelledAt = $cancelledAt; return $this; }

    public function getCancelledBy(): ?User { return $this->cancelledBy; }
    public function setCancelledBy(?User $cancelledBy): static { $this->cancelledBy = $cancelledBy; return $this; }

    public function getSupersededAt(): ?\DateTimeImmutable { return $this->supersededAt; }
    public function setSupersededAt(?\DateTimeImmutable $supersededAt): static { $this->supersededAt = $supersededAt; return $this; }

    public function getSupersededByCalculation(): ?self { return $this->supersededByCalculation; }
    public function setSupersededByCalculation(?self $calculation): static { $this->supersededByCalculation = $calculation; return $this; }

    /** @return Collection<int, FinancialCalculationLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(FinancialCalculationLine $line): static
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setFinancialCalculation($this);
        }
        return $this;
    }

    /**
     * §15 — jamais d'addition silencieuse entre devises différentes. Regroupe par
     * devise puis par type de bénéficiaire.
     *
     * @return array<string, array<string, string>> ex: ['EUR' => ['FIRM' => '320.00', 'INSTRUMENTIST' => '75.00']]
     */
    public function totalsByCurrency(): array
    {
        $totals = [];
        foreach ($this->lines as $line) {
            $currency = $line->getCurrency();
            $beneficiary = $line->getBeneficiaryType()->value;
            $totals[$currency][$beneficiary] ??= '0.00';
            $totals[$currency][$beneficiary] = $this->addDecimalStrings($totals[$currency][$beneficiary], $line->getTotalAmount());
        }
        return $totals;
    }

    /**
     * Addition décimale sur des chaînes — bcmath n'est pas une dépendance garantie de
     * ce projet (jamais utilisé ailleurs dans le code existant). Cast float cohérent
     * avec la convention déjà en place dans FirmInvoiceService/
     * InstrumentistStatementService (round(...,2) sur des montants de l'ordre de
     * quelques centaines d'euros — aucun risque de perte de précision à cette échelle).
     */
    private function addDecimalStrings(string $a, string $b): string
    {
        return number_format((float) $a + (float) $b, 2, '.', '');
    }

    /**
     * EPIC Exécution & Valorisation, Lot 4 (D-074) — §11/§29 du lot : un calcul peut être
     * PARTIELLEMENT documenté (certaines lignes déjà facturées/décomptées, d'autres
     * encore libres) — jamais modélisé par un seul booléen "entièrement facturé". États
     * dérivés depuis les relations des lignes, jamais stockés en doublon.
     */
    public function hasUnassignedFirmLines(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->getBeneficiaryType() === FinancialBeneficiaryType::FIRM && !$line->isAssigned()) {
                return true;
            }
        }
        return false;
    }

    public function hasUnassignedInstrumentistLines(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->getBeneficiaryType() === FinancialBeneficiaryType::INSTRUMENTIST && !$line->isAssigned()) {
                return true;
            }
        }
        return false;
    }

    public function isFullyDocumented(): bool
    {
        foreach ($this->lines as $line) {
            if (!$line->isAssigned()) {
                return false;
            }
        }
        return true;
    }
}
