<?php

namespace App\Service;

use App\Dto\FinancialStatisticsFilter;
use App\Dto\FirmStatisticsDto;
use App\Dto\InstrumentistStatisticsDto;
use App\Dto\InterventionStatisticsDto;
use App\Dto\MaterialStatisticsDto;
use App\Dto\SurgeonStatisticsDto;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — GET .../by-firm, .../by-instrumentist,
 * .../by-surgeon, .../by-intervention, .../top-materials (§12-16 du lot).
 *
 * Toutes ces requêtes agrègent d'abord en SQL (une ligne par bénéficiaire×devise, un
 * nombre borné par le nombre de firmes/instrumentistes/chirurgiens/types
 * d'intervention/matériels distincts — jamais par le nombre de missions ou de lignes
 * financières) puis trient/paginent en PHP sur ce résultat déjà réduit (§22 : ce n'est
 * PAS l'agrégation interdite "PHP sur des milliers de lignes", le tri porte sur un
 * ensemble déjà petit et borné).
 */
final class FinancialStatisticsRankingService
{
    private readonly Connection $connection;

    public function __construct(EntityManagerInterface $em)
    {
        $this->connection = $em->getConnection();
    }

    // ── Par firme (§12) ──────────────────────────────────────────────────

    /** @return array{items: FirmStatisticsDto[], total: int, page: int, limit: int} */
    public function byFirm(FinancialStatisticsFilter $filter, int $page, int $limit, string $sortBy, string $sortDirection): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');

        $sql = "SELECT
                    fcl.beneficiary_firm_id AS firmId, fcl.currency,
                    COUNT(DISTINCT fc.mission_id) AS missionCount,
                    SUM(CASE WHEN fcl.line_type = 'FIRM_INTERVENTION_FEE' THEN fcl.total_amount ELSE 0 END) AS interventionRevenue,
                    SUM(CASE WHEN fcl.line_type = 'FIRM_MATERIAL_FEE' THEN fcl.total_amount ELSE 0 END) AS materialRevenue,
                    SUM(fcl.total_amount) AS generatedRevenue,
                    " . $this->latestSnapshotExpr('fcl.snapshot', '$.firmNameSnapshot', 'fcl.effective_at') . " AS firmNameSnapshot
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fcl.beneficiary_type = 'FIRM'
                  AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY fcl.beneficiary_firm_id, fcl.currency";

        $generatedRows = $this->connection->fetchAllAssociative($sql, $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to),
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $cte = $this->documentBalanceDerivedTable('firm_invoice', 'firm_invoice_line', 'firm_id');
        $docSql = "SELECT root.firm_id AS firmId, root.currency,
                        SUM(bal.net_amount) AS invoicedNetAmount,
                        SUM(bal.paid_amount) AS paidAmount,
                        SUM(GREATEST(0, bal.net_amount - bal.paid_amount + bal.refunded_amount)) AS remainingAmount
                    FROM firm_invoice root
                    INNER JOIN ($cte) bal ON bal.root_id = root.id
                    WHERE root.document_type = 'STANDARD' AND root.status IN ('SENT','PAID')
                      AND root.sent_at >= :fUtc AND root.sent_at < :tUtc
                      " . ($filter->firmId !== null ? 'AND root.firm_id = :firmId' : '') . "
                      " . ($filter->currency !== null ? 'AND root.currency = :currencyFilter' : '') . "
                    GROUP BY root.firm_id, root.currency";

        $docParams = ['fUtc' => $this->utcDateTimeParam($filter->from), 'tUtc' => $this->utcDateTimeParam($filter->to)];
        $docTypes = ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING];
        if ($filter->firmId !== null) { $docParams['firmId'] = $filter->firmId; $docTypes['firmId'] = ParameterType::INTEGER; }
        if ($filter->currency !== null) { $docParams['currencyFilter'] = $filter->currency; $docTypes['currencyFilter'] = ParameterType::STRING; }

        $docRows = $this->connection->fetchAllAssociative($docSql, $docParams, $docTypes);
        $docByKey = [];
        foreach ($docRows as $row) {
            $docByKey[$row['firmId'] . '|' . $row['currency']] = $row;
        }

        $items = [];
        foreach ($generatedRows as $row) {
            $key = $row['firmId'] . '|' . $row['currency'];
            $doc = $docByKey[$key] ?? ['invoicedNetAmount' => '0.00', 'paidAmount' => '0.00', 'remainingAmount' => '0.00'];
            unset($docByKey[$key]);
            $missionCount = (int) $row['missionCount'];
            $items[] = new FirmStatisticsDto(
                firmId: $row['firmId'] !== null ? (int) $row['firmId'] : null,
                firmNameSnapshot: (string) ($row['firmNameSnapshot'] ?? '—'),
                currency: $row['currency'],
                missionCount: $missionCount,
                interventionRevenue: $this->money($row['interventionRevenue']),
                materialRevenue: $this->money($row['materialRevenue']),
                generatedRevenue: $this->money($row['generatedRevenue']),
                invoicedNetAmount: $this->money($doc['invoicedNetAmount']),
                paidAmount: $this->money($doc['paidAmount']),
                remainingAmount: $this->money($doc['remainingAmount']),
                averageRevenuePerMission: $missionCount > 0 ? number_format((float) $row['generatedRevenue'] / $missionCount, 2, '.', '') : '0.00',
            );
        }
        // Firmes documentées (facturées) sans aucune ligne générée dans la période (rare — ex. correction seule) : toujours exposées, jamais silencieusement omises.
        foreach ($docByKey as $row) {
            $items[] = new FirmStatisticsDto(
                firmId: $row['firmId'] !== null ? (int) $row['firmId'] : null,
                firmNameSnapshot: '—',
                currency: $row['currency'],
                missionCount: 0,
                interventionRevenue: '0.00',
                materialRevenue: '0.00',
                generatedRevenue: '0.00',
                invoicedNetAmount: $this->money($row['invoicedNetAmount']),
                paidAmount: $this->money($row['paidAmount']),
                remainingAmount: $this->money($row['remainingAmount']),
                averageRevenuePerMission: '0.00',
            );
        }

        return $this->paginate($items, $page, $limit, $sortBy, $sortDirection);
    }

    // ── Par instrumentiste (§13) ─────────────────────────────────────────

    /** @return array{items: InstrumentistStatisticsDto[], total: int, page: int, limit: int} */
    public function byInstrumentist(FinancialStatisticsFilter $filter, int $page, int $limit, string $sortBy, string $sortDirection): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');

        $sql = "SELECT
                    fcl.beneficiary_instrumentist_id AS instrumentistId, fcl.currency,
                    COUNT(DISTINCT fc.mission_id) AS missionCount,
                    SUM(CASE WHEN fcl.line_type = 'INSTRUMENTIST_HOURLY' THEN COALESCE(fcl.duration_minutes,0) ELSE 0 END) AS executedMinutes,
                    SUM(CASE WHEN fcl.line_type = 'INSTRUMENTIST_HOURLY' THEN fcl.total_amount ELSE 0 END) AS hourlyCompensation,
                    SUM(CASE WHEN fcl.line_type = 'INSTRUMENTIST_CONSULTATION_FEE' THEN fcl.total_amount ELSE 0 END) AS consultationFees,
                    SUM(fcl.total_amount) AS generatedCompensation,
                    " . $this->latestSnapshotExpr('fcl.snapshot', '$.instrumentistNameSnapshot', 'fcl.effective_at') . " AS instrumentistNameSnapshot
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fcl.beneficiary_type = 'INSTRUMENTIST'
                  AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY fcl.beneficiary_instrumentist_id, fcl.currency";

        $generatedRows = $this->connection->fetchAllAssociative($sql, $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to),
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $cte = $this->documentBalanceDerivedTable('instrumentist_statement', 'instrumentist_statement_line', 'instrumentist_id');
        $docSql = "SELECT root.instrumentist_id AS instrumentistId, root.currency,
                        SUM(bal.net_amount) AS statementNetAmount,
                        SUM(bal.paid_amount) AS paidAmount,
                        SUM(GREATEST(0, bal.net_amount - bal.paid_amount + bal.refunded_amount)) AS remainingAmount
                    FROM instrumentist_statement root
                    INNER JOIN ($cte) bal ON bal.root_id = root.id
                    WHERE root.document_type = 'STANDARD' AND root.status IN ('SENT','PAID')
                      AND root.sent_at >= :fUtc AND root.sent_at < :tUtc
                      " . ($filter->instrumentistId !== null ? 'AND root.instrumentist_id = :instrumentistId' : '') . "
                      " . ($filter->currency !== null ? 'AND root.currency = :currencyFilter' : '') . "
                    GROUP BY root.instrumentist_id, root.currency";

        $docParams = ['fUtc' => $this->utcDateTimeParam($filter->from), 'tUtc' => $this->utcDateTimeParam($filter->to)];
        $docTypes = ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING];
        if ($filter->instrumentistId !== null) { $docParams['instrumentistId'] = $filter->instrumentistId; $docTypes['instrumentistId'] = ParameterType::INTEGER; }
        if ($filter->currency !== null) { $docParams['currencyFilter'] = $filter->currency; $docTypes['currencyFilter'] = ParameterType::STRING; }

        $docRows = $this->connection->fetchAllAssociative($docSql, $docParams, $docTypes);
        $docByKey = [];
        foreach ($docRows as $row) {
            $docByKey[$row['instrumentistId'] . '|' . $row['currency']] = $row;
        }

        $items = [];
        foreach ($generatedRows as $row) {
            $key = $row['instrumentistId'] . '|' . $row['currency'];
            $doc = $docByKey[$key] ?? ['statementNetAmount' => '0.00', 'paidAmount' => '0.00', 'remainingAmount' => '0.00'];
            unset($docByKey[$key]);
            $missionCount = (int) $row['missionCount'];
            $items[] = new InstrumentistStatisticsDto(
                instrumentistId: $row['instrumentistId'] !== null ? (int) $row['instrumentistId'] : null,
                instrumentistNameSnapshot: (string) ($row['instrumentistNameSnapshot'] ?? '—'),
                currency: $row['currency'],
                missionCount: $missionCount,
                executedMinutes: (int) $row['executedMinutes'],
                hourlyCompensation: $this->money($row['hourlyCompensation']),
                consultationFees: $this->money($row['consultationFees']),
                generatedCompensation: $this->money($row['generatedCompensation']),
                statementNetAmount: $this->money($doc['statementNetAmount']),
                paidAmount: $this->money($doc['paidAmount']),
                remainingAmount: $this->money($doc['remainingAmount']),
                averageCompensationPerMission: $missionCount > 0 ? number_format((float) $row['generatedCompensation'] / $missionCount, 2, '.', '') : '0.00',
            );
        }
        foreach ($docByKey as $row) {
            $items[] = new InstrumentistStatisticsDto(
                instrumentistId: $row['instrumentistId'] !== null ? (int) $row['instrumentistId'] : null,
                instrumentistNameSnapshot: '—',
                currency: $row['currency'],
                missionCount: 0,
                executedMinutes: 0,
                hourlyCompensation: '0.00',
                consultationFees: '0.00',
                generatedCompensation: '0.00',
                statementNetAmount: $this->money($row['statementNetAmount']),
                paidAmount: $this->money($row['paidAmount']),
                remainingAmount: $this->money($row['remainingAmount']),
                averageCompensationPerMission: '0.00',
            );
        }

        return $this->paginate($items, $page, $limit, $sortBy, $sortDirection);
    }

    // ── Par chirurgien (§14) ─────────────────────────────────────────────

    /** @return array{items: SurgeonStatisticsDto[], total: int, page: int, limit: int} */
    public function bySurgeon(FinancialStatisticsFilter $filter, int $page, int $limit, string $sortBy, string $sortDirection): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');

        $sql = "SELECT
                    m.surgeon_id AS surgeonId, fcl.currency,
                    COUNT(DISTINCT fc.mission_id) AS missionCount,
                    COUNT(DISTINCT CASE WHEN me.id IS NOT NULL THEN fc.mission_id END) AS executedMissionCount,
                    SUM(CASE WHEN fcl.line_type IN ('FIRM_INTERVENTION_FEE','FIRM_MATERIAL_FEE') THEN fcl.total_amount ELSE 0 END) AS firmRevenue,
                    SUM(CASE WHEN fcl.line_type IN ('INSTRUMENTIST_HOURLY','INSTRUMENTIST_CONSULTATION_FEE') THEN fcl.total_amount ELSE 0 END) AS instrumentistComp
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                LEFT JOIN mission_execution me ON me.mission_id = m.id
                WHERE fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY m.surgeon_id, fcl.currency";

        $rows = $this->connection->fetchAllAssociative($sql, $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to),
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $surgeonIds = array_unique(array_filter(array_map(static fn ($r) => $r['surgeonId'], $rows)));
        $names = $this->fetchUserDisplayNames($surgeonIds);

        $items = [];
        foreach ($rows as $row) {
            $missionCount = (int) $row['missionCount'];
            $total = $this->add($row['firmRevenue'], $row['instrumentistComp']);
            $items[] = new SurgeonStatisticsDto(
                surgeonId: $row['surgeonId'] !== null ? (int) $row['surgeonId'] : null,
                surgeonNameSnapshot: $names[(int) $row['surgeonId']] ?? '—',
                currency: $row['currency'],
                missionCount: $missionCount,
                executedMissionCount: (int) $row['executedMissionCount'],
                generatedFirmRevenue: $this->money($row['firmRevenue']),
                generatedInstrumentistCompensation: $this->money($row['instrumentistComp']),
                averageMissionValue: $missionCount > 0 ? number_format((float) $total / $missionCount, 2, '.', '') : '0.00',
            );
        }

        return $this->paginate($items, $page, $limit, $sortBy, $sortDirection);
    }

    // ── Par intervention (§15) ───────────────────────────────────────────

    /**
     * §15 — trois lignées d'attribution distinctes fusionnées par interventionTypeId
     * (D-077, limite documentée) : interventionRevenue est directe
     * (FinancialCalculationLine.missionIntervention.interventionType) ; materialRevenue
     * suit material_line.mission_intervention_id (NULL = matériel non rattaché à une
     * intervention précise, regroupé sous interventionTypeId=null) ;
     * instrumentistCompensation n'a AUCUN lien direct à une intervention (une ligne
     * INSTRUMENTIST_HOURLY/CONSULTATION_FEE est au niveau mission, pas intervention) —
     * attribuée à l'intervention PRIMAIRE de la mission (order_index minimal), un choix
     * explicite documenté plutôt qu'une répartition proportionnelle non demandée par le
     * lot.
     *
     * @return array{items: InterventionStatisticsDto[], total: int, page: int, limit: int}
     */
    public function byIntervention(FinancialStatisticsFilter $filter, int $page, int $limit, string $sortBy, string $sortDirection): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');
        $fDate = $this->dateParam($filter->from);
        $tDate = $this->dateParam($filter->to);

        $interventionSql = "SELECT
                    mi.intervention_type_id AS interventionTypeId, fcl.currency,
                    COUNT(DISTINCT fc.mission_id) AS missionCount,
                    SUM(fcl.total_amount) AS interventionRevenue,
                    " . $this->latestSnapshotExpr('fcl.snapshot', '$.interventionCodeSnapshot', 'fcl.effective_at') . " AS codeSnapshot,
                    " . $this->latestSnapshotExpr('fcl.snapshot', '$.interventionLabelSnapshot', 'fcl.effective_at') . " AS labelSnapshot,
                    AVG(me.actual_duration_minutes) AS avgDuration
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                INNER JOIN mission_intervention mi ON mi.id = fcl.mission_intervention_id
                LEFT JOIN mission_execution me ON me.mission_id = m.id
                WHERE fcl.line_type = 'FIRM_INTERVENTION_FEE'
                  AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY mi.intervention_type_id, fcl.currency";

        $interventionRows = $this->connection->fetchAllAssociative($interventionSql, $missionParams + $currencyParams + ['fDate' => $fDate, 'tDate' => $tDate], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $materialSql = "SELECT
                    mi2.intervention_type_id AS interventionTypeId, fcl.currency,
                    SUM(fcl.total_amount) AS materialRevenue
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                INNER JOIN material_line ml ON ml.id = fcl.material_line_id
                LEFT JOIN mission_intervention mi2 ON mi2.id = ml.mission_intervention_id
                WHERE fcl.line_type = 'FIRM_MATERIAL_FEE'
                  AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY mi2.intervention_type_id, fcl.currency";

        $materialRows = $this->connection->fetchAllAssociative($materialSql, $missionParams + $currencyParams + ['fDate' => $fDate, 'tDate' => $tDate], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $instrumentistSql = "SELECT
                    (SELECT mi3.intervention_type_id FROM mission_intervention mi3 WHERE mi3.mission_id = fc.mission_id ORDER BY mi3.order_index ASC, mi3.id ASC LIMIT 1) AS interventionTypeId,
                    fcl.currency,
                    SUM(fcl.total_amount) AS instrumentistCompensation
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fcl.beneficiary_type = 'INSTRUMENTIST'
                  AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY interventionTypeId, fcl.currency";

        $instrumentistRows = $this->connection->fetchAllAssociative($instrumentistSql, $missionParams + $currencyParams + ['fDate' => $fDate, 'tDate' => $tDate], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $merged = [];
        foreach ($interventionRows as $row) {
            $key = $row['interventionTypeId'] . '|' . $row['currency'];
            $merged[$key] = [
                'interventionTypeId' => $row['interventionTypeId'],
                'currency' => $row['currency'],
                'missionCount' => (int) $row['missionCount'],
                'interventionRevenue' => $this->money($row['interventionRevenue']),
                'materialRevenue' => '0.00',
                'instrumentistCompensation' => '0.00',
                'codeSnapshot' => (string) ($row['codeSnapshot'] ?? '—'),
                'labelSnapshot' => (string) ($row['labelSnapshot'] ?? '—'),
                'avgDuration' => (int) round((float) ($row['avgDuration'] ?? 0)),
            ];
        }
        foreach ($materialRows as $row) {
            $key = $row['interventionTypeId'] . '|' . $row['currency'];
            $merged[$key] ??= $this->emptyInterventionBucket($row['interventionTypeId'], $row['currency']);
            $merged[$key]['materialRevenue'] = $this->money($row['materialRevenue']);
        }
        foreach ($instrumentistRows as $row) {
            $key = $row['interventionTypeId'] . '|' . $row['currency'];
            $merged[$key] ??= $this->emptyInterventionBucket($row['interventionTypeId'], $row['currency']);
            $merged[$key]['instrumentistCompensation'] = $this->money($row['instrumentistCompensation']);
        }

        // Complète le code/libellé des buckets qui n'ont jamais eu de ligne FIRM_INTERVENTION_FEE (donc aucun snapshot).
        $missingIds = array_unique(array_filter(array_map(
            static fn (array $b) => $b['codeSnapshot'] === '—' && $b['interventionTypeId'] !== null ? (int) $b['interventionTypeId'] : null,
            $merged,
        )));
        $liveLabels = $this->fetchInterventionTypeLabels($missingIds);

        $items = [];
        foreach ($merged as $b) {
            if ($b['codeSnapshot'] === '—' && $b['interventionTypeId'] !== null && isset($liveLabels[(int) $b['interventionTypeId']])) {
                [$b['codeSnapshot'], $b['labelSnapshot']] = $liveLabels[(int) $b['interventionTypeId']];
            }
            $total = $this->add($b['interventionRevenue'], $this->add($b['materialRevenue'], $b['instrumentistCompensation']));
            $items[] = new InterventionStatisticsDto(
                interventionTypeId: $b['interventionTypeId'] !== null ? (int) $b['interventionTypeId'] : null,
                interventionCodeSnapshot: $b['codeSnapshot'],
                interventionNameSnapshot: $b['labelSnapshot'],
                currency: $b['currency'],
                missionCount: $b['missionCount'],
                interventionRevenue: $b['interventionRevenue'],
                materialRevenue: $b['materialRevenue'],
                instrumentistCompensation: $b['instrumentistCompensation'],
                averageMissionValue: $b['missionCount'] > 0 ? number_format((float) $total / $b['missionCount'], 2, '.', '') : '0.00',
                averageDurationMinutes: $b['avgDuration'],
            );
        }

        return $this->paginate($items, $page, $limit, $sortBy, $sortDirection);
    }

    /** @return array{interventionTypeId: mixed, currency: string, missionCount: int, interventionRevenue: string, materialRevenue: string, instrumentistCompensation: string, codeSnapshot: string, labelSnapshot: string, avgDuration: int} */
    private function emptyInterventionBucket(mixed $interventionTypeId, string $currency): array
    {
        return [
            'interventionTypeId' => $interventionTypeId, 'currency' => $currency, 'missionCount' => 0,
            'interventionRevenue' => '0.00', 'materialRevenue' => '0.00', 'instrumentistCompensation' => '0.00',
            'codeSnapshot' => '—', 'labelSnapshot' => '—', 'avgDuration' => 0,
        ];
    }

    /** @param int[] $ids @return array<int, array{0: string, 1: string}> */
    private function fetchInterventionTypeLabels(array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, code, label FROM intervention_type WHERE id IN (?)',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = [$row['code'], $row['label']];
        }
        return $result;
    }

    // ── Top matériels (§16) ──────────────────────────────────────────────

    /** @return array{items: MaterialStatisticsDto[], total: int, page: int, limit: int} */
    public function topMaterials(FinancialStatisticsFilter $filter, int $limit, string $sortBy, string $sortDirection): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');

        $sortColumn = match ($sortBy) {
            'quantity' => 'quantity',
            'missionCount' => 'missionCount',
            'averageUnitRevenue' => 'generatedRevenue / NULLIF(quantity, 0)',
            default => 'generatedRevenue',
        };

        $sql = "SELECT
                    ml.item_id AS materialId, fcl.currency,
                    SUM(fcl.quantity) AS quantity,
                    COUNT(DISTINCT fc.mission_id) AS missionCount,
                    SUM(fcl.total_amount) AS generatedRevenue,
                    " . $this->latestSnapshotExpr('fcl.snapshot', '$.materialNameSnapshot', 'fcl.effective_at') . " AS materialNameSnapshot,
                    " . $this->latestSnapshotExpr('fcl.snapshot', '$.materialFirmSnapshot', 'fcl.effective_at') . " AS firmSnapshot,
                    mi2.reference_code AS materialReferenceSnapshot
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                INNER JOIN material_line ml ON ml.id = fcl.material_line_id
                LEFT JOIN material_item mi2 ON mi2.id = ml.item_id
                WHERE fcl.line_type = 'FIRM_MATERIAL_FEE'
                  AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY ml.item_id, fcl.currency, mi2.reference_code
                ORDER BY $sortColumn " . ($sortDirection === 'ASC' ? 'ASC' : 'DESC') . "
                LIMIT :limit";

        $rows = $this->connection->fetchAllAssociative($sql, $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to), 'limit' => $limit,
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING, 'limit' => ParameterType::INTEGER]);

        $items = [];
        foreach ($rows as $row) {
            $quantity = (float) $row['quantity'];
            $items[] = new MaterialStatisticsDto(
                materialId: $row['materialId'] !== null ? (int) $row['materialId'] : null,
                materialReferenceSnapshot: $row['materialReferenceSnapshot'],
                materialNameSnapshot: (string) ($row['materialNameSnapshot'] ?? '—'),
                firmSnapshot: (string) ($row['firmSnapshot'] ?? '—'),
                currency: $row['currency'],
                quantity: number_format($quantity, 2, '.', ''),
                missionCount: (int) $row['missionCount'],
                generatedRevenue: $this->money($row['generatedRevenue']),
                averageUnitRevenue: $quantity > 0 ? number_format((float) $row['generatedRevenue'] / $quantity, 2, '.', '') : '0.00',
            );
        }

        return ['items' => $items, 'total' => count($items), 'page' => 1, 'limit' => $limit];
    }

    // ── Pagination/tri en mémoire sur un résultat déjà agrégé (§22/§30) ──

    /**
     * @template T
     * @param T[] $items
     * @return array{items: T[], total: int, page: int, limit: int}
     */
    private function paginate(array $items, int $page, int $limit, string $sortBy, string $sortDirection): array
    {
        usort($items, function ($a, $b) use ($sortBy, $sortDirection) {
            $va = $a->{$sortBy};
            $vb = $b->{$sortBy};
            $cmp = is_numeric($va) && is_numeric($vb) ? ((float) $va <=> (float) $vb) : ((string) $va <=> (string) $vb);
            return $sortDirection === 'ASC' ? $cmp : -$cmp;
        });

        $total = count($items);
        $offset = ($page - 1) * $limit;

        return ['items' => array_slice($items, $offset, $limit), 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    // ── Utilisateurs (chirurgiens) — nom d'affichage vivant (§14, aucun snapshot disponible) ──

    /** @param int[] $ids @return array<int, string> */
    private function fetchUserDisplayNames(array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, firstname, lastname, email FROM `user` WHERE id IN (?)',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $result = [];
        foreach ($rows as $row) {
            $name = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
            $result[(int) $row['id']] = $name !== '' ? $name : $row['email'];
        }
        return $result;
    }

    // ── Filtres partagés (mêmes règles que FinancialStatisticsQueryService) ─

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, int>} */
    private function missionPopulationClause(FinancialStatisticsFilter $filter, string $alias): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($filter->siteId !== null) { $conditions[] = "$alias.site_id = :siteId"; $params['siteId'] = $filter->siteId; $types['siteId'] = ParameterType::INTEGER; }
        if ($filter->surgeonId !== null) { $conditions[] = "$alias.surgeon_id = :surgeonId"; $params['surgeonId'] = $filter->surgeonId; $types['surgeonId'] = ParameterType::INTEGER; }
        if ($filter->instrumentistId !== null) { $conditions[] = "$alias.instrumentist_id = :instrumentistId"; $params['instrumentistId'] = $filter->instrumentistId; $types['instrumentistId'] = ParameterType::INTEGER; }
        if ($filter->firmId !== null) {
            $conditions[] = "EXISTS (SELECT 1 FROM mission_intervention mif WHERE mif.mission_id = $alias.id AND mif.primary_firm_id = :firmId)";
            $params['firmId'] = $filter->firmId; $types['firmId'] = ParameterType::INTEGER;
        }
        if ($filter->interventionTypeId !== null) {
            $conditions[] = "EXISTS (SELECT 1 FROM mission_intervention mit WHERE mit.mission_id = $alias.id AND mit.intervention_type_id = :interventionTypeId)";
            $params['interventionTypeId'] = $filter->interventionTypeId; $types['interventionTypeId'] = ParameterType::INTEGER;
        }

        return [count($conditions) > 0 ? ('AND ' . implode(' AND ', $conditions)) : '', $params, $types];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, int>} */
    private function currencyClause(FinancialStatisticsFilter $filter, string $alias): array
    {
        if ($filter->currency === null) {
            return ['', [], []];
        }
        return ["AND $alias.currency = :currencyFilter", ['currencyFilter' => $filter->currency], ['currencyFilter' => ParameterType::STRING]];
    }

    private function documentBalanceDerivedTable(string $docTable, string $lineTable, string $ownerColumn): string
    {
        $paymentDocType = $docTable === 'firm_invoice' ? 'FIRM_INVOICE' : 'INSTRUMENTIST_STATEMENT';
        $lineFk = $docTable === 'firm_invoice' ? 'invoice_id' : 'statement_id';

        return "
            SELECT
                root.id AS root_id,
                COALESCE(credits.amt, 0) AS credit_amount,
                COALESCE(debits.amt, 0) AS debit_amount,
                (root.total_amount - COALESCE(credits.amt, 0) + COALESCE(debits.amt, 0)) AS net_amount,
                CASE
                    WHEN COALESCE(paid.amt, 0) = 0 AND COALESCE(refunded.amt, 0) = 0 AND root.status = 'PAID'
                        THEN (root.total_amount - COALESCE(credits.amt, 0) + COALESCE(debits.amt, 0))
                    ELSE COALESCE(paid.amt, 0)
                END AS paid_amount,
                COALESCE(refunded.amt, 0) AS refunded_amount
            FROM $docTable root
            LEFT JOIN (
                SELECT c.corrects_document_id AS root_id, SUM(cl.total_amount) AS amt
                FROM $docTable c INNER JOIN $lineTable cl ON cl.$lineFk = c.id
                WHERE c.document_type = 'CREDIT_NOTE' AND c.status IN ('SENT','PAID')
                GROUP BY c.corrects_document_id
            ) credits ON credits.root_id = root.id
            LEFT JOIN (
                SELECT c.corrects_document_id AS root_id, SUM(cl.total_amount) AS amt
                FROM $docTable c INNER JOIN $lineTable cl ON cl.$lineFk = c.id
                WHERE c.document_type = 'DEBIT_NOTE' AND c.status IN ('SENT','PAID')
                GROUP BY c.corrects_document_id
            ) debits ON debits.root_id = root.id
            LEFT JOIN (SELECT document_id, SUM(amount) AS amt FROM payment WHERE document_type = '$paymentDocType' AND direction = 'INBOUND' GROUP BY document_id) paid ON paid.document_id = root.id
            LEFT JOIN (SELECT document_id, SUM(amount) AS amt FROM payment WHERE document_type = '$paymentDocType' AND direction = 'OUTBOUND' GROUP BY document_id) refunded ON refunded.document_id = root.id
            WHERE root.document_type = 'STANDARD'
        ";
    }

    /**
     * MySQL : dernière valeur (par ordre décroissant de $orderColumn) d'un chemin JSON,
     * agrégée sur le groupe — voir docblock de classe. Séparateur = octet de contrôle
     * 0x01 (jamais présent dans un nom de firme/matériel réel), pas une simple
     * séquence de caractères imprimables.
     */
    private function latestSnapshotExpr(string $jsonColumn, string $jsonPath, string $orderColumn): string
    {
        $separator = "\x01";
        return "SUBSTRING_INDEX(GROUP_CONCAT(JSON_UNQUOTE(JSON_EXTRACT($jsonColumn, '$jsonPath')) ORDER BY $orderColumn DESC SEPARATOR '$separator'), '$separator', 1)";
    }

    private function utcDateTimeParam(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function dateParam(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone(\App\Doctrine\Type\BusinessDateTimeImmutableType::BUSINESS_TIMEZONE))->format('Y-m-d');
    }

    private function money(mixed $raw): string
    {
        return number_format((float) ($raw ?? 0), 2, '.', '');
    }

    private function add(string $a, string $b): string
    {
        return number_format((float) $a + (float) $b, 2, '.', '');
    }
}
