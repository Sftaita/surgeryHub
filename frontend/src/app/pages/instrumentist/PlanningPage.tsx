import * as React from "react";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import { useQuery } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogTitle,
  DialogContent,
  Stack,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
  useMediaQuery,
  useTheme,
} from "@mui/material";
import ChevronLeftIcon from "@mui/icons-material/ChevronLeft";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";

import { fetchMissions } from "../../features/missions/api/missions.api";
import { MissionDetailContent } from "./MissionDetailPage";
import type { Mission } from "../../features/missions/api/missions.types";

type ViewMode = "week" | "month";

const APPBAR_HEIGHT = 56;
const NAV_HEIGHT = 56;
const CONTROLS_HEIGHT = 52;

const MY_MISSIONS_STATUSES =
  "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED,CLOSED";

const CONFLICT_STATUSES = new Set(["ASSIGNED", "DECLARED", "IN_PROGRESS"]);
const SWIPE_THRESHOLD_PX = 50;
const WEEK_SCROLL_TIME = "08:00:00";

function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

function formatDateToYmd(date: Date): string {
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

function getSiteLabel(mission: Mission): string {
  return mission.site?.name?.trim() ?? "";
}

function buildMonthMissionLabel(mission: Mission): string {
  const time = formatMissionTime(mission.startAt);
  const surgeon = getSurgeonLabel(mission);
  const site = getSiteLabel(mission);
  if (time && surgeon) return `${time} ${surgeon}`;
  if (time && site) return `${time} ${site}`;
  if (surgeon) return surgeon;
  if (site) return site;
  return `#${mission.id}`;
}

function buildWeekMissionLabel(mission: Mission): string {
  const surgeon = getSurgeonLabel(mission);
  const site = getSiteLabel(mission);
  if (surgeon && site) return `${surgeon} • ${site}`;
  if (surgeon) return surgeon;
  if (site) return site;
  return `#${mission.id}`;
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

export default function PlanningPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [selectedMissionId, setSelectedMissionId] = React.useState<number | null>(null);
  const touchStartXRef = React.useRef<number | null>(null);
  const touchStartYRef = React.useRef<number | null>(null);

  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down("sm"));

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
  const calendarView = view === "month" ? "dayGridMonth" : "timeGridWeek";

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

  const monthDayBuckets = React.useMemo(() => {
    const buckets = new Map<string, { missions: Mission[]; hasConflict: boolean }>();
    for (const mission of missions) {
      const dayKey = getMissionStartDayKey(mission);
      if (!dayKey) continue;
      const existing = buckets.get(dayKey);
      if (existing) {
        existing.missions.push(mission);
        if (conflictMissionIds.has(mission.id)) existing.hasConflict = true;
      } else {
        buckets.set(dayKey, { missions: [mission], hasConflict: conflictMissionIds.has(mission.id) });
      }
    }
    for (const bucket of buckets.values()) {
      bucket.missions.sort(compareMissionsByStart);
    }
    return buckets;
  }, [missions, conflictMissionIds]);

  const monthDayMeta = React.useMemo(() => {
    const meta = new Map<string, { missionId: number; title: string; extraCount: number; hasConflict: boolean }>();
    for (const [dayKey, bucket] of monthDayBuckets.entries()) {
      const first = bucket.missions[0];
      if (!first) continue;
      meta.set(dayKey, {
        missionId: first.id,
        title: buildMonthMissionLabel(first),
        extraCount: Math.max(bucket.missions.length - 1, 0),
        hasConflict: bucket.hasConflict,
      });
    }
    return meta;
  }, [monthDayBuckets]);

  const events = React.useMemo(() => {
    if (view === "month") {
      return Array.from(monthDayMeta.entries()).map(([dayKey, meta]) => ({
        id: String(meta.missionId),
        title: meta.title,
        start: dayKey,
        allDay: true,
      }));
    }
    return missions.map((mission) => {
      const interval = normalizeMissionInterval(mission);
      return {
        id: String(mission.id),
        title: buildWeekMissionLabel(mission),
        start: interval?.start ?? mission.startAt,
        end: interval?.end ?? mission.endAt ?? mission.startAt,
      };
    });
  }, [view, monthDayMeta, missions]);

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

  const handleTouchStart = React.useCallback(
    (event: React.TouchEvent<HTMLDivElement>) => {
      if (!isMobile) return;
      touchStartXRef.current = event.changedTouches[0]?.clientX ?? null;
      touchStartYRef.current = event.changedTouches[0]?.clientY ?? null;
    },
    [isMobile],
  );

  const handleTouchEnd = React.useCallback(
    (event: React.TouchEvent<HTMLDivElement>) => {
      if (!isMobile) return;
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
    [handlePeriodShift, isMobile],
  );

  const calendarHeight = `calc(100dvh - ${APPBAR_HEIGHT + NAV_HEIGHT + CONTROLS_HEIGHT}px)`;

  return (
    <Box
      onTouchStart={handleTouchStart}
      onTouchEnd={handleTouchEnd}
    >
      {/* Compact controls bar */}
      <Stack
        direction="row"
        alignItems="center"
        spacing={1}
        sx={{
          px: 1.5,
          py: 0.75,
          borderBottom: "1px solid",
          borderColor: "divider",
          bgcolor: "background.paper",
          flexShrink: 0,
          height: CONTROLS_HEIGHT,
        }}
      >
        {/* View toggle */}
        <ToggleButtonGroup
          value={view}
          exclusive
          onChange={(_, value: ViewMode | null) => {
            if (!value) return;
            updateSearchParams({ view: value });
          }}
          size="small"
          sx={{ flexShrink: 0 }}
        >
          <ToggleButton value="month" sx={{ px: 1.5, fontSize: "0.75rem" }}>
            Mois
          </ToggleButton>
          <ToggleButton value="week" sx={{ px: 1.5, fontSize: "0.75rem" }}>
            Semaine
          </ToggleButton>
        </ToggleButtonGroup>

        {/* Date navigation */}
        <Stack direction="row" alignItems="center" sx={{ flex: 1, minWidth: 0 }}>
          <Button
            size="small"
            onClick={() => handlePeriodShift(-1)}
            sx={{ minWidth: 0, p: 0.5 }}
          >
            <ChevronLeftIcon fontSize="small" />
          </Button>
          <Typography
            variant="caption"
            fontWeight={600}
            sx={{ flex: 1, textAlign: "center", minWidth: 0, px: 0.5 }}
            noWrap
          >
            {formatDisplayDate(date, view)}
          </Typography>
          <Button
            size="small"
            onClick={() => handlePeriodShift(1)}
            sx={{ minWidth: 0, p: 0.5 }}
          >
            <ChevronRightIcon fontSize="small" />
          </Button>
        </Stack>

        {/* Today */}
        <Button
          size="small"
          variant="text"
          onClick={() => updateSearchParams({ date: todayYmd })}
          sx={{ flexShrink: 0, fontSize: "0.7rem", px: 1 }}
        >
          Auj.
        </Button>
      </Stack>

      {missionsQuery.isError && (
        <Alert severity="error" sx={{ mx: 1.5, mt: 1 }}>
          Impossible de charger le planning.
        </Alert>
      )}

      {/* Calendar */}
      {missionsQuery.isLoading ? (
        <Box sx={{ display: "flex", alignItems: "center", justifyContent: "center", height: calendarHeight }}>
          <CircularProgress size={28} />
        </Box>
      ) : (
        <Box sx={{ overflow: "hidden" }}>
          <FullCalendar
            key={`${calendarView}-${date}`}
            plugins={[dayGridPlugin, timeGridPlugin]}
            initialView={calendarView}
            initialDate={date}
            headerToolbar={false}
            events={events}
            height={calendarHeight}
            locale="fr"
            eventClick={(info) => {
              const missionId = Number(info.event.id);
              if (!Number.isFinite(missionId) || missionId <= 0) return;
              setSelectedMissionId(missionId);
            }}
            eventContent={(arg) => {
              if (view !== "month") return undefined;
              const dayKey = formatDateToYmd(arg.event.start ?? new Date());
              const meta = monthDayMeta.get(dayKey);
              const extraCount = meta?.extraCount ?? 0;
              return (
                <Box sx={{ display: "flex", alignItems: "center", gap: 0.5, minWidth: 0, overflow: "hidden" }}>
                  <Box component="span" sx={{ minWidth: 0, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
                    {arg.event.title}
                  </Box>
                  {extraCount > 0 && (
                    <Box component="span" sx={{ flexShrink: 0, color: "text.secondary" }}>
                      +{extraCount}
                    </Box>
                  )}
                </Box>
              );
            }}
            dayCellContent={(arg) => {
              if (view !== "month") return arg.dayNumberText;
              const dayKey = formatDateToYmd(arg.date);
              const meta = monthDayMeta.get(dayKey);
              return (
                <Box sx={{ display: "flex", alignItems: "center", justifyContent: "space-between", width: "100%" }}>
                  <Box component="span">{arg.dayNumberText}</Box>
                  {meta?.hasConflict && (
                    <Box component="span" sx={{ fontSize: "0.7rem", lineHeight: 1, color: "text.secondary" }} title="Conflit potentiel">
                      ⚠
                    </Box>
                  )}
                </Box>
              );
            }}
            dayHeaderFormat={
              view === "week"
                ? { weekday: "short", day: "2-digit", month: "2-digit" }
                : undefined
            }
            eventTimeFormat={
              view === "week"
                ? { hour: "2-digit", minute: "2-digit", hour12: false }
                : undefined
            }
            slotLabelFormat={
              view === "week"
                ? { hour: "2-digit", minute: "2-digit", hour12: false }
                : undefined
            }
            slotMinTime="00:00:00"
            slotMaxTime="24:00:00"
            scrollTime={WEEK_SCROLL_TIME}
            allDaySlot={view !== "week"}
            slotEventOverlap={view === "week" ? false : true}
            expandRows={false}
          />
        </Box>
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
