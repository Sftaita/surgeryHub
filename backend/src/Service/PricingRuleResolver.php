<?php

namespace App\Service;

use App\Entity\ChoiceOption;
use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Enum\PricingRuleType;
use App\Exception\PricingRuleConflictException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur de résolution des règles tarifaires — la seule porte d'entrée du calcul
 * financier vers PricingRule. Ne lit jamais FirmServiceOffering ni SuggestedMaterial
 * (voir docs/decisions.md — l'invariant central de tout ce module).
 *
 * Résolution déterministe : jamais de choix silencieux entre plusieurs règles actives
 * qui se chevaucheraient — si l'anti-chevauchement (hasOverlap, appelé à l'écriture) a
 * été respecté, au plus une règle peut jamais matcher une date donnée. Si ce n'est pas
 * le cas (données écrites hors application), on lève PricingRuleConflictException
 * (exception MÉTIER, D-138) plutôt qu'on ne devine : anomalie explicite dans le moteur,
 * 409 ailleurs — jamais un 500.
 */
final class PricingRuleResolver
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * $choiceOption : discriminant optionnel (tarification firme conditionnée à un choix
     * obligatoire) — `null` résout le forfait standard (comportement inchangé, immense
     * majorité des prestations), une ChoiceOption ne résout QUE les règles posées pour
     * cette option précise (jamais un repli silencieux vers une règle sans discriminant).
     */
    public function resolveInterventionFee(Firm $firm, InterventionType $interventionType, \DateTimeImmutable $date, ?ChoiceOption $choiceOption = null): ?PricingRule
    {
        $matching = $this->matchingRules($firm, PricingRuleType::INTERVENTION_FEE, $date, interventionType: $interventionType, choiceOption: $choiceOption);

        if (count($matching) > 1) {
            throw new PricingRuleConflictException($matching, $date);
        }

        return $matching[0] ?? null;
    }

    public function resolveMaterialFee(MaterialItem $materialItem, \DateTimeImmutable $date): ?PricingRule
    {
        return self::resolveMaterialFeeAmong($this->materialFeeRulesFor($materialItem), $date);
    }

    /**
     * Même résolution que resolveMaterialFee(), sur les règles de l'article déjà chargées
     * (materialFeeRulesFor() ou materialFeeRulesByItem()) : actives, couvrant $date, conflit
     * si plusieurs. Évite une requête par article quand on parcourt tout le catalogue (D-142).
     *
     * @param PricingRule[] $rules toutes les règles MATERIAL_FEE de l'article (actives ou non)
     */
    public static function resolveMaterialFeeAmong(array $rules, \DateTimeImmutable $date): ?PricingRule
    {
        $matching = self::coveringRules($rules, $date);

        if (count($matching) > 1) {
            throw new PricingRuleConflictException($matching, $date);
        }

        return $matching[0] ?? null;
    }

    /**
     * Toutes les règles de la cible (actives ou non, quelle que soit leur période), triées
     * par début de validité — jamais utilisées pour résoudre un montant : seulement pour
     * expliquer une absence de tarif (« un tarif existe, mais pas à cette date » ≠ « aucun
     * tarif n'a jamais été configuré »).
     *
     * @return PricingRule[]
     */
    public function interventionFeeRulesFor(Firm $firm, InterventionType $interventionType, ?ChoiceOption $choiceOption = null): array
    {
        return $this->targetRules($firm, PricingRuleType::INTERVENTION_FEE, activeOnly: false, interventionType: $interventionType, choiceOption: $choiceOption);
    }

    /** @return PricingRule[] voir interventionFeeRulesFor() */
    public function materialFeeRulesFor(MaterialItem $materialItem): array
    {
        return $this->targetRules($materialItem->getFirm(), PricingRuleType::MATERIAL_FEE, activeOnly: false, materialItem: $materialItem);
    }

    /**
     * Instantané stable de règles (même forme que PricingRuleConflictException::rulesSnapshot(),
     * plus `active`) — repris tel quel dans un contexte audité.
     *
     * @param PricingRule[] $rules
     * @return list<array{id: ?int, unitPrice: ?string, currency: ?string, validFrom: ?string, validTo: ?string, active: bool}>
     */
    public static function snapshotRules(array $rules): array
    {
        return array_map(static fn (PricingRule $r) => [
            'id' => $r->getId(),
            'unitPrice' => $r->getUnitPrice(),
            'currency' => $r->getCurrency(),
            'validFrom' => $r->getValidFrom()?->format('Y-m-d'),
            'validTo' => $r->getValidTo()?->format('Y-m-d'),
            'active' => $r->isActive(),
        ], array_values($rules));
    }

    /**
     * Vrai si $candidate chevauche, en date, une autre règle active déjà posée sur la
     * même cible (même firme + même type d'intervention, ou même matériel). Appelé par
     * le contrôleur avant toute création/mise à jour — un chevauchement doit être un
     * refus bloquant, jamais un simple avertissement (voir docs/decisions.md).
     */
    public function hasOverlap(PricingRule $candidate): bool
    {
        $qb = $this->em->getRepository(PricingRule::class)->createQueryBuilder('r')
            ->andWhere('r.firm = :firm')
            ->andWhere('r.ruleType = :type')
            ->andWhere('r.active = true')
            ->setParameter('firm', $candidate->getFirm())
            ->setParameter('type', $candidate->getRuleType());

        if ($candidate->getRuleType() === PricingRuleType::INTERVENTION_FEE) {
            $qb->andWhere('r.interventionType = :it')->setParameter('it', $candidate->getInterventionType());
            if ($candidate->getChoiceOption() !== null) {
                $qb->andWhere('r.choiceOption = :co')->setParameter('co', $candidate->getChoiceOption());
            } else {
                $qb->andWhere('r.choiceOption IS NULL');
            }
        } else {
            $qb->andWhere('r.materialItem = :mi')->setParameter('mi', $candidate->getMaterialItem());
        }

        if ($candidate->getId() !== null) {
            $qb->andWhere('r.id != :selfId')->setParameter('selfId', $candidate->getId());
        }

        /** @var PricingRule[] $others */
        $others = $qb->getQuery()->getResult();

        foreach ($others as $other) {
            if ($candidate->overlapsWith($other)) {
                return true;
            }
        }

        return false;
    }

    /** @return PricingRule[] */
    private function matchingRules(
        ?Firm $firm,
        PricingRuleType $type,
        \DateTimeImmutable $date,
        ?InterventionType $interventionType = null,
        ?MaterialItem $materialItem = null,
        ?ChoiceOption $choiceOption = null,
    ): array {
        $candidates = $this->targetRules($firm, $type, activeOnly: true, interventionType: $interventionType, materialItem: $materialItem, choiceOption: $choiceOption);

        return self::coveringRules($candidates, $date);
    }

    /**
     * Règles actives couvrant $date, triées par id — le seul critère de sélection, partagé
     * par la résolution unitaire et la résolution sur règles préchargées.
     *
     * @param PricingRule[] $rules
     * @return PricingRule[]
     */
    private static function coveringRules(array $rules, \DateTimeImmutable $date): array
    {
        $matching = array_values(array_filter($rules, static fn (PricingRule $r) => $r->isActive() && $r->coversDate($date)));
        usort($matching, static fn (PricingRule $a, PricingRule $b) => $a->getId() <=> $b->getId());

        return $matching;
    }

    /**
     * Toutes les règles MATERIAL_FEE (actives ou non) indexées par id d'article, en UNE
     * requête, avec le même périmètre (firme de la règle = firme de l'article) et le même
     * ordre que materialFeeRulesFor() (D-142, vue de régularisation du catalogue).
     *
     * @return array<int, PricingRule[]>
     */
    public function materialFeeRulesByItem(): array
    {
        /** @var PricingRule[] $rules */
        $rules = $this->em->getRepository(PricingRule::class)->createQueryBuilder('r')
            ->join('r.materialItem', 'mi')
            ->andWhere('r.ruleType = :type')
            ->andWhere('r.firm = mi.firm')
            ->setParameter('type', PricingRuleType::MATERIAL_FEE)
            ->orderBy('r.validFrom', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byItem = [];
        foreach ($rules as $rule) {
            $byItem[$rule->getMaterialItem()->getId()][] = $rule;
        }

        return $byItem;
    }

    /** @return PricingRule[] */
    private function targetRules(
        ?Firm $firm,
        PricingRuleType $type,
        bool $activeOnly,
        ?InterventionType $interventionType = null,
        ?MaterialItem $materialItem = null,
        ?ChoiceOption $choiceOption = null,
    ): array {
        if ($firm === null) {
            return [];
        }

        $qb = $this->em->getRepository(PricingRule::class)->createQueryBuilder('r')
            ->andWhere('r.firm = :firm')
            ->andWhere('r.ruleType = :type')
            ->setParameter('firm', $firm)
            ->setParameter('type', $type)
            ->orderBy('r.validFrom', 'ASC')
            ->addOrderBy('r.id', 'ASC');
        if ($activeOnly) {
            $qb->andWhere('r.active = true');
        }

        if ($type === PricingRuleType::INTERVENTION_FEE) {
            $qb->andWhere('r.interventionType = :it')->setParameter('it', $interventionType);
            if ($choiceOption !== null) {
                $qb->andWhere('r.choiceOption = :co')->setParameter('co', $choiceOption);
            } else {
                $qb->andWhere('r.choiceOption IS NULL');
            }
        } else {
            $qb->andWhere('r.materialItem = :mi')->setParameter('mi', $materialItem);
        }

        /** @var PricingRule[] $rules */
        $rules = $qb->getQuery()->getResult();

        return $rules;
    }
}
