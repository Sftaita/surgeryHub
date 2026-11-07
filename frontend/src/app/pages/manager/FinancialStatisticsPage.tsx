import * as React from "react";
import { Alert, Box, Chip, CircularProgress, MenuItem, Paper, Select, Stack, Tab, Table, TableBody, TableCell, TableFooter, TableHead, TableRow, Tabs, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import StatFilterBar, { defaultFilterState, toApiFilter, type FilterState } from "../../features/financial-statistics/components/StatFilterBar";
import RankingTable, { type RankingColumn } from "../../features/financial-statistics/components/RankingTable";
import DrilldownTable from "../../features/financial-statistics/components/DrilldownTable";
import { ZeroFinancialDataExplanation } from "../../features/financial-statistics/components/ZeroFinancialDataExplanation";
import { Amount, ExportPdfButton, FinancialSummary, type AmountKind, type SummaryCard } from "../../features/financial-statistics/components/FinancialAmount";
import {
  exportStatisticsPdf,
  getByFirm,
  getByInstrumentist,
  getBySurgeon,
  getByIntervention,
  getTopMaterials,
  getCalculationsDrilldown,
  getDocumentsDrilldown,
  getMissionsDrilldown,
  getOverview,
  getPipeline,
  getTimeseries,
  type FirmStatistic,
  type InstrumentistStatistic,
  type InterventionStatistic,
  type MaterialStatistic,
  type Page,
  type StatisticsGranularity,
  type SurgeonStatistic,
  type TimeSeriesCurrencyAmounts,
} from "../../features/financial-statistics/api/financialStatistics.api";

const TABS = [
  "overview", "timeseries", "pipeline", "by-firm", "by-instrumentist",
  "by-surgeon", "by-intervention", "top-materials", "missions", "calculations", "documents",
] as const;
type TabKey = (typeof TABS)[number];

const TAB_LABELS: Record<TabKey, string> = {
  overview: "Vue d'ensemble",
  timeseries: "Séries temporelles",
  pipeline: "Pipeline",
  "by-firm": "Par firme",
  "by-instrumentist": "Par instrumentiste",
  "by-surgeon": "Par chirurgien",
  "by-intervention": "Par intervention",
  "top-materials": "Top matériels",
  missions: "Missions",
  calculations: "Calculs",
  documents: "Documents",
};

type ApiFilter = ReturnType<typeof toApiFilter>;

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — tableau de bord manager. Aucune donnée n'est
 * recalculée côté frontend — tout vient tel quel de l'API.
 *
 * D-186 — convention commune à tous les onglets : recettes « + » en vert, dépenses « − »
 * en rouge, solde net (calculé serveur) vert/rouge/neutre. Les trois couches Calculé,
 * Facturé / Décompté et Encaissé / Décaissé gardent chacune leur propre solde. Totaux
 * serveur sur tout l'ensemble filtré, export PDF serveur par onglet.
 */
export default function FinancialStatisticsPage() {
  const [filter, setFilter] = React.useState<FilterState>(defaultFilterState());
  const [tab, setTab] = React.useState<TabKey>("overview");
  const apiFilter = React.useMemo(() => toApiFilter(filter), [filter]);

  return (
    <Stack spacing={3}>
      <Typography variant="h6" fontWeight={700}>Statistiques financières</Typography>

      <Alert severity="info">
        Recettes en <Box component="strong" sx={{ color: "success.main" }}>vert (+)</Box>, dépenses en{" "}
        <Box component="strong" sx={{ color: "error.main" }}>rouge (−)</Box>, solde net = recettes − dépenses.
        Trois couches distinctes, jamais mélangées : <strong>Calculé</strong> (valorisation des missions) ;{" "}
        <strong>Facturé / Décompté</strong> (factures firmes et décomptes instrumentistes émis) ;{" "}
        <strong>Encaissé / Décaissé</strong> (paiements réellement enregistrés). Un écart entre "Calculé" et
        "Facturé / Décompté" signale une valorisation pas encore facturée — voir l'onglet <strong>Pipeline</strong>.
      </Alert>

      <StatFilterBar value={filter} onChange={setFilter} />

      <Tabs value={tab} onChange={(_, v) => setTab(v)} variant="scrollable" scrollButtons="auto">
        {TABS.map((t) => <Tab key={t} value={t} label={TAB_LABELS[t]} />)}
      </Tabs>

      {tab === "overview" && <OverviewTab filter={apiFilter} />}
      {tab === "timeseries" && <TimeseriesTab filter={apiFilter} />}
      {tab === "pipeline" && <PipelineTab filter={apiFilter} />}
      {tab === "by-firm" && <ByFirmTab filter={apiFilter} />}
      {tab === "by-instrumentist" && <ByInstrumentistTab filter={apiFilter} />}
      {tab === "by-surgeon" && <BySurgeonTab filter={apiFilter} />}
      {tab === "by-intervention" && <ByInterventionTab filter={apiFilter} />}
      {tab === "top-materials" && <TopMaterialsTab filter={apiFilter} />}
      {tab === "missions" && <DrilldownTab filter={apiFilter} kind="missions" />}
      {tab === "calculations" && <DrilldownTab filter={apiFilter} kind="calculations" />}
      {tab === "documents" && <DrilldownTab filter={apiFilter} kind="documents" />}
    </Stack>
  );
}

// ── Overview ────────────────────────────────────────────────────────────

function OverviewTab({ filter }: { filter: ApiFilter }) {
  const query = useQuery({ queryKey: ["fin-stats", "overview", filter], queryFn: () => getOverview(filter) });

  if (query.isLoading) return <CircularProgress size={24} />;
  if (!query.data) return null;
  const { activity, currencies } = query.data;

  return (
    <Stack spacing={3}>
      <Stack direction="row" justifyContent="flex-end">
        <ExportPdfButton onExport={() => exportStatisticsPdf("overview", filter)} />
      </Stack>

      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Typography variant="subtitle2" fontWeight={700} mb={1.5}>Activité (missions/exécutions — sans devise propre)</Typography>
        <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 2 }}>
          <Metric label="Missions" value={activity.missionCount} />
          <Metric label="Missions exécutées" value={activity.executedMissionCount} />
          <Metric label="Missions validées" value={activity.validatedMissionCount} />
          <Metric label="Durée moyenne (min)" value={activity.averageExecutionDurationMinutes} />
        </Box>
      </Paper>

      {currencies.length === 0 && <ZeroFinancialDataExplanation filter={filter} />}

      {currencies.map((c) => (
        <Paper key={c.currency} variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
          <Typography variant="subtitle2" fontWeight={700} mb={1.5}>Devise : {c.currency}</Typography>
          <Stack spacing={2.5}>
            <Layer title="Calculé — valorisation des missions" hint="Hors charges sociales, TVA et frais généraux.">
              <FinancialSummary groups={[{ currency: c.currency, cards: [
                { label: "Total recettes (CA firmes)", amount: c.generatedFirmRevenue, kind: "revenue" },
                { label: "Total dépenses (rémunérations instrumentistes)", amount: c.generatedInstrumentistCompensation, kind: "expense" },
                { label: "Solde net calculé", amount: c.generatedContributionMargin, kind: "net" },
              ] }]} />
            </Layer>
            <Layer title="Facturé / Décompté — documents émis">
              <FinancialSummary groups={[{ currency: c.currency, cards: [
                { label: "Net facturé aux firmes", amount: c.invoicedNetAmount, kind: "revenue" },
                { label: "Net décompté aux instrumentistes", amount: c.statementNetAmount, kind: "expense" },
                { label: "Solde net documenté", amount: c.documentedNetBalance, kind: "net" },
              ] }]} />
              <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(170px, 1fr))", gap: 2, mt: 1.5 }}>
                <MoneyMetric label="Brut facturé" amount={c.invoicedGrossAmount} kind="revenue" currency={c.currency} />
                <MoneyMetric label="Notes de crédit (factures)" amount={c.invoiceCreditNotesAmount} kind="amount" currency={c.currency} />
                <MoneyMetric label="Notes de débit (factures)" amount={c.invoiceDebitNotesAmount} kind="amount" currency={c.currency} />
                <MoneyMetric label="Brut décompté" amount={c.statementGrossAmount} kind="expense" currency={c.currency} />
                <MoneyMetric label="Notes de crédit (décomptes)" amount={c.statementCreditNotesAmount} kind="amount" currency={c.currency} />
                <MoneyMetric label="Notes de débit (décomptes)" amount={c.statementDebitNotesAmount} kind="amount" currency={c.currency} />
              </Box>
            </Layer>
            <Layer title="Encaissé / Décaissé — paiements enregistrés">
              <FinancialSummary groups={[{ currency: c.currency, cards: [
                { label: "Encaissé", amount: c.paymentsIn, kind: "revenue" },
                { label: "Décaissé", amount: c.paymentsOut, kind: "expense" },
                { label: "Flux net", amount: c.netCashFlow, kind: "net" },
              ] }]} />
            </Layer>
            <Layer title="Soldes ouverts sur documents émis">
              <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(170px, 1fr))", gap: 2 }}>
                <MoneyMetric label="Restant à recevoir des firmes" amount={c.openFirmBalance} kind="amount" currency={c.currency} warn={Number(c.openFirmBalance) > 0} />
                <MoneyMetric label="Restant à payer aux instrumentistes" amount={c.openInstrumentistBalance} kind="amount" currency={c.currency} warn={Number(c.openInstrumentistBalance) > 0} />
              </Box>
            </Layer>
          </Stack>
        </Paper>
      ))}
    </Stack>
  );
}

function Layer({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }) {
  return (
    <Box>
      <Stack direction="row" spacing={1} alignItems="baseline" mb={0.75} flexWrap="wrap">
        <Typography variant="caption" fontWeight={700} color="text.secondary" sx={{ textTransform: "uppercase", letterSpacing: 0.4 }}>{title}</Typography>
        {hint && <Typography variant="caption" color="text.secondary">{hint}</Typography>}
      </Stack>
      {children}
    </Box>
  );
}

function Metric({ label, value }: { label: string; value: number | string }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Typography variant="body1" fontWeight={500}>{value}</Typography>
    </Box>
  );
}

function MoneyMetric({ label, amount, kind, currency, warn }: { label: string; amount: string; kind: AmountKind; currency: string; warn?: boolean }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Typography variant="body1" component="div" sx={warn ? { "& span": { color: "warning.main" } } : undefined}>
        <Amount amount={amount} kind={kind} currency={currency} />
      </Typography>
    </Box>
  );
}

// ── Timeseries ──────────────────────────────────────────────────────────

const TIMESERIES_COLUMNS: { field: keyof TimeSeriesCurrencyAmounts; label: string; kind: AmountKind }[] = [
  { field: "generatedFirmRevenue", label: "Recettes calculées", kind: "revenue" },
  { field: "generatedInstrumentistCompensation", label: "Dépenses calculées", kind: "expense" },
  { field: "generatedNetBalance", label: "Solde calculé", kind: "net" },
  { field: "invoicedNetAmount", label: "Net facturé", kind: "revenue" },
  { field: "statementNetAmount", label: "Net décompté", kind: "expense" },
  { field: "paymentsIn", label: "Encaissé", kind: "revenue" },
  { field: "paymentsOut", label: "Décaissé", kind: "expense" },
  { field: "netCashFlow", label: "Flux net", kind: "net" },
];

function TimeseriesTab({ filter }: { filter: ApiFilter }) {
  const [granularity, setGranularity] = React.useState<StatisticsGranularity>("DAY");
  const query = useQuery({
    queryKey: ["fin-stats", "timeseries", filter, granularity],
    queryFn: () => getTimeseries(filter, granularity),
  });
  const totals = query.data?.totals ?? [];

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between" flexWrap="wrap" useFlexGap>
        <Stack direction="row" spacing={1} alignItems="center">
          <Typography variant="body2" color="text.secondary">Granularité</Typography>
          <Select size="small" value={granularity} onChange={(e) => setGranularity(e.target.value as StatisticsGranularity)} sx={{ width: 120 }}>
            <MenuItem value="DAY">Jour</MenuItem>
            <MenuItem value="WEEK">Semaine</MenuItem>
            <MenuItem value="MONTH">Mois</MenuItem>
          </Select>
        </Stack>
        <ExportPdfButton onExport={() => exportStatisticsPdf("timeseries", filter, { granularity })} />
      </Stack>

      {query.isLoading ? <CircularProgress size={24} /> : (
        <>
          <FinancialSummary groups={totals.map((t) => ({ currency: t.currency, cards: [
            { label: "Total recettes calculées", amount: t.generatedFirmRevenue, kind: "revenue" },
            { label: "Total dépenses calculées", amount: t.generatedInstrumentistCompensation, kind: "expense" },
            { label: "Solde net calculé", amount: t.generatedNetBalance, kind: "net" },
          ] }))} />
          <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
            <Box sx={{ overflowX: "auto" }}>
              <Table size="small" sx={{ "& td, & th": { px: 1.5, py: 0.9, whiteSpace: "nowrap" }, "& td": { fontVariantNumeric: "tabular-nums" } }}>
                <TableHead>
                  <TableRow>
                    <TableCell sx={{ fontWeight: 700 }}>Période</TableCell>
                    <TableCell sx={{ fontWeight: 700 }} align="right">Missions</TableCell>
                    {TIMESERIES_COLUMNS.map((col) => <TableCell key={col.field} sx={{ fontWeight: 700 }} align="right">{col.label}</TableCell>)}
                  </TableRow>
                </TableHead>
                <TableBody>
                  {(query.data?.points ?? []).map((p) => {
                    const rows = p.currencies.length > 0 ? p.currencies : [null];
                    return rows.map((c, i) => (
                      <TableRow key={`${p.periodStart}-${i}`} hover>
                        {i === 0 && <TableCell rowSpan={rows.length}>{new Date(p.periodStart).toLocaleDateString("fr-BE")}</TableCell>}
                        {i === 0 && <TableCell rowSpan={rows.length} align="right">{p.missionCount}</TableCell>}
                        {TIMESERIES_COLUMNS.map((col) => (
                          <TableCell key={col.field} align="right" sx={{ color: c ? undefined : "text.disabled" }}>
                            {c ? <Amount amount={c[col.field]} kind={col.kind} currency={c.currency} /> : "—"}
                          </TableCell>
                        ))}
                      </TableRow>
                    ));
                  })}
                </TableBody>
                {totals.length > 0 && (
                  <TableFooter>
                    {totals.map((t) => (
                      <TableRow key={t.currency} sx={{ bgcolor: "grey.50", "& td": { fontWeight: 700, color: "text.primary", fontSize: "0.875rem" } }}>
                        <TableCell colSpan={2}>TOTAL GÉNÉRAL{totals.length > 1 ? ` (${t.currency})` : ""}</TableCell>
                        {TIMESERIES_COLUMNS.map((col) => (
                          <TableCell key={col.field} align="right"><Amount amount={t[col.field]} kind={col.kind} currency={t.currency} strong /></TableCell>
                        ))}
                      </TableRow>
                    ))}
                  </TableFooter>
                )}
              </Table>
            </Box>
          </Paper>
        </>
      )}
    </Stack>
  );
}

// ── Pipeline ────────────────────────────────────────────────────────────

function PipelineTab({ filter }: { filter: ApiFilter }) {
  const query = useQuery({ queryKey: ["fin-stats", "pipeline", filter], queryFn: () => getPipeline(filter) });
  if (query.isLoading) return <CircularProgress size={24} />;
  if (!query.data) return null;
  const p = query.data;

  const items: { label: string; value: number; hint: string }[] = [
    { label: "Missions validées sans calcul", value: p.validatedMissionsWithoutCalculation, hint: "À valoriser" },
    { label: "Calculs en attente d'approbation", value: p.calculationsAwaitingApproval, hint: "CALCULATED" },
    { label: "Calculs approuvés sans document", value: p.approvedCalculationsWithoutDocuments, hint: "Rien facturé/décompté" },
    { label: "Calculs partiellement documentés", value: p.partiallyDocumentedCalculations, hint: "Facturation incomplète" },
    { label: "Factures générées non émises", value: p.generatedInvoicesNotIssued, hint: "GENERATED, jamais SENT" },
    { label: "Décomptes générés non émis", value: p.generatedStatementsNotIssued, hint: "GENERATED, jamais SENT" },
    { label: "Factures émises à solde ouvert", value: p.issuedInvoicesWithOpenBalance, hint: "Reste dû" },
    { label: "Décomptes émis à solde ouvert", value: p.issuedStatementsWithOpenBalance, hint: "Reste dû" },
    { label: "Documents en trop-perçu à rembourser", value: p.overpaidDocumentsAwaitingRefund, hint: "Remboursement en attente" },
  ];

  return (
    <Stack spacing={1.5}>
      <Typography variant="caption" color="text.secondary">
        Liste de suivi opérationnel : chaque compteur au-dessus de zéro pointe une étape de la chaîne
        Mission → Calcul → Facture/Décompte → Paiement qui attend une action manager (approuver, verrouiller,
        générer, émettre, ou relancer un paiement).
      </Typography>
      <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(240px, 1fr))", gap: 2 }}>
      {items.map((it) => (
        <Paper key={it.label} variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
          <Typography variant="caption" color="text.secondary">{it.label}</Typography>
          <Stack direction="row" alignItems="baseline" spacing={1}>
            <Typography variant="h4" fontWeight={700} color={it.value > 0 ? "warning.main" : "success.main"}>{it.value}</Typography>
            {it.value > 0 && <Chip size="small" label={it.hint} variant="outlined" />}
          </Stack>
        </Paper>
      ))}
      </Box>
    </Stack>
  );
}

// ── Classements ─────────────────────────────────────────────────────────

const PAGE_SIZE = 20;

function usePaginationState(defaultSortBy: string) {
  const [page, setPage] = React.useState(1);
  const [sortBy, setSortBy] = React.useState(defaultSortBy);
  const [sortDirection, setSortDirection] = React.useState<"ASC" | "DESC">("DESC");
  return { page, setPage, sortBy, setSortBy, sortDirection, setSortDirection };
}

type PaginationState = ReturnType<typeof usePaginationState>;
type Totals = NonNullable<Page<unknown>["totals"]>;

/** Colonne monétaire : cellule colorée/signée + cellule de la ligne TOTAL GÉNÉRAL (totaux serveur). */
function moneyColumn<T extends { currency: string }>(key: string, label: string, field: keyof T & string, kind: AmountKind, sortKey?: string): RankingColumn<T> {
  return {
    key,
    label,
    align: "right",
    sortKey,
    render: (r) => <Amount amount={r[field] as unknown as string} kind={kind} currency={r.currency} />,
    renderTotal: (t) => (t[field] !== undefined ? <Amount amount={t[field]} kind={kind} currency={t.currency} strong /> : null),
  };
}

function summaryGroups(totals: Totals | undefined, cards: (t: Totals[number]) => SummaryCard[]) {
  return (totals ?? []).map((t) => ({ currency: t.currency, cards: cards(t) }));
}

function RankingTab<T>({ query, columns, p, view, filter, maxWidth, caption, summary }: {
  query: { data?: Page<T>; isLoading: boolean };
  columns: RankingColumn<T>[];
  p: PaginationState;
  view: "by-firm" | "by-instrumentist" | "by-surgeon" | "by-intervention";
  filter: ApiFilter;
  maxWidth?: number;
  caption?: React.ReactNode;
  summary: (t: Totals[number]) => SummaryCard[];
}) {
  return (
    <Stack spacing={2} sx={{ width: "100%", maxWidth, mx: "auto" }}>
      {caption && <Typography variant="caption" color="text.secondary">{caption}</Typography>}
      <FinancialSummary groups={summaryGroups(query.data?.totals, summary)} />
      <RankingTable
        columns={columns}
        rows={query.data?.items ?? []}
        total={query.data?.total ?? 0}
        totals={query.data?.totals}
        page={p.page}
        limit={PAGE_SIZE}
        isLoading={query.isLoading}
        sortBy={p.sortBy}
        sortDirection={p.sortDirection}
        onPageChange={p.setPage}
        onSortChange={(sb, sd) => { p.setSortBy(sb); p.setSortDirection(sd); p.setPage(1); }}
        actions={<ExportPdfButton onExport={() => exportStatisticsPdf(view, filter, { sortBy: p.sortBy, sortDirection: p.sortDirection })} />}
      />
    </Stack>
  );
}

function ByFirmTab({ filter }: { filter: ApiFilter }) {
  const p = usePaginationState("generatedRevenue");
  const query = useQuery({
    queryKey: ["fin-stats", "by-firm", filter, p.page, p.sortBy, p.sortDirection],
    queryFn: () => getByFirm(filter, { page: p.page, limit: PAGE_SIZE, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<FirmStatistic>[] = [
    { key: "firm", label: "Firme", sortKey: "firmNameSnapshot", render: (r) => r.firmNameSnapshot },
    { key: "missions", label: "Missions", align: "right", sortKey: "missionCount", render: (r) => r.missionCount },
    moneyColumn("intervention", "CA interventions", "interventionRevenue", "revenue", "interventionRevenue"),
    moneyColumn("material", "CA matériel", "materialRevenue", "revenue", "materialRevenue"),
    moneyColumn("generated", "Total recettes", "generatedRevenue", "revenue", "generatedRevenue"),
    moneyColumn("net", "Net facturé", "invoicedNetAmount", "revenue", "invoicedNetAmount"),
    moneyColumn("paid", "Encaissé", "paidAmount", "revenue", "paidAmount"),
    moneyColumn("remaining", "Restant dû", "remainingAmount", "amount", "remainingAmount"),
    { key: "avg", label: "CA moyen / mission", align: "right", render: (r) => <Amount amount={r.averageRevenuePerMission} kind="amount" currency={r.currency} /> },
  ];

  return (
    <RankingTab
      query={query} columns={columns} p={p} view="by-firm" filter={filter}
      caption="Recettes réellement associées à chaque firme (calculées, facturées, encaissées). Aucune rémunération instrumentiste n'y est imputée : une mission peut concerner plusieurs firmes, aucun lien fiable n'existe."
      summary={(t) => [
        { label: "Total recettes calculées", amount: t.generatedRevenue, kind: "revenue" },
        { label: "Net facturé", amount: t.invoicedNetAmount, kind: "revenue" },
        { label: "Encaissé", amount: t.paidAmount, kind: "revenue" },
      ]}
    />
  );
}

function ByInstrumentistTab({ filter }: { filter: ApiFilter }) {
  const p = usePaginationState("generatedCompensation");
  const query = useQuery({
    queryKey: ["fin-stats", "by-instrumentist", filter, p.page, p.sortBy, p.sortDirection],
    queryFn: () => getByInstrumentist(filter, { page: p.page, limit: PAGE_SIZE, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<InstrumentistStatistic>[] = [
    { key: "name", label: "Instrumentiste", sortKey: "instrumentistNameSnapshot", render: (r) => r.instrumentistNameSnapshot },
    { key: "missions", label: "Missions", align: "right", sortKey: "missionCount", render: (r) => r.missionCount },
    { key: "minutes", label: "Heures", align: "right", sortKey: "executedMinutes", render: (r) => `${Math.floor(r.executedMinutes / 60)} h ${String(r.executedMinutes % 60).padStart(2, "0")}` },
    moneyColumn("hourly", "Rémun. horaire", "hourlyCompensation", "expense", "hourlyCompensation"),
    moneyColumn("consult", "Consultations", "consultationFees", "expense", "consultationFees"),
    moneyColumn("generated", "Total dépenses", "generatedCompensation", "expense", "generatedCompensation"),
    moneyColumn("net", "Net décompté", "statementNetAmount", "expense", "statementNetAmount"),
    moneyColumn("paid", "Décaissé", "paidAmount", "expense", "paidAmount"),
    moneyColumn("remaining", "Restant à payer", "remainingAmount", "amount", "remainingAmount"),
    { key: "avg", label: "Coût moyen / mission", align: "right", render: (r) => <Amount amount={r.averageCompensationPerMission} kind="amount" currency={r.currency} /> },
  ];

  return (
    <RankingTab
      query={query} columns={columns} p={p} view="by-instrumentist" filter={filter}
      caption="Les rémunérations des instrumentistes sont des dépenses pour SurgicalHub."
      summary={(t) => [
        { label: "Total dépenses calculées", amount: t.generatedCompensation, kind: "expense" },
        { label: "Net décompté", amount: t.statementNetAmount, kind: "expense" },
        { label: "Décaissé", amount: t.paidAmount, kind: "expense" },
      ]}
    />
  );
}

function BySurgeonTab({ filter }: { filter: ApiFilter }) {
  const p = usePaginationState("generatedFirmRevenue");
  const query = useQuery({
    queryKey: ["fin-stats", "by-surgeon", filter, p.page, p.sortBy, p.sortDirection],
    queryFn: () => getBySurgeon(filter, { page: p.page, limit: PAGE_SIZE, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<SurgeonStatistic>[] = [
    { key: "name", label: "Chirurgien", sortKey: "surgeonNameSnapshot", render: (r) => r.surgeonNameSnapshot },
    { key: "missions", label: "Missions", align: "right", sortKey: "missionCount", render: (r) => r.missionCount },
    { key: "executed", label: "Exécutées", align: "right", sortKey: "executedMissionCount", render: (r) => r.executedMissionCount },
    moneyColumn("firm", "CA firme", "generatedFirmRevenue", "revenue", "generatedFirmRevenue"),
    moneyColumn("instr", "Frais instrumentiste", "generatedInstrumentistCompensation", "expense", "generatedInstrumentistCompensation"),
    moneyColumn("net", "Solde net", "netBalance", "net", "netBalance"),
  ];

  return (
    <RankingTab
      query={query} columns={columns} p={p} view="by-surgeon" filter={filter} maxWidth={1000}
      caption="Le chirurgien est un axe analytique : ces montants ne constituent pas une rémunération versée au chirurgien. Couche « Calculé » uniquement."
      summary={(t) => [
        { label: "Total recettes", amount: t.generatedFirmRevenue, kind: "revenue" },
        { label: "Total dépenses", amount: t.generatedInstrumentistCompensation, kind: "expense" },
        { label: "Solde net", amount: t.netBalance, kind: "net" },
      ]}
    />
  );
}

function ByInterventionTab({ filter }: { filter: ApiFilter }) {
  const p = usePaginationState("interventionRevenue");
  const query = useQuery({
    queryKey: ["fin-stats", "by-intervention", filter, p.page, p.sortBy, p.sortDirection],
    queryFn: () => getByIntervention(filter, { page: p.page, limit: PAGE_SIZE, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<InterventionStatistic>[] = [
    {
      key: "intervention", label: "Intervention", sortKey: "interventionCodeSnapshot",
      render: (r) => (
        <Box sx={{ minWidth: 0 }}>
          <Typography variant="body2" fontWeight={600}>{r.interventionCodeSnapshot}</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: "block", lineHeight: 1.3 }}>{r.interventionNameSnapshot}</Typography>
        </Box>
      ),
    },
    { key: "count", label: "Interventions", align: "right", sortKey: "interventionCount", render: (r) => r.interventionCount },
    { key: "missions", label: "Missions", align: "right", sortKey: "missionCount", hideBelow: "xl", render: (r) => r.missionCount },
    moneyColumn("intervention", "CA interventions", "interventionRevenue", "revenue", "interventionRevenue"),
    moneyColumn("material", "CA matériel", "materialRevenue", "revenue", "materialRevenue"),
    moneyColumn("total", "Total recettes", "totalRevenue", "revenue", "totalRevenue"),
    {
      key: "avgRevenue", label: "Recette moy. / intervention", align: "right", sortKey: "averageRevenuePerIntervention", hideBelow: "lg",
      hint: "Total recettes ÷ nombre d'interventions de ce type (indicatif).",
      render: (r) => (r.interventionCount > 0 ? <Amount amount={r.averageRevenuePerIntervention} kind="amount" currency={r.currency} /> : "—"),
    },
    {
      key: "avgMissionCost", label: "Coût moyen de mission associée", align: "right", sortKey: "averageAssociatedMissionCost", hideBelow: "lg",
      hint: "Rémunération instrumentiste totale moyenne des missions comportant ce type. Indicatif : une mission à plusieurs types figure dans chaque groupe. Ce n'est pas un coût par intervention.",
      render: (r) => (r.averageAssociatedMissionCost !== null ? <Amount amount={r.averageAssociatedMissionCost} kind="amount" currency={r.currency} /> : "—"),
    },
    { key: "duration", label: "Durée moy. (min)", align: "right", sortKey: "averageDurationMinutes", hideBelow: "xl", render: (r) => r.averageDurationMinutes },
  ];

  return (
    <RankingTab
      query={query} columns={columns} p={p} view="by-intervention" filter={filter}
      caption={<>
        La rémunération instrumentiste appartient à la <strong>mission</strong>, jamais à une intervention : aucune part n'est
        imputée à un type d'intervention. « Coût moyen de mission associée » est un indicateur contextuel (rémunération totale
        moyenne des missions comportant ce type, une mission à plusieurs types figurant dans chaque groupe) — ce n'est pas un
        coût par intervention. Les dépenses ci-dessous sont comptées une seule fois par mission.
      </>}
      summary={(t) => [
        { label: "Total recettes (interventions + matériel)", amount: t.totalRevenue, kind: "revenue" },
        { label: "Total dépenses (au niveau mission)", amount: t.missionInstrumentistCompensation, kind: "expense" },
        { label: "Solde net", amount: t.netBalance, kind: "net" },
      ]}
    />
  );
}

function TopMaterialsTab({ filter }: { filter: ApiFilter }) {
  const p = usePaginationState("generatedRevenue");
  const query = useQuery({
    queryKey: ["fin-stats", "top-materials", filter, p.sortBy, p.sortDirection],
    queryFn: () => getTopMaterials(filter, { limit: 50, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<MaterialStatistic>[] = [
    { key: "ref", label: "Référence", render: (r) => r.materialReferenceSnapshot ?? "—" },
    { key: "name", label: "Matériel", render: (r) => r.materialNameSnapshot },
    { key: "firm", label: "Firme", render: (r) => r.firmSnapshot },
    { key: "qty", label: "Quantité", align: "right", sortKey: "quantity", render: (r) => r.quantity },
    { key: "missions", label: "Missions", align: "right", sortKey: "missionCount", render: (r) => r.missionCount },
    moneyColumn("revenue", "CA généré", "generatedRevenue", "revenue", "generatedRevenue"),
    { key: "avgUnit", label: "CA moyen / unité", align: "right", sortKey: "averageUnitRevenue", render: (r) => <Amount amount={r.averageUnitRevenue} kind="amount" currency={r.currency} /> },
  ];

  return (
    <Stack spacing={2}>
      <Typography variant="caption" color="text.secondary">
        Les 50 premiers matériels sont affichés ; le total couvre tous les matériels filtrés (l'export PDF les reprend tous).
      </Typography>
      <FinancialSummary groups={summaryGroups(query.data?.totals, (t) => [
        { label: "Total recettes matériel", amount: t.generatedRevenue, kind: "revenue" },
      ])} />
      <RankingTable
        columns={columns}
        rows={query.data?.items ?? []}
        total={query.data?.total ?? 0}
        totals={query.data?.totals}
        page={1}
        limit={50}
        isLoading={query.isLoading}
        sortBy={p.sortBy}
        sortDirection={p.sortDirection}
        onPageChange={() => {}}
        onSortChange={(sb, sd) => { p.setSortBy(sb); p.setSortDirection(sd); }}
        actions={<ExportPdfButton onExport={() => exportStatisticsPdf("top-materials", filter, { sortBy: p.sortBy, sortDirection: p.sortDirection })} />}
      />
    </Stack>
  );
}

// ── Drill-down ──────────────────────────────────────────────────────────

const DRILLDOWN_CAPTIONS: Record<"missions" | "calculations" | "documents", string> = {
  missions: "Liste brute des missions correspondant aux filtres, avec leur statut d'exécution — point de départ pour retrouver une mission précise derrière un chiffre agrégé.",
  calculations: "Tous les calculs financiers (quel que soit leur statut : CALCULATED, APPROVED, LOCKED, SUPERSEDED, CANCELLED) — utile pour repérer un calcul jamais approuvé/verrouillé.",
  documents: "Toutes les factures firmes (recettes, +) et tous les décomptes instrumentistes (dépenses, −) émis sur la période — vue transversale indépendante du détail par firme/instrumentiste.",
};

function DrilldownTab({ filter, kind }: { filter: ApiFilter; kind: "missions" | "calculations" | "documents" }) {
  const [page, setPage] = React.useState(1);
  const fn = kind === "missions" ? getMissionsDrilldown : kind === "calculations" ? getCalculationsDrilldown : getDocumentsDrilldown;
  const query = useQuery({
    queryKey: ["fin-stats", "drilldown", kind, filter, page],
    queryFn: () => fn(filter, { page, limit: 25 }),
  });

  return (
    <Stack spacing={1}>
      <Typography variant="caption" color="text.secondary">{DRILLDOWN_CAPTIONS[kind]}</Typography>
      <DrilldownTable
        rows={query.data?.items ?? []}
        total={query.data?.total ?? 0}
        page={page}
        limit={25}
        isLoading={query.isLoading}
        onPageChange={setPage}
      />
    </Stack>
  );
}
