import * as React from "react";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import { useQuery } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogContent,
  DialogTitle,
  Paper,
  Stack,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
} from "@mui/material";

import { getInstrumentistPlanning } from "../api/instrumentists.api";
import { MissionDetailContent } from "../../../pages/manager/MissionDetailPage";

type ViewMode = "week" | "month";

function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

function formatDateToYmd(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function parseYmdToLocalDate(value: string): Date {
  const [year, month, day] = value.split("-").map(Number);
  return new Date(year, month - 1, day, 0, 0, 0, 0);
}

function startOfWeek(date: Date): Date {
  const day = date.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  const copy = new Date(date);
  copy.setDate(copy.getDate() + diff);
  copy.setHours(0, 0, 0, 0);
  return copy;
}

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1, 0, 0, 0, 0);
}

function addDays(date: Date, days: number): Date {
  const copy = new Date(date);
  copy.setDate(copy.getDate() + days);
  return copy;
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

function getRange(
  view: ViewMode,
  dateYmd: string,
): { from: string; to: string } {
  const baseDate = parseYmdToLocalDate(dateYmd);

  if (view === "month") {
    const from = startOfMonth(baseDate);
    const to = new Date(
      from.getFullYear(),
      from.getMonth() + 1,
      1,
      0,
      0,
      0,
      0,
    );
    return { from: from.toISOString(), to: to.toISOString() };
  }

  const from = startOfWeek(baseDate);
  const to = addDays(from, 7);
  return { from: from.toISOString(), to: to.toISOString() };
}

type Props = {
  instrumentistId: number;
};

export function InstrumentistPlanningSection({ instrumentistId }: Props) {
  const todayYmd = React.useMemo(() => formatDateToYmd(new Date()), []);
  const [view, setView] = React.useState<ViewMode>("month");
  const [dateYmd, setDateYmd] = React.useState(todayYmd);
  const [selectedMissionId, setSelectedMissionId] = React.useState<
    number | null
  >(null);

  const range = React.useMemo(() => getRange(view, dateYmd), [view, dateYmd]);

  const planningQuery = useQuery({
    queryKey: [
      "instrumentist-planning",
      instrumentistId,
      range.from,
      range.to,
    ],
    queryFn: () =>
      getInstrumentistPlanning(instrumentistId, {
        from: range.from,
        to: range.to,
      }),
    enabled: instrumentistId > 0,
  });

  const events = React.useMemo(() => {
    return (planningQuery.data ?? []).map((event) => ({
      id: String(event.id),
      title: event.title,
      start: event.start,
      end: event.end,
      allDay: event.allDay,
    }));
  }, [planningQuery.data]);

  const calendarView = view === "month" ? "dayGridMonth" : "timeGridWeek";

  return (
    <>
      <Stack spacing={1.5}>
        <Stack
          direction="row"
          spacing={1}
          alignItems="center"
          justifyContent="space-between"
        >
          <ToggleButtonGroup
            value={view}
            exclusive
            onChange={(_, value: ViewMode | null) => {
              if (!value) return;
              setView(value);
            }}
            size="small"
          >
            <ToggleButton value="week">Semaine</ToggleButton>
            <ToggleButton value="month">Mois</ToggleButton>
          </ToggleButtonGroup>

          <Button
            size="small"
            variant="text"
            onClick={() => setDateYmd(todayYmd)}
          >
            Aujourd'hui
          </Button>
        </Stack>

        <Stack
          direction="row"
          spacing={1}
          alignItems="center"
          justifyContent="space-between"
        >
          <Button
            variant="outlined"
            size="small"
            onClick={() => setDateYmd(shiftDate(dateYmd, view, -1))}
          >
            Précédent
          </Button>

          <Typography
            variant="caption"
            sx={{ flex: 1, textAlign: "center", fontWeight: 500 }}
          >
            {formatDisplayDate(dateYmd, view)}
          </Typography>

          <Button
            variant="outlined"
            size="small"
            onClick={() => setDateYmd(shiftDate(dateYmd, view, 1))}
          >
            Suivant
          </Button>
        </Stack>

        {planningQuery.isError ? (
          <Alert severity="error">Impossible de charger le planning.</Alert>
        ) : null}

        <Paper variant="outlined" sx={{ p: 1, position: "relative" }}>
          {planningQuery.isLoading ? (
            <Box
              sx={{
                minHeight: 400,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
              }}
            >
              <CircularProgress size={28} />
            </Box>
          ) : (
            <FullCalendar
              key={`${calendarView}-${dateYmd}`}
              plugins={[dayGridPlugin, timeGridPlugin]}
              initialView={calendarView}
              initialDate={dateYmd}
              headerToolbar={false}
              events={events}
              height={view === "week" ? 500 : "auto"}
              locale="fr"
              eventClick={(info) => {
                const missionId = Number(info.event.id);
                if (!Number.isFinite(missionId) || missionId <= 0) return;
                setSelectedMissionId(missionId);
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
              scrollTime="08:00:00"
              allDaySlot={view !== "week"}
              slotEventOverlap={view === "week" ? false : true}
            />
          )}
        </Paper>
      </Stack>

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
    </>
  );
}
