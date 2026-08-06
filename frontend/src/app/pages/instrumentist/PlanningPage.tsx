import * as React from "react";
import { useQuery } from "@tanstack/react-query";
import { useNavigate, useSearchParams } from "react-router-dom";
import {
  Alert,
  Box,
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

import { fetchMissions } from "../../features/missions/api/missions.api";
import { MissionDetailContent } from "./MissionDetailPage";
import type { Mission } from "../../features/missions/api/missions.types";
import type { DateTileVariant } from "../../ui/mobile/DateTile";
import type { StatusPillVariant } from "../../ui/mobile/StatusPill";
import {
  type ViewMode,
  type MonthDayMeta,
  formatDateToYmd,
  isValidYmd,
  getRange,
  getSafeView,
  shiftDate,
  formatDisplayDate,
  normalizeMissionInterval,
  getMissionStartDayKey,
  compareMissionsByStart,
  buildMonthGridCells,
  SegmentedControl,
  WeekStrip,
  MonthGrid,
  MissionListRow,
  EmptyStateRow,
} from "../../features/mobile-planning/planningPrimitives";

// Planning instrumentiste — la grille/calendrier générique (dates, SegmentedControl,
// WeekStrip, MonthGrid, MissionListRow, EmptyStateRow) vit désormais dans
// features/mobile-planning/planningPrimitives.tsx, partagée avec le planning
// chirurgien (Lot 2, socle mobile D-095). Ce fichier ne garde que ce qui reste
// réellement spécifique instrumentiste : la classification "à encoder"/"à venir"
// (basée sur allowedActions, jamais sur le statut seul), le libellé chirurgien
// affiché sur chaque ligne, et la bannière "aucune mission à venir" (CTA offres).
// Comportement inchangé — voir PlanningPage.test.tsx (non-régression explicite).

// Ré-exportées pour compat : PlanningPage.test.tsx importe historiquement
// buildMonthGridCells/formatDateToYmd directement depuis ce fichier.
export { buildMonthGridCells, formatDateToYmd };

const GREEN_700 = "#2C7D5F";
const AMBER_500 = "#F0A91B";
const BLUE_50 = "#EDF4FF";
const BLUE_700 = "#1B5FD0";

const MY_MISSIONS_STATUSES =
  "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED,CLOSED";

const CONFLICT_STATUSES = new Set(["ASSIGNED", "DECLARED", "IN_PROGRESS"]);
const SWIPE_THRESHOLD_PX = 50;

function getSurgeonLabel(mission: Mission): string {
  const displayName = mission.surgeon?.displayName?.trim();
  if (displayName) return `Dr ${displayName}`;
  const firstname = mission.surgeon?.firstname?.trim() ?? "";
  const lastname = mission.surgeon?.lastname?.trim() ?? "";
  const fullName = `${firstname} ${lastname}`.trim();
  if (fullName) return `Dr ${fullName}`;
  return "";
}

function isPendingEncoding(mission: Mission): boolean {
  return mission.allowedActions?.some((a) => a === "encoding" || a === "edit_encoding") ?? false;
}

export type PlanningBucket = "toEncode" | "upcoming" | "other";

/**
 * "À encoder" / "À venir" pour le planning instrumentiste — la seule source fiable pour
 * "cette mission attend un encodage" est `allowedActions` (déjà calculé côté backend par
 * MissionEncodingGuard sur `startAt`, jamais `actualStartAt` : ce champ n'existe que sur
 * MissionExecution, pour la durée, jamais exposé par /api/missions). Une mission ne peut
 * porter "encoding"/"edit_encoding" qu'une fois son heure de début passée et tant qu'elle
 * n'est ni REJECTED ni verrouillée (encodingLockedAt) — donc jamais une mission future, et
 * jamais une mission déjà SUBMITTED/VALIDATED (ces statuts ne génèrent plus ces actions).
 * "À venir" est un pur repli d'affichage sur `startAt` (chaîne ISO déjà porteuse du bon
 * offset horaire métier, comparaison d'instants absolus — pas une comparaison de date
 * locale naïve) : jamais recalculé pour "à encoder", jamais dupliqué entre les deux.
 */
export function classifyPlanningMission(mission: Mission, nowMs: number): PlanningBucket {
  if (isPendingEncoding(mission)) return "toEncode";
  const startMs = new Date(mission.startAt).getTime();
  if (Number.isFinite(startMs) && startMs > nowMs) return "upcoming";
  return "other";
}

function missionRowStatus(mission: Mission): { variant: StatusPillVariant; label: string; withDot?: boolean } {
  if (mission.status === "IN_PROGRESS") return { variant: "enCours", label: "En cours", withDot: true };
  if (isPendingEncoding(mission)) return { variant: "aEncoder", label: "À encoder" };
  if (mission.status === "DECLARED") return { variant: "enAttente", label: "En attente" };
  return { variant: "confirmee", label: "Confirmée" };
}

/** Même correspondance que l'ancien MissionListRow interne (comportement inchangé). */
function dateTileVariantFor(status: { variant: StatusPillVariant }): DateTileVariant {
  if (status.variant === "enCours" || status.variant === "confirmee") return "confirmee";
  if (status.variant === "aEncoder") return "aEncoder";
  return "aVenir";
}

// ── Bandeau info (aucune mission à venir) ───────────────────────────────────
function EmptyUpcomingBanner({ onSeeOffers }: { onSeeOffers: () => void }) {
  return (
    <Stack direction="row" alignItems="center" spacing={1.5} sx={{ background: BLUE_50, borderRadius: "14px", padding: "14px 16px" }}>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={BLUE_700} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="9" /><path d="M12 8h.01M12 11v5" />
      </svg>
      <Typography sx={{ fontSize: 14, color: BLUE_700, lineHeight: 1.45 }}>
        Aucune mission à venir. Acceptez des offres pour compléter votre planning.{" "}
        <Box component="button" type="button" onClick={onSeeOffers} sx={{ border: "none", background: "none", p: 0, font: "inherit", fontWeight: 700, color: BLUE_700, textDecoration: "underline", cursor: "pointer" }}>
          Voir les offres
        </Box>
      </Typography>
    </Stack>
  );
}

export default function PlanningPage() {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const [selectedMissionId, setSelectedMissionId] = React.useState<number | null>(null);
  const touchStartXRef = React.useRef<number | null>(null);
  const touchStartYRef = React.useRef<number | null>(null);

  const todayYmd = React.useMemo(() => formatDateToYmd(new Date()), []);
  const view = getSafeView(searchParams.get("view"));
  const date = isValidYmd(searchParams.get("date")) ? (searchParams.get("date") as string) : todayYmd;

  React.useEffect(() => {
    const next = new URLSearchParams(searchParams);
    let changed = false;
    if (next.get("view") !== view) { next.set("view", view); changed = true; }
    if (next.get("date") !== date) { next.set("date", date); changed = true; }
    if (changed) setSearchParams(next, { replace: true });
  }, [date, searchParams, setSearchParams, view]);

  const range = React.useMemo(() => getRange(view, date), [view, date]);

  const missionsQuery = useQuery({
    queryKey: ["missions", "planning", { view, date, from: range.from, to: range.to }],
    queryFn: () =>
      fetchMissions(1, 100, {
        from: range.from,
        to: range.to,
        assignedToMe: true,
        status: MY_MISSIONS_STATUSES,
      }),
  });

  const missions = missionsQuery.data?.items ?? [];

  const conflictMissionIds = React.useMemo(() => {
    const relevant = missions
      .filter((m) => m.status && CONFLICT_STATUSES.has(m.status))
      .map((m) => ({ mission: m, interval: normalizeMissionInterval(m) }))
      .filter((item): item is { mission: Mission; interval: NonNullable<ReturnType<typeof normalizeMissionInterval>> } =>
        item.interval !== null
      );

    const ids = new Set<number>();
    for (let i = 0; i < relevant.length; i++) {
      for (let j = i + 1; j < relevant.length; j++) {
        const a = relevant[i].interval;
        const b = relevant[j].interval;
        if (a.startMs < b.endMs && b.startMs < a.endMs) {
          ids.add(relevant[i].mission.id);
          ids.add(relevant[j].mission.id);
        }
      }
    }
    return ids;
  }, [missions]);

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

  const dayMeta = React.useMemo(() => {
    const meta = new Map<string, MonthDayMeta & { firstMissionId: number }>();
    for (const [dayKey, list] of dayBuckets.entries()) {
      const first = list[0];
      if (!first) continue;
      meta.set(dayKey, {
        hasConflict: list.some((m) => conflictMissionIds.has(m.id)),
        hasSecondary: list.some(isPendingEncoding),
        hasMission: true,
        firstMissionId: first.id,
      });
    }
    return meta;
  }, [dayBuckets, conflictMissionIds]);

  const { toEncodeMissions, upcomingMissions } = React.useMemo(() => {
    const nowMs = Date.now();
    const toEncode: Mission[] = [];
    const upcoming: Mission[] = [];
    for (const mission of missions) {
      const bucket = classifyPlanningMission(mission, nowMs);
      if (bucket === "toEncode") toEncode.push(mission);
      else if (bucket === "upcoming") upcoming.push(mission);
    }
    toEncode.sort(compareMissionsByStart);
    upcoming.sort(compareMissionsByStart);
    return { toEncodeMissions: toEncode, upcomingMissions: upcoming };
  }, [missions]);

  const updateSearchParams = React.useCallback(
    (patch: Partial<{ view: ViewMode; date: string }>) => {
      const next = new URLSearchParams(searchParams);
      next.set("view", patch.view ?? view);
      next.set("date", patch.date ?? date);
      setSearchParams(next);
    },
    [searchParams, view, date, setSearchParams],
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
      if (meta) setSelectedMissionId(meta.firstMissionId);
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
      if (Math.abs(deltaX) < SWIPE_THRESHOLD_PX) return;
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
            sx={{ border: "none", background: "none", color: GREEN_700, fontWeight: 700, fontSize: 13, cursor: "pointer", fontFamily: "inherit", ml: "4px" }}
          >
            Auj.
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
            <WeekStrip date={date} todayYmd={todayYmd} hasMissionOn={(k) => dayBuckets.has(k)} onDayClick={handleDayClick} />
          ) : (
            <MonthGrid date={date} todayYmd={todayYmd} dayMeta={dayMeta} onDayClick={handleDayClick} />
          )}

          <Stack spacing={1.375}>
            <Stack direction="row" alignItems="center" spacing={1.5}>
              <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: AMBER_500, whiteSpace: "nowrap" }}>
                À ENCODER
              </Box>
              <Box sx={{ flex: 1, borderTop: "1px dashed", borderColor: "grey.200" }} />
            </Stack>

            {toEncodeMissions.length === 0 ? (
              <EmptyStateRow text="Aucune mission à encoder" />
            ) : (
              toEncodeMissions.map((m) => {
                const status = missionRowStatus(m);
                return (
                  <MissionListRow
                    key={m.id}
                    mission={m}
                    subtitlePerson={getSurgeonLabel(m)}
                    statusInfo={status}
                    dateTileVariant={dateTileVariantFor(status)}
                    onClick={() => navigate(`/app/i/missions/${m.id}/encoding`)}
                  />
                );
              })
            )}
          </Stack>

          <Stack spacing={1.375}>
            <Stack direction="row" alignItems="center" spacing={1.5}>
              <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700, whiteSpace: "nowrap" }}>
                À VENIR
              </Box>
              <Box sx={{ flex: 1, borderTop: "1px dashed", borderColor: "grey.200" }} />
            </Stack>

            {upcomingMissions.length === 0 ? (
              <EmptyUpcomingBanner onSeeOffers={() => navigate("/app/i/offers")} />
            ) : (
              upcomingMissions.map((m) => {
                const status = missionRowStatus(m);
                return (
                  <MissionListRow
                    key={m.id}
                    mission={m}
                    subtitlePerson={getSurgeonLabel(m)}
                    statusInfo={status}
                    dateTileVariant={dateTileVariantFor(status)}
                    onClick={() => setSelectedMissionId(m.id)}
                  />
                );
              })
            )}
          </Stack>
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
    </Box>
  );
}
