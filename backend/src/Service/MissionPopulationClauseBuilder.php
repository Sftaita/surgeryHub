<?php

namespace App\Service;

use App\Dto\FinancialStatisticsFilter;
use Doctrine\DBAL\ParameterType;

/**
 * Traduction canonique d'un FinancialStatisticsFilter en clause SQL sur la table
 * `mission`. Extrait de FinancialStatisticsQueryService (D-077 §6) lors du chantier
 * Suivi des encodages (D-118), pour que les deux modules filtrent une période
 * EXACTEMENT de la même façon.
 *
 * Ce n'est pas qu'un utilitaire SQL : il porte une règle métier (ce que "filtré par
 * firme" signifie — la firme principale d'au moins une intervention de la mission, pas
 * une colonne de la mission). Dupliquer cette traduction ferait diverger deux écrans
 * censés décrire la même population.
 *
 * Un filtre absent signifie "tous", jamais une valeur par défaut devinée (D-077 §6).
 */
final class MissionPopulationClauseBuilder
{
    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, int>}
     *         Clause préfixée par "AND " (ou chaîne vide), paramètres, types.
     */
    public function build(FinancialStatisticsFilter $filter, string $alias): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($filter->siteId !== null) {
            $conditions[] = "$alias.site_id = :siteId";
            $params['siteId'] = $filter->siteId;
            $types['siteId'] = ParameterType::INTEGER;
        }
        if ($filter->surgeonId !== null) {
            $conditions[] = "$alias.surgeon_id = :surgeonId";
            $params['surgeonId'] = $filter->surgeonId;
            $types['surgeonId'] = ParameterType::INTEGER;
        }
        if ($filter->instrumentistId !== null) {
            $conditions[] = "$alias.instrumentist_id = :instrumentistId";
            $params['instrumentistId'] = $filter->instrumentistId;
            $types['instrumentistId'] = ParameterType::INTEGER;
        }
        if ($filter->firmId !== null) {
            $conditions[] = "EXISTS (SELECT 1 FROM mission_intervention mif WHERE mif.mission_id = $alias.id AND mif.primary_firm_id = :firmId)";
            $params['firmId'] = $filter->firmId;
            $types['firmId'] = ParameterType::INTEGER;
        }
        if ($filter->interventionTypeId !== null) {
            $conditions[] = "EXISTS (SELECT 1 FROM mission_intervention mit WHERE mit.mission_id = $alias.id AND mit.intervention_type_id = :interventionTypeId)";
            $params['interventionTypeId'] = $filter->interventionTypeId;
            $types['interventionTypeId'] = ParameterType::INTEGER;
        }

        return [count($conditions) > 0 ? ('AND ' . implode(' AND ', $conditions)) : '', $params, $types];
    }
}
