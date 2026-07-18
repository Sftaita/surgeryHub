<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\PricingRuleType;
use App\Exception\PricingRuleImmutableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Exécution & Valorisation, Lot 2 (D-072) — seul point d'entrée métier pour toute
 * mutation de PricingRule. Les contrôleurs ne mutent jamais l'entité directement.
 *
 * Append-only : un tarif déjà applicable ou passé n'est jamais réécrit en place (ni le
 * montant, ni la devise, ni le périmètre, ni validFrom, ni la nature du tarif). Les
 * seules opérations possibles sur une règle déjà commencée sont replaceCurrentRuleFrom()
 * (ferme l'ancienne période + ouvre la nouvelle, atomique) — jamais une mutation de la
 * ligne existante.
 *
 * Bas niveau (verrouillage pessimiste déterministe, anti-chevauchement) délégué à
 * PricingRuleWriteService — inchangé, ses garanties de concurrence restent celles
 * prouvées par PricingRuleConcurrencyTest. Ce service orchestre les opérations métier
 * par-dessus, sans dupliquer la logique de verrouillage.
 */
final class PricingRuleVersioningService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PricingRuleWriteService $writeService,
        private readonly PricingRuleResolver $resolver,
        private readonly AuditService $audit,
    ) {}

    /**
     * Première règle jamais posée sur cette cible (ou première règle après une période
     * sans couverture) — validFrom peut être aujourd'hui, dans le passé (rattrapage
     * documenté) ou dans le futur. Identique mécaniquement à scheduleRule() ; distingué
     * uniquement pour la lisibilité de l'intention et l'événement d'audit émis.
     */
    public function createInitialRule(
        Firm $firm,
        PricingRuleType $ruleType,
        ?InterventionType $interventionType,
        ?MaterialItem $materialItem,
        string $amount,
        string $currency,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
        User $actor,
    ): PricingRule {
        $rule = $this->persistNewRule($firm, $ruleType, $interventionType, $materialItem, $amount, $currency, $validFrom, $validTo);

        $this->audit->recordGlobal($actor, AuditEventType::PRICING_RULE_CREATED, $this->scopePayload($rule) + [
            'amount' => $amount, 'currency' => $rule->getCurrency(),
            'validFrom' => $validFrom?->format('Y-m-d'), 'validTo' => $validTo?->format('Y-m-d'),
        ]);
        $this->em->flush();

        return $rule;
    }

    /** Règle dont validFrom est strictement future — pré-provisionnée avant d'entrer en vigueur. */
    public function scheduleRule(
        Firm $firm,
        PricingRuleType $ruleType,
        ?InterventionType $interventionType,
        ?MaterialItem $materialItem,
        string $amount,
        string $currency,
        \DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
        User $actor,
    ): PricingRule {
        if ($validFrom <= new \DateTimeImmutable('today')) {
            throw new PricingRuleImmutableException('scheduleRule() exige une date de début strictement future — utilisez createInitialRule() sinon.');
        }

        $rule = $this->persistNewRule($firm, $ruleType, $interventionType, $materialItem, $amount, $currency, $validFrom, $validTo);

        $this->audit->recordGlobal($actor, AuditEventType::PRICING_RULE_SCHEDULED, $this->scopePayload($rule) + [
            'amount' => $amount, 'currency' => $rule->getCurrency(),
            'validFrom' => $validFrom->format('Y-m-d'), 'validTo' => $validTo?->format('Y-m-d'),
        ]);
        $this->em->flush();

        return $rule;
    }

    /**
     * Le cas principal : remplacer le tarif actuellement en vigueur à partir d'une date
     * donnée. Ferme l'ancienne règle (validTo = $effectiveFrom) ET ouvre la nouvelle
     * (validFrom = $effectiveFrom, validTo = null) dans UNE SEULE transaction — jamais
     * d'état intermédiaire avec deux règles ouvertes, aucune règle active, ou un
     * chevauchement (§7 du lot). PricingRuleWriteService::update()/create() ouvrent
     * chacun leur propre wrapInTransaction() ; Doctrine DBAL imbrique correctement via
     * son compteur de nesting — le commit réel n'a lieu qu'à la sortie de CETTE méthode.
     */
    public function replaceCurrentRuleFrom(
        PricingRule $currentRule,
        string $newAmount,
        string $newCurrency,
        \DateTimeImmutable $effectiveFrom,
        User $actor,
    ): PricingRule {
        $this->assertCurrentlyActive($currentRule);
        $this->assertPositiveAmount($newAmount);
        $this->assertValidCurrency($newCurrency);

        $today = new \DateTimeImmutable('today');
        if ($effectiveFrom < $today) {
            throw new PricingRuleImmutableException("La date d'effet d'un remplacement ne peut jamais être dans le passé.");
        }
        if ($currentRule->getValidFrom() !== null && $effectiveFrom <= $currentRule->getValidFrom()) {
            throw new PricingRuleImmutableException("La date d'effet doit être strictement postérieure au début de la règle actuelle.");
        }

        $oldAmount = $currentRule->getUnitPrice();
        $oldCurrency = $currentRule->getCurrency();

        $newRule = new PricingRule();
        $newRule->setFirm($currentRule->getFirm());
        $newRule->setRuleType($currentRule->getRuleType());
        if ($currentRule->getInterventionType() !== null) {
            $newRule->setInterventionType($currentRule->getInterventionType());
        }
        if ($currentRule->getMaterialItem() !== null) {
            $newRule->setMaterialItem($currentRule->getMaterialItem());
        }
        $newRule->setUnitPrice($newAmount);
        $newRule->setCurrency($newCurrency);
        $newRule->setValidFrom($effectiveFrom);
        $newRule->setValidTo(null);
        $newRule->setActive(true);

        $created = null;
        $this->em->wrapInTransaction(function () use (&$created, $currentRule, $newRule, $effectiveFrom): void {
            $currentRule->setValidTo($effectiveFrom);
            $this->writeService->update($currentRule);
            $created = $this->writeService->create($newRule);
        });

        $this->audit->recordGlobal($actor, AuditEventType::PRICING_RULE_REPLACED, $this->scopePayload($currentRule) + [
            'previousPricingRuleId' => $currentRule->getId(),
            'newPricingRuleId' => $created->getId(),
            'oldAmount' => $oldAmount, 'newAmount' => $newAmount,
            'oldCurrency' => $oldCurrency, 'newCurrency' => $created->getCurrency(),
            'effectiveFrom' => $effectiveFrom->format('Y-m-d'),
        ]);
        $this->em->flush();

        return $created;
    }

    /**
     * Corrige une règle qui n'est pas encore entrée en vigueur (validFrom > aujourd'hui).
     * Seule opération de mutation directe encore permise — parce que la règle n'a
     * jamais été applicable, aucun historique à préserver.
     */
    public function updateFutureRule(
        PricingRule $rule,
        ?string $amount,
        ?string $currency,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
        User $actor,
    ): PricingRule {
        $this->assertNotYetApplicable($rule);

        $old = [
            'amount' => $rule->getUnitPrice(), 'currency' => $rule->getCurrency(),
            'validFrom' => $rule->getValidFrom()?->format('Y-m-d'), 'validTo' => $rule->getValidTo()?->format('Y-m-d'),
        ];

        if ($amount !== null) {
            $this->assertPositiveAmount($amount);
            $rule->setUnitPrice($amount);
        }
        if ($currency !== null) {
            $this->assertValidCurrency($currency);
            $rule->setCurrency($currency);
        }
        if ($validFrom !== null) {
            if ($validFrom <= new \DateTimeImmutable('today')) {
                throw new PricingRuleImmutableException('Une règle future ne peut pas être déplacée à une date déjà applicable — annulez-la et créez-en une nouvelle si nécessaire.');
            }
            $rule->setValidFrom($validFrom);
        }
        if ($validTo !== null) {
            $rule->setValidTo($validTo);
        }

        $updated = $this->writeService->update($rule);

        $this->audit->recordGlobal($actor, AuditEventType::PRICING_RULE_FUTURE_UPDATED, $this->scopePayload($rule) + [
            'old' => $old,
            'new' => [
                'amount' => $rule->getUnitPrice(), 'currency' => $rule->getCurrency(),
                'validFrom' => $rule->getValidFrom()?->format('Y-m-d'), 'validTo' => $rule->getValidTo()?->format('Y-m-d'),
            ],
        ]);
        $this->em->flush();

        return $updated;
    }

    /** Annule une règle future qui n'a jamais été applicable — suppression physique légitime uniquement ici. */
    public function cancelFutureRule(PricingRule $rule, User $actor): void
    {
        $this->assertNotYetApplicable($rule);

        $payload = $this->scopePayload($rule) + [
            'amount' => $rule->getUnitPrice(), 'currency' => $rule->getCurrency(),
            'validFrom' => $rule->getValidFrom()?->format('Y-m-d'), 'validTo' => $rule->getValidTo()?->format('Y-m-d'),
        ];

        $this->writeService->delete($rule);

        $this->audit->recordGlobal($actor, AuditEventType::PRICING_RULE_FUTURE_CANCELLED, $payload);
        $this->em->flush();
    }

    /**
     * Résolution en lecture seule, date explicite obligatoire — jamais now() implicite
     * (§8 du lot). Point d'entrée que le futur FinancialCalculation appellera.
     */
    public function resolveAt(
        Firm $firm,
        PricingRuleType $ruleType,
        ?InterventionType $interventionType,
        ?MaterialItem $materialItem,
        \DateTimeImmutable $effectiveAt,
    ): ?PricingRule {
        if ($ruleType === PricingRuleType::INTERVENTION_FEE) {
            if ($interventionType === null) {
                throw new \InvalidArgumentException('interventionType requis pour résoudre une règle INTERVENTION_FEE.');
            }
            return $this->resolver->resolveInterventionFee($firm, $interventionType, $effectiveAt);
        }

        if ($materialItem === null) {
            throw new \InvalidArgumentException('materialItem requis pour résoudre une règle MATERIAL_FEE.');
        }
        return $this->resolver->resolveMaterialFee($materialItem, $effectiveAt);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function persistNewRule(
        Firm $firm,
        PricingRuleType $ruleType,
        ?InterventionType $interventionType,
        ?MaterialItem $materialItem,
        string $amount,
        string $currency,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $validTo,
    ): PricingRule {
        $this->assertPositiveAmount($amount);
        $this->assertValidCurrency($currency);

        if ($validFrom !== null && $validTo !== null && $validTo <= $validFrom) {
            throw new PricingRuleImmutableException('validTo doit être strictement postérieure à validFrom.');
        }

        $rule = new PricingRule();
        $rule->setFirm($firm);
        $rule->setRuleType($ruleType);
        if ($ruleType === PricingRuleType::INTERVENTION_FEE) {
            if ($interventionType === null) {
                throw new \InvalidArgumentException('interventionType requis pour une règle INTERVENTION_FEE.');
            }
            $rule->setInterventionType($interventionType);
        } else {
            if ($materialItem === null) {
                throw new \InvalidArgumentException('materialItem requis pour une règle MATERIAL_FEE.');
            }
            $rule->setMaterialItem($materialItem);
        }
        $rule->setUnitPrice($amount);
        $rule->setCurrency($currency);
        $rule->setValidFrom($validFrom);
        $rule->setValidTo($validTo);
        $rule->setActive(true);

        return $this->writeService->create($rule);
    }

    /** Dès que validFrom <= aujourd'hui, la règle appartient à l'historique (§14 du lot). */
    private function assertCurrentlyActive(PricingRule $rule): void
    {
        $today = new \DateTimeImmutable('today');
        $validFrom = $rule->getValidFrom();
        $validTo = $rule->getValidTo();

        if ($validFrom !== null && $validFrom > $today) {
            throw new PricingRuleImmutableException('Cette règle est future, pas encore applicable — utilisez updateFutureRule() ou cancelFutureRule().');
        }
        if ($validTo !== null && $validTo <= $today) {
            throw new PricingRuleImmutableException('Cette règle est déjà terminée — seule une règle actuellement active peut être remplacée.');
        }
    }

    private function assertNotYetApplicable(PricingRule $rule): void
    {
        $validFrom = $rule->getValidFrom();
        if ($validFrom === null || $validFrom <= new \DateTimeImmutable('today')) {
            throw new PricingRuleImmutableException('Seule une règle future (validFrom strictement postérieure à aujourd\'hui) peut être modifiée ou annulée directement.');
        }
    }

    private function assertPositiveAmount(string $amount): void
    {
        if ((float) $amount < 0) {
            throw new \InvalidArgumentException('Le montant doit être positif ou nul.');
        }
    }

    private function assertValidCurrency(string $currency): void
    {
        if (!preg_match('/^[A-Z]{3}$/', strtoupper($currency))) {
            throw new \InvalidArgumentException('Devise invalide (code ISO 4217 à 3 lettres attendu).');
        }
    }

    /** @return array<string, mixed> */
    private function scopePayload(PricingRule $rule): array
    {
        return [
            'pricingRuleId' => $rule->getId(),
            'firmId' => $rule->getFirm()?->getId(),
            'firmName' => $rule->getFirm()?->getName(),
            'ruleType' => $rule->getRuleType()?->value,
            'interventionTypeId' => $rule->getInterventionType()?->getId(),
            'interventionTypeCode' => $rule->getInterventionType()?->getCode(),
            'materialItemId' => $rule->getMaterialItem()?->getId(),
            'materialItemLabel' => $rule->getMaterialItem()?->getLabel(),
        ];
    }
}
