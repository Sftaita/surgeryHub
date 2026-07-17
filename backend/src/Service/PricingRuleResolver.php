<?php

namespace App\Service;

use App\Entity\Firm;
use App\Entity\InterventionType;
use App\Entity\MaterialItem;
use App\Entity\PricingRule;
use App\Enum\PricingRuleType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur de résolution des règles tarifaires — la seule porte d'entrée du calcul
 * financier vers PricingRule. Ne lit jamais FirmServiceOffering ni SuggestedMaterial
 * (voir docs/decisions.md — l'invariant central de tout ce module).
 *
 * Résolution déterministe : jamais de choix silencieux entre plusieurs règles actives
 * qui se chevauperaient — si l'anti-chevauchement (hasOverlap, appelé à l'écriture) a
 * été respecté, au plus une règle peut jamais matcher une date donnée. Si ce n'est pas
 * le cas (données corrompues, contrainte contournée), on lève plutôt qu'on ne devine.
 */
final class PricingRuleResolver
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function resolveInterventionFee(Firm $firm, InterventionType $interventionType, \DateTimeImmutable $date): ?PricingRule
    {
        $matching = $this->matchingRules($firm, PricingRuleType::INTERVENTION_FEE, $date, interventionType: $interventionType);

        if (count($matching) > 1) {
            throw new \LogicException(sprintf(
                'Plusieurs PricingRule INTERVENTION_FEE actives se chevauchent pour firm=%d, interventionType=%d à la date %s.',
                $firm->getId(), $interventionType->getId(), $date->format('Y-m-d'),
            ));
        }

        return $matching[0] ?? null;
    }

    public function resolveMaterialFee(MaterialItem $materialItem, \DateTimeImmutable $date): ?PricingRule
    {
        $firm = $materialItem->getFirm();
        $matching = $this->matchingRules($firm, PricingRuleType::MATERIAL_FEE, $date, materialItem: $materialItem);

        if (count($matching) > 1) {
            throw new \LogicException(sprintf(
                'Plusieurs PricingRule MATERIAL_FEE actives se chevauchent pour materialItem=%d à la date %s.',
                $materialItem->getId(), $date->format('Y-m-d'),
            ));
        }

        return $matching[0] ?? null;
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
    ): array {
        if ($firm === null) {
            return [];
        }

        $qb = $this->em->getRepository(PricingRule::class)->createQueryBuilder('r')
            ->andWhere('r.firm = :firm')
            ->andWhere('r.ruleType = :type')
            ->andWhere('r.active = true')
            ->setParameter('firm', $firm)
            ->setParameter('type', $type);

        if ($type === PricingRuleType::INTERVENTION_FEE) {
            $qb->andWhere('r.interventionType = :it')->setParameter('it', $interventionType);
        } else {
            $qb->andWhere('r.materialItem = :mi')->setParameter('mi', $materialItem);
        }

        /** @var PricingRule[] $candidates */
        $candidates = $qb->getQuery()->getResult();

        return array_values(array_filter($candidates, static fn (PricingRule $r) => $r->coversDate($date)));
    }
}
