import * as React from "react";
import { Box, Stack, Tab, Tabs, Typography } from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import TrackChangesOutlinedIcon from "@mui/icons-material/TrackChangesOutlined";
import { PageHeader } from "../../../ui/PageHeader";
import { PeriodNav } from "../../../features/encoding-tracking/components/PeriodNav";
import EncodingTrackingFilterBar, {
  defaultTrackingFilterState,
  type TrackingFilterState,
} from "../../../features/encoding-tracking/components/EncodingTrackingFilterBar";
import { AttentionCard } from "../../../features/encoding-tracking/components/AttentionCard";
import { WeekFunnel } from "../../../features/encoding-tracking/components/WeekFunnel";
import { EncodingTrackingTable } from "../../../features/encoding-tracking/components/EncodingTrackingTable";
import { ToTreatPanel } from "../../../features/encoding-tracking/components/ToTreatPanel";
import { ByInstrumentistView } from "../../../features/encoding-tracking/components/ByInstrumentistView";
import { MissionTrackingDrawer } from "../../../features/encoding-tracking/components/MissionTrackingDrawer";
import { periodForShortcut, type Period } from "../../../features/encoding-tracking/period";
import {
  getEncodingTracking,
  getEncodingTrackingSummary,
  type EncodingState,
  type EncodingTrackingFilter,
  type EncodingTrackingItem,
} from "../../../features/encoding-tracking/api/encodingTracking.api";

const TABS = ["table", "toTreat", "byInstrumentist"] as const;
type TabKey = (typeof TABS)[number];
const TAB_LABELS: Record<TabKey, string> = {
  table: "Toutes les missions",
  toTreat: "À traiter",
  byInstrumentist: "Par instrumentiste",
};

const TABLE_LIMIT = 50;
/** Plafond des vues "large" (À traiter / Par instrumentiste) — max autorisé par l'API. */
const BROAD_LIMIT = 200;

/**
 * Refetch au focus + polling léger, cohérents avec Étape 12/14 : le défaut global de
 * l'app (`refetchOnWindowFocus: false`, `staleTime: 30s`, voir queryClient.ts) est pensé
 * pour des écrans peu volatils. Ce cockpit doit refléter rapidement les changements
 * d'encodage — override local plutôt que changer le défaut global.
 */
const LIVE_QUERY_OPTIONS = { refetchOnWindowFocus: true, refetchInterval: 45_000, staleTime: 15_000 };

function toApiFilter(period: Period, f: TrackingFilterState): EncodingTrackingFilter {
  return {
    from: `${period.from}T00:00:00`,
    to: `${period.to}T00:00:00`,
    siteId: f.siteId ? Number(f.siteId) : undefined,
    surgeonId: f.surgeonId ? Number(f.surgeonId) : undefined,
    instrumentistId: f.instrumentistId ? Number(f.instrumentistId) : undefined,
    missionType: f.missionType || undefined,
  };
}

/**
 * Suivi des encodages (D-118) — cockpit opérationnel manager. Distinct des statistiques
 * financières (D-077) : répond à "qu'est-ce qui a été encodé, par qui, et qu'est-ce qui
 * réclame mon attention ?", jamais à "combien".
 *
 * Maquette validée (docs/design/Instruction design/Suivi-encodages-admin). D-133 : le tiroir
 * de détail se SUPERPOSE à la colonne (position fixed), il ne la rétrécit plus — aucune
 * largeur, colonne, carte ni position du cockpit ne bouge à l'ouverture/fermeture (le
 * margin-right de la maquette provoquait un reflow complet). La liste reste visible et
 * cliquable à gauche du tiroir pour enchaîner les validations. Desktop uniquement pour cette
 * passe (bascule mobile en cartes : lot séparé).
 */
export default function EncodingTrackingPage() {
  const [period, setPeriod] = React.useState<Period>(() => periodForShortcut("today"));
  const [filter, setFilter] = React.useState<TrackingFilterState>(defaultTrackingFilterState());
  const [tableEncodingStates, setTableEncodingStates] = React.useState<EncodingState[]>([]);
  const [page, setPage] = React.useState(1);
  const [tab, setTab] = React.useState<TabKey>("table");
  const [selectedMissionId, setSelectedMissionId] = React.useState<number | null>(null);

  const baseFilter = React.useMemo(() => toApiFilter(period, filter), [period, filter]);

  // Réinitialise la page quand la population change — sinon "page 3" peut pointer sur
  // une page qui n'existe plus après un changement de filtre/période.
  React.useEffect(() => setPage(1), [baseFilter, tableEncodingStates]);

  const summaryQuery = useQuery({
    queryKey: ["encoding-tracking", "summary", baseFilter],
    queryFn: () => getEncodingTrackingSummary(baseFilter),
    ...LIVE_QUERY_OPTIONS,
  });

  const tableQuery = useQuery({
    queryKey: ["encoding-tracking", "list", baseFilter, tableEncodingStates, page],
    queryFn: () => getEncodingTracking({ ...baseFilter, encodingState: tableEncodingStates }, { page, limit: TABLE_LIMIT }),
    enabled: tab === "table",
    ...LIVE_QUERY_OPTIONS,
  });

  // "À traiter" et "Par instrumentiste" ont besoin d'une vue large de la période (pas
  // seulement la page affichée) — une seule requête partagée entre les deux onglets pour
  // ne pas la déclencher deux fois au changement d'onglet.
  const broadQuery = useQuery({
    queryKey: ["encoding-tracking", "broad", baseFilter],
    queryFn: () => getEncodingTracking(baseFilter, { page: 1, limit: BROAD_LIMIT }),
    enabled: tab === "toTreat" || tab === "byInstrumentist",
    ...LIVE_QUERY_OPTIONS,
  });

  const summary = summaryQuery.data?.summary;
  const noMissionsAtAll = tableQuery.data !== undefined && tableQuery.data.total === 0 && tableEncodingStates.length === 0;
  const noMatchForFilters = tableQuery.data !== undefined && tableQuery.data.items.length === 0 && tableQuery.data.total > 0;

  // Le tiroir a besoin de l'EncodingTrackingItem déjà chargé (état, heures, compteurs) —
  // jamais une seconde source : on le retrouve dans la vue actuellement affichée.
  const selectedItem: EncodingTrackingItem | null = React.useMemo(() => {
    if (selectedMissionId === null) return null;
    const pool = tab === "table" ? tableQuery.data?.items : broadQuery.data?.items;
    return pool?.find((i) => i.missionId === selectedMissionId) ?? null;
  }, [selectedMissionId, tab, tableQuery.data, broadQuery.data]);

  function selectState(state: EncodingState) {
    setTableEncodingStates([state]);
    setTab("table");
  }

  return (
    <Box sx={{ position: "relative" }}>
      <Box data-testid="encoding-tracking-main">
        <Stack spacing={3}>
          <PageHeader
            icon={TrackChangesOutlinedIcon}
            title="Suivi des encodages"
            subtitle="Qu'est-ce qui a été encodé, par qui, quand — et qu'est-ce qui nécessite votre attention ?"
          />

          <Stack direction="row" justifyContent="space-between" alignItems="center" flexWrap="wrap" gap={2}>
            <PeriodNav value={period} onChange={setPeriod} />
          </Stack>

          <Stack direction="row" spacing={2} alignItems="stretch" flexWrap="wrap" useFlexGap>
            <AttentionCard
              summary={summary}
              onSelectStale={() => selectState("IN_PROGRESS")}
              onSelectMissing={() => selectState("TO_ENCODE")}
              onSelectSubmitted={() => selectState("SUBMITTED")}
            />
            <WeekFunnel summary={summary} />
          </Stack>

          <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap>
            <Tabs value={tab} onChange={(_, v) => setTab(v)} sx={{ minHeight: 0 }}>
              {TABS.map((t) => <Tab key={t} value={t} label={TAB_LABELS[t]} sx={{ minHeight: 0 }} />)}
            </Tabs>
            <Box sx={{ flex: 1 }} />
            <EncodingTrackingFilterBar value={filter} onChange={setFilter} />
          </Stack>

          {tab === "table" && (
            <Stack spacing={1}>
              {tableEncodingStates.length > 0 && (
                <Typography variant="caption" color="text.secondary">
                  Filtré sur : {tableEncodingStates.join(", ")} —{" "}
                  <Typography
                    component="span" variant="caption" color="primary"
                    sx={{ cursor: "pointer", textDecoration: "underline" }}
                    onClick={() => setTableEncodingStates([])}
                  >
                    réinitialiser
                  </Typography>
                </Typography>
              )}
              <EncodingTrackingTable
                items={tableQuery.data?.items ?? []}
                total={tableQuery.data?.total ?? 0}
                page={page}
                limit={TABLE_LIMIT}
                isLoading={tableQuery.isLoading}
                isError={tableQuery.isError}
                onPageChange={setPage}
                onOpen={setSelectedMissionId}
                selectedMissionId={selectedMissionId}
                emptyTitle={
                  noMissionsAtAll
                    ? "Aucune mission sur cette période"
                    : noMatchForFilters
                      ? "Aucune mission ne correspond aux filtres actuels"
                      : "Aucune mission"
                }
                emptyDescription={
                  noMissionsAtAll
                    ? "Élargissez la période ou modifiez les filtres site/instrumentiste/chirurgien."
                    : noMatchForFilters
                      ? `${tableQuery.data?.total ?? 0} mission(s) existent sur cette période — essayez d'élargir le filtre d'état d'encodage.`
                      : undefined
                }
              />
            </Stack>
          )}

          {tab === "toTreat" && (
            <ToTreatPanel
              items={broadQuery.data?.items ?? []}
              isLoading={broadQuery.isLoading}
              isError={broadQuery.isError}
              isCapped={(broadQuery.data?.total ?? 0) > BROAD_LIMIT}
              cappedTotal={broadQuery.data?.total ?? 0}
              onOpen={setSelectedMissionId}
              selectedMissionId={selectedMissionId}
            />
          )}

          {tab === "byInstrumentist" && (
            <ByInstrumentistView
              items={broadQuery.data?.items ?? []}
              isLoading={broadQuery.isLoading}
              isError={broadQuery.isError}
              isCapped={(broadQuery.data?.total ?? 0) > BROAD_LIMIT}
              cappedTotal={broadQuery.data?.total ?? 0}
              onOpen={setSelectedMissionId}
              selectedMissionId={selectedMissionId}
            />
          )}
        </Stack>
      </Box>

      <MissionTrackingDrawer item={selectedItem} onClose={() => setSelectedMissionId(null)} />
    </Box>
  );
}
