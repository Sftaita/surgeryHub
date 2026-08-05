import * as React from "react";
import { Box, Paper, Stack, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import DashboardIcon from "@mui/icons-material/Dashboard";

import { fetchMissions } from "../../features/missions/api/missions.api";
import { fetchSites } from "../../features/sites/api/sites.api";
import { getInstrumentists } from "../../features/manager-instrumentists/api/instrumentists.api";
import { getSurgeons } from "../../features/manager-surgeons/api/surgeons.api";
import { getMaterialRequests } from "../../features/manager-catalogue/api/catalogue.api";
import { getInterventionTypeRequests } from "../../features/manager-catalogue/api/interventionTypeRequests.api";
import { getAlerts } from "../../features/planning-v2/api/planningV2.api";
import {
  getOverview,
  getPipeline,
  getByFirm,
  getBySurgeon,
  getTopMaterials,
} from "../../features/financial-statistics/api/financialStatistics.api";
import { defaultFilterState, toApiFilter } from "../../features/financial-statistics/components/StatFilterBar";
import { PageHeader } from "../../ui/PageHeader";
import { StatCard } from "../../ui/StatCard";

function startOfDay(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}T00:00:00`;
}
function endOfDay(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}T23:59:59`;
}
function startOfWeek(d: Date): Date {
  const day = d.getDay() === 0 ? 7 : d.getDay(); // lundi = 1
  const monday = new Date(d);
  monday.setDate(d.getDate() - day + 1);
  return monday;
}
function endOfWeek(d: Date): Date {
  const monday = startOfWeek(d);
  const sunday = new Date(monday);
  sunday.setDate(monday.getDate() + 6);
  return sunday;
}

const GRID_SX = { display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))", gap: 2 } as const;

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Stack spacing={1.5}>
      <Typography variant="subtitle1" fontWeight={700}>{title}</Typography>
      {children}
    </Stack>
  );
}

/**
 * Point d'entrée manager (D-079) — agrège uniquement des requêtes déjà
 * existantes (missions, alertes planning, effectifs, demandes, pilotage
 * financier). Aucune nouvelle route backend créée pour cette page.
 */
export default function DashboardPage() {
  const navigate = useNavigate();
  const today = new Date();
  const statFilter = React.useMemo(() => toApiFilter(defaultFilterState()), []);

  const todayMissionsQuery = useQuery({
    queryKey: ["dashboard", "missions-today"],
    queryFn: () => fetchMissions(1, 1, { from: startOfDay(today), to: endOfDay(today) }),
  });
  const weekMissionsQuery = useQuery({
    queryKey: ["dashboard", "missions-week"],
    queryFn: () => fetchMissions(1, 1, { from: startOfDay(startOfWeek(today)), to: endOfDay(endOfWeek(today)) }),
  });
  const toValidateQuery = useQuery({
    queryKey: ["dashboard", "missions-to-validate"],
    queryFn: () => fetchMissions(1, 1, { status: "DECLARED" }),
  });
  const openMissionsQuery = useQuery({
    queryKey: ["dashboard", "missions-open"],
    queryFn: () => fetchMissions(1, 1, { status: "OPEN" }),
  });

  const alertsQuery = useQuery({
    queryKey: ["dashboard", "alerts-open"],
    queryFn: () => getAlerts({ status: "OPEN", limit: 1 }),
  });

  const activeInstrumentistsQuery = useQuery({
    queryKey: ["dashboard", "instrumentists-active"],
    queryFn: () => getInstrumentists({ active: true }),
  });
  const activeSurgeonsQuery = useQuery({
    queryKey: ["dashboard", "surgeons-active"],
    queryFn: () => getSurgeons({ active: true }),
  });
  const sitesQuery = useQuery({
    queryKey: ["dashboard", "sites"],
    queryFn: () => fetchSites(),
  });

  const materialRequestsQuery = useQuery({
    queryKey: ["dashboard", "material-requests-pending"],
    queryFn: () => getMaterialRequests({ status: "PENDING" }),
  });
  const interventionRequestsQuery = useQuery({
    queryKey: ["dashboard", "intervention-requests-pending"],
    queryFn: () => getInterventionTypeRequests({ status: "PENDING" }),
  });

  const pipelineQuery = useQuery({
    queryKey: ["dashboard", "pipeline", statFilter],
    queryFn: () => getPipeline(statFilter),
  });
  const overviewQuery = useQuery({
    queryKey: ["dashboard", "overview", statFilter],
    queryFn: () => getOverview(statFilter),
  });

  const topFirmsQuery = useQuery({
    queryKey: ["dashboard", "top-firms", statFilter],
    queryFn: () => getByFirm(statFilter, { page: 1, limit: 5, sortBy: "generatedRevenue", sortDirection: "DESC" }),
  });
  const topSurgeonsQuery = useQuery({
    queryKey: ["dashboard", "top-surgeons", statFilter],
    queryFn: () => getBySurgeon(statFilter, { page: 1, limit: 5, sortBy: "generatedFirmRevenue", sortDirection: "DESC" }),
  });
  const topMaterialsQuery = useQuery({
    queryKey: ["dashboard", "top-materials", statFilter],
    queryFn: () => getTopMaterials(statFilter, { page: 1, limit: 5, sortBy: "generatedRevenue", sortDirection: "DESC" }),
  });

  const pendingRequestsCount = (materialRequestsQuery.data?.items?.length ?? 0) + (interventionRequestsQuery.data?.items?.length ?? 0);
  const mainCurrency = overviewQuery.data?.currencies?.[0];

  return (
    <Box sx={{ p: 3 }}>
      <PageHeader icon={DashboardIcon} title="Dashboard" subtitle="Vue d'ensemble de l'activité manager." />

      <Stack spacing={4}>
        <Section title="Missions">
          <Box sx={GRID_SX}>
            <StatCard label="Aujourd'hui" value={todayMissionsQuery.data?.total ?? "—"} onClick={() => navigate("/app/m/missions")} />
            <StatCard label="Cette semaine" value={weekMissionsQuery.data?.total ?? "—"} onClick={() => navigate("/app/m/missions")} />
            <StatCard
              label="À valider" value={toValidateQuery.data?.total ?? "—"}
              hint={toValidateQuery.data && toValidateQuery.data.total > 0 ? "À traiter" : undefined}
              onClick={() => navigate("/app/m/missions/to-validate")}
            />
            <StatCard label="Ouvertes" value={openMissionsQuery.data?.total ?? "—"} onClick={() => navigate("/app/m/missions")} />
            <StatCard
              label="Alertes planning ouvertes" value={alertsQuery.data?.total ?? "—"}
              hint={alertsQuery.data && alertsQuery.data.total > 0 ? "À traiter" : undefined}
              onClick={() => navigate("/app/m/planning/v2")}
            />
          </Box>
        </Section>

        <Section title="Effectifs & catalogue">
          <Box sx={GRID_SX}>
            <StatCard label="Instrumentistes actifs" value={activeInstrumentistsQuery.data?.total ?? "—"} onClick={() => navigate("/app/m/instrumentists")} />
            <StatCard label="Chirurgiens actifs" value={activeSurgeonsQuery.data?.total ?? "—"} onClick={() => navigate("/app/m/surgeons")} />
            <StatCard label="Établissements" value={sitesQuery.data?.length ?? "—"} onClick={() => navigate("/app/m/hospitals")} />
            <StatCard
              label="Demandes en attente" value={pendingRequestsCount}
              hint={pendingRequestsCount > 0 ? "À traiter" : undefined}
              onClick={() => navigate("/app/m/catalogue/requests")}
            />
          </Box>
        </Section>

        <Section title="Facturation — pipeline">
          <Box sx={GRID_SX}>
            <StatCard
              label="Missions validées sans calcul" value={pipelineQuery.data?.validatedMissionsWithoutCalculation ?? "—"}
              onClick={() => navigate("/app/m/missions?validatedWithoutCalculation=true")}
            />
            <StatCard
              label="Factures générées non émises" value={pipelineQuery.data?.generatedInvoicesNotIssued ?? "—"}
              onClick={() => navigate("/app/m/billing/firm-invoices")}
            />
            <StatCard
              label="Décomptes générés non émis" value={pipelineQuery.data?.generatedStatementsNotIssued ?? "—"}
              onClick={() => navigate("/app/m/billing/statements")}
            />
            <StatCard
              label="Factures avec solde ouvert" value={pipelineQuery.data?.issuedInvoicesWithOpenBalance ?? "—"}
              onClick={() => navigate("/app/m/billing/firm-invoices")}
            />
            <StatCard
              label="Décomptes avec solde ouvert" value={pipelineQuery.data?.issuedStatementsWithOpenBalance ?? "—"}
              onClick={() => navigate("/app/m/billing/statements")}
            />
          </Box>
        </Section>

        <Section title="Activité financière (30 derniers jours)">
          <Box sx={GRID_SX}>
            <StatCard label="Missions" value={overviewQuery.data?.activity.missionCount ?? "—"} onClick={() => navigate("/app/m/finance/statistics")} />
            <StatCard label="Missions exécutées" value={overviewQuery.data?.activity.executedMissionCount ?? "—"} onClick={() => navigate("/app/m/finance/statistics")} />
            <StatCard label="Missions validées" value={overviewQuery.data?.activity.validatedMissionCount ?? "—"} onClick={() => navigate("/app/m/finance/statistics")} />
            {mainCurrency && (
              <StatCard
                label={`CA généré (${mainCurrency.currency})`}
                value={`${Number(mainCurrency.generatedTotalValue).toFixed(2)} ${mainCurrency.currency}`}
                color="primary.main"
                onClick={() => navigate("/app/m/finance/statistics")}
              />
            )}
          </Box>
        </Section>

        <Section title="Classements">
          <Box sx={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(280px, 1fr))", gap: 2 }}>
            <RankingPreview
              title="Top firmes (CA généré)"
              rows={(topFirmsQuery.data?.items ?? []).map((f) => ({ label: f.firmNameSnapshot, value: `${Number(f.generatedRevenue).toFixed(2)} ${f.currency}` }))}
              isLoading={topFirmsQuery.isLoading}
              onSeeAll={() => navigate("/app/m/finance/statistics")}
            />
            <RankingPreview
              title="Top chirurgiens (CA firme)"
              rows={(topSurgeonsQuery.data?.items ?? []).map((s) => ({ label: s.surgeonNameSnapshot, value: `${Number(s.generatedFirmRevenue).toFixed(2)} ${s.currency}` }))}
              isLoading={topSurgeonsQuery.isLoading}
              onSeeAll={() => navigate("/app/m/finance/statistics")}
            />
            <RankingPreview
              title="Top matériels (CA généré)"
              rows={(topMaterialsQuery.data?.items ?? []).map((m) => ({ label: m.materialNameSnapshot, value: `${Number(m.generatedRevenue).toFixed(2)} ${m.currency}` }))}
              isLoading={topMaterialsQuery.isLoading}
              onSeeAll={() => navigate("/app/m/finance/statistics")}
            />
          </Box>
        </Section>
      </Stack>
    </Box>
  );
}

function RankingPreview({
  title, rows, isLoading, onSeeAll,
}: {
  title: string;
  rows: { label: string; value: string }[];
  isLoading: boolean;
  onSeeAll: () => void;
}) {
  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2, cursor: "pointer" }} onClick={onSeeAll}>
      <Typography variant="subtitle2" fontWeight={700} sx={{ mb: 1 }}>{title}</Typography>
      {isLoading ? (
        <Typography variant="body2" color="text.secondary">Chargement…</Typography>
      ) : rows.length === 0 ? (
        <Typography variant="body2" color="text.secondary">Aucune donnée sur la période.</Typography>
      ) : (
        <Stack spacing={0.75}>
          {rows.map((r, i) => (
            <Stack key={i} direction="row" justifyContent="space-between" spacing={1}>
              <Typography variant="body2" noWrap sx={{ minWidth: 0 }}>{r.label}</Typography>
              <Typography variant="body2" fontWeight={700} noWrap>{r.value}</Typography>
            </Stack>
          ))}
        </Stack>
      )}
    </Paper>
  );
}
