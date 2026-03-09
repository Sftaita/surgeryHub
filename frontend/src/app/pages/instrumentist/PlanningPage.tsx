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
} from "@mui/material";

import { fetchMissions } from "../../features/missions/api/missions.api";
import { MissionDetailContent } from "./MissionDetailPage";

type PlanningMode = "my" | "offers";
type ViewMode = "week" | "month" | "list";

const MY_MISSIONS_STATUSES =
  "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED,CLOSED";
const OFFERS_STATUS = "OPEN";
const CONFLICT_STATUSES = new Set(["ASSIGNED", "DECLARED", "IN_PROGRESS"]);

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
      new Date(date.getFullYear(), date.getMonth() + direction, date.getDate()),
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

export default function PlanningPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [selectedMissionId, setSelectedMissionId] = React.useState<
    number | null
  >(null);

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

  const range = React.useMemo(() => getRange(view, date), [view, date]);

  const filters = React.useMemo(() => {
    if (mode === "offers") {
      return {
        from: range.from,
        to: range.to,
        eligibleToMe: true,
        status: OFFERS_STATUS,
      };
    }

    return {
      from: range.from,
      to: range.to,
      assignedToMe: true,
      status: MY_MISSIONS_STATUSES,
    };
  }, [mode, range.from, range.to]);

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
      },
    ],
    queryFn: () => fetchMissions(1, 100, filters),
  });

  const calendarView = view === "month" ? "dayGridMonth" : "timeGridWeek";

  const conflictMissionIds = React.useMemo(() => {
    const missions = missionsQuery.data?.items ?? [];

    const relevantMissions = missions
      .filter((mission) =>
        mission.status ? CONFLICT_STATUSES.has(mission.status) : false,
      )
      .map((mission) => ({
        mission,
        interval: normalizeMissionInterval(mission),
      }))
      .filter((item): item is any => item.interval !== null);

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
  }, [missionsQuery.data?.items]);

  const events = React.useMemo(() => {
    return (missionsQuery.data?.items ?? []).map((mission) => {
      const hasConflict = conflictMissionIds.has(mission.id);
      const isDeclared = mission.status === "DECLARED";
      const interval = normalizeMissionInterval(mission);

      let title = `Mission #${mission.id}`;

      if (hasConflict) {
        title = `⚠ ${title}`;
      }

      if (isDeclared) {
        title = `${title} • DECLARED`;
      }

      return {
        id: String(mission.id),
        title,
        start: interval?.start ?? mission.startAt,
        end: interval?.end ?? mission.endAt ?? mission.startAt,
      };
    });
  }, [conflictMissionIds, missionsQuery.data?.items]);

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

      <Paper variant="outlined" sx={{ p: 2 }}>
        <Stack spacing={1.5}>
          <Stack direction="row" spacing={1} justifyContent="space-between">
            <Button
              variant="outlined"
              size="small"
              onClick={() =>
                updateSearchParams({
                  date: shiftDate(date, view, -1),
                })
              }
            >
              Précédent
            </Button>

            <Button
              variant="text"
              size="small"
              onClick={() => updateSearchParams({ date: todayYmd })}
            >
              Aujourd’hui
            </Button>

            <Button
              variant="outlined"
              size="small"
              onClick={() =>
                updateSearchParams({
                  date: shiftDate(date, view, 1),
                })
              }
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

      <Paper variant="outlined" sx={{ p: 1 }}>
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
            />
          )}
        </Box>
      </Paper>

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
