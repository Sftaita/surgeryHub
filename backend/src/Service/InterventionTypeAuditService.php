<?php

namespace App\Service;

use App\Entity\FirmServiceOffering;
use App\Entity\InterventionType;
use App\Entity\MissionIntervention;
use App\Entity\PricingRule;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Task 11, section 3 — audit complet des InterventionType avant toute correction. Ne
 * fusionne jamais rien : produit uniquement la table de candidats à examiner
 * manuellement (voir InterventionTypeMergeService pour l'action explicite).
 */
final class InterventionTypeAuditService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InterventionTypeSimilarityService $similarity,
    ) {}

    /**
     * @return list<array{
     *   id: int, code: string, label: string, active: bool, merged: bool,
     *   firmsCount: int, firms: list<string>, missionsCount: int,
     *   pricingRulesCount: int, financialLinesCount: int,
     *   candidates: list<array{id: int, code: string, label: string, confidence: string}>,
     * }>
     */
    public function buildAuditTable(): array
    {
        /** @var InterventionType[] $types */
        $types = $this->em->getRepository(InterventionType::class)->createQueryBuilder('it')
            ->orderBy('it.label', 'ASC')
            ->getQuery()->getResult();

        // Chargé UNE SEULE fois, réutilisé pour chaque comparaison ci-dessous — voir
        // InterventionTypeSimilarityService::findCandidatesInPool() (évite un O(n²)
        // d'hydratations répétées).
        $activePool = array_values(array_filter($types, fn (InterventionType $t) => $t->isActive() && !$t->isMerged()));

        $rows = [];
        foreach ($types as $type) {
            $offerings = $this->em->getRepository(FirmServiceOffering::class)->createQueryBuilder('o')
                ->leftJoin('o.firm', 'f')->addSelect('f')
                ->andWhere('o.interventionType = :t')->setParameter('t', $type)
                ->getQuery()->getResult();

            $firms = array_values(array_unique(array_map(
                fn (FirmServiceOffering $o) => $o->getFirm()->getName(),
                $offerings,
            )));

            $missionsCount = (int) $this->em->getRepository(MissionIntervention::class)->createQueryBuilder('mi')
                ->select('COUNT(mi.id)')
                ->andWhere('mi.interventionType = :t')->setParameter('t', $type)
                ->getQuery()->getSingleScalarResult();

            $pricingRulesCount = (int) $this->em->getRepository(PricingRule::class)->createQueryBuilder('pr')
                ->select('COUNT(pr.id)')
                ->andWhere('pr.interventionType = :t')->setParameter('t', $type)
                ->getQuery()->getSingleScalarResult();

            $financialLinesCount = (int) $this->em->createQueryBuilder()
                ->select('COUNT(fcl.id)')
                ->from('App\\Entity\\FinancialCalculationLine', 'fcl')
                ->join('fcl.missionIntervention', 'mi2')
                ->andWhere('mi2.interventionType = :t')->setParameter('t', $type)
                ->getQuery()->getSingleScalarResult();

            $candidates = [];
            if (!$type->isMerged() && $type->isActive()) {
                foreach ($this->similarity->findCandidatesInPool($type->getLabel(), $activePool, $type->getId()) as $candidate) {
                    /** @var InterventionType $candidateType */
                    $candidateType = $candidate['type'];
                    $candidates[] = [
                        'id' => $candidateType->getId(),
                        'code' => $candidateType->getCode(),
                        'label' => $candidateType->getLabel(),
                        'confidence' => $candidate['confidence'],
                    ];
                }
            }

            $rows[] = [
                'id' => $type->getId(),
                'code' => $type->getCode(),
                'label' => $type->getLabel(),
                'active' => $type->isActive(),
                'merged' => $type->isMerged(),
                'mergedIntoId' => $type->getMergedInto()?->getId(),
                'firmsCount' => count($firms),
                'firms' => $firms,
                'missionsCount' => $missionsCount,
                'pricingRulesCount' => $pricingRulesCount,
                'financialLinesCount' => (int) $financialLinesCount,
                'candidates' => $candidates,
            ];
        }

        return $rows;
    }
}
