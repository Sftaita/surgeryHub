import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate, useSearchParams } from "react-router-dom";
import {
  Alert,
  Badge,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogTitle,
  DialogContent,
  IconButton,
  Stack,
  Typography,
} from "@mui/material";
import ChevronLeftIcon from "@mui/icons-material/ChevronLeft";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";
import MeetingRoomOutlinedIcon from "@mui/icons-material/MeetingRoomOutlined";

import { fetchMissions } from "../../features/missions/api/missions.api";
import { MissionDetailContent } from "../instrumentist/MissionDetailPage";
import type { Mission } from "../../features/missions/api/missions.types";
import type { DateTileVariant } from "../../ui/mobile/DateTile";
import type { StatusPillVariant } from "../../ui/mobile/StatusPill";
import { getMyAvailableRooms, getMyAvailableRoomsCount } from "../../features/planning-v2/api/planningV2.api";
import type { ReleasedRoomSlotV2 } from "../../features/planning-v2/api/planningV2.types";
import {
  type ViewMode,
  type MonthDayMeta,
  formatDateToYmd,
  isValidYmd,
  getRange,
  getYmdRange,
  getSafeView,
  shiftDate,
  formatDisplayDate,
  parseYmdToLocalDate,
  getMissionStartDayKey,
  compareMissionsByStart,
  SegmentedControl,
  WeekStrip,
  MonthGrid,
  MissionListRow,
  AvailableRoomsListSection,
  EmptyStateRow,
} from "../../features/mobile-planning/planningPrimitives";

// Planning chirurgien (Lot 2, socle mobile D-095) — réutilise intégralement le moteur
// de grille/calendrier extrait de l'instrumentiste (features/mobile-planning), jamais
// une deuxième implémentation. Diffère uniquement sur ce qui doit vraiment différer :
// - pas de section "À encoder" (l'encodage est instrumentiste uniquement) ;
// - la couleur secondaire de la grille mensuelle représente "à couvrir" (statut OPEN),
//   pas "à encoder" ;
// - le libellé affiché sur chaque ligne est l'instrumentiste assigné (ou "À couvrir"),
//   jamais le chirurgien (déjà connu du viewer) ;
// - un filtre Toutes/Couvertes/À couvrir remplace les deux sections fixes.
//
// Couverture : jamais recalculée ici — `mission.covered` vient du backend
// (PlanningCoverageService::isCovered(), même règle que le planning manager/la
// tuile dashboard). "À couvrir" = statut OPEN précisément (les statuts DRAFT/
// DECLARED/REJECTED/ENCODING_IN_PROGRESS ne sont ni couverts ni "à couvrir" au sens
// actionnable — visibles seulement sous "Toutes"). CANCELLED n'est jamais compté
// comme "à couvrir", affiché discrètement (pastille grise "Annulée").
const SURGEON_PLANNING_STATUSES =
  "OPEN,ASSIGNED,SUBMITTED,VALIDATED,CLOSED,IN_PROGRESS,CANCELLED";

type CoverageFilter = "all" | "covered" | "uncovered";

function isActionableUncovered(mission: Mission): boolean {
  return mission.status === "OPEN";
}

/** Libellé d'un jour unique pour le panneau détail — jamais une plage (formatDisplayDate
 *  ne couvre que "mois" et "semaine"). */
function formatSingleDayLabel(dayKey: string): string {
  return parseYmdToLocalDate(dayKey).toLocaleDateString("fr-BE", {
    weekday: "long",
    day: "numeric",
    month: "long",
  });
}

function getInstrumentistLabel(mission: Mission): string {
  const i = mission.instrumentist;
  if (!i) return "À couvrir";
  const displayName = i.displayName?.trim();
  if (displayName) return displayName;
  const fn = (i.firstname ?? "").trim();
  const ln = (i.lastname ?? "").trim();
  const full = `${fn} ${ln}`.trim();
  if (full) return full;
  return i.email || "—";
}

function surgeonMissionRowStatus(mission: Mission): { variant: StatusPillVariant; label: string; withDot?: boolean } {
  if (mission.status === "CANCELLED") return { variant: "refusee", label: "Annulée" };
  if (mission.status === "IN_PROGRESS") return { variant: "enCours", label: "En cours", withDot: true };
  if (mission.covered) return { variant: "confirmee", label: "Couverte" };
  return { variant: "aEncoder", label: "À couvrir" };
}

function dateTileVariantFor(status: { variant: StatusPillVariant }): DateTileVariant {
  if (status.variant === "refusee") return "refusee";
  if (status.variant === "aEncoder") return "aEncoder";
  return "confirmee";
}

function matchesFilter(mission: Mission, filter: CoverageFilter): boolean {
  if (filter === "all") return true;
  if (filter === "covered") return mission.covered === true;
  return isActionableUncovered(mission);
}

// ── Filtre 3 options (Toutes/Couvertes/À couvrir) — même style visuel que
// SegmentedControl (2 options, Semaine/Mois), gardé local plutôt que de généraliser
// le composant partagé pour un seul consommateur.
function CoverageFilterControl({ value, onChange }: { value: CoverageFilter; onChange: (v: CoverageFilter) => void }) {
  const opt = (key: CoverageFilter, label: string) => (
    <Box
      component="button"
      type="button"
      onClick={() => onChange(key)}
      sx={{
        height: 32, px: "14px", borderRadius: "9px", border: "none", fontFamily: "inherit",
        fontSize: 12.5, fontWeight: value === key ? 700 : 600, cursor: "pointer",
        background: value === key ? "#F1F4F7" : "transparent",
        color: value === key ? "#16202B" : "#566270",
      }}
    >
      {label}
    </Box>
  );

  return (
    <Box sx={{ display: "flex", gap: "3px", background: "#fff", borderRadius: "12px", padding: "3px", boxShadow: "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)", width: "max-content" }}>
      {opt("all", "Toutes")}
      {opt("covered", "Couvertes")}
      {opt("uncovered", "À couvrir")}
    </Box>
  );
}

export default function SurgeonPlanningPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [selectedMissionId, setSelectedMissionId] = React.useState<number | null>(null);
  // Intégration ReleasedOperatingRoomSlot dans l'agenda (revue 2026-09-07) — panneau détail
  // du jour, distinct du détail mission : un jour avec au moins une salle disponible ouvre
  // toujours ce panneau (missions + salles listées séparément), jamais mélangées.
  const [selectedDayKey, setSelectedDayKey] = React.useState<string | null>(null);
  const touchStartXRef = React.useRef<number | null>(null);
  const touchStartYRef = React.useRef<number | null>(null);

  const todayYmd = React.useMemo(() => formatDateToYmd(new Date()), []);
  const view = getSafeView(searchParams.get("view"));
  const date = isValidYmd(searchParams.get("date")) ? (searchParams.get("date") as string) : todayYmd;
  const filterParam = searchParams.get("filter");
  const filter: CoverageFilter = filterParam === "covered" || filterParam === "uncovered" ? filterParam : "all";

  React.useEffect(() => {
    const next = new URLSearchParams(searchParams);
    let changed = false;
    if (next.get("view") !== view) { next.set("view", view); changed = true; }
    if (next.get("date") !== date) { next.set("date", date); changed = true; }
    if (changed) setSearchParams(next, { replace: true });
  }, [date, searchParams, setSearchParams, view]);

  const range = React.useMemo(() => getRange(view, date), [view, date]);

  // Pas de assignedToMe:true ici — /api/missions self-scope déjà m.surgeon = self pour
  // tout appelant ROLE_SURGEON sans ce paramètre (MissionService::list()). Passer
  // assignedToMe:true filtrerait sur m.instrumentist, toujours vide pour un chirurgien.
  const missionsQuery = useQuery({
    queryKey: ["missions", "surgeon-planning", { view, date, from: range.from, to: range.to }],
    queryFn: () =>
      fetchMissions(1, 200, {
        from: range.from,
        to: range.to,
        status: SURGEON_PLANNING_STATUSES,
      }),
  });

  const allMissions = missionsQuery.data?.items ?? [];
  const missions = React.useMemo(() => allMissions.filter((m) => matchesFilter(m, filter)), [allMissions, filter]);

  const dayBuckets = React.useMemo(() => {
    const buckets = new Map<string, Mission[]>();
    for (const mission of missions) {
      const dayKey = getMissionStartDayKey(mission);
      if (!dayKey) continue;
      const existing = buckets.get(dayKey);
      if (existing) existing.push(mission);
      else buckets.set(dayKey, [mission]);
    }
    for (const list of buckets.values()) list.sort(compareMissionsByStart);
    return buckets;
  }, [missions]);

  // Salles disponibles (ReleasedOperatingRoomSlot, intégration agenda, revue 2026-09-07) —
  // fenêtre visible uniquement (dateFrom/dateTo = range affiché), jamais tout l'historique
  // (§13 de la demande). Source unique : GET /api/me/available-rooms, jamais un recalcul
  // frontend des absences ni le journal email (§11).
  // `getYmdRange()`, jamais `range` (ISO/`.toISOString()`) : le backend exige strictement
  // `Y-m-d` et rejette (400) tout ce qui porte une heure/un `Z` — vérifié en live, le premier
  // câblage utilisant `range` ici renvoyait silencieusement une liste vide (requête en échec
  // jamais surfacée à l'utilisateur, seul le badge compteur — appel séparé — restait correct).
  const roomsRange = React.useMemo(() => getYmdRange(view, date), [view, date]);
  const roomsQuery = useQuery({
    queryKey: ["available-rooms", "mine", "planning", { from: roomsRange.from, to: roomsRange.to }],
    queryFn: () => getMyAvailableRooms({ dateFrom: roomsRange.from, dateTo: roomsRange.to, limit: 100 }),
  });
  const roomsInView = roomsQuery.data?.items ?? [];

  // Badge CTA : nombre total de créneaux futurs visibles par ce chirurgien, jamais borné à
  // la fenêtre affichée — endpoint léger dédié (§12/§13), jamais la liste complète chargée
  // seulement pour compter.
  const roomsCountQuery = useQuery({
    queryKey: ["available-rooms", "mine", "count"],
    queryFn: () => getMyAvailableRoomsCount(),
  });

  const roomsByDay = React.useMemo(() => {
    const buckets = new Map<string, ReleasedRoomSlotV2[]>();
    for (const slot of roomsInView) {
      const dayKey = slot.occurrenceDate.slice(0, 10);
      const existing = buckets.get(dayKey);
      if (existing) existing.push(slot);
      else buckets.set(dayKey, [slot]);
    }
    return buckets;
  }, [roomsInView]);

  const dayMeta = React.useMemo(() => {
    const meta = new Map<string, MonthDayMeta & { firstMissionId: number | null }>();
    const dayKeys = new Set<string>([...dayBuckets.keys(), ...roomsByDay.keys()]);
    for (const dayKey of dayKeys) {
      const missionList = dayBuckets.get(dayKey);
      const roomList = roomsByDay.get(dayKey);
      meta.set(dayKey, {
        hasConflict: false,
        hasSecondary: missionList?.some(isActionableUncovered) ?? false,
        hasMission: Boolean(missionList?.length),
        firstMissionId: missionList?.[0]?.id ?? null,
        availableRoomCount: roomList?.length ?? 0,
      });
    }
    return meta;
  }, [dayBuckets, roomsByDay]);

  const sortedMissions = React.useMemo(
    () => [...missions].sort(compareMissionsByStart),
    [missions],
  );

  const sortedRoomsInView = React.useMemo(
    () => [...roomsInView].sort((a, b) => a.occurrenceDate.localeCompare(b.occurrenceDate) || a.id - b.id),
    [roomsInView],
  );

  const updateSearchParams = React.useCallback(
    (patch: Partial<{ view: ViewMode; date: string; filter: CoverageFilter }>) => {
      const next = new URLSearchParams(searchParams);
      next.set("view", patch.view ?? view);
      next.set("date", patch.date ?? date);
      next.set("filter", patch.filter ?? filter);
      setSearchParams(next);
    },
    [searchParams, view, date, filter, setSearchParams],
  );

  const handlePeriodShift = React.useCallback(
    (direction: -1 | 1) => {
      updateSearchParams({ date: shiftDate(date, view, direction) });
    },
    [date, updateSearchParams, view],
  );

  const handleDayClick = React.useCallback(
    (dayKey: string) => {
      const meta = dayMeta.get(dayKey);
      if (!meta) return;
      // Zéro régression : un jour sans salle disponible garde exactement le comportement
      // existant (ouverture directe de la première mission). Dès qu'il y a au moins une
      // salle ce jour-là, on passe par le panneau détail du jour — jamais de salle
      // silencieusement absorbée dans le dialogue mission.
      if ((meta.availableRoomCount ?? 0) === 0) {
        if (meta.firstMissionId !== null) setSelectedMissionId(meta.firstMissionId);
        return;
      }
      setSelectedDayKey(dayKey);
    },
    [dayMeta],
  );

  const handleTouchStart = React.useCallback((event: React.TouchEvent<HTMLDivElement>) => {
    touchStartXRef.current = event.changedTouches[0]?.clientX ?? null;
    touchStartYRef.current = event.changedTouches[0]?.clientY ?? null;
  }, []);

  const handleTouchEnd = React.useCallback(
    (event: React.TouchEvent<HTMLDivElement>) => {
      const startX = touchStartXRef.current;
      const startY = touchStartYRef.current;
      const endX = event.changedTouches[0]?.clientX ?? null;
      const endY = event.changedTouches[0]?.clientY ?? null;
      touchStartXRef.current = null;
      touchStartYRef.current = null;
      if (startX === null || startY === null || endX === null || endY === null) return;
      const deltaX = endX - startX;
      const deltaY = endY - startY;
      if (Math.abs(deltaX) < 50) return;
      if (Math.abs(deltaX) <= Math.abs(deltaY)) return;
      handlePeriodShift(deltaX < 0 ? 1 : -1);
    },
    [handlePeriodShift],
  );

  return (
    <Box onTouchStart={handleTouchStart} onTouchEnd={handleTouchEnd} sx={{ display: "flex", flexDirection: "column", gap: "20px" }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1.5} flexWrap="wrap" useFlexGap>
        <SegmentedControl view={view} onChange={(v) => updateSearchParams({ view: v })} />
        <Stack direction="row" alignItems="center" spacing={0.25}>
          <IconButton size="small" onClick={() => handlePeriodShift(-1)}>
            <ChevronLeftIcon fontSize="small" />
          </IconButton>
          <Typography variant="body2" fontWeight={600} sx={{ minWidth: 140, textAlign: "center" }} noWrap>
            {formatDisplayDate(date, view)}
          </Typography>
          <IconButton size="small" onClick={() => handlePeriodShift(1)}>
            <ChevronRightIcon fontSize="small" />
          </IconButton>
          <Box
            component="button"
            type="button"
            onClick={() => updateSearchParams({ date: todayYmd })}
            sx={{ border: "none", background: "none", color: "#2C7D5F", fontWeight: 700, fontSize: 13, cursor: "pointer", fontFamily: "inherit", ml: "4px" }}
          >
            Auj.
          </Box>
        </Stack>
      </Stack>

      <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1.5} flexWrap="wrap" useFlexGap>
        <CoverageFilterControl value={filter} onChange={(v) => updateSearchParams({ filter: v })} />
        <Stack direction="row" alignItems="center" spacing={1.5}>
          {/* Lot D (post D-114, intégration agenda revue 2026-09-07) — accès à la vue
              "Salles disponibles", jamais une 6e entrée navbar. Badge = nombre total de
              créneaux futurs visibles par ce chirurgien (endpoint count dédié, §7/§12). */}
          <Button
            size="small"
            variant="outlined"
            startIcon={<MeetingRoomOutlinedIcon fontSize="small" />}
            onClick={() => navigate("/app/s/planning/salles-disponibles")}
            sx={{ borderColor: "#1B5FD0", color: "#1B5FD0", fontWeight: 700, fontSize: 13, textTransform: "none", borderRadius: "10px" }}
          >
            Salles disponibles
            {Boolean(roomsCountQuery.data?.count) && (
              <Badge
                badgeContent={roomsCountQuery.data?.count}
                color="primary"
                sx={{ ml: 1.5, "& .MuiBadge-badge": { position: "static", transform: "none", background: "#1B5FD0" } }}
              />
            )}
          </Button>
          {/* CTA demande de mission (Lot 5, D-099, §14) — accessible depuis le planning,
              jamais une 6e entrée navbar. */}
          <Box
            component="button" type="button" onClick={() => navigate("/app/s/mission-requests/new")}
            sx={{ border: "none", background: "none", color: "#1F6B4F", fontWeight: 700, fontSize: 13, cursor: "pointer", fontFamily: "inherit" }}
          >
            + Demander une mission
          </Box>
        </Stack>
      </Stack>

      {missionsQuery.isError && (
        <Alert severity="error">Impossible de charger le planning.</Alert>
      )}

      {missionsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
          <CircularProgress size={28} />
        </Box>
      ) : (
        <>
          {view === "week" ? (
            <WeekStrip
              date={date}
              todayYmd={todayYmd}
              hasMissionOn={(k) => dayBuckets.has(k)}
              hasAvailableRoomOn={(k) => (roomsByDay.get(k)?.length ?? 0) > 0}
              onDayClick={handleDayClick}
            />
          ) : (
            <MonthGrid
              date={date}
              todayYmd={todayYmd}
              dayMeta={dayMeta}
              onDayClick={handleDayClick}
              secondaryLabel="À couvrir"
            />
          )}

          <Stack spacing={1.375}>
            <Stack direction="row" alignItems="center" spacing={1.5}>
              <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: "#2C7D5F", whiteSpace: "nowrap" }}>
                MISSIONS
              </Box>
              <Box sx={{ flex: 1, borderTop: "1px dashed", borderColor: "grey.200" }} />
            </Stack>

            {sortedMissions.length === 0 ? (
              <EmptyStateRow
                text={
                  filter === "covered"
                    ? "Aucune mission couverte sur cette période"
                    : filter === "uncovered"
                      ? "Aucune mission à couvrir sur cette période"
                      : "Aucune mission sur cette période"
                }
              />
            ) : (
              sortedMissions.map((m) => {
                const status = surgeonMissionRowStatus(m);
                return (
                  <MissionListRow
                    key={m.id}
                    mission={m}
                    subtitlePerson={getInstrumentistLabel(m)}
                    statusInfo={status}
                    dateTileVariant={dateTileVariantFor(status)}
                    onClick={() => setSelectedMissionId(m.id)}
                  />
                );
              })
            )}
          </Stack>

          {/* Section séparée, jamais mélangée à MISSIONS — intégration agenda 2026-09-07. */}
          <AvailableRoomsListSection slots={sortedRoomsInView} />
        </>
      )}

      <Dialog
        open={selectedMissionId !== null}
        onClose={() => setSelectedMissionId(null)}
        fullWidth
        maxWidth="md"
      >
        <DialogTitle>Détail mission</DialogTitle>
        <DialogContent dividers>
          {selectedMissionId ? (
            <MissionDetailContent
              missionId={selectedMissionId}
              embedded
              onCloseEmbedded={() => setSelectedMissionId(null)}
            />
          ) : null}
        </DialogContent>
      </Dialog>

      {/* Panneau détail du jour — missions et salles disponibles listées séparément, jamais
          mélangées. N'ouvre jamais directement le détail d'une salle (elle n'a pas de "détail"
          au sens mission) ; cliquer une mission ici ouvre le dialogue mission ci-dessus. */}
      <Dialog
        open={selectedDayKey !== null}
        onClose={() => setSelectedDayKey(null)}
        fullWidth
        maxWidth="sm"
      >
        <DialogTitle>{selectedDayKey ? formatSingleDayLabel(selectedDayKey) : ""}</DialogTitle>
        <DialogContent dividers sx={{ display: "flex", flexDirection: "column", gap: "20px" }}>
          {selectedDayKey && (dayBuckets.get(selectedDayKey)?.length ?? 0) > 0 && (
            <Stack spacing={1.375}>
              <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: "#2C7D5F" }}>MISSIONS</Box>
              {(dayBuckets.get(selectedDayKey) ?? []).map((m) => {
                const status = surgeonMissionRowStatus(m);
                return (
                  <MissionListRow
                    key={m.id}
                    mission={m}
                    subtitlePerson={getInstrumentistLabel(m)}
                    statusInfo={status}
                    dateTileVariant={dateTileVariantFor(status)}
                    onClick={() => {
                      setSelectedDayKey(null);
                      setSelectedMissionId(m.id);
                    }}
                  />
                );
              })}
            </Stack>
          )}
          {selectedDayKey && (
            <AvailableRoomsListSection slots={roomsByDay.get(selectedDayKey) ?? []} />
          )}
        </DialogContent>
      </Dialog>
    </Box>
  );
}
