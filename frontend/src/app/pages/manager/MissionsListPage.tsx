import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useLocation, useNavigate, useSearchParams } from "react-router-dom";
import { keepPreviousData, useInfiniteQuery, useQuery } from "@tanstack/react-query";
import { DataGrid, GridColDef } from "@mui/x-data-grid";
import { Badge, Box, Button, Chip, CircularProgress, LinearProgress, Stack, Tab, Tabs, Typography } from "@mui/material";
import AddIcon from "@mui/icons-material/Add";

import { fetchMissions, type MissionsFilters } from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import { MissionsFiltersBar, type MissionsFiltersValue, type MissionsView } from "../../features/missions/components/MissionsFiltersBar";
import { MissionsDayList, MissionStatusChip } from "../../features/missions/components/MissionsDayList";
import { MissionEncodingBadge } from "../../features/missions/components/MissionEncodingBadge";
import {
  formatBrusselsRange,
  formatPersonLabel,
  formatMissionType,
} from "../../../app/features/missions/utils/missions.format";
import { isMissionsPeriod, periodParams, type MissionsPeriod } from "../../features/missions/utils/missionsListPeriod";
import SurgeonMissionRequestsPanel from "../../features/manager-mission-requests/SurgeonMissionRequestsPanel";
import { getSurgeonMissionRequestCounts } from "../../features/manager-mission-requests/api/managerSurgeonMissionRequests.api";
import { REQUESTS_COUNTS_KEY } from "../../features/manager-mission-requests/requestReviewOptimistic";
import { useNavBadgeCount } from "../../ui/hooks/useNavBadgeCount";
import { useDebouncedValue } from "../../ui/hooks/useDebouncedValue";
import { EmptyState } from "../../ui/EmptyState";
import { ManagerMissionDrawer } from "../../features/missions/drawer/ManagerMissionDrawer";

const DAY_PAGE_SIZE = 50;
const TABLE_PAGE_SIZE = 100;

export default function MissionsListPage() {
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();

  const isToValidateView = location.pathname.endsWith("/to-validate");
  // Lot 5 (D-099) — onglet "Demandes chirurgien" : même page, contenu entièrement
  // différent (pas de Mission tant qu'une demande n'est pas acceptée), voir §20.
  const isRequestsView = location.pathname.endsWith("/requests");

  // D-147 — même cache que les compteurs de l'onglet Demandes : la décision optimiste
  // d'un manager fait bouger ce badge immédiatement.
  const pendingSurgeonMissionRequestCount = useNavBadgeCount(
    REQUESTS_COUNTS_KEY,
    getSurgeonMissionRequestCounts,
    30_000,
    (c) => c.PENDING,
  );

  // Diagnostic tarifs instrumentistes (2026-08-05) — filtre pré-appliqué en arrivant
  // depuis la tuile dashboard "Missions validées sans calcul" (?validatedWithoutCalculation=true).
  const validatedWithoutCalculation = searchParams.get("validatedWithoutCalculation") === "true";

  // D-147 — filtres dans l'URL (partageables, conservés au retour depuis une mission).
  // Période par défaut : « À venir » sur Toutes ; « Toutes les dates » quand la liste
  // est un diagnostic (À valider, validées sans calcul) — rien ne doit en être masqué.
  const rawPeriod = searchParams.get("period");
  const defaultPeriod: MissionsPeriod = isToValidateView || validatedWithoutCalculation ? "all" : "upcoming";
  const filters: MissionsFiltersValue = {
    search: searchParams.get("q") ?? "",
    period: isMissionsPeriod(rawPeriod) ? rawPeriod : defaultPeriod,
    status: isToValidateView ? "DECLARED" : searchParams.get("status") ?? undefined,
    type: searchParams.get("type") ?? undefined,
    siteId: Number(searchParams.get("siteId")) || undefined,
    view: (searchParams.get("view") as MissionsView) === "table" ? "table" : "day",
    showCancelled: searchParams.get("cancelled") === "1",
    withoutInstrumentist: searchParams.get("withoutInstrumentist") === "1",
  };

  // La saisie est locale (fluide), l'URL et la requête suivent après 300 ms.
  const [searchInput, setSearchInput] = useState(filters.search);
  const debouncedSearch = useDebouncedValue(searchInput, 300);
  useEffect(() => { setSearchInput(searchParams.get("q") ?? ""); }, [location.pathname]); // eslint-disable-line react-hooks/exhaustive-deps

  const patchParams = useCallback((patch: Record<string, string | undefined>) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      for (const [k, v] of Object.entries(patch)) {
        if (v === undefined || v === "") next.delete(k); else next.set(k, v);
      }
      return next;
    }, { replace: true });
  }, [setSearchParams]);

  useEffect(() => {
    if (debouncedSearch.trim() !== (searchParams.get("q") ?? "")) patchParams({ q: debouncedSearch.trim() || undefined });
  }, [debouncedSearch]); // eslint-disable-line react-hooks/exhaustive-deps

  const onFiltersChange = (patch: Partial<MissionsFiltersValue>) => {
    if (patch.search !== undefined) setSearchInput(patch.search);
    const p: Record<string, string | undefined> = {};
    if ("period" in patch) p.period = patch.period;
    if ("status" in patch) p.status = patch.status;
    if ("type" in patch) p.type = patch.type;
    if ("siteId" in patch) p.siteId = patch.siteId ? String(patch.siteId) : undefined;
    if ("view" in patch) p.view = patch.view === "table" ? "table" : undefined;
    if ("showCancelled" in patch) p.cancelled = patch.showCancelled ? "1" : undefined;
    if ("withoutInstrumentist" in patch) p.withoutInstrumentist = patch.withoutInstrumentist ? "1" : undefined;
    if (Object.keys(p).length > 0) patchParams(p);
  };

  const hasActiveFilters = Boolean(
    searchInput || filters.type || filters.siteId || (!isToValidateView && filters.status) || rawPeriod
      || filters.showCancelled || filters.withoutInstrumentist,
  );
  const resetFilters = () => {
    setSearchInput("");
    patchParams({ q: undefined, period: undefined, status: undefined, type: undefined, siteId: undefined, cancelled: undefined, withoutInstrumentist: undefined });
  };

  // Les bornes de période sont recalculées à chaque changement de filtre, pas à chaque
  // rendu (sinon `from` change à la milliseconde et la clé de requête aussi).
  const effectiveFilters = useMemo<MissionsFilters>(() => {
    const { from, to, order } = periodParams(filters.period);
    const base: MissionsFilters = { order };
    if (from) base.from = from;
    if (to) base.to = to;
    if (filters.status) base.status = filters.status;
    if (filters.type) base.type = filters.type;
    if (filters.siteId) base.siteId = filters.siteId;
    if (filters.search.trim()) base.q = filters.search.trim();
    if (validatedWithoutCalculation) base.validatedWithoutCalculation = true;
    // D-172 — masquage côté serveur (compteur exact) ; un statut explicite prime.
    if (!filters.showCancelled && !filters.status) base.excludeCancelled = true;
    if (filters.withoutInstrumentist) base.withoutInstrumentist = true;
    return base;
  }, [filters.period, filters.status, filters.type, filters.siteId, filters.search, validatedWithoutCalculation, filters.showCancelled, filters.withoutInstrumentist]);

  const dayQuery = useInfiniteQuery({
    queryKey: ["missions", "by-day", effectiveFilters],
    queryFn: ({ pageParam }) => fetchMissions(pageParam, DAY_PAGE_SIZE, effectiveFilters),
    initialPageParam: 1,
    getNextPageParam: (last, pages) => (pages.length * DAY_PAGE_SIZE < last.total ? pages.length + 1 : undefined),
    enabled: !isRequestsView && filters.view === "day",
    placeholderData: keepPreviousData,
  });

  const [tablePage, setTablePage] = useState(1);
  useEffect(() => setTablePage(1), [effectiveFilters]);
  const tableQuery = useQuery({
    queryKey: ["missions", { page: tablePage, limit: TABLE_PAGE_SIZE, filters: effectiveFilters }],
    queryFn: () => fetchMissions(tablePage, TABLE_PAGE_SIZE, effectiveFilters),
    enabled: !isRequestsView && filters.view === "table",
    placeholderData: keepPreviousData,
  });

  const activeQuery = filters.view === "day" ? dayQuery : tableQuery;
  const dayMissions = useMemo(() => dayQuery.data?.pages.flatMap((p) => p.items) ?? [], [dayQuery.data]);
  const total = filters.view === "day" ? dayQuery.data?.pages[0]?.total ?? 0 : tableQuery.data?.total ?? 0;
  const cancelledExcluded = filters.view === "day" ? dayQuery.data?.pages[0]?.cancelledExcluded ?? null : tableQuery.data?.cancelledExcluded ?? null;
  // D-172 — le compteur dit toujours s'il inclut ou exclut les annulées (nombre servi par le backend).
  const cancelledNote = filters.status
    ? null
    : cancelledExcluded !== null
      ? `annulées exclues${cancelledExcluded > 0 ? ` (${cancelledExcluded} masquée${cancelledExcluded > 1 ? "s" : ""})` : ""}`
      : "annulées incluses";

  // Chargement automatique de la page suivante en bas de liste (bouton en secours).
  const sentinelRef = useRef<HTMLDivElement | null>(null);
  const { hasNextPage, isFetchingNextPage, fetchNextPage } = dayQuery;
  useEffect(() => {
    const el = sentinelRef.current;
    if (!el || typeof IntersectionObserver === "undefined") return;
    const observer = new IntersectionObserver((entries) => {
      if (entries[0]?.isIntersecting && hasNextPage && !isFetchingNextPage) fetchNextPage();
    }, { rootMargin: "400px" });
    observer.observe(el);
    return () => observer.disconnect();
  }, [hasNextPage, isFetchingNextPage, fetchNextPage, dayMissions.length]);

  // Maquette Mission-drawer — le détail s'ouvre en tiroir au-dessus de la liste, qui reste
  // montée ; la mission ouverte est dans l'URL (?mission=) pour être partageable.
  const openedMissionId = Number(searchParams.get("mission")) || null;
  const openMission = (m: Mission) => patchParams({ mission: String(m.id) });
  const closeMission = useCallback(() => patchParams({ mission: undefined }), [patchParams]);

  const columns = useMemo<GridColDef<Mission>[]>(
    () => [
      {
        field: "date",
        headerName: "Date / heure",
        flex: 1.5,
        sortable: false,
        renderCell: ({ row }) => (
          <Typography variant="body2">
            {formatBrusselsRange(row.startAt, row.endAt)}
          </Typography>
        ),
      },
      {
        field: "site",
        headerName: "Site",
        flex: 1,
        sortable: false,
        valueGetter: (_, row) => row.site?.name ?? "—",
      },
      {
        field: "type",
        headerName: "Type",
        flex: 0.9,
        sortable: false,
        renderCell: ({ row }) => (
          <Typography variant="body2" color="text.secondary">
            {formatMissionType(row.type)}
          </Typography>
        ),
      },
      {
        field: "surgeon",
        headerName: "Chirurgien",
        flex: 1,
        sortable: false,
        valueGetter: (_, row) => formatPersonLabel(row.surgeon),
      },
      {
        field: "instrumentist",
        headerName: "Instrumentiste",
        flex: 1,
        sortable: false,
        valueGetter: (_, row) => formatPersonLabel(row.instrumentist),
      },
      {
        field: "status",
        headerName: "Statut",
        flex: 0.9,
        sortable: false,
        renderCell: ({ row }) => <MissionStatusChip status={row.status} encoding={row.encoding} />,
      },
      {
        field: "encoding",
        headerName: "Encodage",
        flex: 1.1,
        sortable: false,
        renderCell: ({ row }) => <MissionEncodingBadge encoding={row.encoding} status={row.status} />,
      },
    ],
    [],
  );

  if (activeQuery.isError && !isRequestsView) return <Typography color="error" sx={{ p: 3 }}>Erreur de chargement</Typography>;

  return (
    <Box>
      <Stack direction="row" alignItems="center" justifyContent="space-between" mb={2.5}>
        <Typography variant="h6" fontWeight={600}>Missions</Typography>

        {!isToValidateView && !isRequestsView && (
          <Button
            variant="contained"
            startIcon={<AddIcon />}
            onClick={() => navigate("/app/m/missions/new")}
            disableElevation
          >
            Nouvelle mission
          </Button>
        )}
      </Stack>

      <Tabs
        value={isRequestsView ? "requests" : isToValidateView ? "to-validate" : "all"}
        onChange={(_, v) => navigate(v === "to-validate" ? "/app/m/missions/to-validate" : v === "requests" ? "/app/m/missions/requests" : "/app/m/missions")}
        sx={{ mb: 2, borderBottom: 1, borderColor: "divider", minHeight: 40 }}
      >
        <Tab value="all" label="Toutes" sx={{ minHeight: 40 }} />
        <Tab value="to-validate" label="À valider" sx={{ minHeight: 40 }} />
        <Tab
          value="requests"
          sx={{ minHeight: 40 }}
          label={
            <Badge badgeContent={pendingSurgeonMissionRequestCount} color="warning" sx={{ "& .MuiBadge-badge": { right: -10, top: -2 } }}>
              Demandes chirurgien
            </Badge>
          }
        />
      </Tabs>

      {isRequestsView ? (
        <SurgeonMissionRequestsPanel />
      ) : (
        <>
          {validatedWithoutCalculation && (
            <Chip
              label="Validées sans calcul"
              color="warning"
              size="small"
              onDelete={() => patchParams({ validatedWithoutCalculation: undefined })}
              sx={{ mb: 2 }}
            />
          )}

          <MissionsFiltersBar
            value={{ ...filters, search: searchInput }}
            onChange={onFiltersChange}
            statusLocked={isToValidateView}
            onReset={hasActiveFilters ? resetFilters : undefined}
          />

          <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 1.5, minHeight: 24 }}>
            <Typography variant="body2" color="text.secondary" data-testid="missions-total">
              {activeQuery.isLoading ? "Chargement…" : `${total} mission${total > 1 ? "s" : ""}${cancelledNote ? ` · ${cancelledNote}` : ""}`}
            </Typography>
            {activeQuery.isFetching && !activeQuery.isLoading && <CircularProgress size={14} />}
          </Stack>

          {filters.view === "day" ? (
            dayQuery.isLoading ? (
              <LinearProgress />
            ) : dayMissions.length === 0 ? (
              <EmptyState
                title="Aucune mission."
                description={hasActiveFilters ? "Aucun résultat pour ces filtres." : undefined}
              />
            ) : (
              <Box sx={{ opacity: dayQuery.isPlaceholderData ? 0.6 : 1, transition: "opacity 150ms" }}>
                <MissionsDayList missions={dayMissions} onOpen={openMission} selectedId={openedMissionId} />
                <Box ref={sentinelRef} sx={{ display: "flex", justifyContent: "center", py: 2 }}>
                  {hasNextPage && (
                    <Button size="small" variant="outlined" onClick={() => fetchNextPage()} disabled={isFetchingNextPage}>
                      {isFetchingNextPage ? <CircularProgress size={16} /> : `Charger plus (${dayMissions.length} / ${total})`}
                    </Button>
                  )}
                </Box>
              </Box>
            )
          ) : (
            <DataGrid
              rows={tableQuery.data?.items ?? []}
              columns={columns}
              getRowId={(row) => row.id}
              loading={tableQuery.isLoading || tableQuery.isFetching}
              paginationMode="server"
              rowCount={total}
              pageSizeOptions={[TABLE_PAGE_SIZE]}
              paginationModel={{ page: tablePage - 1, pageSize: TABLE_PAGE_SIZE }}
              onPaginationModelChange={(m) => setTablePage(m.page + 1)}
              autoHeight
              disableRowSelectionOnClick
              onRowClick={({ row }) => openMission(row)}
              getRowClassName={({ row }) => (row.id === openedMissionId ? "is-selected" : "")}
              sx={{
                border: "none",
                "& .MuiDataGrid-row": { cursor: "pointer" },
                "& .MuiDataGrid-row:hover": { bgcolor: "action.hover" },
                "& .MuiDataGrid-row.is-selected, & .MuiDataGrid-row.is-selected:hover": { bgcolor: "#EFFAF5", boxShadow: "inset 3px 0 0 #338F6E" },
                "& .MuiDataGrid-columnHeaders": { bgcolor: "grey.50" },
                "& .MuiDataGrid-cell": { alignContent: "center" },
              }}
            />
          )}
        </>
      )}

      {!isRequestsView && <ManagerMissionDrawer missionId={openedMissionId} onClose={closeMission} />}
    </Box>
  );
}
