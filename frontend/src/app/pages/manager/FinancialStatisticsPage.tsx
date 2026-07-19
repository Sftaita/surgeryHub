import * as React from "react";
import { Alert, Box, Chip, CircularProgress, MenuItem, Paper, Select, Stack, Tab, Tabs, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import StatFilterBar, { defaultFilterState, toApiFilter, type FilterState } from "../../features/financial-statistics/components/StatFilterBar";
import RankingTable, { type RankingColumn } from "../../features/financial-statistics/components/RankingTable";
import DrilldownTable from "../../features/financial-statistics/components/DrilldownTable";
import {
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
  type StatisticsGranularity,
  type SurgeonStatistic,
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

/**
 * EPIC Pilotage financier, Lot 7 (D-077) — tableau de bord manager. Fonctionnel avant
 * le design : chaque endpoint backend est exposé, les informations sont toujours
 * disponibles (pas d'état masqué), aucune donnée n'est recalculée côté frontend — tout
 * vient tel quel de l'API.
 */
export default function FinancialStatisticsPage() {
  const [filter, setFilter] = React.useState<FilterState>(defaultFilterState());
  const [tab, setTab] = React.useState<TabKey>("overview");
  const apiFilter = React.useMemo(() => toApiFilter(filter), [filter]);

  return (
    <Stack spacing={3}>
      <Typography variant="h6" fontWeight={700}>Statistiques financières</Typography>

      <Alert severity="info">
        Ces chiffres distinguent trois couches, qui peuvent diverger : <strong>Généré</strong> (valeur figée par les
        calculs financiers verrouillés — voir la fiche mission) ; <strong>Documenté</strong> (montants réellement
        inscrits sur une facture firme ou un décompte instrumentiste émis) ; <strong>Encaissé/Décaissé</strong>
        (paiements et remboursements réellement enregistrés). Un écart entre "Généré" et "Documenté" signale une
        valorisation pas encore facturée — voir l'onglet <strong>Pipeline</strong> pour l'identifier précisément.
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

function OverviewTab({ filter }: { filter: ReturnType<typeof toApiFilter> }) {
  const query = useQuery({ queryKey: ["fin-stats", "overview", filter], queryFn: () => getOverview(filter) });

  if (query.isLoading) return <CircularProgress size={24} />;
  if (!query.data) return null;
  const { activity, currencies } = query.data;

  return (
    <Stack spacing={3}>
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Typography variant="subtitle2" fontWeight={700} mb={1.5}>Activité (missions/exécutions — sans devise propre)</Typography>
        <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 2 }}>
          <Metric label="Missions" value={activity.missionCount} />
          <Metric label="Missions exécutées" value={activity.executedMissionCount} />
          <Metric label="Missions validées" value={activity.validatedMissionCount} />
          <Metric label="Durée moyenne (min)" value={activity.averageExecutionDurationMinutes} />
        </Box>
      </Paper>

      {currencies.length === 0 && (
        <Typography color="text.secondary">Aucune donnée financière sur cette période/ces filtres.</Typography>
      )}

      {currencies.map((c) => (
        <Paper key={c.currency} variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
          <Typography variant="subtitle2" fontWeight={700} mb={1.5}>Devise : {c.currency}</Typography>
          <Stack spacing={2}>
            <Box>
              <Typography variant="caption" fontWeight={700} color="text.secondary">Valeur générée</Typography>
              <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 2, mt: 0.5 }}>
                <Metric label="CA firme généré" value={c.generatedFirmRevenue} money />
                <Metric label="Rémunération instrumentiste" value={c.generatedInstrumentistCompensation} money />
                <Metric label="Valeur totale générée" value={c.generatedTotalValue} money strong />
                <Metric label="Contribution margin" value={c.generatedContributionMargin} money />
                <Metric label="Valeur moyenne / mission" value={c.averageMissionValue} money />
              </Box>
            </Box>
            <Box>
              <Typography variant="caption" fontWeight={700} color="text.secondary">Valeur documentée — Factures firmes</Typography>
              <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 2, mt: 0.5 }}>
                <Metric label="Brut facturé" value={c.invoicedGrossAmount} money />
                <Metric label="Notes de crédit" value={c.invoiceCreditNotesAmount} money />
                <Metric label="Notes de débit" value={c.invoiceDebitNotesAmount} money />
                <Metric label="Net facturé" value={c.invoicedNetAmount} money strong />
                <Metric label="Solde ouvert firmes" value={c.openFirmBalance} money color={Number(c.openFirmBalance) > 0 ? "warning.main" : undefined} />
              </Box>
            </Box>
            <Box>
              <Typography variant="caption" fontWeight={700} color="text.secondary">Valeur documentée — Décomptes instrumentistes</Typography>
              <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 2, mt: 0.5 }}>
                <Metric label="Brut décompté" value={c.statementGrossAmount} money />
                <Metric label="Notes de crédit" value={c.statementCreditNotesAmount} money />
                <Metric label="Notes de débit" value={c.statementDebitNotesAmount} money />
                <Metric label="Net décompté" value={c.statementNetAmount} money strong />
                <Metric label="Solde ouvert instrumentistes" value={c.openInstrumentistBalance} money color={Number(c.openInstrumentistBalance) > 0 ? "warning.main" : undefined} />
              </Box>
            </Box>
            <Box>
              <Typography variant="caption" fontWeight={700} color="text.secondary">Flux de trésorerie réel</Typography>
              <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 2, mt: 0.5 }}>
                <Metric label="Encaissé" value={c.paymentsIn} money color="success.main" />
                <Metric label="Décaissé" value={c.paymentsOut} money color="error.main" />
                <Metric label="Flux net" value={c.netCashFlow} money strong />
              </Box>
            </Box>
          </Stack>
        </Paper>
      ))}
    </Stack>
  );
}

function Metric({ label, value, money, strong, color }: { label: string; value: number | string; money?: boolean; strong?: boolean; color?: string }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Typography variant={strong ? "h6" : "body1"} fontWeight={strong ? 700 : 500} color={color}>
        {money ? Number(value).toFixed(2) : value}
      </Typography>
    </Box>
  );
}

// ── Timeseries ──────────────────────────────────────────────────────────

function TimeseriesTab({ filter }: { filter: ReturnType<typeof toApiFilter> }) {
  const [granularity, setGranularity] = React.useState<StatisticsGranularity>("DAY");
  const query = useQuery({
    queryKey: ["fin-stats", "timeseries", filter, granularity],
    queryFn: () => getTimeseries(filter, granularity),
  });

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Typography variant="body2" color="text.secondary">Granularité</Typography>
        <Select size="small" value={granularity} onChange={(e) => setGranularity(e.target.value as StatisticsGranularity)} sx={{ width: 120 }}>
          <MenuItem value="DAY">Jour</MenuItem>
          <MenuItem value="WEEK">Semaine</MenuItem>
          <MenuItem value="MONTH">Mois</MenuItem>
        </Select>
      </Stack>

      {query.isLoading ? <CircularProgress size={24} /> : (
        <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "auto" }}>
          <Box sx={{ overflowX: "auto" }}>
            <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 13 }}>
              <thead>
                <tr style={{ background: "#fafafa" }}>
                  <th style={cellStyle}>Période</th>
                  <th style={cellStyle}>Missions</th>
                  <th style={cellStyle}>Devise</th>
                  <th style={{ ...cellStyle, textAlign: "right" }}>CA firme</th>
                  <th style={{ ...cellStyle, textAlign: "right" }}>Rémun. instr.</th>
                  <th style={{ ...cellStyle, textAlign: "right" }}>Net facturé</th>
                  <th style={{ ...cellStyle, textAlign: "right" }}>Net décompté</th>
                  <th style={{ ...cellStyle, textAlign: "right" }}>Encaissé</th>
                  <th style={{ ...cellStyle, textAlign: "right" }}>Décaissé</th>
                </tr>
              </thead>
              <tbody>
                {(query.data?.points ?? []).map((p) => {
                  const rows = p.currencies.length > 0 ? p.currencies : [null];
                  return rows.map((c, i) => (
                    <tr key={`${p.periodStart}-${i}`}>
                      {i === 0 && <td style={cellStyle} rowSpan={rows.length}>{new Date(p.periodStart).toLocaleDateString("fr-BE")}</td>}
                      {i === 0 && <td style={cellStyle} rowSpan={rows.length}>{p.missionCount}</td>}
                      <td style={cellStyle}>{c?.currency ?? "—"}</td>
                      <td style={{ ...cellStyle, textAlign: "right" }}>{c ? Number(c.generatedFirmRevenue).toFixed(2) : "—"}</td>
                      <td style={{ ...cellStyle, textAlign: "right" }}>{c ? Number(c.generatedInstrumentistCompensation).toFixed(2) : "—"}</td>
                      <td style={{ ...cellStyle, textAlign: "right" }}>{c ? Number(c.invoicedNetAmount).toFixed(2) : "—"}</td>
                      <td style={{ ...cellStyle, textAlign: "right" }}>{c ? Number(c.statementNetAmount).toFixed(2) : "—"}</td>
                      <td style={{ ...cellStyle, textAlign: "right" }}>{c ? Number(c.paymentsIn).toFixed(2) : "—"}</td>
                      <td style={{ ...cellStyle, textAlign: "right" }}>{c ? Number(c.paymentsOut).toFixed(2) : "—"}</td>
                    </tr>
                  ));
                })}
              </tbody>
            </table>
          </Box>
        </Paper>
      )}
    </Stack>
  );
}

const cellStyle: React.CSSProperties = { padding: "6px 10px", borderBottom: "1px solid #eee", textAlign: "left" };

// ── Pipeline ────────────────────────────────────────────────────────────

function PipelineTab({ filter }: { filter: ReturnType<typeof toApiFilter> }) {
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

function usePaginationState(defaultSortBy: string) {
  const [page, setPage] = React.useState(1);
  const [sortBy, setSortBy] = React.useState(defaultSortBy);
  const [sortDirection, setSortDirection] = React.useState<"ASC" | "DESC">("DESC");
  return { page, setPage, sortBy, setSortBy, sortDirection, setSortDirection };
}

function ByFirmTab({ filter }: { filter: ReturnType<typeof toApiFilter> }) {
  const p = usePaginationState("generatedRevenue");
  const query = useQuery({
    queryKey: ["fin-stats", "by-firm", filter, p.page, p.sortBy, p.sortDirection],
    queryFn: () => getByFirm(filter, { page: p.page, limit: 20, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<FirmStatistic>[] = [
    { key: "firm", label: "Firme", render: (r) => r.firmNameSnapshot },
    { key: "currency", label: "Devise", render: (r) => r.currency },
    { key: "missions", label: "Missions", align: "right", render: (r) => r.missionCount },
    { key: "intervention", label: "CA interventions", align: "right", render: (r) => Number(r.interventionRevenue).toFixed(2) },
    { key: "material", label: "CA matériel", align: "right", render: (r) => Number(r.materialRevenue).toFixed(2) },
    { key: "generated", label: "CA généré", align: "right", render: (r) => Number(r.generatedRevenue).toFixed(2) },
    { key: "net", label: "Net facturé", align: "right", render: (r) => Number(r.invoicedNetAmount).toFixed(2) },
    { key: "paid", label: "Payé", align: "right", render: (r) => Number(r.paidAmount).toFixed(2) },
    { key: "remaining", label: "Restant dû", align: "right", render: (r) => Number(r.remainingAmount).toFixed(2) },
    { key: "avg", label: "Moy./mission", align: "right", render: (r) => Number(r.averageRevenuePerMission).toFixed(2) },
  ];

  return (
    <RankingTable
      columns={columns}
      rows={query.data?.items ?? []}
      total={query.data?.total ?? 0}
      page={p.page}
      limit={20}
      isLoading={query.isLoading}
      sortBy={p.sortBy}
      sortDirection={p.sortDirection}
      sortOptions={[
        { value: "generatedRevenue", label: "CA généré" },
        { value: "invoicedNetAmount", label: "Net facturé" },
        { value: "remainingAmount", label: "Restant dû" },
        { value: "missionCount", label: "Missions" },
        { value: "firmNameSnapshot", label: "Nom" },
      ]}
      onPageChange={p.setPage}
      onSortChange={(sb, sd) => { p.setSortBy(sb); p.setSortDirection(sd); p.setPage(1); }}
    />
  );
}

function ByInstrumentistTab({ filter }: { filter: ReturnType<typeof toApiFilter> }) {
  const p = usePaginationState("generatedCompensation");
  const query = useQuery({
    queryKey: ["fin-stats", "by-instrumentist", filter, p.page, p.sortBy, p.sortDirection],
    queryFn: () => getByInstrumentist(filter, { page: p.page, limit: 20, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<InstrumentistStatistic>[] = [
    { key: "name", label: "Instrumentiste", render: (r) => r.instrumentistNameSnapshot },
    { key: "currency", label: "Devise", render: (r) => r.currency },
    { key: "missions", label: "Missions", align: "right", render: (r) => r.missionCount },
    { key: "minutes", label: "Minutes exécutées", align: "right", render: (r) => r.executedMinutes },
    { key: "hourly", label: "Rémun. horaire", align: "right", render: (r) => Number(r.hourlyCompensation).toFixed(2) },
    { key: "consult", label: "Consultations", align: "right", render: (r) => Number(r.consultationFees).toFixed(2) },
    { key: "generated", label: "Rémun. générée", align: "right", render: (r) => Number(r.generatedCompensation).toFixed(2) },
    { key: "net", label: "Net décompté", align: "right", render: (r) => Number(r.statementNetAmount).toFixed(2) },
    { key: "paid", label: "Payé", align: "right", render: (r) => Number(r.paidAmount).toFixed(2) },
    { key: "remaining", label: "Restant dû", align: "right", render: (r) => Number(r.remainingAmount).toFixed(2) },
    { key: "avg", label: "Moy./mission", align: "right", render: (r) => Number(r.averageCompensationPerMission).toFixed(2) },
  ];

  return (
    <RankingTable
      columns={columns}
      rows={query.data?.items ?? []}
      total={query.data?.total ?? 0}
      page={p.page}
      limit={20}
      isLoading={query.isLoading}
      sortBy={p.sortBy}
      sortDirection={p.sortDirection}
      sortOptions={[
        { value: "generatedCompensation", label: "Rémun. générée" },
        { value: "statementNetAmount", label: "Net décompté" },
        { value: "remainingAmount", label: "Restant dû" },
        { value: "missionCount", label: "Missions" },
        { value: "instrumentistNameSnapshot", label: "Nom" },
      ]}
      onPageChange={p.setPage}
      onSortChange={(sb, sd) => { p.setSortBy(sb); p.setSortDirection(sd); p.setPage(1); }}
    />
  );
}

function BySurgeonTab({ filter }: { filter: ReturnType<typeof toApiFilter> }) {
  const p = usePaginationState("generatedFirmRevenue");
  const query = useQuery({
    queryKey: ["fin-stats", "by-surgeon", filter, p.page, p.sortBy, p.sortDirection],
    queryFn: () => getBySurgeon(filter, { page: p.page, limit: 20, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<SurgeonStatistic>[] = [
    { key: "name", label: "Chirurgien", render: (r) => r.surgeonNameSnapshot },
    { key: "currency", label: "Devise", render: (r) => r.currency },
    { key: "missions", label: "Missions", align: "right", render: (r) => r.missionCount },
    { key: "executed", label: "Missions exécutées", align: "right", render: (r) => r.executedMissionCount },
    { key: "firm", label: "CA firme (analytique)", align: "right", render: (r) => Number(r.generatedFirmRevenue).toFixed(2) },
    { key: "instr", label: "Rémun. instrumentiste", align: "right", render: (r) => Number(r.generatedInstrumentistCompensation).toFixed(2) },
    { key: "avg", label: "Valeur moy./mission", align: "right", render: (r) => Number(r.averageMissionValue).toFixed(2) },
  ];

  return (
    <Stack spacing={1}>
      <Typography variant="caption" color="text.secondary">
        Le chirurgien est un axe analytique — il n'est jamais le bénéficiaire des paiements affichés ici.
      </Typography>
      <RankingTable
        columns={columns}
        rows={query.data?.items ?? []}
        total={query.data?.total ?? 0}
        page={p.page}
        limit={20}
        isLoading={query.isLoading}
        sortBy={p.sortBy}
        sortDirection={p.sortDirection}
        sortOptions={[
          { value: "generatedFirmRevenue", label: "CA firme" },
          { value: "generatedInstrumentistCompensation", label: "Rémun. instrumentiste" },
          { value: "missionCount", label: "Missions" },
          { value: "surgeonNameSnapshot", label: "Nom" },
        ]}
        onPageChange={p.setPage}
        onSortChange={(sb, sd) => { p.setSortBy(sb); p.setSortDirection(sd); p.setPage(1); }}
      />
    </Stack>
  );
}

function ByInterventionTab({ filter }: { filter: ReturnType<typeof toApiFilter> }) {
  const p = usePaginationState("interventionRevenue");
  const query = useQuery({
    queryKey: ["fin-stats", "by-intervention", filter, p.page, p.sortBy, p.sortDirection],
    queryFn: () => getByIntervention(filter, { page: p.page, limit: 20, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<InterventionStatistic>[] = [
    { key: "code", label: "Code", render: (r) => r.interventionCodeSnapshot },
    { key: "label", label: "Libellé", render: (r) => r.interventionNameSnapshot },
    { key: "currency", label: "Devise", render: (r) => r.currency },
    { key: "missions", label: "Missions", align: "right", render: (r) => r.missionCount },
    { key: "intervention", label: "CA interventions", align: "right", render: (r) => Number(r.interventionRevenue).toFixed(2) },
    { key: "material", label: "CA matériel", align: "right", render: (r) => Number(r.materialRevenue).toFixed(2) },
    { key: "instr", label: "Rémun. instrumentiste", align: "right", render: (r) => Number(r.instrumentistCompensation).toFixed(2) },
    { key: "avg", label: "Valeur moy./mission", align: "right", render: (r) => Number(r.averageMissionValue).toFixed(2) },
    { key: "duration", label: "Durée moy. (min)", align: "right", render: (r) => r.averageDurationMinutes },
  ];

  return (
    <Stack spacing={1}>
      <Typography variant="caption" color="text.secondary">
        La rémunération instrumentiste est attribuée à l'intervention primaire de la mission (aucun lien direct n'existe entre une ligne instrumentiste et une intervention précise).
      </Typography>
      <RankingTable
        columns={columns}
        rows={query.data?.items ?? []}
        total={query.data?.total ?? 0}
        page={p.page}
        limit={20}
        isLoading={query.isLoading}
        sortBy={p.sortBy}
        sortDirection={p.sortDirection}
        sortOptions={[
          { value: "interventionRevenue", label: "CA interventions" },
          { value: "materialRevenue", label: "CA matériel" },
          { value: "missionCount", label: "Missions" },
          { value: "interventionCodeSnapshot", label: "Code" },
        ]}
        onPageChange={p.setPage}
        onSortChange={(sb, sd) => { p.setSortBy(sb); p.setSortDirection(sd); p.setPage(1); }}
      />
    </Stack>
  );
}

function TopMaterialsTab({ filter }: { filter: ReturnType<typeof toApiFilter> }) {
  const p = usePaginationState("generatedRevenue");
  const query = useQuery({
    queryKey: ["fin-stats", "top-materials", filter, p.sortBy, p.sortDirection],
    queryFn: () => getTopMaterials(filter, { limit: 50, sortBy: p.sortBy, sortDirection: p.sortDirection }),
  });

  const columns: RankingColumn<MaterialStatistic>[] = [
    { key: "ref", label: "Référence", render: (r) => r.materialReferenceSnapshot ?? "—" },
    { key: "name", label: "Matériel", render: (r) => r.materialNameSnapshot },
    { key: "firm", label: "Firme", render: (r) => r.firmSnapshot },
    { key: "currency", label: "Devise", render: (r) => r.currency },
    { key: "qty", label: "Quantité", align: "right", render: (r) => r.quantity },
    { key: "missions", label: "Missions", align: "right", render: (r) => r.missionCount },
    { key: "revenue", label: "CA généré", align: "right", render: (r) => Number(r.generatedRevenue).toFixed(2) },
    { key: "avgUnit", label: "CA moy./unité", align: "right", render: (r) => Number(r.averageUnitRevenue).toFixed(2) },
  ];

  return (
    <RankingTable
      columns={columns}
      rows={query.data?.items ?? []}
      total={query.data?.total ?? 0}
      page={1}
      limit={50}
      isLoading={query.isLoading}
      sortBy={p.sortBy}
      sortDirection={p.sortDirection}
      sortOptions={[
        { value: "generatedRevenue", label: "CA généré" },
        { value: "quantity", label: "Quantité" },
        { value: "missionCount", label: "Missions" },
        { value: "averageUnitRevenue", label: "CA moy./unité" },
      ]}
      onPageChange={() => {}}
      onSortChange={(sb, sd) => { p.setSortBy(sb); p.setSortDirection(sd); }}
    />
  );
}

// ── Drill-down ──────────────────────────────────────────────────────────

const DRILLDOWN_CAPTIONS: Record<"missions" | "calculations" | "documents", string> = {
  missions: "Liste brute des missions correspondant aux filtres, avec leur statut d'exécution — point de départ pour retrouver une mission précise derrière un chiffre agrégé.",
  calculations: "Tous les calculs financiers (quel que soit leur statut : CALCULATED, APPROVED, LOCKED, SUPERSEDED, CANCELLED) — utile pour repérer un calcul jamais approuvé/verrouillé.",
  documents: "Toutes les factures firmes et tous les décomptes instrumentistes (documents STANDARD et corrections) émis sur la période — vue transversale indépendante du détail par firme/instrumentiste.",
};

function DrilldownTab({ filter, kind }: { filter: ReturnType<typeof toApiFilter>; kind: "missions" | "calculations" | "documents" }) {
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
