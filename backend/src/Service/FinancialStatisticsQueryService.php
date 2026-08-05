<?php

namespace App\Service;

use App\Doctrine\Type\BusinessDateTimeImmutableType;
use App\Dto\FinancialOverviewActivityDto;
use App\Dto\FinancialOverviewCurrencyDto;
use App\Dto\FinancialOverviewDto;
use App\Dto\FinancialPipelineDto;
use App\Dto\FinancialStatisticsFilter;
use App\Dto\FinancialTimeSeriesCurrencyAmountsDto;
use App\Dto\FinancialTimeSeriesPointDto;
use App\Enum\StatisticsGranularity;
use App\Exception\InvalidStatisticsFilterException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — §22/§23 du lot : agrégats SQL (jamais
 * d'hydratation Doctrine complète, jamais de boucle PHP sur des milliers de lignes).
 * Source de vérité par catégorie (§2 du lot, voir D-077) :
 *
 * - Activité : Mission/MissionExecution (aucune devise propre — jamais dupliquée par
 *   devise, voir FinancialOverviewActivityDto).
 * - Valeur générée : FinancialCalculationLine d'un FinancialCalculation ACTIF
 *   (CALCULATED/APPROVED/LOCKED — §8 du lot). Le modèle garantit qu'au plus UNE version
 *   est active par mission à tout instant (FinancialCalculationService::calculate()
 *   refuse d'en créer une deuxième tant qu'une est active) — aucune déduplication de
 *   version supplémentaire n'est donc nécessaire ici.
 * - Valeur documentée : FirmInvoice/InstrumentistStatement STANDARD émis (SENT/PAID,
 *   jamais GENERATED — §4 du lot) + leurs corrections ISSUED (même formule que
 *   DocumentPaymentService::computeBalance(), répliquée en SQL pour éviter un appel
 *   PHP par document — voir documentBalanceDerivedTable()).
 * - Flux monétaires : Payment append-only, direction convertie en sens de trésorerie
 *   RÉEL (§20 du lot — voir cashFlowSignExpression()).
 *
 * Timezone (§3/§4 du lot, D-066) : Mission.startAt/MissionExecution.actualStartAt sont
 * stockées en digits Europe/Brussels littéraux (BusinessDateTimeImmutableType) — aucune
 * conversion SQL nécessaire, comparaison directe. FinancialCalculationLine.effectiveAt/
 * Payment.paidAt sont des DATE pures (aucune heure, D-066 non applicable — voir
 * docblocks respectifs). FirmInvoice/InstrumentistStatement.sentAt sont de simples
 * `datetime_immutable` (digits UTC nus, PHP tourne en UTC) — comparées en UTC direct.
 * Limite documentée (D-077) : le regroupement par bucket de série temporelle sur
 * sentAt n'applique PAS de conversion Europe/Brussels (CONVERT_TZ avec zones nommées
 * indisponible sur cette instance MySQL — zoneinfo non chargée, vérifié) ; un document
 * émis très tôt/tard dans la journée peut apparaître dans le bucket UTC plutôt que le
 * bucket Bruxelles adjacent. Impact mineur et documenté, sans rapport avec la règle
 * D-066 (qui ne couvre que Mission.startAt/endAt).
 */
final class FinancialStatisticsQueryService
{
    private const ACTIVE_CALCULATION_STATUSES = ['CALCULATED', 'APPROVED', 'LOCKED'];
    private const ISSUED_DOCUMENT_STATUSES = ['SENT', 'PAID'];
    private const MAX_TIMESERIES_BUCKETS = 730;

    private readonly Connection $connection;

    public function __construct(EntityManagerInterface $em)
    {
        $this->connection = $em->getConnection();
    }

    // ── Overview (§7) ────────────────────────────────────────────────────

    public function overview(FinancialStatisticsFilter $filter): FinancialOverviewDto
    {
        $activity = $this->activityRow($filter);

        $generated = $this->generatedValueByCurrency($filter);
        $invoiced = $this->documentedValueByCurrency($filter, 'firm_invoice', 'firm_invoice_line', 'firm_id', $filter->firmId);
        $statement = $this->documentedValueByCurrency($filter, 'instrumentist_statement', 'instrumentist_statement_line', 'instrumentist_id', $filter->instrumentistId);
        $cashFlow = $this->cashFlowByCurrency($filter);
        $openFirm = $this->openBalanceByCurrency($filter, 'firm_invoice', 'firm_invoice_line', 'firm_id', $filter->firmId);
        $openInstrumentist = $this->openBalanceByCurrency($filter, 'instrumentist_statement', 'instrumentist_statement_line', 'instrumentist_id', $filter->instrumentistId);

        $currencies = [];
        foreach ($this->unionCurrencyKeys([$generated, $invoiced, $statement, $cashFlow, $openFirm, $openInstrumentist]) as $currency) {
            $g = $generated[$currency] ?? ['firmRevenue' => '0.00', 'instrumentistComp' => '0.00', 'missionCount' => 0];
            $inv = $invoiced[$currency] ?? ['gross' => '0.00', 'credit' => '0.00', 'debit' => '0.00', 'net' => '0.00'];
            $stmt = $statement[$currency] ?? ['gross' => '0.00', 'credit' => '0.00', 'debit' => '0.00', 'net' => '0.00'];
            $cf = $cashFlow[$currency] ?? ['in' => '0.00', 'out' => '0.00'];
            $ofb = $openFirm[$currency] ?? '0.00';
            $oib = $openInstrumentist[$currency] ?? '0.00';

            $totalValue = $this->add($g['firmRevenue'], $g['instrumentistComp']);
            $margin = $this->sub($g['firmRevenue'], $g['instrumentistComp']);
            $netCashFlow = $this->sub($cf['in'], $cf['out']);
            $avgMissionValue = $g['missionCount'] > 0
                ? number_format((float) $totalValue / $g['missionCount'], 2, '.', '')
                : '0.00';

            $currencies[] = new FinancialOverviewCurrencyDto(
                currency: $currency,
                generatedFirmRevenue: $g['firmRevenue'],
                generatedInstrumentistCompensation: $g['instrumentistComp'],
                generatedTotalValue: $totalValue,
                generatedContributionMargin: $margin,
                invoicedGrossAmount: $inv['gross'],
                invoiceCreditNotesAmount: $inv['credit'],
                invoiceDebitNotesAmount: $inv['debit'],
                invoicedNetAmount: $inv['net'],
                statementGrossAmount: $stmt['gross'],
                statementCreditNotesAmount: $stmt['credit'],
                statementDebitNotesAmount: $stmt['debit'],
                statementNetAmount: $stmt['net'],
                paymentsIn: $cf['in'],
                paymentsOut: $cf['out'],
                netCashFlow: $netCashFlow,
                openFirmBalance: $ofb,
                openInstrumentistBalance: $oib,
                averageMissionValue: $avgMissionValue,
            );
        }

        return new FinancialOverviewDto(
            from: $filter->from,
            to: $filter->to,
            activity: $activity,
            currencies: $currencies,
        );
    }

    // ── Timeseries (§11) ─────────────────────────────────────────────────

    /** @return FinancialTimeSeriesPointDto[] */
    public function timeseries(FinancialStatisticsFilter $filter, StatisticsGranularity $granularity): array
    {
        $buckets = $this->generateBuckets($filter->from, $filter->to, $granularity);

        $missionCounts = $this->bucketedMissionCounts($filter, $granularity);
        $generated = $this->bucketedGeneratedValue($filter, $granularity);
        $invoiced = $this->bucketedDocumentNet($filter, $granularity, 'firm_invoice', 'firm_invoice_line', 'firm_id', $filter->firmId);
        $statement = $this->bucketedDocumentNet($filter, $granularity, 'instrumentist_statement', 'instrumentist_statement_line', 'instrumentist_id', $filter->instrumentistId);
        $cashFlow = $this->bucketedCashFlow($filter, $granularity);

        $points = [];
        foreach ($buckets as $bucketKey => [$start, $end]) {
            $currencyKeys = $this->unionCurrencyKeys([
                $generated[$bucketKey] ?? [],
                $invoiced[$bucketKey] ?? [],
                $statement[$bucketKey] ?? [],
                $cashFlow[$bucketKey] ?? [],
            ]);

            $currencies = [];
            foreach ($currencyKeys as $currency) {
                $g = ($generated[$bucketKey] ?? [])[$currency] ?? ['firmRevenue' => '0.00', 'instrumentistComp' => '0.00'];
                $inv = ($invoiced[$bucketKey] ?? [])[$currency] ?? '0.00';
                $stmt = ($statement[$bucketKey] ?? [])[$currency] ?? '0.00';
                $cf = ($cashFlow[$bucketKey] ?? [])[$currency] ?? ['in' => '0.00', 'out' => '0.00'];

                $currencies[] = new FinancialTimeSeriesCurrencyAmountsDto(
                    currency: $currency,
                    generatedFirmRevenue: $g['firmRevenue'],
                    generatedInstrumentistCompensation: $g['instrumentistComp'],
                    invoicedNetAmount: $inv,
                    statementNetAmount: $stmt,
                    paymentsIn: $cf['in'],
                    paymentsOut: $cf['out'],
                );
            }

            $points[] = new FinancialTimeSeriesPointDto(
                periodStart: $start,
                periodEnd: $end,
                missionCount: $missionCounts[$bucketKey] ?? 0,
                currencies: $currencies,
            );
        }

        return $points;
    }

    // ── Pipeline (§17) ───────────────────────────────────────────────────

    public function pipeline(FinancialStatisticsFilter $filter): FinancialPipelineDto
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$fBusiness, $tBusiness] = [$this->businessDateTimeParam($filter->from), $this->businessDateTimeParam($filter->to)];

        // §17 — mission VALIDATED sans AUCUN FinancialCalculation (première ligne du
        // pipeline, jamais recalculée ici : simple absence, aucune double comptabilité
        // possible avec les autres compteurs qui portent tous sur des FinancialCalculation
        // existants). Même règle métier — sans la fenêtre from/to — que
        // MissionFilter::validatedWithoutCalculation (MissionService::list(), diagnostic
        // tarifs instrumentistes 2026-08-05, tuile dashboard cliquable) : toute évolution
        // de cette condition doit être reportée sur les deux implémentations.
        $sql = "SELECT COUNT(*) FROM mission m
                WHERE m.status = 'VALIDATED'
                  AND COALESCE((SELECT me.actual_start_at FROM mission_execution me WHERE me.mission_id = m.id), m.start_at) >= :fBusiness
                  AND COALESCE((SELECT me.actual_start_at FROM mission_execution me WHERE me.mission_id = m.id), m.start_at) < :tBusiness
                  AND NOT EXISTS (SELECT 1 FROM financial_calculation fc WHERE fc.mission_id = m.id)
                  $missionWhere";
        $validatedMissionsWithoutCalculation = (int) $this->connection->fetchOne($sql, $missionParams + ['fBusiness' => $fBusiness, 'tBusiness' => $tBusiness], $missionTypes + ['fBusiness' => ParameterType::STRING, 'tBusiness' => ParameterType::STRING]);

        $sql = "SELECT COUNT(*) FROM financial_calculation fc
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fc.status = 'CALCULATED'
                  AND fc.effective_at >= :fDate AND fc.effective_at < :tDate
                  $missionWhere";
        $calculationsAwaitingApproval = (int) $this->connection->fetchOne($sql, $missionParams + ['fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to)], $missionTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        // §17 — APPROVED (ou LOCKED, jamais documenté = anomalie identique) sans AUCUNE
        // ligne assignée (ni firm_invoice_line ni instrumentist_statement_line) —
        // disjoint de "partiellement documenté" (au moins une ligne assignée, au moins
        // une libre) par construction (EXISTS vs NOT EXISTS opposés).
        $sql = "SELECT COUNT(*) FROM financial_calculation fc
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fc.status IN ('APPROVED','LOCKED')
                  AND fc.effective_at >= :fDate AND fc.effective_at < :tDate
                  AND NOT EXISTS (
                    SELECT 1 FROM financial_calculation_line fcl
                    WHERE fcl.financial_calculation_id = fc.id
                      AND (fcl.id IN (SELECT financial_calculation_line_id FROM firm_invoice_line WHERE financial_calculation_line_id IS NOT NULL)
                           OR fcl.id IN (SELECT financial_calculation_line_id FROM instrumentist_statement_line WHERE financial_calculation_line_id IS NOT NULL))
                  )
                  $missionWhere";
        $approvedCalculationsWithoutDocuments = (int) $this->connection->fetchOne($sql, $missionParams + ['fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to)], $missionTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $sql = "SELECT COUNT(*) FROM financial_calculation fc
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fc.status IN ('APPROVED','LOCKED')
                  AND fc.effective_at >= :fDate AND fc.effective_at < :tDate
                  AND EXISTS (
                    SELECT 1 FROM financial_calculation_line fcl
                    WHERE fcl.financial_calculation_id = fc.id
                      AND (fcl.id IN (SELECT financial_calculation_line_id FROM firm_invoice_line WHERE financial_calculation_line_id IS NOT NULL)
                           OR fcl.id IN (SELECT financial_calculation_line_id FROM instrumentist_statement_line WHERE financial_calculation_line_id IS NOT NULL))
                  )
                  AND EXISTS (
                    SELECT 1 FROM financial_calculation_line fcl2
                    WHERE fcl2.financial_calculation_id = fc.id
                      AND fcl2.id NOT IN (SELECT financial_calculation_line_id FROM firm_invoice_line WHERE financial_calculation_line_id IS NOT NULL)
                      AND fcl2.id NOT IN (SELECT financial_calculation_line_id FROM instrumentist_statement_line WHERE financial_calculation_line_id IS NOT NULL)
                  )
                  $missionWhere";
        $partiallyDocumentedCalculations = (int) $this->connection->fetchOne($sql, $missionParams + ['fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to)], $missionTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $generatedInvoicesNotIssued = $this->countGeneratedNotIssued($filter, 'firm_invoice', 'firm_invoice_line');
        $generatedStatementsNotIssued = $this->countGeneratedNotIssued($filter, 'instrumentist_statement', 'instrumentist_statement_line');

        $issuedInvoicesWithOpenBalance = $this->countOpenBalanceDocuments($filter, 'firm_invoice', 'firm_invoice_line', 'firm_id', $filter->firmId);
        $issuedStatementsWithOpenBalance = $this->countOpenBalanceDocuments($filter, 'instrumentist_statement', 'instrumentist_statement_line', 'instrumentist_id', $filter->instrumentistId);

        $overpaidInvoices = $this->countOverpaidDocuments($filter, 'firm_invoice', 'firm_invoice_line', 'firm_id', $filter->firmId);
        $overpaidStatements = $this->countOverpaidDocuments($filter, 'instrumentist_statement', 'instrumentist_statement_line', 'instrumentist_id', $filter->instrumentistId);

        return new FinancialPipelineDto(
            validatedMissionsWithoutCalculation: $validatedMissionsWithoutCalculation,
            calculationsAwaitingApproval: $calculationsAwaitingApproval,
            approvedCalculationsWithoutDocuments: $approvedCalculationsWithoutDocuments,
            partiallyDocumentedCalculations: $partiallyDocumentedCalculations,
            generatedInvoicesNotIssued: $generatedInvoicesNotIssued,
            generatedStatementsNotIssued: $generatedStatementsNotIssued,
            issuedInvoicesWithOpenBalance: $issuedInvoicesWithOpenBalance,
            issuedStatementsWithOpenBalance: $issuedStatementsWithOpenBalance,
            overpaidDocumentsAwaitingRefund: $overpaidInvoices + $overpaidStatements,
        );
    }

    // ── Activité (§2/§4) ─────────────────────────────────────────────────

    private function activityRow(FinancialStatisticsFilter $filter): FinancialOverviewActivityDto
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');

        $sql = "SELECT
                    COUNT(*) AS missionCount,
                    SUM(CASE WHEN me.id IS NOT NULL THEN 1 ELSE 0 END) AS executedMissionCount,
                    SUM(CASE WHEN m.status = 'VALIDATED' THEN 1 ELSE 0 END) AS validatedMissionCount,
                    AVG(me.actual_duration_minutes) AS avgDuration
                FROM mission m
                LEFT JOIN mission_execution me ON me.mission_id = m.id
                WHERE COALESCE(me.actual_start_at, m.start_at) >= :fBusiness
                  AND COALESCE(me.actual_start_at, m.start_at) < :tBusiness
                  $missionWhere";

        $row = $this->connection->fetchAssociative($sql, $missionParams + [
            'fBusiness' => $this->businessDateTimeParam($filter->from),
            'tBusiness' => $this->businessDateTimeParam($filter->to),
        ], $missionTypes + ['fBusiness' => ParameterType::STRING, 'tBusiness' => ParameterType::STRING]);

        return new FinancialOverviewActivityDto(
            missionCount: (int) ($row['missionCount'] ?? 0),
            executedMissionCount: (int) ($row['executedMissionCount'] ?? 0),
            validatedMissionCount: (int) ($row['validatedMissionCount'] ?? 0),
            averageExecutionDurationMinutes: (int) round((float) ($row['avgDuration'] ?? 0)),
        );
    }

    // ── Valeur générée (§8/§9) ───────────────────────────────────────────

    /** @return array<string, array{firmRevenue: string, instrumentistComp: string, missionCount: int}> */
    private function generatedValueByCurrency(FinancialStatisticsFilter $filter): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');

        $sql = "SELECT
                    fcl.currency,
                    SUM(CASE WHEN fcl.line_type IN ('FIRM_INTERVENTION_FEE','FIRM_MATERIAL_FEE') THEN fcl.total_amount ELSE 0 END) AS firmRevenue,
                    SUM(CASE WHEN fcl.line_type IN ('INSTRUMENTIST_HOURLY','INSTRUMENTIST_CONSULTATION_FEE') THEN fcl.total_amount ELSE 0 END) AS instrumentistComp,
                    COUNT(DISTINCT fc.mission_id) AS missionCount
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY fcl.currency";

        $rows = $this->connection->fetchAllAssociative($sql, $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from),
            'tDate' => $this->dateParam($filter->to),
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['currency']] = [
                'firmRevenue' => $this->money($row['firmRevenue']),
                'instrumentistComp' => $this->money($row['instrumentistComp']),
                'missionCount' => (int) $row['missionCount'],
            ];
        }
        return $result;
    }

    // ── Valeur documentée (§4/§12/§19) ───────────────────────────────────

    /** @return array<string, array{gross: string, credit: string, debit: string, net: string}> */
    private function documentedValueByCurrency(FinancialStatisticsFilter $filter, string $docTable, string $lineTable, string $ownerColumn, ?int $ownerId): array
    {
        $cte = $this->documentBalanceDerivedTable($docTable, $lineTable, $ownerColumn);
        [$populationWhere, $populationParams, $populationTypes] = $this->documentPopulationClause($filter, $docTable, $lineTable, $ownerColumn, $ownerId, 'root');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'root');

        $sql = "SELECT root.currency,
                       SUM(root.total_amount) AS gross,
                       SUM(bal.credit_amount) AS credit,
                       SUM(bal.debit_amount) AS debit,
                       SUM(bal.net_amount) AS net
                FROM $docTable root
                INNER JOIN ($cte) bal ON bal.root_id = root.id
                WHERE root.document_type = 'STANDARD'
                  AND root.status IN ('SENT','PAID')
                  AND root.sent_at >= :fUtc AND root.sent_at < :tUtc
                  $populationWhere $currencyWhere
                GROUP BY root.currency";

        $rows = $this->connection->fetchAllAssociative($sql, $populationParams + $currencyParams + [
            'fUtc' => $this->utcDateTimeParam($filter->from),
            'tUtc' => $this->utcDateTimeParam($filter->to),
        ], $populationTypes + $currencyTypes + ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['currency']] = [
                'gross' => $this->money($row['gross']),
                'credit' => $this->money($row['credit']),
                'debit' => $this->money($row['debit']),
                'net' => $this->money($row['net']),
            ];
        }
        return $result;
    }

    /**
     * §12/§19 — table dérivée : une ligne par document racine STANDARD, portant les
     * mêmes agrégats que DocumentPaymentService::computeBalance() (répliqués en SQL,
     * jamais un appel PHP par document — §22 du lot). `credit_amount`/`debit_amount`
     * ne comptent que les corrections ISSUED (SENT/PAID), jamais GENERATED/CANCELLED
     * (§19). `paid_amount` applique la même compatibilité legacy que computeBalance()
     * (document PAID sans aucun Payment => intégralement soldé).
     */
    private function documentBalanceDerivedTable(string $docTable, string $lineTable, string $ownerColumn): string
    {
        $paymentDocType = $docTable === 'firm_invoice' ? 'FIRM_INVOICE' : 'INSTRUMENTIST_STATEMENT';

        return "
            SELECT
                root.id AS root_id,
                root.currency AS currency,
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
                FROM $docTable c
                INNER JOIN $lineTable cl ON cl." . ($docTable === 'firm_invoice' ? 'invoice_id' : 'statement_id') . " = c.id
                WHERE c.document_type = 'CREDIT_NOTE' AND c.status IN ('SENT','PAID')
                GROUP BY c.corrects_document_id
            ) credits ON credits.root_id = root.id
            LEFT JOIN (
                SELECT c.corrects_document_id AS root_id, SUM(cl.total_amount) AS amt
                FROM $docTable c
                INNER JOIN $lineTable cl ON cl." . ($docTable === 'firm_invoice' ? 'invoice_id' : 'statement_id') . " = c.id
                WHERE c.document_type = 'DEBIT_NOTE' AND c.status IN ('SENT','PAID')
                GROUP BY c.corrects_document_id
            ) debits ON debits.root_id = root.id
            LEFT JOIN (
                SELECT document_id, SUM(amount) AS amt FROM payment
                WHERE document_type = '$paymentDocType' AND direction = 'INBOUND'
                GROUP BY document_id
            ) paid ON paid.document_id = root.id
            LEFT JOIN (
                SELECT document_id, SUM(amount) AS amt FROM payment
                WHERE document_type = '$paymentDocType' AND direction = 'OUTBOUND'
                GROUP BY document_id
            ) refunded ON refunded.document_id = root.id
            WHERE root.document_type = 'STANDARD'
        ";
    }

    // ── Flux monétaires (§20) ────────────────────────────────────────────

    /**
     * §20 — le sens de trésorerie RÉEL diffère de Payment.direction seul (voir docblock
     * de classe et FinancialOverviewCurrencyDto) : direction=INBOUND sur un FirmInvoice
     * est un encaissement réel ; direction=INBOUND sur un InstrumentistStatement est un
     * décaissement réel (l'entreprise règle l'instrumentiste). Convention testée par
     * FinancialStatisticsQueryServiceTest::test_instrumentist_statement_payment_counts_as_cash_out().
     *
     * @return array<string, array{in: string, out: string}>
     */
    private function cashFlowByCurrency(FinancialStatisticsFilter $filter): array
    {
        [$populationWhere, $populationParams, $populationTypes] = $this->paymentPopulationClause($filter);

        $sql = "SELECT
                    p.currency,
                    SUM(CASE WHEN (p.document_type = 'FIRM_INVOICE' AND p.direction = 'INBOUND')
                               OR (p.document_type = 'INSTRUMENTIST_STATEMENT' AND p.direction = 'OUTBOUND')
                             THEN p.amount ELSE 0 END) AS cashIn,
                    SUM(CASE WHEN (p.document_type = 'INSTRUMENTIST_STATEMENT' AND p.direction = 'INBOUND')
                               OR (p.document_type = 'FIRM_INVOICE' AND p.direction = 'OUTBOUND')
                             THEN p.amount ELSE 0 END) AS cashOut
                FROM payment p
                WHERE p.paid_at >= :fDate AND p.paid_at < :tDate
                  " . ($filter->currency !== null ? 'AND p.currency = :currency' : '') . "
                  $populationWhere
                GROUP BY p.currency";

        $params = $populationParams + ['fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to)];
        $types = $populationTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING];
        if ($filter->currency !== null) {
            $params['currency'] = $filter->currency;
            $types['currency'] = ParameterType::STRING;
        }

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['currency']] = ['in' => $this->money($row['cashIn']), 'out' => $this->money($row['cashOut'])];
        }
        return $result;
    }

    // ── Soldes ouverts (§7) ──────────────────────────────────────────────

    /** @return array<string, string> */
    private function openBalanceByCurrency(FinancialStatisticsFilter $filter, string $docTable, string $lineTable, string $ownerColumn, ?int $ownerId): array
    {
        $cte = $this->documentBalanceDerivedTable($docTable, $lineTable, $ownerColumn);
        [$populationWhere, $populationParams, $populationTypes] = $this->documentPopulationClause($filter, $docTable, $lineTable, $ownerColumn, $ownerId, 'root');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'root');

        $sql = "SELECT root.currency,
                       SUM(GREATEST(0, bal.net_amount - bal.paid_amount + bal.refunded_amount)) AS remaining
                FROM $docTable root
                INNER JOIN ($cte) bal ON bal.root_id = root.id
                WHERE root.document_type = 'STANDARD'
                  AND root.status IN ('SENT','PAID')
                  AND root.sent_at >= :fUtc AND root.sent_at < :tUtc
                  $populationWhere $currencyWhere
                GROUP BY root.currency";

        $rows = $this->connection->fetchAllAssociative($sql, $populationParams + $currencyParams + [
            'fUtc' => $this->utcDateTimeParam($filter->from),
            'tUtc' => $this->utcDateTimeParam($filter->to),
        ], $populationTypes + $currencyTypes + ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['currency']] = $this->money($row['remaining']);
        }
        return $result;
    }

    // ── Pipeline helpers ─────────────────────────────────────────────────

    /**
     * §4/§17 du lot — un document GENERATED n'a pas encore de `sentAt` (par
     * définition, jamais émis) : sa date de rattachement pour cette catégorie du
     * pipeline est `createdAt` (horodatage serveur UTC nu, comme sentAt une fois émis).
     */
    private function countGeneratedNotIssued(FinancialStatisticsFilter $filter, string $docTable, string $lineTable): int
    {
        [$populationWhere, $populationParams, $populationTypes] = $this->documentPopulationClause($filter, $docTable, $lineTable, $docTable === 'firm_invoice' ? 'firm_id' : 'instrumentist_id', $docTable === 'firm_invoice' ? $filter->firmId : $filter->instrumentistId, 'root');

        $sql = "SELECT COUNT(*) FROM $docTable root
                WHERE root.document_type = 'STANDARD' AND root.status = 'GENERATED'
                  AND root.created_at >= :fUtc AND root.created_at < :tUtc
                $populationWhere";

        return (int) $this->connection->fetchOne($sql, $populationParams + [
            'fUtc' => $this->utcDateTimeParam($filter->from), 'tUtc' => $this->utcDateTimeParam($filter->to),
        ], $populationTypes + ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING]);
    }

    private function countOpenBalanceDocuments(FinancialStatisticsFilter $filter, string $docTable, string $lineTable, string $ownerColumn, ?int $ownerId): int
    {
        $cte = $this->documentBalanceDerivedTable($docTable, $lineTable, $ownerColumn);
        [$populationWhere, $populationParams, $populationTypes] = $this->documentPopulationClause($filter, $docTable, $lineTable, $ownerColumn, $ownerId, 'root');

        $sql = "SELECT COUNT(*) FROM $docTable root
                INNER JOIN ($cte) bal ON bal.root_id = root.id
                WHERE root.document_type = 'STANDARD' AND root.status IN ('SENT','PAID')
                  AND root.sent_at >= :fUtc AND root.sent_at < :tUtc
                  AND (bal.net_amount - bal.paid_amount + bal.refunded_amount) > 0.001
                  $populationWhere";

        return (int) $this->connection->fetchOne($sql, $populationParams + [
            'fUtc' => $this->utcDateTimeParam($filter->from),
            'tUtc' => $this->utcDateTimeParam($filter->to),
        ], $populationTypes + ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING]);
    }

    private function countOverpaidDocuments(FinancialStatisticsFilter $filter, string $docTable, string $lineTable, string $ownerColumn, ?int $ownerId): int
    {
        $cte = $this->documentBalanceDerivedTable($docTable, $lineTable, $ownerColumn);
        [$populationWhere, $populationParams, $populationTypes] = $this->documentPopulationClause($filter, $docTable, $lineTable, $ownerColumn, $ownerId, 'root');

        $sql = "SELECT COUNT(*) FROM $docTable root
                INNER JOIN ($cte) bal ON bal.root_id = root.id
                WHERE root.document_type = 'STANDARD' AND root.status IN ('SENT','PAID')
                  AND root.sent_at >= :fUtc AND root.sent_at < :tUtc
                  AND (bal.paid_amount - bal.refunded_amount - bal.net_amount) > 0.001
                  $populationWhere";

        return (int) $this->connection->fetchOne($sql, $populationParams + [
            'fUtc' => $this->utcDateTimeParam($filter->from),
            'tUtc' => $this->utcDateTimeParam($filter->to),
        ], $populationTypes + ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING]);
    }

    // ── Timeseries bucket helpers (§11) ──────────────────────────────────

    /** @return array<string, array{firmRevenue: string, instrumentistComp: string}[]> keyed by bucket then currency */
    private function bucketedGeneratedValue(FinancialStatisticsFilter $filter, StatisticsGranularity $granularity): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'fcl');
        $bucketExpr = $this->truncateSql('fcl.effective_at', $granularity);

        $sql = "SELECT $bucketExpr AS bucket, fcl.currency,
                    SUM(CASE WHEN fcl.line_type IN ('FIRM_INTERVENTION_FEE','FIRM_MATERIAL_FEE') THEN fcl.total_amount ELSE 0 END) AS firmRevenue,
                    SUM(CASE WHEN fcl.line_type IN ('INSTRUMENTIST_HOURLY','INSTRUMENTIST_CONSULTATION_FEE') THEN fcl.total_amount ELSE 0 END) AS instrumentistComp
                FROM financial_calculation_line fcl
                INNER JOIN financial_calculation fc ON fc.id = fcl.financial_calculation_id AND fc.status IN ('CALCULATED','APPROVED','LOCKED')
                INNER JOIN mission m ON m.id = fc.mission_id
                WHERE fcl.effective_at >= :fDate AND fcl.effective_at < :tDate
                  $missionWhere $currencyWhere
                GROUP BY bucket, fcl.currency";

        $rows = $this->connection->fetchAllAssociative($sql, $missionParams + $currencyParams + [
            'fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to),
        ], $missionTypes + $currencyTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['bucket']][$row['currency']] = [
                'firmRevenue' => $this->money($row['firmRevenue']),
                'instrumentistComp' => $this->money($row['instrumentistComp']),
            ];
        }
        return $result;
    }

    /** @return array<string, array<string, string>> keyed by bucket then currency => net */
    private function bucketedDocumentNet(FinancialStatisticsFilter $filter, StatisticsGranularity $granularity, string $docTable, string $lineTable, string $ownerColumn, ?int $ownerId): array
    {
        $cte = $this->documentBalanceDerivedTable($docTable, $lineTable, $ownerColumn);
        [$populationWhere, $populationParams, $populationTypes] = $this->documentPopulationClause($filter, $docTable, $lineTable, $ownerColumn, $ownerId, 'root');
        [$currencyWhere, $currencyParams, $currencyTypes] = $this->currencyClause($filter, 'root');
        $bucketExpr = $this->truncateSql('root.sent_at', $granularity);

        $sql = "SELECT $bucketExpr AS bucket, root.currency, SUM(bal.net_amount) AS net
                FROM $docTable root
                INNER JOIN ($cte) bal ON bal.root_id = root.id
                WHERE root.document_type = 'STANDARD' AND root.status IN ('SENT','PAID')
                  AND root.sent_at >= :fUtc AND root.sent_at < :tUtc
                  $populationWhere $currencyWhere
                GROUP BY bucket, root.currency";

        $rows = $this->connection->fetchAllAssociative($sql, $populationParams + $currencyParams + [
            'fUtc' => $this->utcDateTimeParam($filter->from), 'tUtc' => $this->utcDateTimeParam($filter->to),
        ], $populationTypes + $currencyTypes + ['fUtc' => ParameterType::STRING, 'tUtc' => ParameterType::STRING]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['bucket']][$row['currency']] = $this->money($row['net']);
        }
        return $result;
    }

    /** @return array<string, array{in: string, out: string}[]> keyed by bucket then currency */
    private function bucketedCashFlow(FinancialStatisticsFilter $filter, StatisticsGranularity $granularity): array
    {
        [$populationWhere, $populationParams, $populationTypes] = $this->paymentPopulationClause($filter);
        $bucketExpr = $this->truncateSql('p.paid_at', $granularity);

        $sql = "SELECT $bucketExpr AS bucket, p.currency,
                    SUM(CASE WHEN (p.document_type = 'FIRM_INVOICE' AND p.direction = 'INBOUND')
                               OR (p.document_type = 'INSTRUMENTIST_STATEMENT' AND p.direction = 'OUTBOUND')
                             THEN p.amount ELSE 0 END) AS cashIn,
                    SUM(CASE WHEN (p.document_type = 'INSTRUMENTIST_STATEMENT' AND p.direction = 'INBOUND')
                               OR (p.document_type = 'FIRM_INVOICE' AND p.direction = 'OUTBOUND')
                             THEN p.amount ELSE 0 END) AS cashOut
                FROM payment p
                WHERE p.paid_at >= :fDate AND p.paid_at < :tDate
                  " . ($filter->currency !== null ? 'AND p.currency = :currency' : '') . "
                  $populationWhere
                GROUP BY bucket, p.currency";

        $params = $populationParams + ['fDate' => $this->dateParam($filter->from), 'tDate' => $this->dateParam($filter->to)];
        $types = $populationTypes + ['fDate' => ParameterType::STRING, 'tDate' => ParameterType::STRING];
        if ($filter->currency !== null) {
            $params['currency'] = $filter->currency;
            $types['currency'] = ParameterType::STRING;
        }

        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['bucket']][$row['currency']] = ['in' => $this->money($row['cashIn']), 'out' => $this->money($row['cashOut'])];
        }
        return $result;
    }

    /** @return array<string, int> keyed by bucket */
    private function bucketedMissionCounts(FinancialStatisticsFilter $filter, StatisticsGranularity $granularity): array
    {
        [$missionWhere, $missionParams, $missionTypes] = $this->missionPopulationClause($filter, 'm');
        $bucketExpr = $this->truncateSql('COALESCE(me.actual_start_at, m.start_at)', $granularity);

        $sql = "SELECT $bucketExpr AS bucket, COUNT(*) AS c
                FROM mission m
                LEFT JOIN mission_execution me ON me.mission_id = m.id
                WHERE COALESCE(me.actual_start_at, m.start_at) >= :fBusiness
                  AND COALESCE(me.actual_start_at, m.start_at) < :tBusiness
                  $missionWhere
                GROUP BY bucket";

        $rows = $this->connection->fetchAllAssociative($sql, $missionParams + [
            'fBusiness' => $this->businessDateTimeParam($filter->from), 'tBusiness' => $this->businessDateTimeParam($filter->to),
        ], $missionTypes + ['fBusiness' => ParameterType::STRING, 'tBusiness' => ParameterType::STRING]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['bucket']] = (int) $row['c'];
        }
        return $result;
    }

    /**
     * §11 — génère TOUS les buckets attendus sur [from, to), y compris ceux sans
     * donnée (présents avec zéro, jamais omis). Clé = date de début du bucket au
     * format 'Y-m-d' (même format que la troncature SQL, pour un merge PHP direct).
     *
     * @return array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function generateBuckets(\DateTimeImmutable $from, \DateTimeImmutable $to, StatisticsGranularity $granularity): array
    {
        $businessTz = new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE);
        $cursor = $from->setTimezone($businessTz);
        $cursor = match ($granularity) {
            StatisticsGranularity::DAY => $cursor->setTime(0, 0),
            StatisticsGranularity::WEEK => $cursor->setTime(0, 0)->modify('monday this week'),
            StatisticsGranularity::MONTH => $cursor->setTime(0, 0)->modify('first day of this month'),
        };
        $step = match ($granularity) {
            StatisticsGranularity::DAY => '+1 day',
            StatisticsGranularity::WEEK => '+1 week',
            StatisticsGranularity::MONTH => '+1 month',
        };

        $buckets = [];
        $count = 0;
        while ($cursor < $to) {
            if (++$count > self::MAX_TIMESERIES_BUCKETS) {
                throw new InvalidStatisticsFilterException(sprintf('Période trop large pour la granularité %s (%d buckets max) — réduisez from/to ou augmentez la granularité.', $granularity->value, self::MAX_TIMESERIES_BUCKETS));
            }
            $next = $cursor->modify($step);
            $buckets[$cursor->format('Y-m-d')] = [$cursor, $next];
            $cursor = $next;
        }
        return $buckets;
    }

    private function truncateSql(string $column, StatisticsGranularity $granularity): string
    {
        return match ($granularity) {
            StatisticsGranularity::DAY => "DATE($column)",
            StatisticsGranularity::WEEK => "DATE_SUB(DATE($column), INTERVAL WEEKDAY($column) DAY)",
            StatisticsGranularity::MONTH => "DATE_FORMAT($column, '%Y-%m-01')",
        };
    }

    // ── Filtres partagés ─────────────────────────────────────────────────

    /**
     * §6 du lot — filtres de population appliqués directement sur la table `mission`
     * (alias fourni). firmId/interventionTypeId passent par une sous-requête EXISTS sur
     * mission_intervention (aucune colonne directe sur mission).
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, int>}
     */
    private function missionPopulationClause(FinancialStatisticsFilter $filter, string $alias): array
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

    /**
     * §6 du lot — filtres de population pour un document racine (FirmInvoice/
     * InstrumentistStatement). `$ownerColumn`/`$ownerId` gèrent firmId/instrumentistId
     * directement (colonne native du document) ; site/surgeon/interventionType passent
     * par une sous-requête EXISTS via les lignes documentaires jusqu'à Mission.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, int>}
     */
    private function documentPopulationClause(FinancialStatisticsFilter $filter, string $docTable, string $lineTable, string $ownerColumn, ?int $ownerId, string $alias): array
    {
        $conditions = [];
        $params = [];
        $types = [];

        if ($ownerId !== null) {
            $conditions[] = "$alias.$ownerColumn = :ownerId";
            $params['ownerId'] = $ownerId;
            $types['ownerId'] = ParameterType::INTEGER;
        }

        if ($filter->siteId !== null || $filter->surgeonId !== null || $filter->interventionTypeId !== null
            || ($filter->firmId !== null && $ownerColumn !== 'firm_id')
            || ($filter->instrumentistId !== null && $ownerColumn !== 'instrumentist_id')
        ) {
            $lineFk = $docTable === 'firm_invoice' ? 'invoice_id' : 'statement_id';
            $missionSub = [];
            if ($filter->siteId !== null) { $missionSub[] = 'm.site_id = :siteId'; $params['siteId'] = $filter->siteId; $types['siteId'] = ParameterType::INTEGER; }
            if ($filter->surgeonId !== null) { $missionSub[] = 'm.surgeon_id = :surgeonId'; $params['surgeonId'] = $filter->surgeonId; $types['surgeonId'] = ParameterType::INTEGER; }
            if ($filter->interventionTypeId !== null) {
                $missionSub[] = 'EXISTS (SELECT 1 FROM mission_intervention mit WHERE mit.mission_id = m.id AND mit.intervention_type_id = :interventionTypeId)';
                $params['interventionTypeId'] = $filter->interventionTypeId;
                $types['interventionTypeId'] = ParameterType::INTEGER;
            }
            if ($filter->firmId !== null && $ownerColumn !== 'firm_id') {
                $missionSub[] = 'EXISTS (SELECT 1 FROM mission_intervention mif WHERE mif.mission_id = m.id AND mif.primary_firm_id = :firmId)';
                $params['firmId'] = $filter->firmId;
                $types['firmId'] = ParameterType::INTEGER;
            }
            if ($filter->instrumentistId !== null && $ownerColumn !== 'instrumentist_id') {
                $missionSub[] = 'm.instrumentist_id = :instrumentistId';
                $params['instrumentistId'] = $filter->instrumentistId;
                $types['instrumentistId'] = ParameterType::INTEGER;
            }
            $missionWhere = implode(' AND ', $missionSub);
            $conditions[] = "EXISTS (SELECT 1 FROM $lineTable dl INNER JOIN mission m ON m.id = dl.mission_id WHERE dl.$lineFk = $alias.id AND $missionWhere)";
        }

        return [count($conditions) > 0 ? ('AND ' . implode(' AND ', $conditions)) : '', $params, $types];
    }

    /**
     * §6 du lot — filtres de population pour Payment (polymorphe : rattaché à un
     * FirmInvoice OU un InstrumentistStatement racine, jamais une correction — voir
     * DocumentPaymentService::resolveRoot(), Lot 6). Ignoré si aucun filtre de
     * population n'est actif (évite un EXISTS coûteux inutile).
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, int>}
     */
    private function paymentPopulationClause(FinancialStatisticsFilter $filter): array
    {
        if ($filter->siteId === null && $filter->surgeonId === null && $filter->instrumentistId === null && $filter->firmId === null && $filter->interventionTypeId === null) {
            return ['', [], []];
        }

        [$invoiceWhere, $invoiceParams, $invoiceTypes] = $this->documentPopulationClause($filter, 'firm_invoice', 'firm_invoice_line', 'firm_id', $filter->firmId, 'fi');
        [$stmtWhere, $stmtParams, $stmtTypes] = $this->documentPopulationClause($filter, 'instrumentist_statement', 'instrumentist_statement_line', 'instrumentist_id', $filter->instrumentistId, 'is');

        $invoiceWhere = $invoiceWhere !== '' ? substr($invoiceWhere, 4) : '1=1'; // strip leading "AND "
        $stmtWhere = $stmtWhere !== '' ? substr($stmtWhere, 4) : '1=1';

        $condition = "AND (
            (p.document_type = 'FIRM_INVOICE' AND EXISTS (SELECT 1 FROM firm_invoice fi WHERE fi.id = p.document_id AND $invoiceWhere))
            OR
            (p.document_type = 'INSTRUMENTIST_STATEMENT' AND EXISTS (SELECT 1 FROM instrumentist_statement `is` WHERE `is`.id = p.document_id AND $stmtWhere))
        )";

        return [$condition, $invoiceParams + $stmtParams, $invoiceTypes + $stmtTypes];
    }

    /** @return array{0: string, 1: array<string, mixed>, 2: array<string, int>} */
    private function currencyClause(FinancialStatisticsFilter $filter, string $alias): array
    {
        if ($filter->currency === null) {
            return ['', [], []];
        }
        return ["AND $alias.currency = :currencyFilter", ['currencyFilter' => $filter->currency], ['currencyFilter' => ParameterType::STRING]];
    }

    // ── Conversions temporelles (§3/§4, D-066) ──────────────────────────

    private function businessDateTimeParam(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE))->format('Y-m-d H:i:s');
    }

    private function utcDateTimeParam(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function dateParam(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone(BusinessDateTimeImmutableType::BUSINESS_TIMEZONE))->format('Y-m-d');
    }

    // ── Arithmétique décimale (§25 — jamais de float pour une somme) ────

    private function money(mixed $raw): string
    {
        return number_format((float) ($raw ?? 0), 2, '.', '');
    }

    private function add(string $a, string $b): string
    {
        return number_format((float) $a + (float) $b, 2, '.', '');
    }

    private function sub(string $a, string $b): string
    {
        return number_format((float) $a - (float) $b, 2, '.', '');
    }

    /** @param array<string, mixed>[] $maps @return string[] */
    private function unionCurrencyKeys(array $maps): array
    {
        $keys = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $key) {
                $keys[$key] = true;
            }
        }
        return array_keys($keys);
    }
}
