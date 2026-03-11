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
  Paper,
  Stack,
  Typography,
  ToggleButton,
  ToggleButtonGroup,
  Dialog,
  DialogTitle,
  DialogContent,
  useMediaQuery,
  useTheme,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
} from "@mui/material";

import { fetchMissions } from "../../features/missions/api/missions.api";
import { fetchSites } from "../../features/sites/api/sites.api";
import { MissionDetailContent } from "./MissionDetailPage";
import type { Mission } from "../../features/missions/api/missions.types";

type PlanningMode = "my" | "offers";
type ViewMode = "week" | "month" | "list";

const MY_MISSIONS_STATUSES =
  "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED,CLOSED";
const OFFERS_STATUS = "OPEN";
const CONFLICT_STATUSES = new Set(["ASSIGNED", "DECLARED", "IN_PROGRESS"]);
const SWIPE_THRESHOLD_PX = 50;
const SITE_FILTER_STORAGE_KEY = "instrumentist-planning-site-filter";

function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

function formatDateToYmd(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(
    date.getDate(),
  )}`;
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
  return new Date(
    date.getFullYear(),
    date.getMonth(),
    date.getDate(),
    0,
    0,
    0,
    0,
  );
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

function toIsoString(date: Date): string {
  return date.toISOString();
}

function getRange(
  view: ViewMode,
  dateYmd: string,
): { from: string; to: string } {
  const baseDate = parseYmdToLocalDate(dateYmd);

  if (view === "month") {
    const from = startOfMonth(baseDate);
    const to = new Date(from.getFullYear(), from.getMonth() + 1, 1, 0, 0, 0, 0);

    return {
      from: toIsoString(from),
      to: toIsoString(to),
    };
  }

  const from = startOfWeek(baseDate);
  const to = addDays(from, 7);

  return {
    from: toIsoString(from),
    to: toIsoString(to),
  };
}

function getSafeMode(value: string | null): PlanningMode {
  return value === "offers" ? "offers" : "my";
}

function getSafeView(value: string | null): ViewMode {
  if (value === "week" || value === "month" || value === "list") return value;
  return "month";
}

function shiftDate(dateYmd: string, view: ViewMode, direction: -1 | 1): string {
  const date = parseYmdToLocalDate(dateYmd);

  if (view === "month") {
    return formatDateToYmd(
      new Date(date.getFullYear(), date.getMonth() + direction, 1),
    );
  }

  return formatDateToYmd(addDays(date, direction * 7));
}

function formatDisplayDate(dateYmd: string, view: ViewMode): string {
  const date = parseYmdToLocalDate(dateYmd);

  if (view === "month") {
    return date.toLocaleDateString("fr-BE", {
      month: "long",
      year: "numeric",
    });
  }

  if (view === "week") {
    const weekStart = startOfWeek(date);
    const weekEnd = addDays(weekStart, 6);

    const startLabel = weekStart.toLocaleDateString("fr-BE", {
      day: "2-digit",
      month: "long",
      year:
        weekStart.getFullYear() !== weekEnd.getFullYear()
          ? "numeric"
          : undefined,
    });

    const endLabel = weekEnd.toLocaleDateString("fr-BE", {
      day: "2-digit",
      month: "long",
      year: "numeric",
    });

    return `${startLabel} — ${endLabel}`;
  }

  return date.toLocaleDateString("fr-BE", {
    day: "2-digit",
    month: "long",
    year: "numeric",
  });
}

function normalizeMissionInterval(mission: {
  startAt: string;
  endAt?: string | null;
}): { start: string; end: string; startMs: number; endMs: number } | null {
  const startDate = new Date(mission.startAt);
  const startMs = startDate.getTime();

  if (!Number.isFinite(startMs)) {
    return null;
  }

  const rawEndDate = mission.endAt ? new Date(mission.endAt) : null;
  const rawEndMs = rawEndDate ? rawEndDate.getTime() : Number.NaN;

  const endDate =
    rawEndDate && Number.isFinite(rawEndMs) && rawEndMs > startMs
      ? rawEndDate
      : addMinutes(startDate, 1);

  const endMs = endDate.getTime();

  return {
    start: startDate.toISOString(),
    end: endDate.toISOString(),
    startMs,
    endMs,
  };
}

function formatMissionTime(startAt: string): string {
  const date = new Date(startAt);
  if (!Number.isFinite(date.getTime())) return "";
  return date.toLocaleTimeString("fr-BE", {
    hour: "2-digit",
    minute: "2-digit",
  });
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
  if (time) return time;

  return `Mission #${mission.id}`;
}

function buildWeekMissionLabel(mission: Mission): string {
  const surgeon = getSurgeonLabel(mission);
  let title = surgeon || `Mission #${mission.id}`;

  if (mission.status === "DECLARED") {
    title = `${title} • DECLARED`;
  }

  return title;
}

function getMissionStartDayKey(mission: Mission): string | null {
  const date = new Date(mission.startAt);
  if (!Number.isFinite(date.getTime())) return null;
  return formatDateToYmd(date);
}

function parseHours(value: unknown): number {
  if (typeof value === "number") {
    return Number.isFinite(value) ? value : 0;
  }

  if (typeof value === "string") {
    const normalized = Number(value);
    return Number.isFinite(normalized) ? normalized : 0;
  }

  return 0;
}

function compareMissionsByStart(a: Mission, b: Mission): number {
  const aTime = new Date(a.startAt).getTime();
  const bTime = new Date(b.startAt).getTime();

  const safeATime = Number.isFinite(aTime) ? aTime : Number.MAX_SAFE_INTEGER;
  const safeBTime = Number.isFinite(bTime) ? bTime : Number.MAX_SAFE_INTEGER;

  if (safeATime !== safeBTime) {
    return safeATime - safeBTime;
  }

  return a.id - b.id;
}

export default function PlanningPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [selectedMissionId, setSelectedMissionId] = React.useState<
    number | null
  >(null);
  const touchStartXRef = React.useRef<number | null>(null);

  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down("sm"));

  const [siteFilter, setSiteFilter] = React.useState<number | "">(() => {
    if (typeof window === "undefined") return "";
    const saved = window.localStorage.getItem(SITE_FILTER_STORAGE_KEY);
    if (!saved) return "";
    const parsed = Number(saved);
    return Number.isFinite(parsed) ? parsed : "";
  });

  const todayYmd = React.useMemo(() => formatDateToYmd(new Date()), []);
  const mode = getSafeMode(searchParams.get("mode"));
  const view = getSafeView(searchParams.get("view"));
  const date = isValidYmd(searchParams.get("date"))
    ? (searchParams.get("date") as string)
    : todayYmd;

  React.useEffect(() => {
    const next = new URLSearchParams(searchParams);
    let changed = false;

    if (next.get("mode") !== mode) {
      next.set("mode", mode);
      changed = true;
    }

    if (next.get("view") !== view) {
      next.set("view", view);
      changed = true;
    }

    if (next.get("date") !== date) {
      next.set("date", date);
      changed = true;
    }

    if (changed) {
      setSearchParams(next, { replace: true });
    }
  }, [date, mode, searchParams, setSearchParams, view]);

  React.useEffect(() => {
    if (typeof window === "undefined") return;

    if (siteFilter === "") {
      window.localStorage.removeItem(SITE_FILTER_STORAGE_KEY);
    } else {
      window.localStorage.setItem(SITE_FILTER_STORAGE_KEY, String(siteFilter));
    }
  }, [siteFilter]);

  const sitesQuery = useQuery({
    queryKey: ["sites"],
    queryFn: fetchSites,
    enabled: !isMobile,
  });

  const range = React.useMemo(() => getRange(view, date), [view, date]);

  const filters = React.useMemo(() => {
    const baseFilters = {
      from: range.from,
      to: range.to,
      ...(siteFilter !== "" ? { siteId: siteFilter } : {}),
    };

    if (mode === "offers") {
      return {
        ...baseFilters,
        eligibleToMe: true,
        status: OFFERS_STATUS,
      };
    }

    return {
      ...baseFilters,
      assignedToMe: true,
      status: MY_MISSIONS_STATUSES,
    };
  }, [mode, range.from, range.to, siteFilter]);

  const missionsQuery = useQuery({
    queryKey: [
      "missions",
      "planning",
      {
        mode,
        view,
        date,
        from: range.from,
        to: range.to,
        siteId: siteFilter === "" ? null : siteFilter,
      },
    ],
    queryFn: () => fetchMissions(1, 100, filters),
  });

  const missions = missionsQuery.data?.items ?? [];
  const calendarView = view === "month" ? "dayGridMonth" : "timeGridWeek";

  const conflictMissionIds = React.useMemo(() => {
    const relevantMissions = missions
      .filter((mission) =>
        mission.status ? CONFLICT_STATUSES.has(mission.status) : false,
      )
      .map((mission) => ({
        mission,
        interval: normalizeMissionInterval(mission),
      }))
      .filter(
        (
          item,
        ): item is {
          mission: Mission;
          interval: {
            start: string;
            end: string;
            startMs: number;
            endMs: number;
          };
        } => item.interval !== null,
      );

    const conflictingIds = new Set<number>();

    for (let i = 0; i < relevantMissions.length; i++) {
      const currentMission = relevantMissions[i];

      for (let j = i + 1; j < relevantMissions.length; j++) {
        const otherMission = relevantMissions[j];

        const hasOverlap =
          currentMission.interval.startMs < otherMission.interval.endMs &&
          otherMission.interval.startMs < currentMission.interval.endMs;

        if (hasOverlap) {
          conflictingIds.add(currentMission.mission.id);
          conflictingIds.add(otherMission.mission.id);
        }
      }
    }

    return conflictingIds;
  }, [missions]);

  const monthDayBuckets = React.useMemo(() => {
    const buckets = new Map<
      string,
      {
        missions: Mission[];
        hasConflict: boolean;
      }
    >();

    for (const mission of missions) {
      const dayKey = getMissionStartDayKey(mission);
      if (!dayKey) continue;

      const existing = buckets.get(dayKey);

      if (existing) {
        existing.missions.push(mission);
        if (conflictMissionIds.has(mission.id)) {
          existing.hasConflict = true;
        }
      } else {
        buckets.set(dayKey, {
          missions: [mission],
          hasConflict: conflictMissionIds.has(mission.id),
        });
      }
    }

    for (const bucket of buckets.values()) {
      bucket.missions.sort(compareMissionsByStart);
    }

    return buckets;
  }, [missions, conflictMissionIds]);

  const monthlySummary = React.useMemo(() => {
    const baseDate = parseYmdToLocalDate(date);
    const month = baseDate.getMonth();
    const year = baseDate.getFullYear();

    let totalMissions = 0;
    let totalHours = 0;

    for (const mission of missions) {
      const startDate = new Date(mission.startAt);
      if (!Number.isFinite(startDate.getTime())) continue;

      if (startDate.getFullYear() === year && startDate.getMonth() === month) {
        totalMissions += 1;
        totalHours += parseHours(mission.service?.hours);
      }
    }

    return {
      totalMissions,
      totalHours,
    };
  }, [missions, date]);

  const monthDayMeta = React.useMemo(() => {
    const meta = new Map<
      string,
      {
        missionId: number;
        title: string;
        extraCount: number;
        hasConflict: boolean;
      }
    >();

    for (const [dayKey, bucket] of monthDayBuckets.entries()) {
      const firstMission = bucket.missions[0];
      if (!firstMission) continue;

      meta.set(dayKey, {
        missionId: firstMission.id,
        title: buildMonthMissionLabel(firstMission),
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
    (patch: Partial<{ mode: PlanningMode; view: ViewMode; date: string }>) => {
      const next = new URLSearchParams(searchParams);

      const nextMode = patch.mode ?? mode;
      const nextView = patch.view ?? view;
      const nextDate = patch.date ?? date;

      next.set("mode", nextMode);
      next.set("view", nextView);
      next.set("date", nextDate);

      setSearchParams(next);
    },
    [searchParams, mode, view, date, setSearchParams],
  );

  const handlePeriodShift = React.useCallback(
    (direction: -1 | 1) => {
      updateSearchParams({
        date: shiftDate(date, view, direction),
      });
    },
    [date, updateSearchParams, view],
  );

  const handleTouchStart = React.useCallback(
    (event: React.TouchEvent<HTMLDivElement>) => {
      if (!isMobile) return;
      touchStartXRef.current = event.changedTouches[0]?.clientX ?? null;
    },
    [isMobile],
  );

  const handleTouchEnd = React.useCallback(
    (event: React.TouchEvent<HTMLDivElement>) => {
      if (!isMobile) return;

      const startX = touchStartXRef.current;
      const endX = event.changedTouches[0]?.clientX ?? null;
      touchStartXRef.current = null;

      if (startX === null || endX === null) return;

      const deltaX = endX - startX;

      if (Math.abs(deltaX) < SWIPE_THRESHOLD_PX) return;

      if (deltaX < 0) {
        handlePeriodShift(1);
      } else {
        handlePeriodShift(-1);
      }
    },
    [handlePeriodShift, isMobile],
  );

  return (
    <Stack spacing={2}>
      <Typography variant="h6">Planning</Typography>

      <ToggleButtonGroup
        value={mode}
        exclusive
        onChange={(_, value: PlanningMode | null) => {
          if (!value) return;
          updateSearchParams({ mode: value });
        }}
        fullWidth
        size="small"
      >
        <ToggleButton value="my">Mes missions</ToggleButton>
        <ToggleButton value="offers">Offres</ToggleButton>
      </ToggleButtonGroup>

      <ToggleButtonGroup
        value={view}
        exclusive
        onChange={(_, value: ViewMode | null) => {
          if (!value) return;
          updateSearchParams({ view: value });
        }}
        fullWidth
        size="small"
      >
        <ToggleButton value="week">Semaine</ToggleButton>
        <ToggleButton value="month">Mois</ToggleButton>
        <ToggleButton value="list">Liste</ToggleButton>
      </ToggleButtonGroup>

      {!isMobile ? (
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack
            direction="row"
            spacing={2}
            useFlexGap
            flexWrap="wrap"
            alignItems="center"
          >
            <FormControl size="small" sx={{ minWidth: 220 }}>
              <InputLabel id="planning-site-filter-label">Site</InputLabel>
              <Select
                labelId="planning-site-filter-label"
                value={siteFilter}
                label="Site"
                onChange={(event) => {
                  const value = event.target.value as string | number;
                  setSiteFilter(value === "" ? "" : Number(value));
                }}
              >
                <MenuItem value="">Tous les sites</MenuItem>
                {(sitesQuery.data ?? []).map((site) => (
                  <MenuItem key={site.id} value={site.id}>
                    {site.name}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>
          </Stack>
        </Paper>
      ) : null}

      <Box onTouchStart={handleTouchStart} onTouchEnd={handleTouchEnd}>
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack spacing={1.5}>
            <Stack direction="row" spacing={1} justifyContent="space-between">
              <Button
                variant="outlined"
                size="small"
                onClick={() => handlePeriodShift(-1)}
              >
                Précédent
              </Button>

              {!isMobile ? (
                <Button
                  variant="text"
                  size="small"
                  onClick={() => updateSearchParams({ date: todayYmd })}
                >
                  Aujourd’hui
                </Button>
              ) : (
                <Box />
              )}

              <Button
                variant="outlined"
                size="small"
                onClick={() => handlePeriodShift(1)}
              >
                Suivant
              </Button>
            </Stack>

            <Typography variant="subtitle2">
              {formatDisplayDate(date, view)}
            </Typography>
          </Stack>
        </Paper>

        {missionsQuery.isError ? (
          <Alert severity="error">Impossible de charger le planning.</Alert>
        ) : null}

        <Paper variant="outlined" sx={{ p: 1, mt: 2 }}>
          <Box sx={{ minHeight: 600, position: "relative" }}>
            {missionsQuery.isLoading ? (
              <Box
                sx={{
                  minHeight: 600,
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                }}
              >
                <CircularProgress size={28} />
              </Box>
            ) : (
              <FullCalendar
                key={`${calendarView}-${date}`}
                plugins={[dayGridPlugin, timeGridPlugin]}
                initialView={calendarView}
                initialDate={date}
                headerToolbar={false}
                events={view === "list" ? [] : events}
                height="auto"
                locale="fr"
                eventClick={(info) => {
                  const missionId = Number(info.event.id);
                  if (!Number.isFinite(missionId) || missionId <= 0) return;
                  setSelectedMissionId(missionId);
                }}
                eventContent={(arg) => {
                  if (view !== "month") {
                    return undefined;
                  }

                  const dayKey = formatDateToYmd(arg.event.start ?? new Date());
                  const meta = monthDayMeta.get(dayKey);
                  const extraCount = meta?.extraCount ?? 0;

                  return (
                    <Box
                      sx={{
                        display: "flex",
                        alignItems: "center",
                        gap: 0.5,
                        minWidth: 0,
                        overflow: "hidden",
                      }}
                    >
                      <Box
                        component="span"
                        sx={{
                          minWidth: 0,
                          overflow: "hidden",
                          textOverflow: "ellipsis",
                          whiteSpace: "nowrap",
                        }}
                      >
                        {arg.event.title}
                      </Box>

                      {extraCount > 0 ? (
                        <Box
                          component="span"
                          sx={{
                            flexShrink: 0,
                            color: "text.secondary",
                          }}
                        >
                          +{extraCount}
                        </Box>
                      ) : null}
                    </Box>
                  );
                }}
                dayCellContent={(arg) => {
                  if (view !== "month") {
                    return arg.dayNumberText;
                  }

                  const dayKey = formatDateToYmd(arg.date);
                  const meta = monthDayMeta.get(dayKey);

                  return (
                    <Box
                      sx={{
                        display: "flex",
                        alignItems: "center",
                        justifyContent: "space-between",
                        width: "100%",
                      }}
                    >
                      <Box component="span">{arg.dayNumberText}</Box>

                      {meta?.hasConflict ? (
                        <Box
                          component="span"
                          sx={{
                            fontSize: "0.7rem",
                            lineHeight: 1,
                            color: "text.secondary",
                          }}
                          aria-label="Conflit potentiel"
                          title="Conflit potentiel"
                        >
                          ⚠
                        </Box>
                      ) : null}
                    </Box>
                  );
                }}
                slotMinTime="00:00:00"
                slotMaxTime="24:00:00"
                scrollTime="08:00:00"
                allDaySlot={view !== "week" ? true : false}
                slotEventOverlap={view === "week" ? false : true}
                expandRows={view === "week"}
              />
            )}
          </Box>
        </Paper>
      </Box>

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
    </Stack>
  );
}
