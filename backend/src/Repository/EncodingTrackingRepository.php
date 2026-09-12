<?php

namespace App\Repository;

use App\Dto\EncodingStateFacts;
use App\Dto\FinancialStatisticsFilter;
use App\Enum\MissionStatus;
use App\Service\MissionPopulationClauseBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Suivi des encodages (D-092) — accès agrégé, jamais d'hydratation Doctrine.
 *
 * Même discipline de performance que D-077 §22 : SQL natif, aucun graphe d'entités
 * chargé pour compter. La seule boucle PHP porte sur des lignes plates de 8 colonnes
 * scalaires — c'est le prix assumé pour que EncodingStateResolver reste l'unique
 * définition de l'état (l'alternative, un CASE SQL, dupliquerait la règle métier et
 * divergerait à la première évolution).
 *
 * La fenêtre de période utilise COALESCE(me.actual_start_at, m.start_at), STRICTEMENT
 * la même que FinancialStatisticsQueryService::activityRow(). Sans cette identité, la
 * ventilation affichée pour expliquer les zéros ne totaliserait pas le nombre de
 * missions affiché juste au-dessus, et le message d'explication serait incohérent.
 */
final class EncodingTrackingRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MissionPopulationClauseBuilder $missionPopulation,
    ) {}

    /**
     * Faits bruts de toute la période, une ligne par mission, sans pagination.
     * Destiné au résumé : la ventilation doit porter sur la période entière, pas sur la
     * page affichée.
     *
     * @return array<int, EncodingStateFacts> indexé par mission id
     */
    public function fetchFactsForPeriod(FinancialStatisticsFilter $filter): array
    {
        [$missionWhere, $params, $types] = $this->missionPopulationClause($filter, 'm');

        $sql = "SELECT {$this->factColumns()}
                FROM mission m
                LEFT JOIN mission_execution me ON me.mission_id = m.id
                WHERE COALESCE(me.actual_start_at, m.start_at) >= :fBusiness
                  AND COALESCE(me.actual_start_at, m.start_at) < :tBusiness
                  $missionWhere";

        $rows = $this->connection->fetchAllAssociative(
            $sql,
            $params + ['fBusiness' => $this->businessParam($filter->from), 'tBusiness' => $this->businessParam($filter->to)],
            $types + ['fBusiness' => ParameterType::STRING, 'tBusiness' => ParameterType::STRING],
        );

        return $this->hydrateFacts($rows);
    }

    /**
     * Ids des missions de la période, triés chronologiquement, paginés.
     * Le tri porte sur la même expression que la fenêtre — une mission dont les horaires
     * réels diffèrent du planifié doit apparaître au jour où elle a réellement eu lieu.
     *
     * @return array{ids: int[], total: int}
     */
    public function fetchPageIds(FinancialStatisticsFilter $filter, int $page, int $limit): array
    {
        [$missionWhere, $params, $types] = $this->missionPopulationClause($filter, 'm');

        $window = 'COALESCE(me.actual_start_at, m.start_at)';
        $from = "FROM mission m
                 LEFT JOIN mission_execution me ON me.mission_id = m.id
                 WHERE $window >= :fBusiness AND $window < :tBusiness
                   $missionWhere";

        $bound = $params + ['fBusiness' => $this->businessParam($filter->from), 'tBusiness' => $this->businessParam($filter->to)];
        $boundTypes = $types + ['fBusiness' => ParameterType::STRING, 'tBusiness' => ParameterType::STRING];

        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) $from", $bound, $boundTypes);

        $ids = $this->connection->fetchFirstColumn(
            "SELECT m.id $from ORDER BY $window ASC, m.id ASC LIMIT :limit OFFSET :offset",
            $bound + ['limit' => $limit, 'offset' => max(0, ($page - 1) * $limit)],
            $boundTypes + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return ['ids' => array_map('intval', $ids), 'total' => $total];
    }

    /**
     * Faits bruts pour un lot d'ids déjà sélectionné (chemin liste).
     *
     * @param int[] $missionIds
     * @return array<int, EncodingStateFacts> indexé par mission id
     */
    public function fetchFactsForIds(array $missionIds): array
    {
        if (count($missionIds) === 0) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT {$this->factColumns()}
             FROM mission m
             LEFT JOIN mission_execution me ON me.mission_id = m.id
             WHERE m.id IN (:ids)",
            ['ids' => $missionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return $this->hydrateFacts($rows);
    }

    /**
     * Missions dont la dernière tentative de calcul financier a échoué sur des anomalies.
     *
     * Les anomalies ne sont pas persistées comme un état interrogeable : elles sont
     * levées en exception et tracées par un AuditEvent FINANCIAL_CALCULATION_FAILED
     * (FinancialCalculationService::buildAndPersist()). On lit donc l'historique — jamais
     * relancer le moteur de tarification depuis un endpoint de lecture.
     *
     * Une tentative échouée est considérée résolue dès qu'un calcul actif plus récent
     * existe : sinon une mission recalculée avec succès resterait signalée en anomalie.
     *
     * @param int[] $missionIds
     * @return array<int, true> indexé par mission id en anomalie
     */
    public function findMissionsWithFailedCalculation(array $missionIds): array
    {
        if (count($missionIds) === 0) {
            return [];
        }

        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT ae.mission_id
             FROM audit_event ae
             WHERE ae.mission_id IN (:ids)
               AND ae.event_type = 'FINANCIAL_CALCULATION_FAILED'
               AND NOT EXISTS (
                   SELECT 1 FROM financial_calculation fc
                   WHERE fc.mission_id = ae.mission_id
                     AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                     AND fc.calculated_at >= ae.created_at
               )",
            ['ids' => $missionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_fill_keys(array_map('intval', $rows), true);
    }

    /**
     * Colonnes de faits — strictement celles dont EncodingStateFacts a besoin.
     * Les deux sous-requêtes corrélées s'appuient sur idx_intervention_mission et
     * idx_material_line_mission. quantity > 0 reproduit exactement la définition d'une
     * ligne "active" de MissionEncodingWorkflowService::countActiveMaterialLines().
     */
    private function factColumns(): string
    {
        return "m.id AS missionId,
                m.status AS status,
                m.end_at AS endAt,
                m.encoding_started_at AS encodingStartedAt,
                m.invoice_generated_at AS invoiceGeneratedAt,
                (SELECT COUNT(*) FROM mission_intervention mi WHERE mi.mission_id = m.id) AS interventionCount,
                (SELECT COUNT(*) FROM material_line ml WHERE ml.mission_id = m.id AND ml.quantity > 0) AS activeMaterialLineCount,
                CASE WHEN me.id IS NOT NULL
                      AND (me.actual_start_at IS NOT NULL OR me.actual_end_at IS NOT NULL OR me.actual_duration_minutes IS NOT NULL)
                     THEN 1 ELSE 0 END AS hasExecutionActuals";
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, EncodingStateFacts>
     */
    private function hydrateFacts(array $rows): array
    {
        $facts = [];
        foreach ($rows as $row) {
            $facts[(int) $row['missionId']] = new EncodingStateFacts(
                status: MissionStatus::from((string) $row['status']),
                endAt: $this->toDate($row['endAt']),
                encodingStartedAt: $this->toDate($row['encodingStartedAt']),
                invoiceGeneratedAt: $this->toDate($row['invoiceGeneratedAt']),
                interventionCount: (int) $row['interventionCount'],
                activeMaterialLineCount: (int) $row['activeMaterialLineCount'],
                hasExecutionActuals: (bool) $row['hasExecutionActuals'],
            );
        }

        return $facts;
    }

    /**
     * end_at est stocké en heure métier (D-066, business_datetime_immutable) : le relire
     * en SQL brut impose de réappliquer explicitement la timezone métier, sinon la
     * comparaison avec "maintenant" dérive d'une à deux heures selon la saison — soit
     * exactement une mission affichée "à venir" alors qu'elle est terminée.
     */
    private function toDate(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return new \DateTimeImmutable((string) $raw, new \DateTimeZone('Europe/Brussels'));
    }

    private function businessParam(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('Europe/Brussels'))->format('Y-m-d H:i:s');
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, int>} */
    private function missionPopulationClause(FinancialStatisticsFilter $filter, string $alias): array
    {
        return $this->missionPopulation->build($filter, $alias);
    }
}
