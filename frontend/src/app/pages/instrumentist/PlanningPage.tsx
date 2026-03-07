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
} from "@mui/material";

import { fetchMissions } from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";

type PlanningMode = "my" | "offers";
type ViewMode = "week" | "month" | "list";

const MY_MISSIONS_STATUSES =
  "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED,CLOSED";
const OFFERS_STATUS = "OPEN";

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
  if (value === "month" || value === "list") return value;
  return "week";
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

function buildEventTitle(mission: Mission) {
  const parts: string[] = [];

  if (mission.site?.name) {
    parts.push(mission.site.name);
  }

  if (mission.surgeon?.lastname) {
    parts.push(`Dr ${mission.surgeon.lastname}`);
  }

  return parts.join(" • ") || `Mission ${mission.id}`;
}

function formatStartTime(startAt: string) {
  try {
    return new Date(startAt).toLocaleTimeString("fr-BE", {
      hour: "2-digit",
      minute: "2-digit",
      timeZone: "Europe/Brussels",
    });
  } catch {
    return "";
  }
}

export default function PlanningPage() {
  const [searchParams, setSearchParams] = useSearchParams();

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

  const calendarView =
    view === "month"
      ? "dayGridMonth"
      : view === "week"
        ? "timeGridWeek"
        : "timeGridWeek";

  const events = React.useMemo(() => {
    return (missionsQuery.data?.items ?? []).map((mission) => ({
      id: String(mission.id),
      start: mission.startAt,
      end: mission.endAt,
      title: buildEventTitle(mission),
      extendedProps: {
        startTime: formatStartTime(mission.startAt),
      },
    }));
  }, [missionsQuery.data?.items]);

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

      {view !== "list" && (
        <Paper variant="outlined" sx={{ p: 1 }}>
          <Box sx={{ minHeight: 600 }}>
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
                key={calendarView}
                plugins={[dayGridPlugin, timeGridPlugin]}
                initialView={calendarView}
                headerToolbar={false}
                events={events}
                height="auto"
                locale="fr"
              />
            )}
          </Box>
        </Paper>
      )}

      {view === "list" && (
        <Paper variant="outlined" sx={{ p: 2 }}>
          <Stack spacing={1}>
            {(missionsQuery.data?.items ?? []).map((mission) => (
              <Box key={mission.id}>
                <Typography variant="body2">
                  {formatStartTime(mission.startAt)} —{" "}
                  {buildEventTitle(mission)}
                </Typography>
              </Box>
            ))}
          </Stack>
        </Paper>
      )}
    </Stack>
  );
}
