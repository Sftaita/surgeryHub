<?php

namespace App\Repository;

use App\Dto\EncodingStateFacts;
use App\Dto\FinancialStatisticsFilter;
use App\Dto\MissionFinancialFacts;
use App\Entity\Mission;
use App\Enum\MissionStatus;
use App\Enum\MissionType;
use App\Service\MissionPopulationClauseBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suivi des encodages (D-118) — accès agrégé, jamais d'hydratation Doctrine.
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
        private readonly EntityManagerInterface $em,
        private readonly MissionPopulationClauseBuilder $missionPopulation,
    ) {}

    /**
     * Seul point d'hydratation Doctrine du module, volontairement borné à une page.
     *
     * Les associations affichées (chirurgien, instrumentiste, site) ET l'exécution sont
     * jointes en une requête : sans le JOIN sur l'exécution, resolveEffectiveDuration()
     * déclencherait un lazy-load par mission — le N+1 exact que ce module doit éviter.
     *
     * @param int[] $missionIds
     * @return array<int, Mission> indexé par mission id
     */
    public function hydrateMissionsForDisplay(array $missionIds): array
    {
        if (count($missionIds) === 0) {
            return [];
        }

        $missions = $this->em->createQueryBuilder()
            ->select('m', 'surgeon', 'instrumentist', 'site', 'execution')
            ->from(Mission::class, 'm')
            ->leftJoin('m.surgeon', 'surgeon')
            ->leftJoin('m.instrumentist', 'instrumentist')
            ->leftJoin('m.site', 'site')
            ->leftJoin('m.execution', 'execution')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $missionIds)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($missions as $mission) {
            $byId[(int) $mission->getId()] = $mission;
        }

        return $byId;
    }

    /**
     * Faits bruts de toute la période, une ligne par mission, sans pagination.
     * Destiné au résumé : la ventilation doit porter sur la période entière, pas sur la
     * page affichée.
     *
     * @return array<int, EncodingStateFacts> indexé par mission id
     */
    public function fetchFactsForPeriod(FinancialStatisticsFilter $filter, ?MissionType $missionType = null): array
    {
        [$missionWhere, $params, $types] = $this->missionPopulationClause($filter, 'm', $missionType);

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
    public function fetchPageIds(FinancialStatisticsFilter $filter, int $page, int $limit, ?MissionType $missionType = null): array
    {
        [$missionWhere, $params, $types] = $this->missionPopulationClause($filter, 'm', $missionType);

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

    /**
     * Faits financiers bruts pour un lot de missions, en une seule requête.
     *
     * Réutilise strictement les définitions de D-077 : calcul actif =
     * CALCULATED/APPROVED/LOCKED, document émis = SENT/PAID (un document GENERATED n'est
     * jamais "émis"). Aucun montant n'est lu, aucun solde n'est recalculé — le statut PAID
     * du document fait foi, plutôt que de dupliquer DocumentPaymentService::computeBalance().
     *
     * @param int[] $missionIds
     * @return array<int, MissionFinancialFacts> indexé par mission id
     */
    public function fetchFinancialFacts(array $missionIds): array
    {
        if (count($missionIds) === 0) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.id AS missionId,
                    EXISTS (SELECT 1 FROM financial_calculation fc
                            WHERE fc.mission_id = m.id
                              AND fc.status IN ('CALCULATED','APPROVED','LOCKED')) AS hasActiveCalculation,
                    (SELECT COUNT(*) FROM firm_invoice_line fil
                       INNER JOIN firm_invoice fi ON fi.id = fil.invoice_id
                      WHERE fil.mission_id = m.id AND fi.status IN ('SENT','PAID'))
                  + (SELECT COUNT(*) FROM instrumentist_statement_line isl
                       INNER JOIN instrumentist_statement ist ON ist.id = isl.statement_id
                      WHERE isl.mission_id = m.id AND ist.status IN ('SENT','PAID')) AS issuedDocumentLines,
                    (SELECT COUNT(*) FROM firm_invoice_line fil
                       INNER JOIN firm_invoice fi ON fi.id = fil.invoice_id
                      WHERE fil.mission_id = m.id AND fi.status = 'SENT')
                  + (SELECT COUNT(*) FROM instrumentist_statement_line isl
                       INNER JOIN instrumentist_statement ist ON ist.id = isl.statement_id
                      WHERE isl.mission_id = m.id AND ist.status = 'SENT') AS unpaidDocumentLines
             FROM mission m
             WHERE m.id IN (:ids)",
            ['ids' => $missionIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $failures = $this->findMissionsWithFailedCalculation($missionIds);

        $facts = [];
        foreach ($rows as $row) {
            $missionId = (int) $row['missionId'];
            $issued = (int) $row['issuedDocumentLines'];

            $facts[$missionId] = new MissionFinancialFacts(
                hasActiveCalculation: (bool) $row['hasActiveCalculation'],
                hasIssuedDocument: $issued > 0,
                allIssuedDocumentsPaid: $issued > 0 && (int) $row['unpaidDocumentLines'] === 0,
                hasUnresolvedCalculationFailure: isset($failures[$missionId]),
            );
        }

        return $facts;
    }

    /**
     * Filtres partagés + le type de mission, propre à ce module. missionType n'est
     * délibérément pas ajouté à FinancialStatisticsFilter : ce serait modifier le contrat
     * gelé de D-077 pour un besoin qui n'appartient qu'au suivi des encodages.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, int>}
     */
    private function missionPopulationClause(FinancialStatisticsFilter $filter, string $alias, ?MissionType $missionType = null): array
    {
        [$where, $params, $types] = $this->missionPopulation->build($filter, $alias);

        if ($missionType !== null) {
            $where .= " AND $alias.type = :missionType";
            $params['missionType'] = $missionType->value;
            $types['missionType'] = ParameterType::STRING;
        }

        return [$where, $params, $types];
    }
}
