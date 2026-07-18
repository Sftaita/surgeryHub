<?php

namespace App\Service;

use App\Dto\FinancialCalculationAnomaly;
use App\Entity\FinancialCalculation;
use App\Entity\FinancialCalculationLine;
use App\Entity\MaterialLine;
use App\Entity\Mission;
use App\Entity\MissionIntervention;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\FinancialBeneficiaryType;
use App\Enum\FinancialCalculationStatus;
use App\Enum\FinancialLineSourceType;
use App\Enum\FinancialLineType;
use App\Enum\InstrumentistRateType;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Exception\FinancialCalculationAnomaliesException;
use App\Exception\FinancialCalculationIneligibleException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 3 (D-073) — seul point d'entrée métier pour
 * FinancialCalculation. Les contrôleurs ne construisent jamais les lignes eux-mêmes.
 *
 * Déterministe : ne lit jamais now() pour la résolution des tarifs (resolveEffectiveAt()
 * centralise la seule règle de date), ne devine jamais un tarif manquant (§14 — échec
 * global structuré, aucune persistance partielle). Append-only : voir
 * FinancialCalculation/FinancialCalculationLine — ce service ne mute jamais les données
 * financières d'un calcul déjà CALCULATED, seulement son statut/ses métadonnées de
 * workflow (approve/lock/cancel) ou en crée un nouveau (recalculate → SUPERSEDED + une
 * nouvelle ligne, jamais une réécriture).
 */
final class FinancialCalculationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PricingRuleResolver $pricingRuleResolver,
        private readonly InstrumentistRateResolver $instrumentistRateResolver,
        private readonly MissionExecutionService $missionExecutionService,
        private readonly AuditService $audit,
    ) {}

    /**
     * §9 du lot — la seule règle de date, centralisée : horaires réels si connus,
     * sinon planifié. Jamais now() implicitement.
     */
    public function resolveEffectiveAt(Mission $mission): \DateTimeImmutable
    {
        $execution = $mission->getExecution();
        if ($execution?->getActualStartAt() !== null) {
            return $execution->getActualStartAt();
        }

        return $mission->getStartAt();
    }

    /** Premier calcul d'une mission — refuse s'il en existe déjà un actif (voir recalculate()). */
    public function calculate(Mission $mission, User $actor): FinancialCalculation
    {
        $result = null;
        $anomalies = null;

        $this->em->wrapInTransaction(function () use (&$result, &$anomalies, $mission, $actor): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
            $this->assertEligible($mission);

            if ($this->findActiveCalculation($mission) !== null) {
                throw new FinancialCalculationIneligibleException(
                    'A calculation already exists for this mission — use recalculate() to produce a new version.',
                );
            }

            $calculation = $this->buildAndPersist($mission, $actor, version: 1, anomaliesOut: $anomalies);
            if ($calculation === null) {
                return; // anomalies déjà auditées par buildAndPersist ; transaction validée quand même.
            }

            $this->audit->record($mission, $actor, AuditEventType::FINANCIAL_CALCULATION_CREATED, $this->summaryPayload($calculation));
            $this->em->flush();

            $result = $calculation;
        });

        if ($anomalies !== null) {
            throw new FinancialCalculationAnomaliesException($anomalies);
        }

        return $result;
    }

    /**
     * §18 — recalcul explicite tant qu'aucun engagement externe n'existe. L'ancien
     * calcul passe SUPERSEDED (jamais muté, jamais supprimé) ; le nouveau devient
     * CALCULATED. Refuse si le calcul actif est LOCKED (§19).
     */
    public function recalculate(Mission $mission, User $actor): FinancialCalculation
    {
        $result = null;
        $anomalies = null;

        $this->em->wrapInTransaction(function () use (&$result, &$anomalies, $mission, $actor): void {
            $this->em->lock($mission, LockMode::PESSIMISTIC_WRITE);
            $this->assertEligible($mission);

            $current = $this->findActiveCalculation($mission);
            if ($current === null) {
                throw new FinancialCalculationIneligibleException('No existing calculation to recalculate — use calculate() instead.');
            }
            if ($current->getStatus() === FinancialCalculationStatus::LOCKED) {
                throw new FinancialCalculationIneligibleException('This mission\'s calculation is LOCKED and can no longer be recalculated.');
            }

            $new = $this->buildAndPersist($mission, $actor, version: $current->getVersion() + 1, anomaliesOut: $anomalies);
            if ($new === null) {
                return; // anomalies déjà auditées par buildAndPersist ; l'ancien calcul reste actif, inchangé.
            }

            $current->setStatus(FinancialCalculationStatus::SUPERSEDED);
            $current->setSupersededAt(new \DateTimeImmutable());
            $current->setSupersededByCalculation($new);

            $this->audit->record($mission, $actor, AuditEventType::FINANCIAL_CALCULATION_SUPERSEDED, [
                'financialCalculationId' => $current->getId(),
                'version' => $current->getVersion(),
                'supersededByCalculationId' => $new->getId(),
            ]);
            $this->audit->record($mission, $actor, AuditEventType::FINANCIAL_CALCULATION_RECALCULATED, $this->summaryPayload($new) + [
                'previousCalculationId' => $current->getId(),
            ]);
            $this->em->flush();

            $result = $new;
        });

        if ($anomalies !== null) {
            throw new FinancialCalculationAnomaliesException($anomalies);
        }

        return $result;
    }

    public function approve(FinancialCalculation $calculation, User $actor): FinancialCalculation
    {
        if ($calculation->getStatus() !== FinancialCalculationStatus::CALCULATED) {
            throw new FinancialCalculationIneligibleException('Only a CALCULATED calculation can be approved.');
        }

        $calculation->setStatus(FinancialCalculationStatus::APPROVED);
        $calculation->setApprovedAt(new \DateTimeImmutable());
        $calculation->setApprovedBy($actor);

        $this->audit->record($calculation->getMission(), $actor, AuditEventType::FINANCIAL_CALCULATION_APPROVED, $this->summaryPayload($calculation));
        $this->em->flush();

        return $calculation;
    }

    /** §19 — un calcul LOCKED ne peut plus être superseded ; voir aussi MissionEncodingWorkflowService::reopen(). */
    public function lock(FinancialCalculation $calculation, User $actor): FinancialCalculation
    {
        if ($calculation->getStatus() !== FinancialCalculationStatus::APPROVED) {
            throw new FinancialCalculationIneligibleException('Only an APPROVED calculation can be locked.');
        }

        $calculation->setStatus(FinancialCalculationStatus::LOCKED);
        $calculation->setLockedAt(new \DateTimeImmutable());

        $this->audit->record($calculation->getMission(), $actor, AuditEventType::FINANCIAL_CALCULATION_LOCKED, $this->summaryPayload($calculation));
        $this->em->flush();

        return $calculation;
    }

    /** Annulation explicite, jamais un remplacement — voir recalculate() pour ce cas. Jamais sur un calcul LOCKED. */
    public function cancel(FinancialCalculation $calculation, User $actor, ?string $reason = null): FinancialCalculation
    {
        if (!in_array($calculation->getStatus(), [FinancialCalculationStatus::CALCULATED, FinancialCalculationStatus::APPROVED], true)) {
            throw new FinancialCalculationIneligibleException('Only a CALCULATED or APPROVED (not LOCKED) calculation can be cancelled.');
        }

        $calculation->setStatus(FinancialCalculationStatus::CANCELLED);
        $calculation->setCancelledAt(new \DateTimeImmutable());
        $calculation->setCancelledBy($actor);

        $this->audit->record($calculation->getMission(), $actor, AuditEventType::FINANCIAL_CALCULATION_CANCELLED, $this->summaryPayload($calculation) + [
            'reason' => $reason,
        ]);
        $this->em->flush();

        return $calculation;
    }

    /**
     * §20 — exposée pour les consommateurs API/UI qui doivent savoir si une réouverture
     * est possible avant de l'offrir. MissionEncodingWorkflowService::reopen() applique
     * le même contrôle par une requête directe (pas de dépendance à ce service complet
     * pour un seul contrôle — voir son docblock) ; garder les deux synchronisés si la
     * requête évolue.
     */
    public function hasLockedCalculation(Mission $mission): bool
    {
        return $this->em->getRepository(FinancialCalculation::class)->findOneBy([
            'mission' => $mission,
            'status' => FinancialCalculationStatus::LOCKED,
        ]) !== null;
    }

    // ── Construction du calcul ───────────────────────────────────────────────

    private function assertEligible(Mission $mission): void
    {
        if ($mission->getStatus() !== MissionStatus::VALIDATED) {
            throw new FinancialCalculationIneligibleException('Mission must be VALIDATED to be financially calculated.');
        }
        if ($mission->getInstrumentist() === null) {
            throw new FinancialCalculationIneligibleException('Mission has no assigned instrumentist.');
        }
    }

    private function findActiveCalculation(Mission $mission): ?FinancialCalculation
    {
        return $this->em->getRepository(FinancialCalculation::class)->findOneBy(
            ['mission' => $mission, 'status' => [
                FinancialCalculationStatus::CALCULATED,
                FinancialCalculationStatus::APPROVED,
                FinancialCalculationStatus::LOCKED,
            ]],
            ['version' => 'DESC'],
        );
    }

    /**
     * Résout tous les tarifs, collecte TOUTES les anomalies (jamais un échec au premier
     * élément), et soit persiste un calcul complet, soit ne persiste rien du tout (§14
     * du lot). Ne lève jamais d'exception elle-même : EntityManager::wrapInTransaction()
     * ferme l'EntityManager dans son bloc finally dès qu'une exception le traverse (voir
     * EntityManager::wrapInTransaction()), ce qui rendrait tout audit ultérieur
     * impossible sur ce même EntityManager. calculate()/recalculate() restent donc dans
     * leur transaction pour committer l'audit d'échec, puis lèvent
     * FinancialCalculationAnomaliesException APRÈS la fin de wrapInTransaction (transaction
     * déjà validée, EntityManager toujours ouvert).
     *
     * @param FinancialCalculationAnomaly[]|null $anomaliesOut renseigné uniquement en cas d'échec
     */
    private function buildAndPersist(Mission $mission, User $actor, int $version, ?array &$anomaliesOut): ?FinancialCalculation
    {
        $effectiveAt = $this->resolveEffectiveAt($mission);

        /** @var FinancialCalculationAnomaly[] $anomalies */
        $anomalies = [];
        $lineSpecs = [];

        foreach ($mission->getInterventions() as $intervention) {
            $spec = $this->resolveFirmInterventionLine($intervention, $effectiveAt, $anomalies);
            if ($spec !== null) {
                $lineSpecs[] = $spec;
            }
        }

        foreach ($mission->getMaterialLines() as $materialLine) {
            $spec = $this->resolveFirmMaterialLine($materialLine, $effectiveAt, $anomalies);
            if ($spec !== null) {
                $lineSpecs[] = $spec;
            }
        }

        $instrumentist = $mission->getInstrumentist();
        if ($mission->getType() === MissionType::CONSULTATION) {
            $spec = $this->resolveInstrumentistConsultationLine($instrumentist, $effectiveAt, $anomalies);
        } else {
            $spec = $this->resolveInstrumentistHourlyLine($mission, $instrumentist, $effectiveAt, $anomalies);
        }
        if ($spec !== null) {
            $lineSpecs[] = $spec;
        }

        if (count($anomalies) > 0) {
            $anomaliesOut = $anomalies;

            $this->audit->record($mission, $actor, AuditEventType::FINANCIAL_CALCULATION_FAILED, [
                'version' => $version,
                'effectiveAt' => $effectiveAt->format('Y-m-d'),
                'anomalies' => array_map(static fn (FinancialCalculationAnomaly $a) => $a->toArray(), $anomalies),
            ]);
            $this->em->flush();

            return null;
        }

        $calculation = new FinancialCalculation();
        $calculation->setMission($mission);
        $calculation->setVersion($version);
        $calculation->setStatus(FinancialCalculationStatus::CALCULATED);
        $calculation->setEffectiveAt($effectiveAt);
        $calculation->setCalculatedAt(new \DateTimeImmutable());
        $calculation->setCalculatedBy($actor);

        foreach ($lineSpecs as $spec) {
            $line = $this->hydrateLine($spec);
            $calculation->addLine($line);
            $this->em->persist($line);
        }

        $this->em->persist($calculation);
        $this->em->flush();

        return $calculation;
    }

    /** @param FinancialCalculationAnomaly[] &$anomalies */
    private function resolveFirmInterventionLine(MissionIntervention $intervention, \DateTimeImmutable $effectiveAt, array &$anomalies): ?array
    {
        $firm = $intervention->getPrimaryFirm();
        $interventionType = $intervention->getInterventionType();

        if ($firm === null) {
            $anomalies[] = new FinancialCalculationAnomaly(
                'MISSING_PRIMARY_FIRM',
                sprintf('Intervention #%d has no primary firm.', $intervention->getId()),
                ['missionInterventionId' => $intervention->getId()],
            );
            return null;
        }
        if ($interventionType === null) {
            $anomalies[] = new FinancialCalculationAnomaly(
                'MISSING_INTERVENTION_TYPE',
                sprintf('Intervention #%d has no InterventionType (legacy pre-Lot5 row).', $intervention->getId()),
                ['missionInterventionId' => $intervention->getId()],
            );
            return null;
        }

        $rule = $this->pricingRuleResolver->resolveInterventionFee($firm, $interventionType, $effectiveAt);
        if ($rule === null) {
            $anomalies[] = new FinancialCalculationAnomaly(
                'MISSING_FIRM_INTERVENTION_RATE',
                sprintf('No active INTERVENTION_FEE PricingRule for firm=%d, interventionType=%d at %s.', $firm->getId(), $interventionType->getId(), $effectiveAt->format('Y-m-d')),
                ['missionInterventionId' => $intervention->getId(), 'firmId' => $firm->getId(), 'interventionTypeId' => $interventionType->getId()],
            );
            return null;
        }

        $unitAmount = $rule->getUnitPrice();
        $totalAmount = $this->roundMoney((float) $unitAmount);

        return [
            'beneficiaryType' => FinancialBeneficiaryType::FIRM,
            'beneficiaryFirm' => $firm,
            'beneficiaryInstrumentist' => null,
            'lineType' => FinancialLineType::FIRM_INTERVENTION_FEE,
            'sourceType' => FinancialLineSourceType::MISSION_INTERVENTION,
            'missionIntervention' => $intervention,
            'materialLine' => null,
            'pricingRule' => $rule,
            'instrumentistRate' => null,
            'descriptionSnapshot' => sprintf('[%s] %s', $intervention->getCode(), $intervention->getLabel()),
            'quantity' => '1.0000',
            'durationMinutes' => null,
            'unitAmount' => $unitAmount,
            'totalAmount' => $totalAmount,
            'currency' => $rule->getCurrency(),
            'effectiveAt' => $effectiveAt,
            'snapshot' => [
                'pricingRuleId' => $rule->getId(),
                'ruleType' => $rule->getRuleType()->value,
                'validFrom' => $rule->getValidFrom()?->format('Y-m-d'),
                'validTo' => $rule->getValidTo()?->format('Y-m-d'),
                'firmId' => $firm->getId(),
                'firmNameSnapshot' => $firm->getName(),
                'interventionTypeId' => $interventionType->getId(),
                'interventionCodeSnapshot' => $intervention->getCode(),
                'interventionLabelSnapshot' => $intervention->getLabel(),
            ],
        ];
    }

    /** @param FinancialCalculationAnomaly[] &$anomalies */
    private function resolveFirmMaterialLine(MaterialLine $materialLine, \DateTimeImmutable $effectiveAt, array &$anomalies): ?array
    {
        $item = $materialLine->getItem();
        $firm = $item->getFirm();

        $rule = $this->pricingRuleResolver->resolveMaterialFee($item, $effectiveAt);
        if ($rule === null) {
            $anomalies[] = new FinancialCalculationAnomaly(
                'MISSING_FIRM_MATERIAL_RATE',
                sprintf('No active MATERIAL_FEE PricingRule for materialItem=%d at %s.', $item->getId(), $effectiveAt->format('Y-m-d')),
                ['materialLineId' => $materialLine->getId(), 'materialItemId' => $item->getId(), 'firmId' => $firm->getId()],
            );
            return null;
        }

        $quantity = $materialLine->getQuantity();
        $unitAmount = $rule->getUnitPrice();
        $totalAmount = $this->roundMoney((float) $quantity * (float) $unitAmount);

        return [
            'beneficiaryType' => FinancialBeneficiaryType::FIRM,
            'beneficiaryFirm' => $firm,
            'beneficiaryInstrumentist' => null,
            'lineType' => FinancialLineType::FIRM_MATERIAL_FEE,
            'sourceType' => FinancialLineSourceType::MATERIAL_LINE,
            'missionIntervention' => null,
            'materialLine' => $materialLine,
            'pricingRule' => $rule,
            'instrumentistRate' => null,
            'descriptionSnapshot' => sprintf('%s — %s (Réf: %s)', $item->getLabel(), $firm->getName(), $item->getReferenceCode() ?? '—'),
            'quantity' => $quantity,
            'durationMinutes' => null,
            'unitAmount' => $unitAmount,
            'totalAmount' => $totalAmount,
            'currency' => $rule->getCurrency(),
            'effectiveAt' => $effectiveAt,
            'snapshot' => [
                'pricingRuleId' => $rule->getId(),
                'ruleType' => $rule->getRuleType()->value,
                'validFrom' => $rule->getValidFrom()?->format('Y-m-d'),
                'validTo' => $rule->getValidTo()?->format('Y-m-d'),
                'firmId' => $firm->getId(),
                'firmNameSnapshot' => $firm->getName(),
                'materialItemId' => $item->getId(),
                'materialNameSnapshot' => $item->getLabel(),
                'materialFirmSnapshot' => $firm->getName(),
            ],
        ];
    }

    /**
     * §11/§10 du lot — durée exclusivement issue de MissionExecutionService::
     * resolveEffectiveDuration(), jamais dupliquée. Politique d'arrondi : heures
     * arrondies à 4 décimales, montant total arrondi à 2 décimales — une seule fois,
     * jamais un arrondi en cascade.
     *
     * @param FinancialCalculationAnomaly[] &$anomalies
     */
    private function resolveInstrumentistHourlyLine(Mission $mission, User $instrumentist, \DateTimeImmutable $effectiveAt, array &$anomalies): ?array
    {
        $duration = $this->missionExecutionService->resolveEffectiveDuration($mission);
        if ($duration->minutes <= 0) {
            $anomalies[] = new FinancialCalculationAnomaly(
                'INVALID_EFFECTIVE_DURATION',
                sprintf('Effective duration is zero or negative for mission #%d.', $mission->getId()),
                ['missionId' => $mission->getId(), 'minutes' => $duration->minutes],
            );
            return null;
        }

        $rate = $this->instrumentistRateResolver->resolve($instrumentist, InstrumentistRateType::HOURLY_RATE, $effectiveAt);
        if ($rate === null) {
            $anomalies[] = new FinancialCalculationAnomaly(
                'MISSING_INSTRUMENTIST_RATE',
                sprintf('No active HOURLY_RATE InstrumentistRate for instrumentist=%d at %s.', $instrumentist->getId(), $effectiveAt->format('Y-m-d')),
                ['instrumentistId' => $instrumentist->getId(), 'rateType' => InstrumentistRateType::HOURLY_RATE->value],
            );
            return null;
        }

        $hours = round($duration->minutes / 60, 4);
        $unitAmount = $rate->getAmount();
        $totalAmount = $this->roundMoney($hours * (float) $unitAmount);

        return [
            'beneficiaryType' => FinancialBeneficiaryType::INSTRUMENTIST,
            'beneficiaryFirm' => null,
            'beneficiaryInstrumentist' => $instrumentist,
            'lineType' => FinancialLineType::INSTRUMENTIST_HOURLY,
            'sourceType' => FinancialLineSourceType::MISSION_EXECUTION,
            'missionIntervention' => null,
            'materialLine' => null,
            'pricingRule' => null,
            'instrumentistRate' => $rate,
            'descriptionSnapshot' => sprintf('Prestation horaire — %s', $this->displayName($instrumentist)),
            'quantity' => number_format($hours, 4, '.', ''),
            'durationMinutes' => $duration->minutes,
            'unitAmount' => $unitAmount,
            'totalAmount' => $totalAmount,
            'currency' => $rate->getCurrency(),
            'effectiveAt' => $effectiveAt,
            'snapshot' => [
                'instrumentistRateId' => $rate->getId(),
                'rateType' => $rate->getRateType()->value,
                'validFrom' => $rate->getValidFrom()->format('Y-m-d'),
                'validTo' => $rate->getValidTo()?->format('Y-m-d'),
                'instrumentistId' => $instrumentist->getId(),
                'instrumentistNameSnapshot' => $this->displayName($instrumentist),
                'durationMinutes' => $duration->minutes,
                'effectiveDurationSource' => $duration->source->value,
            ],
        ];
    }

    /** @param FinancialCalculationAnomaly[] &$anomalies */
    private function resolveInstrumentistConsultationLine(User $instrumentist, \DateTimeImmutable $effectiveAt, array &$anomalies): ?array
    {
        $rate = $this->instrumentistRateResolver->resolve($instrumentist, InstrumentistRateType::CONSULTATION_FEE, $effectiveAt);
        if ($rate === null) {
            $anomalies[] = new FinancialCalculationAnomaly(
                'MISSING_INSTRUMENTIST_RATE',
                sprintf('No active CONSULTATION_FEE InstrumentistRate for instrumentist=%d at %s.', $instrumentist->getId(), $effectiveAt->format('Y-m-d')),
                ['instrumentistId' => $instrumentist->getId(), 'rateType' => InstrumentistRateType::CONSULTATION_FEE->value],
            );
            return null;
        }

        $unitAmount = $rate->getAmount();
        $totalAmount = $this->roundMoney((float) $unitAmount);

        return [
            'beneficiaryType' => FinancialBeneficiaryType::INSTRUMENTIST,
            'beneficiaryFirm' => null,
            'beneficiaryInstrumentist' => $instrumentist,
            'lineType' => FinancialLineType::INSTRUMENTIST_CONSULTATION_FEE,
            'sourceType' => FinancialLineSourceType::MISSION_EXECUTION,
            'missionIntervention' => null,
            'materialLine' => null,
            'pricingRule' => null,
            'instrumentistRate' => $rate,
            'descriptionSnapshot' => sprintf('Consultation — %s', $this->displayName($instrumentist)),
            'quantity' => '1.0000',
            'durationMinutes' => null,
            'unitAmount' => $unitAmount,
            'totalAmount' => $totalAmount,
            'currency' => $rate->getCurrency(),
            'effectiveAt' => $effectiveAt,
            'snapshot' => [
                'instrumentistRateId' => $rate->getId(),
                'rateType' => $rate->getRateType()->value,
                'validFrom' => $rate->getValidFrom()->format('Y-m-d'),
                'validTo' => $rate->getValidTo()?->format('Y-m-d'),
                'instrumentistId' => $instrumentist->getId(),
                'instrumentistNameSnapshot' => $this->displayName($instrumentist),
            ],
        ];
    }

    /** @param array<string, mixed> $spec */
    private function hydrateLine(array $spec): FinancialCalculationLine
    {
        $line = new FinancialCalculationLine();
        $line->setBeneficiaryType($spec['beneficiaryType']);
        $line->setBeneficiaryFirm($spec['beneficiaryFirm']);
        $line->setBeneficiaryInstrumentist($spec['beneficiaryInstrumentist']);
        $line->setLineType($spec['lineType']);
        $line->setSourceType($spec['sourceType']);
        $line->setMissionIntervention($spec['missionIntervention']);
        $line->setMaterialLine($spec['materialLine']);
        $line->setPricingRule($spec['pricingRule']);
        $line->setInstrumentistRate($spec['instrumentistRate']);
        $line->setDescriptionSnapshot($spec['descriptionSnapshot']);
        $line->setQuantity($spec['quantity']);
        $line->setDurationMinutes($spec['durationMinutes']);
        $line->setUnitAmount($spec['unitAmount']);
        $line->setTotalAmount($spec['totalAmount']);
        $line->setCurrency($spec['currency']);
        $line->setEffectiveAt($spec['effectiveAt']);
        $line->setSnapshot($spec['snapshot']);
        return $line;
    }

    /** §11 — politique d'arrondi unique : 2 décimales, une seule fois, jamais en cascade. */
    private function roundMoney(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }

    private function displayName(User $user): string
    {
        $name = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? ''));
        return $name !== '' ? $name : $user->getEmail();
    }

    /** §25 du lot — contenu minimal de chaque événement FINANCIAL_CALCULATION_*. */
    private function summaryPayload(FinancialCalculation $calculation): array
    {
        return [
            'financialCalculationId' => $calculation->getId(),
            'version' => $calculation->getVersion(),
            'status' => $calculation->getStatus()->value,
            'effectiveAt' => $calculation->getEffectiveAt()?->format('Y-m-d'),
            'totalsByCurrency' => $calculation->totalsByCurrency(),
        ];
    }
}
