<?php

namespace App\Service;

use App\Entity\AuditEvent;
use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Exception\InterventionTypeMergeConflictException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Task 11 — fusion explicite de deux InterventionType représentant la même intervention
 * clinique. TOUJOURS déclenchée manuellement par un manager (jamais automatique — voir
 * InterventionTypeSimilarityService, qui ne fait que suggérer).
 *
 * Ce qui est réassigné (sûr, ne change jamais le sens comptable d'un document déjà émis) :
 * - FirmServiceOffering.interventionType (source -> target), sauf conflit (voir plus bas) ;
 * - MissionIntervention.interventionType (source -> target), pour TOUTES les missions,
 *   passées comme futures : MissionIntervention.code/label restent des instantanés figés
 *   (jamais touchés ici) et FinancialCalculationLine ne référence jamais
 *   intervention_type_id directement (seulement mission_intervention_id) — réassigner
 *   cette FK ne réécrit donc aucun document financier déjà émis, elle ne fait que
 *   regrouper correctement les statistiques futures (voir docs/architecture.md) ;
 * - PricingRule.interventionType, UNIQUEMENT pour les règles FUTURES
 *   (validFrom > aujourd'hui, donc encore mutables au sens D-072) et qui ne chevauchent
 *   aucune règle déjà présente sur le type cible pour la même firme.
 *
 * Ce qui n'est JAMAIS réassigné ni réécrit :
 * - toute PricingRule dont validFrom <= aujourd'hui (append-only, D-072) ;
 * - tout FinancialCalculationLine / document financier déjà émis ;
 * - MissionIntervention.code / MissionIntervention.label (instantanés historiques).
 *
 * Conflit bloquant : si une firme a déjà une FirmServiceOffering sur LES DEUX types (source
 * et cible), la fusion entière est refusée (InterventionTypeMergeConflictException) —
 * aucune mutation partielle. Le manager doit d'abord résoudre ce conflit à la main
 * (désactiver/consolider l'une des deux prestations).
 */
final class InterventionTypeMergeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditService $auditService,
    ) {}

    /**
     * @return array{
     *   offeringsReassigned: int, missionInterventionsReassigned: int,
     *   pricingRulesReassigned: int, pricingRulesSkipped: list<int>,
     * }
     */
    public function merge(InterventionType $source, InterventionType $target, User $actor): array
    {
        if ($source->getId() === $target->getId()) {
            throw new \InvalidArgumentException('Impossible de fusionner un type avec lui-même.');
        }
        if ($source->isMerged()) {
            throw new \InvalidArgumentException('Le type source est déjà fusionné.');
        }
        if ($target->isMerged()) {
            throw new \InvalidArgumentException('Le type cible est lui-même déjà fusionné — choisir son type canonique final.');
        }

        // Ordre de verrouillage déterministe (id croissant) — évite les interblocages
        // symétriques entre deux fusions concurrentes, même principe que
        // PricingRuleWriteService::lockCible(). Le verrouillage pessimiste exige une
        // transaction explicite (wrapInTransaction), même motif que PricingRuleWriteService.
        [$offeringsReassigned, $missionInterventionsReassigned, $pricingRulesReassigned, $pricingRulesSkipped] =
            $this->em->wrapInTransaction(function () use ($source, $target): array {
                [$first, $second] = $source->getId() < $target->getId() ? [$source, $target] : [$target, $source];
                $this->em->lock($first, LockMode::PESSIMISTIC_WRITE);
                $this->em->lock($second, LockMode::PESSIMISTIC_WRITE);

                $this->assertNoOfferingConflict($source, $target);

                $offeringsReassigned = $this->reassignOfferings($source, $target);
                [$pricingRulesReassigned, $pricingRulesSkipped] = $this->reassignFuturePricingRules($source, $target);
                $missionInterventionsReassigned = $this->reassignMissionInterventions($source, $target);

                $source->setMergedInto($target);
                $source->setMergedAt(new \DateTimeImmutable());
                $source->setActive(false);

                $this->em->flush();

                return [$offeringsReassigned, $missionInterventionsReassigned, $pricingRulesReassigned, $pricingRulesSkipped];
            });

        // recordGlobal() ne flush jamais (responsabilité de l'appelant, voir AuditService).
        $this->auditService->recordGlobal($actor, AuditEventType::INTERVENTION_TYPE_MERGED, [
            'sourceId' => $source->getId(),
            'sourceCode' => $source->getCode(),
            'sourceLabel' => $source->getLabel(),
            'targetId' => $target->getId(),
            'targetCode' => $target->getCode(),
            'targetLabel' => $target->getLabel(),
            'offeringsReassigned' => $offeringsReassigned,
            'missionInterventionsReassigned' => $missionInterventionsReassigned,
            'pricingRulesReassigned' => $pricingRulesReassigned,
            'pricingRulesSkipped' => $pricingRulesSkipped,
        ]);
        $this->em->flush();

        return [
            'offeringsReassigned' => $offeringsReassigned,
            'missionInterventionsReassigned' => $missionInterventionsReassigned,
            'pricingRulesReassigned' => $pricingRulesReassigned,
            'pricingRulesSkipped' => $pricingRulesSkipped,
        ];
    }

    private function assertNoOfferingConflict(InterventionType $source, InterventionType $target): void
    {
        $sourceFirmIds = $this->firmIdsWithOffering($source);
        $targetFirmIds = $this->firmIdsWithOffering($target);
        $conflictFirmIds = array_intersect($sourceFirmIds, $targetFirmIds);

        if ($conflictFirmIds === []) {
            return;
        }

        $names = $this->em->getRepository(FirmServiceOffering::class)->createQueryBuilder('o')
            ->select('DISTINCT f.name')
            ->join('o.firm', 'f')
            ->andWhere('o.interventionType = :source')->setParameter('source', $source)
            ->andWhere('f.id IN (:ids)')->setParameter('ids', array_values($conflictFirmIds))
            ->getQuery()->getSingleColumnResult();

        throw new InterventionTypeMergeConflictException($names);
    }

    /**
     * @return list<int>
     */
    private function firmIdsWithOffering(InterventionType $type): array
    {
        return $this->em->getRepository(FirmServiceOffering::class)->createQueryBuilder('o')
            ->select('IDENTITY(o.firm)')
            ->andWhere('o.interventionType = :t')->setParameter('t', $type)
            ->getQuery()->getSingleColumnResult();
    }

    private function reassignOfferings(InterventionType $source, InterventionType $target): int
    {
        return $this->em->createQueryBuilder()
            ->update(FirmServiceOffering::class, 'o')
            ->set('o.interventionType', ':target')
            ->where('o.interventionType = :source')
            ->setParameter('target', $target)
            ->setParameter('source', $source)
            ->getQuery()->execute();
    }

    private function reassignMissionInterventions(InterventionType $source, InterventionType $target): int
    {
        return $this->em->createQueryBuilder()
            ->update(MissionIntervention::class, 'mi')
            ->set('mi.interventionType', ':target')
            ->where('mi.interventionType = :source')
            ->setParameter('target', $target)
            ->setParameter('source', $source)
            ->getQuery()->execute();
    }

    /**
     * @return array{0: int, 1: list<int>} [nombre réassigné, ids des règles NON réassignées par conflit de chevauchement]
     */
    private function reassignFuturePricingRules(InterventionType $source, InterventionType $target): array
    {
        $today = new \DateTimeImmutable('today');

        /** @var PricingRule[] $futureSourceRules */
        $futureSourceRules = $this->em->getRepository(PricingRule::class)->createQueryBuilder('pr')
            ->andWhere('pr.interventionType = :source')->setParameter('source', $source)
            ->andWhere('pr.validFrom > :today')->setParameter('today', $today)
            ->getQuery()->getResult();

        if ($futureSourceRules === []) {
            return [0, []];
        }

        /** @var PricingRule[] $targetRules */
        $targetRules = $this->em->getRepository(PricingRule::class)->createQueryBuilder('pr')
            ->andWhere('pr.interventionType = :target')->setParameter('target', $target)
            ->getQuery()->getResult();

        $reassigned = 0;
        $skipped = [];
        foreach ($futureSourceRules as $rule) {
            $overlaps = false;
            foreach ($targetRules as $targetRule) {
                if ($targetRule->getFirm()->getId() === $rule->getFirm()->getId()
                    && $targetRule->getRuleType() === $rule->getRuleType()
                    && $rule->overlapsWith($targetRule)
                ) {
                    $overlaps = true;
                    break;
                }
            }

            if ($overlaps) {
                $skipped[] = $rule->getId();
                continue;
            }

            $rule->setInterventionType($target);
            $targetRules[] = $rule;
            $reassigned++;
        }

        return [$reassigned, $skipped];
    }
}
