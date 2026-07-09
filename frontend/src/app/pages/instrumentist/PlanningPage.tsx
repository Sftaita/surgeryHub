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
import { DateTile } from "../../ui/mobile/DateTile";
import { StatusPill, type StatusPillVariant } from "../../ui/mobile/StatusPill";

type ViewMode = "week" | "month";

const GREEN_50 = "#EFFAF5";
const GREEN_300 = "#8FDABF";
const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_900 = "#144D38";
const AMBER_50 = "#FEF6E7";
const AMBER_500 = "#F0A91B";
const GRAY_300 = "#C2C9D1";
const GRAY_400 = "#98A2AE";
const GRAY_600 = "#566270";
const GRAY_900 = "#16202B";
const BLUE_50 = "#EDF4FF";
const BLUE_700 = "#1B5FD0";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";

const MY_MISSIONS_STATUSES =
  "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED,CLOSED";

const CONFLICT_STATUSES = new Set(["ASSIGNED", "DECLARED", "IN_PROGRESS"]);
const SWIPE_THRESHOLD_PX = 50;
const DOW_ABBR = ["LUN.", "MAR.", "MER.", "JEU.", "VEN.", "SAM.", "DIM."];
const MONTH_HEADER_LABELS = ["L", "M", "M", "J", "V", "S", "D"];

function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

export function formatDateToYmd(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function isValidYmd(value: string | null): value is string {
  if (!value) return false;
  return /^\d{4}-\d{2}-\d{2}$/.test(value);
}

function parseYmdToLocalDate(value: string): Date {
  const [year, month, day] = value.split("-").map(Number);
  return new Date(year, month - 1, day, 0, 0, 0, 0);
}

function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0, 0);
}

function addDays(date: Date, days: number): Date {
  const copy = new Date(date);
  copy.setDate(copy.getDate() + days);
  return copy;
}

function addMinutes(date: Date, minutes: number): Date {
  return new Date(date.getTime() + minutes * 60 * 1000);
}

function startOfWeek(date: Date): Date {
  const day = date.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  return startOfDay(addDays(date, diff));
}

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1, 0, 0, 0, 0);
}

function getRange(view: ViewMode, dateYmd: string): { from: string; to: string } {
  const baseDate = parseYmdToLocalDate(dateYmd);

  if (view === "month") {
    const from = startOfMonth(baseDate);
    const to = new Date(from.getFullYear(), from.getMonth() + 1, 1, 0, 0, 0, 0);
    return { from: from.toISOString(), to: to.toISOString() };
  }

  const from = startOfWeek(baseDate);
  const to = addDays(from, 7);
  return { from: from.toISOString(), to: to.toISOString() };
}

function getSafeView(value: string | null): ViewMode {
  if (value === "week" || value === "month") return value;
  return "month";
}

function shiftDate(dateYmd: string, view: ViewMode, direction: -1 | 1): string {
  const date = parseYmdToLocalDate(dateYmd);
  if (view === "month") {
    return formatDateToYmd(new Date(date.getFullYear(), date.getMonth() + direction, 1));
  }
  return formatDateToYmd(addDays(date, direction * 7));
}

function formatDisplayDate(dateYmd: string, view: ViewMode): string {
  const date = parseYmdToLocalDate(dateYmd);

  if (view === "month") {
    return date.toLocaleDateString("fr-BE", { month: "long", year: "numeric" });
  }

  const weekStart = startOfWeek(date);
  const weekEnd = addDays(weekStart, 6);
  const startLabel = weekStart.toLocaleDateString("fr-BE", {
    day: "2-digit",
    month: "short",
    year: weekStart.getFullYear() !== weekEnd.getFullYear() ? "numeric" : undefined,
  });
  const endLabel = weekEnd.toLocaleDateString("fr-BE", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
  return `${startLabel} — ${endLabel}`;
}

function normalizeMissionInterval(mission: { startAt: string; endAt?: string | null }) {
  const startDate = new Date(mission.startAt);
  const startMs = startDate.getTime();
  if (!Number.isFinite(startMs)) return null;

  const rawEndDate = mission.endAt ? new Date(mission.endAt) : null;
  const rawEndMs = rawEndDate ? rawEndDate.getTime() : Number.NaN;
  const endDate =
    rawEndDate && Number.isFinite(rawEndMs) && rawEndMs > startMs
      ? rawEndDate
      : addMinutes(startDate, 1);

  return { start: startDate.toISOString(), end: endDate.toISOString(), startMs, endMs: endDate.getTime() };
}

function formatMissionTime(startAt: string): string {
  const date = new Date(startAt);
  if (!Number.isFinite(date.getTime())) return "";
  return date.toLocaleTimeString("fr-BE", { hour: "2-digit", minute: "2-digit" });
}

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

function missionRowStatus(mission: Mission): { variant: StatusPillVariant; label: string; withDot?: boolean } {
  if (mission.status === "IN_PROGRESS") return { variant: "enCours", label: "En cours", withDot: true };
  if (isPendingEncoding(mission)) return { variant: "aEncoder", label: "À encoder" };
  if (mission.status === "DECLARED") return { variant: "enAttente", label: "En attente" };
  return { variant: "confirmee", label: "Confirmée" };
}

function getMissionStartDayKey(mission: Mission): string | null {
  const date = new Date(mission.startAt);
  if (!Number.isFinite(date.getTime())) return null;
  return formatDateToYmd(date);
}

function compareMissionsByStart(a: Mission, b: Mission): number {
  const aTime = new Date(a.startAt).getTime();
  const bTime = new Date(b.startAt).getTime();
  const safeA = Number.isFinite(aTime) ? aTime : Number.MAX_SAFE_INTEGER;
  const safeB = Number.isFinite(bTime) ? bTime : Number.MAX_SAFE_INTEGER;
  return safeA !== safeB ? safeA - safeB : a.id - b.id;
}

/** Grille complète (semaines pleines, lundi en premier) pour le mois de dateYmd. */
export function buildMonthGridCells(dateYmd: string): Array<{ dateYmd: string; dayNumber: number; inCurrentMonth: boolean }> {
  const monthStart = startOfMonth(parseYmdToLocalDate(dateYmd));
  const gridStart = startOfWeek(monthStart);
  const monthIndex = monthStart.getMonth();

  const cells: Array<{ dateYmd: string; dayNumber: number; inCurrentMonth: boolean }> = [];
  for (let i = 0; i < 42; i++) {
    const d = addDays(gridStart, i);
    cells.push({ dateYmd: formatDateToYmd(d), dayNumber: d.getDate(), inCurrentMonth: d.getMonth() === monthIndex });
  }

  let lastInMonth = 0;
  for (let i = 0; i < cells.length; i++) if (cells[i].inCurrentMonth) lastInMonth = i;
  const neededRows = Math.ceil((lastInMonth + 1) / 7);
  return cells.slice(0, neededRows * 7);
}

// ── Segmented control (Semaine/Mois) ────────────────────────────────────────
function SegmentedControl({ view, onChange }: { view: ViewMode; onChange: (v: ViewMode) => void }) {
  const seg = (key: ViewMode, label: string) => (
    <Box
      component="button"
      type="button"
      onClick={() => onChange(key)}
      sx={{
        height: 36, px: "16px", borderRadius: "10px", border: "none", fontFamily: "inherit",
        fontSize: 13.5, fontWeight: view === key ? 700 : 600, cursor: "pointer",
        background: view === key ? "#F1F4F7" : "transparent",
        color: view === key ? GRAY_900 : GRAY_600,
        boxShadow: view === key ? SHADOW_XS : "none",
      }}
    >
      {label}
    </Box>
  );

  return (
    <Box sx={{ display: "flex", gap: "4px", background: "#fff", borderRadius: "13px", padding: "4px", boxShadow: SHADOW_SM, width: "max-content" }}>
      {seg("week", "Semaine")}
      {seg("month", "Mois")}
    </Box>
  );
}

// ── Vue semaine : 7 chips jour ───────────────────────────────────────────────
function WeekStrip({
  date, todayYmd, hasMissionOn, onDayClick,
}: {
  date: string;
  todayYmd: string;
  hasMissionOn: (dayKey: string) => boolean;
  onDayClick: (dayKey: string) => void;
}) {
  const start = startOfWeek(parseYmdToLocalDate(date));
  const days = Array.from({ length: 7 }, (_, i) => addDays(start, i));

  return (
    <Box sx={{ display: "flex", gap: "7px" }}>
      {days.map((d, i) => {
        const dayKey = formatDateToYmd(d);
        const isToday = dayKey === todayYmd;
        const hasMission = hasMissionOn(dayKey);
        return (
          <Box
            key={dayKey}
            component="button"
            type="button"
            onClick={() => onDayClick(dayKey)}
            sx={{
              flex: 1, height: 66, borderRadius: "14px", border: "none", cursor: "pointer", fontFamily: "inherit",
              display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: "3px",
              background: isToday ? GREEN_900 : "#fff",
              color: isToday ? "#fff" : "inherit",
              boxShadow: isToday ? "0 5px 14px rgba(20,77,56,.35)" : SHADOW_XS,
              outline: isToday ? "2px solid #fff" : "none",
              outlineOffset: isToday ? "-4px" : 0,
            }}
          >
            <Box sx={{ fontSize: 11, fontWeight: 600, opacity: isToday ? 0.85 : 1, color: isToday ? "#fff" : GRAY_400 }}>
              {DOW_ABBR[i]}
            </Box>
            <Box sx={{ fontSize: 17, fontWeight: 800, fontVariantNumeric: "tabular-nums" }}>{d.getDate()}</Box>
            <Box sx={{ width: 5, height: 5, borderRadius: "999px", background: hasMission ? (isToday ? "#fff" : GREEN_500) : "transparent" }} />
          </Box>
        );
      })}
    </Box>
  );
}

// ── Vue mois : grille + légende ──────────────────────────────────────────────
function MonthGrid({
  date, todayYmd, dayMeta, onDayClick,
}: {
  date: string;
  todayYmd: string;
  dayMeta: Map<string, { hasConflict: boolean; hasToEncode: boolean; hasMission: boolean }>;
  onDayClick: (dayKey: string) => void;
}) {
  const cells = React.useMemo(() => buildMonthGridCells(date), [date]);

  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", padding: "12px", boxShadow: SHADOW_XS }}>
      <Box sx={{ display: "grid", gridTemplateColumns: "repeat(7, minmax(0,1fr))", gap: "2px" }}>
        {MONTH_HEADER_LABELS.map((label, i) => (
          <Box key={i} sx={{ height: 28, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 11, fontWeight: 700, color: GRAY_400 }}>
            {label}
          </Box>
        ))}
        {cells.map((cell) => {
          const meta = dayMeta.get(cell.dateYmd);
          const isToday = cell.dateYmd === todayYmd;
          const bg = isToday ? GREEN_900 : meta?.hasToEncode ? AMBER_50 : meta?.hasMission ? GREEN_50 : "transparent";
          const dotColor = isToday ? GREEN_300 : meta?.hasToEncode ? AMBER_500 : meta?.hasMission ? GREEN_500 : "transparent";
          const textColor = isToday ? "#fff" : !cell.inCurrentMonth ? GRAY_300 : "inherit";
          return (
            <Box
              key={cell.dateYmd}
              component="button"
              type="button"
              onClick={() => onDayClick(cell.dateYmd)}
              disabled={!meta?.hasMission}
              sx={{
                height: 44, borderRadius: "10px", border: "none", cursor: meta?.hasMission ? "pointer" : "default",
                background: bg, color: textColor, position: "relative",
                display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: "3px",
                fontFamily: "inherit",
              }}
            >
              <Box sx={{ fontSize: 13.5, fontWeight: 600, fontVariantNumeric: "tabular-nums", lineHeight: 1 }}>{cell.dayNumber}</Box>
              <Box sx={{ width: 5, height: 5, borderRadius: "999px", background: dotColor }} />
              {meta?.hasConflict && (
                <Box sx={{ position: "absolute", top: 2, right: 4, fontSize: 9, lineHeight: 1 }} title="Conflit potentiel">⚠</Box>
              )}
            </Box>
          );
        })}
      </Box>
      <Box sx={{ display: "flex", gap: "16px", mt: "12px", pt: "12px", borderTop: "1px dashed", borderColor: "grey.200" }}>
        <Stack direction="row" alignItems="center" spacing={0.75}>
          <Box sx={{ width: 6, height: 6, borderRadius: "999px", background: GREEN_500 }} />
          <Typography sx={{ fontSize: 12, color: GRAY_600 }}>Mission</Typography>
        </Stack>
        <Stack direction="row" alignItems="center" spacing={0.75}>
          <Box sx={{ width: 6, height: 6, borderRadius: "999px", background: AMBER_500 }} />
          <Typography sx={{ fontSize: 12, color: GRAY_600 }}>À encoder</Typography>
        </Stack>
      </Box>
    </Box>
  );
}

// ── Ligne mission "À VENIR" ──────────────────────────────────────────────────
function MissionListRow({ mission, onClick }: { mission: Mission; onClick: () => void }) {
  const start = mission.startAt ? new Date(mission.startAt) : null;
  const status = missionRowStatus(mission);
  const timeLine = mission.startAt && mission.endAt
    ? `${formatMissionTime(mission.startAt)} → ${formatMissionTime(mission.endAt)}`
    : "—";
  const surgeon = getSurgeonLabel(mission);

  return (
    <Box
      component="button"
      type="button"
      onClick={onClick}
      sx={{
        display: "flex", alignItems: "center", gap: "14px", width: "100%", textAlign: "left",
        background: "#fff", border: "1px solid #E7EBEF", borderRadius: "16px", padding: "14px 16px",
        boxShadow: SHADOW_XS, cursor: "pointer", fontFamily: "inherit",
      }}
    >
      <DateTile
        day={start ? String(start.getDate()).padStart(2, "0") : "—"}
        month={start ? start.toLocaleDateString("fr-BE", { month: "short" }).replace(".", "").toUpperCase() : ""}
        variant={status.variant === "enCours" || status.variant === "confirmee" ? "confirmee" : status.variant === "aEncoder" ? "aEncoder" : "aVenir"}
        preset="list"
      />
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Typography sx={{ fontSize: 15, fontWeight: 700 }} noWrap>{mission.site?.name ?? "—"}</Typography>
        <Typography sx={{ mt: "3px", fontSize: 13, color: "text.secondary", fontVariantNumeric: "tabular-nums" }} noWrap>
          {timeLine}{surgeon ? ` · ${surgeon}` : ""}
        </Typography>
      </Box>
      <StatusPill variant={status.variant} label={status.label} withDot={status.withDot} />
    </Box>
  );
}

// ── Bandeau info (aucune mission à venir) ───────────────────────────────────
function EmptyUpcomingBanner({ onSeeOffers }: { onSeeOffers: () => void }) {
  return (
    <Stack direction="row" alignItems="center" spacing={1.5} sx={{ background: BLUE_50, borderRadius: "14px", padding: "14px 16px" }}>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke={BLUE_700} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="12" r="9" /><path d="M12 8h.01M12 11v5" />
      </svg>
      <Typography sx={{ fontSize: 14, color: BLUE_700, lineHeight: 1.45 }}>
        Acceptez des offres pour compléter votre planning.{" "}
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
    const meta = new Map<string, { hasConflict: boolean; hasToEncode: boolean; hasMission: boolean; firstMissionId: number }>();
    for (const [dayKey, list] of dayBuckets.entries()) {
      const first = list[0];
      if (!first) continue;
      meta.set(dayKey, {
        hasConflict: list.some((m) => conflictMissionIds.has(m.id)),
        hasToEncode: list.some(isPendingEncoding),
        hasMission: true,
        firstMissionId: first.id,
      });
    }
    return meta;
  }, [dayBuckets, conflictMissionIds]);

  const upcomingMissions = React.useMemo(
    () => missions.slice().sort(compareMissionsByStart),
    [missions],
  );

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
              <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700, whiteSpace: "nowrap" }}>
                À VENIR
              </Box>
              <Box sx={{ flex: 1, borderTop: "1px dashed", borderColor: "grey.200" }} />
            </Stack>

            {upcomingMissions.length === 0 ? (
              <EmptyUpcomingBanner onSeeOffers={() => navigate("/app/i/offers")} />
            ) : (
              upcomingMissions.map((m) => (
                <MissionListRow key={m.id} mission={m} onClick={() => setSelectedMissionId(m.id)} />
              ))
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
