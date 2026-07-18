<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Enum\InstrumentistRateType;
use Doctrine\ORM\Mapping as ORM;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — modèle historisé remplaçant
 * progressivement User.hourlyRate/consultationFee comme source de vérité financière.
 * Contient uniquement la RÈGLE tarifaire — jamais une durée, jamais un montant calculé
 * (voir MissionExecution pour le réalisé, futur FinancialCalculation pour les montants).
 *
 * Convention temporelle identique à PricingRule (D-072, centralisée) : validFrom
 * INCLUSIF, validTo EXCLUSIF, nullable = ouvert. Contrairement à PricingRule (legacy
 * D-067 : validFrom peut être null = "valide depuis toujours"), validFrom est
 * NOT NULL ici — aucune donnée historique préexistante à cette contrainte (table créée
 * par ce lot), donc aucune raison de reproduire l'ambiguïté.
 *
 * Append-only : jamais de mutation en place d'une ligne déjà applicable — voir
 * InstrumentistRateService, seul point d'écriture autorisé.
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'instrumentist_rate',
    indexes: [
        new ORM\Index(name: 'idx_instrumentist_rate_resolution', columns: ['instrumentist_id', 'rate_type', 'valid_from', 'valid_to']),
    ],
)]
#[ORM\HasLifecycleCallbacks]
class InstrumentistRate
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'instrumentist_id', nullable: false)]
    private ?User $instrumentist = null;

    #[ORM\Column(name: 'rate_type', enumType: InstrumentistRateType::class, length: 30)]
    private ?InstrumentistRateType $rateType = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(name: 'valid_from', type: 'date_immutable')]
    private ?\DateTimeImmutable $validFrom = null;

    /** null = sans date de fin (ouvert). EXCLUSIF — voir docblock de la classe. */
    #[ORM\Column(name: 'valid_to', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstrumentist(): ?User
    {
        return $this->instrumentist;
    }

    public function setInstrumentist(User $instrumentist): static
    {
        $this->instrumentist = $instrumentist;
        return $this;
    }

    public function getRateType(): ?InstrumentistRateType
    {
        return $this->rateType;
    }

    public function setRateType(InstrumentistRateType $rateType): static
    {
        $this->rateType = $rateType;
        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);
        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;
        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;
        return $this;
    }

    /** Identique à PricingRule::coversDate() — validFrom inclusif, validTo exclusif (D-072). */
    public function coversDate(\DateTimeImmutable $date): bool
    {
        if ($date < $this->validFrom) {
            return false;
        }
        if ($this->validTo !== null && $date >= $this->validTo) {
            return false;
        }
        return true;
    }

    /** Identique à PricingRule::overlapsWith() — bornes touchantes = pas de chevauchement (D-072). */
    public function overlapsWith(self $other): bool
    {
        $aStart = $this->validFrom;
        $aEnd   = $this->validTo;
        $bStart = $other->validFrom;
        $bEnd   = $other->validTo;

        $startsBeforeOtherEnds = $bEnd === null || $aStart < $bEnd;
        $endsAfterOtherStarts  = $aEnd === null || $aEnd > $bStart;

        return $startsBeforeOtherEnds && $endsAfterOtherStarts;
    }
}
