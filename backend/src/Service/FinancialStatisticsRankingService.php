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
    public const UNLINKED_MATERIAL_LABEL = 'Matériel non rattaché à une intervention';

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

        return $this->paginate($items, $page, $limit, $sortBy, $sortDirection, 'firmId')
            + ['totals' => $this->totalsByCurrency($items, ['interventionRevenue', 'materialRevenue', 'generatedRevenue', 'invoicedNetAmount', 'paidAmount', 'remainingAmount'])];
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

        return $this->paginate($items, $page, $limit, $sortBy, $sortDirection, 'instrumentistId')
            + ['totals' => $this->totalsByCurrency($items, ['hourlyCompensation', 'consultationFees', 'generatedCompensation', 'statementNetAmount', 'paidAmount', 'remainingAmount'])];
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
                netBalance: $this->sub($row['firmRevenue'], $row['instrumentistComp']),
                averageMissionValue: $missionCount > 0 ? number_format((float) $total / $missionCount, 2, '.', '') : '0.00',
            );
        }

        return $this->paginate($items, $page, $limit, $sortBy, $sortDirection, 'surgeonId')
            + ['totals' => $this->totalsByCurrency($items, ['generatedFirmRevenue', 'generatedInstrumentistCompensation', 'netBalance'])];
    }

    // ── Par intervention (§15) ───────────────────────────────────────────

    /**
     * §15 — recettes par type d'intervention : interventionRevenue est directe
     * (FinancialCalculationLine.missionIntervention.interventionType) ; materialRevenue
     * suit material_line.mission_intervention_id (NULL = matériel non rattaché à une
     * intervention, regroupé sous interventionTypeId=null).
     *
     * D-186 — la rémunération instrumentiste appartient EXCLUSIVEMENT à la mission : elle
     * n'est jamais imputée, ni en tout ni en partie, à un type d'intervention (la règle
     * D-077 « intervention primaire » est abandonnée, sans ventilation de remplacement).
     * Seul indicateur de coût exposé ici : `averageAssociatedMissionCost`, coût
     * instrumentiste TOTAL moyen des missions comportant ce type — contextuel, une même
     * mission pouvant appartenir à plusieurs groupes. Ce n'est jamais un « coût par
     * intervention » et il n'entre dans aucun total : les totaux de dépenses
     * (`missionInstrumentistCompensation`) sont comptés au niveau mission, une seule fois.
     *
     * @return array{items: InterventionStatisticsDto[], total: int, page: int, limit: int, totals: array<int, array<string, string>>}
     */
    public function byIntervention(FinancialStatisticsFilter $filter, int $page, int $limit, string $sortBy, string $sortDirection): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');
        $params = $missionParams + $currencyParams + ['fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to)];
        $types = $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING];

        $interventionRows = $this->connection->fetchAllAssociative("SELECT
                    mi.intervention_type_id AS interventionTypeId, fcl.currency,
                    COUNT(DISTINCT fc.mission_id) AS missionCount,
                    COUNT(DISTINCT fcl.mission_intervention_id) AS interventionCount,
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
                GROUP BY mi.intervention_type_id, fcl.currency", $params, $types);

        $materialRows = $this->connection->fetchAllAssociative("SELECT
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
                GROUP BY mi2.intervention_type_id, fcl.currency", $params, $types);

        // Coût TOTAL de chaque mission associée (une fois par mission et par groupe, même si
        // la mission comporte ce type plusieurs fois) — jamais une fraction de mission.
        $costRows = $this->connection->fetchAllAssociative("SELECT
                    x.interventionTypeId, x.currency, COUNT(*) AS associatedMissionCount,
                    SUM(COALESCE(c.comp, 0)) AS associatedMissionCost
                FROM (
                    SELECT DISTINCT mi.intervention_type_id AS interventionTypeId, fcl.currency, fc.id AS calcId
                    FROM financial_calculation_line fcl
                    INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                    INNER JOIN mission m ON m.id = fc.mission_id
                    INNER JOIN mission_intervention mi ON mi.id = fcl.mission_intervention_id
                    WHERE fcl.line_type = 'FIRM_INTERVENTION_FEE'
                      AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                      $missionWhere $currencyWhere
                ) x
                LEFT JOIN (
                    SELECT financial_calculation_id, currency, SUM(total_amount) AS comp
                    FROM financial_calculation_line
                    WHERE beneficiary_type = 'INSTRUMENTIST' AND effective_at >= :fDate AND effective_at < :tDate
                    GROUP BY financial_calculation_id, currency
                ) c ON c.financial_calculation_id = x.calcId AND c.currency = x.currency
                GROUP BY x.interventionTypeId, x.currency", $params, $types);
        $costByKey = [];
        foreach ($costRows as $row) {
            $costByKey[$row['interventionTypeId'] . '|' . $row['currency']] = $row;
        }

        $merged = [];
        foreach ($interventionRows as $row) {
            $key = $row['interventionTypeId'] . '|' . $row['currency'];
            $merged[$key] = [
                'interventionTypeId' => $row['interventionTypeId'],
                'currency' => $row['currency'],
                'missionCount' => (int) $row['missionCount'],
                'interventionCount' => (int) $row['interventionCount'],
                'interventionRevenue' => $this->money($row['interventionRevenue']),
                'materialRevenue' => '0.00',
                'codeSnapshot' => (string) ($row['codeSnapshot'] ?? '—'),
                'labelSnapshot' => (string) ($row['labelSnapshot'] ?? '—'),
                'avgDuration' => (int) round((float) ($row['avgDuration'] ?? 0)),
            ];
        }
        foreach ($materialRows as $row) {
            $key = $row['interventionTypeId'] . '|' . $row['currency'];
            $merged[$key] ??= [
                'interventionTypeId' => $row['interventionTypeId'], 'currency' => $row['currency'], 'missionCount' => 0, 'interventionCount' => 0,
                'interventionRevenue' => '0.00', 'materialRevenue' => '0.00', 'codeSnapshot' => '—', 'labelSnapshot' => '—', 'avgDuration' => 0,
            ];
            $merged[$key]['materialRevenue'] = $this->money($row['materialRevenue']);
        }

        // Complète le code/libellé des buckets qui n'ont jamais eu de ligne FIRM_INTERVENTION_FEE (donc aucun snapshot).
        $missingIds = array_unique(array_filter(array_map(
            static fn (array $b) => $b['codeSnapshot'] === '—' && $b['interventionTypeId'] !== null ? (int) $b['interventionTypeId'] : null,
            $merged,
        )));
        $liveLabels = $this->fetchInterventionTypeLabels($missingIds);

        $items = [];
        foreach ($merged as $key => $b) {
            if ($b['codeSnapshot'] === '—' && $b['interventionTypeId'] !== null && isset($liveLabels[(int) $b['interventionTypeId']])) {
                [$b['codeSnapshot'], $b['labelSnapshot']] = $liveLabels[(int) $b['interventionTypeId']];
            }
            if ($b['interventionTypeId'] === null && $b['interventionCount'] === 0) {
                [$b['codeSnapshot'], $b['labelSnapshot']] = ['—', self::UNLINKED_MATERIAL_LABEL];
            }
            $revenue = $this->add($b['interventionRevenue'], $b['materialRevenue']);
            $cost = $costByKey[$key] ?? null;
            $associated = $cost !== null ? (int) $cost['associatedMissionCount'] : 0;
            $items[] = new InterventionStatisticsDto(
                interventionTypeId: $b['interventionTypeId'] !== null ? (int) $b['interventionTypeId'] : null,
                interventionCodeSnapshot: $b['codeSnapshot'],
                interventionNameSnapshot: $b['labelSnapshot'],
                currency: $b['currency'],
                missionCount: $b['missionCount'],
                interventionCount: $b['interventionCount'],
                interventionRevenue: $b['interventionRevenue'],
                materialRevenue: $b['materialRevenue'],
                totalRevenue: $revenue,
                averageRevenuePerIntervention: $this->divide($revenue, $b['interventionCount']),
                averageAssociatedMissionCost: $associated > 0 ? $this->divide((string) $cost['associatedMissionCost'], $associated) : null,
                averageDurationMinutes: $b['avgDuration'],
            );
        }

        return $this->paginate($items, $page, $limit, $sortBy, $sortDirection, 'interventionTypeId')
            + ['totals' => $this->interventionTotals($items, $filter)];
    }

    /**
     * D-186 — totaux de l'onglet : recettes additionnées sur les groupes (chaque ligne de
     * recette appartient à un seul groupe) ; dépenses lues au niveau MISSION sur la même
     * population, une seule fois par mission — jamais la somme des coûts moyens associés.
     *
     * @param InterventionStatisticsDto[] $items
     * @return array<int, array<string, string>>
     */
    private function interventionTotals(array $items, FinancialStatisticsFilter $filter): array
    {
        $revenueTotals = [];
        foreach ($this->totalsByCurrency($items, ['interventionRevenue', 'materialRevenue', 'totalRevenue']) as $t) {
            $revenueTotals[$t['currency']] = $t;
        }

        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');
        $rows = $this->connection->fetchAllAssociative("SELECT fcl.currency, SUM(fcl.total_amount) AS comp
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fcl.beneficiary_type = 'INSTRUMENTIST'
                  AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY fcl.currency", $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to),
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);
        $expenses = array_column(array_map(fn (array $r) => [$r['currency'], $this->money($r['comp'])], $rows), 1, 0);

        $currencies = array_unique(array_merge(array_keys($revenueTotals), array_keys($expenses)));
        sort($currencies);
        $totals = [];
        foreach ($currencies as $currency) {
            $r = $revenueTotals[$currency] ?? ['interventionRevenue' => '0.00', 'materialRevenue' => '0.00', 'totalRevenue' => '0.00'];
            $comp = $expenses[$currency] ?? '0.00';
            $totals[] = [
                'currency' => $currency,
                'interventionRevenue' => $r['interventionRevenue'],
                'materialRevenue' => $r['materialRevenue'],
                'totalRevenue' => $r['totalRevenue'],
                'missionInstrumentistCompensation' => $comp,
                'netBalance' => $this->sub($r['totalRevenue'], $comp),
            ];
        }
        return $totals;
    }

    private function divide(string $amount, int $count): string
    {
        return $count > 0 ? number_format(round((float) $amount / $count, 2), 2, '.', '') : '0.00';
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

    /**
     * `total` = nombre de lignes renvoyées (contrat historique du « top N ») ; `groupCount` =
     * nombre de matériels filtrés au total, pour parcourir toutes les pages (export PDF).
     *
     * @return array{items: MaterialStatisticsDto[], total: int, page: int, limit: int, totals: array<int, array<string, string>>, groupCount: int}
     */
    public function topMaterials(FinancialStatisticsFilter $filter, int $limit, string $sortBy, string $sortDirection, int $page = 1): array
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
                ORDER BY $sortColumn " . ($sortDirection === 'ASC' ? 'ASC' : 'DESC') . ", ml.item_id IS NULL, ml.item_id, fcl.currency
                LIMIT :limit OFFSET :offset";

        $rows = $this->connection->fetchAllAssociative($sql, $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to), 'limit' => $limit, 'offset' => ($page - 1) * $limit,
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]);

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

        // D-186 — totaux sur TOUS les matériels filtrés, jamais sur le seul top N affiché.
        $totalRows = $this->connection->fetchAllAssociative("SELECT fcl.currency, SUM(fcl.total_amount) AS generatedRevenue
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fcl.line_type = 'FIRM_MATERIAL_FEE'
                  AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY fcl.currency
                ORDER BY fcl.currency", $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to),
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);
        $totals = array_map(fn (array $r) => ['currency' => $r['currency'], 'generatedRevenue' => $this->money($r['generatedRevenue'])], $totalRows);

        $groupCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM (
                    SELECT 1
                    FROM financial_calculation_line fcl
                    INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                    INNER JOIN mission m ON m.id = fc.mission_id
                    INNER JOIN material_line ml ON ml.id = fcl.material_line_id
                    WHERE fcl.line_type = 'FIRM_MATERIAL_FEE'
                      AND fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                      $missionWhere $currencyWhere
                    GROUP BY ml.item_id, fcl.currency
                ) g", $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to),
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        return ['items' => $items, 'total' => count($items), 'page' => $page, 'limit' => $limit, 'totals' => $totals, 'groupCount' => $groupCount];
    }

    // ── Pagination/tri en mémoire sur un résultat déjà agrégé (§22/§30) ──

    /**
     * @template T
     * @param T[] $items
     * @return array{items: T[], total: int, page: int, limit: int}
     */
    private function paginate(array $items, int $page, int $limit, string $sortBy, string $sortDirection, string $identityField): array
    {
        usort($items, function ($a, $b) use ($sortBy, $sortDirection, $identityField) {
            $va = $a->{$sortBy};
            $vb = $b->{$sortBy};
            $cmp = is_numeric($va) && is_numeric($vb) ? ((float) $va <=> (float) $vb) : ((string) $va <=> (string) $vb);
            if ($cmp !== 0) {
                return $sortDirection === 'ASC' ? $cmp : -$cmp;
            }
            // D-186 — départage déterministe sur la clé du groupe (identifiant, sans identifiant
            // en dernier, puis devise) : l'ordre SQL d'un GROUP BY n'est pas garanti, des valeurs
            // égales ne doivent jamais permuter d'une page à l'autre (ni doublon, ni ligne omise).
            return [$a->{$identityField} === null, $a->{$identityField}, $a->currency]
                <=> [$b->{$identityField} === null, $b->{$identityField}, $b->currency];
        });

        $total = count($items);
        $offset = ($page - 1) * $limit;

        return ['items' => array_slice($items, $offset, $limit), 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    /**
     * D-186 — totaux par devise sur l'ensemble filtré (AVANT pagination), en centimes
     * entiers : jamais de dérive flottante, jamais de total mélangeant deux devises.
     *
     * @param object[] $items
     * @param string[] $fields propriétés monétaires (chaînes décimales) à additionner
     * @return array<int, array<string, string>>
     */
    private function totalsByCurrency(array $items, array $fields): array
    {
        $cents = [];
        foreach ($items as $item) {
            foreach ($fields as $field) {
                $cents[$item->currency][$field] = ($cents[$item->currency][$field] ?? 0) + (int) round((float) $item->{$field} * 100);
            }
        }
        ksort($cents);

        $totals = [];
        foreach ($cents as $currency => $byField) {
            $row = ['currency' => (string) $currency];
            foreach ($fields as $field) {
                $row[$field] = number_format(($byField[$field] ?? 0) / 100, 2, '.', '');
            }
            $totals[] = $row;
        }
        return $totals;
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

    /** D-186 — solde net = recettes − dépenses, calculé en centimes entiers (signé). */
    private function sub(mixed $revenue, mixed $expenses): string
    {
        return number_format(((int) round((float) ($revenue ?? 0) * 100) - (int) round((float) ($expenses ?? 0) * 100)) / 100, 2, '.', '');
    }
}
