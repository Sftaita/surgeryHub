import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress,
  Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle,
  MenuItem, Paper, Select, Stack, ToggleButton, ToggleButtonGroup,
  Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField,
  Typography,
} from "@mui/material";
import LockOpenIcon   from "@mui/icons-material/LockOpen";
import BlockIcon      from "@mui/icons-material/Block";
import SwapHorizIcon  from "@mui/icons-material/SwapHoriz";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { fetchMissions } from "../../../features/missions/api/missions.api";
import type { Mission, MissionStatus } from "../../../features/missions/api/missions.types";
import { fetchSites } from "../../../features/sites/api/sites.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";
import {
  releaseMission, cancelMission, reassignMission,
} from "../../../features/planning-v2/api/planningV2.api";
import { CoverageBanner }      from "../../../features/planning-v2/components/CoverageBanner";
import { MissionHistoryDrawer } from "../../../features/planning-v2/components/MissionHistoryDrawer";
import { CancelMissionDialog }  from "../../../features/planning-v2/components/CancelMissionDialog";
import { ReassignMissionDialog } from "../../../features/planning-v2/components/ReassignMissionDialog";

// ── Helpers ───────────────────────────────────────────────────────────────────

function getISOWeek(dateStr: string): number {
  const d = new Date(Date.UTC(
    +dateStr.slice(0, 4), +dateStr.slice(5, 7) - 1, +dateStr.slice(8, 10),
  ));
  const day = d.getUTCDay() || 7;
  d.setUTCDate(d.getUTCDate() + 4 - day);
  const y0 = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
  return Math.ceil(((d.getTime() - y0.getTime()) / 86_400_000 + 1) / 7);
}

function formatDate(dateStr: string): string {
  return new Date(dateStr + "T00:00:00").toLocaleDateString("fr-BE", {
    day: "2-digit", month: "2-digit", year: "numeric",
  });
}
function formatDateShort(dateStr: string): string {
  return new Date(dateStr + "T00:00:00").toLocaleDateString("fr-BE", {
    day: "2-digit", month: "2-digit",
  });
}
function getDayName(dateStr: string): string {
  const s = new Date(dateStr + "T00:00:00").toLocaleDateString("fr-BE", { weekday: "long" });
  return s.charAt(0).toUpperCase() + s.slice(1);
}
function getPeriod(startTime: string): string {
  return parseInt(startTime.split(":")[0], 10) < 12 ? "Matin" : "Après-midi";
}
function displayName(u: { firstname?: string | null; lastname?: string | null; email?: string } | null | undefined): string {
  if (!u) return "—";
  const n = `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim();
  return n || u.email || "—";
}

// ── Mission → row ─────────────────────────────────────────────────────────────

interface ScheduleRow {
  missionId:          number;
  date:               string;
  surgeonName:        string;
  missionType:        "BLOCK" | "CONSULTATION";
  startTime:          string;
  endTime:            string;
  instrumentistId:    number | null;
  instrumentistName:  string | null;
  siteName:           string | null;
  status:             MissionStatus;
  canEdit:            boolean;
  canRelease:         boolean;
  canCancel:          boolean;
  canReassign:        boolean;
}

function toRow(m: Mission): ScheduleRow {
  const date      = m.startAt.slice(0, 10);
  const startTime = m.startAt.slice(11, 16);
  const endTime   = m.endAt.slice(11, 16);
  const status    = (m.status ?? "OPEN") as MissionStatus;
  return {
    missionId:         m.id,
    date,
    surgeonName:       displayName(m.surgeon),
    missionType:       m.type,
    startTime,
    endTime,
    instrumentistId:   m.instrumentist?.id ?? null,
    instrumentistName: m.instrumentist ? displayName(m.instrumentist) : null,
    siteName:          m.site?.name ?? null,
    status,
    canEdit:    status === "OPEN",
    canRelease: status === "ASSIGNED",
    canCancel:  status === "OPEN",
    canReassign: status === "ASSIGNED",
  };
}

// ── Grouping ──────────────────────────────────────────────────────────────────

interface DayGroup  { date: string; rows: ScheduleRow[] }
interface WeekGroup { weekNumber: number; parity: "PAIR" | "IMPAIR"; label: string; days: DayGroup[] }

function groupRows(rows: ScheduleRow[]): WeekGroup[] {
  const byDate: Record<string, ScheduleRow[]> = {};
  for (const r of rows) (byDate[r.date] ??= []).push(r);

  for (const date of Object.keys(byDate)) {
    byDate[date].sort((a, b) => {
      const nc = a.surgeonName.localeCompare(b.surgeonName, "fr");
      if (nc !== 0) return nc;
      return (parseInt(a.startTime, 10) < 12 ? 0 : 1) - (parseInt(b.startTime, 10) < 12 ? 0 : 1);
    });
  }

  const byWeek: Record<number, WeekGroup> = {};
  for (const date of Object.keys(byDate).sort()) {
    const wn = getISOWeek(date);
    const parity: "PAIR" | "IMPAIR" = wn % 2 === 0 ? "PAIR" : "IMPAIR";
    if (!byWeek[wn]) byWeek[wn] = { weekNumber: wn, parity, label: "", days: [] };
    byWeek[wn].days.push({ date, rows: byDate[date] });
  }
  for (const wg of Object.values(byWeek)) {
    const first = wg.days[0].date;
    const last  = wg.days[wg.days.length - 1].date;
    const p = wg.parity === "PAIR" ? "paire" : "impaire";
    wg.label = `Semaine ${wg.weekNumber} (${p})  —  du ${formatDateShort(first)} au ${formatDate(last)}`;
  }
  return Object.values(byWeek).sort((a, b) => a.weekNumber - b.weekNumber);
}

// ── Status config ─────────────────────────────────────────────────────────────

const STATUS_LABEL: Record<string, {
  label: string;
  color: "default" | "info" | "success" | "warning" | "error" | "primary" | "secondary";
  sx?: object;
}> = {
  DRAFT:       { label: "Brouillon",  color: "default"   },
  OPEN:        { label: "À réserver", color: "info"      },
  ASSIGNED:    { label: "Assigné",    color: "success"   },
  DECLARED:    { label: "Déclaré",    color: "warning"   },
  REJECTED:    { label: "Rejeté",     color: "error"     },
  SUBMITTED:   { label: "Soumis",     color: "primary"   },
  VALIDATED:   { label: "Validé",     color: "secondary" },
  CLOSED:      { label: "Clôturé",    color: "default"   },
  CANCELLED:   { label: "Annulé",     color: "default",  sx: { bgcolor: "#616161", color: "#fff" } },
};

// ── InstrumentistCell ─────────────────────────────────────────────────────────

interface Instrumentist { id: number; displayName: string }

function ScheduleInstrumentistCell({
  row, instrumentists, onUpdated,
}: {
  row:            ScheduleRow;
  instrumentists: Instrumentist[];
  onUpdated:      (missionId: number, instId: number | null, name: string | null) => void;
}) {
  const toast = useToast();

  const assignMutation = useMutation({
    mutationFn: ({ instrumentistId }: { instrumentistId: number | null }) =>
      apiClient.post(`/api/missions/${row.missionId}/assign-instrumentist`, { instrumentistId }),
    onSuccess: (_d, { instrumentistId }) => {
      const name = instrumentistId
        ? (instrumentists.find((i) => i.id === instrumentistId)?.displayName ?? null)
        : null;
      onUpdated(row.missionId, instrumentistId, name);
      toast.success("Instrumentiste mis à jour");
    },
    onError: () => toast.error("Erreur lors de la modification"),
  });

  if (!row.canEdit) {
    return (
      <Typography variant="body2" color={row.instrumentistName ? "text.primary" : "text.disabled"}>
        {row.instrumentistName ?? "—"}
      </Typography>
    );
  }

  return (
    <Select
      size="small"
      value={row.instrumentistId ?? ""}
      onChange={(e) => {
        const val = e.target.value as number | "";
        assignMutation.mutate({ instrumentistId: val === "" ? null : val });
      }}
      displayEmpty
      disabled={assignMutation.isPending}
      sx={{
        fontSize: 13,
        "& .MuiSelect-select": { py: "3px", pr: "28px !important" },
        "& fieldset": { border: "none" },
        "&:hover fieldset": { border: "1px solid" },
        "&.Mui-focused fieldset": { border: "1px solid" },
        color: row.instrumentistId ? "text.primary" : "text.disabled",
        minWidth: 120, maxWidth: "100%",
      }}
    >
      <MenuItem value=""><em>Non assigné</em></MenuItem>
      {instrumentists.map((i) => (
        <MenuItem key={i.id} value={i.id}>{i.displayName}</MenuItem>
      ))}
    </Select>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

type StatusFilter = "ALL" | "OPEN" | "ASSIGNED" | "CANCELLED";

export default function PlanningSchedulePage() {
  const queryClient = useQueryClient();
  const toast = useToast();

  const [from,         setFrom]         = React.useState(() => {
    const d = new Date(); d.setDate(d.getDate() - 7); return d.toISOString().slice(0, 10);
  });
  const [to,           setTo]           = React.useState(() => {
    const d = new Date(); d.setMonth(d.getMonth() + 1); return d.toISOString().slice(0, 10);
  });
  const [siteId,       setSiteId]       = React.useState<number | "">("");
  const [versionId,    setVersionId]    = React.useState<number | "">("");
  const [statusFilter, setStatusFilter] = React.useState<StatusFilter>("ALL");
  const [rows,         setRows]         = React.useState<ScheduleRow[]>([]);
  const [loaded,       setLoaded]       = React.useState(false);

  // Drawer / dialog state
  const [drawerMissionId, setDrawerMissionId]   = React.useState<number | null>(null);
  const [drawerOpen,      setDrawerOpen]        = React.useState(false);

  const [releaseTarget, setReleaseTarget]       = React.useState<number | null>(null);
  const [cancelTarget,  setCancelTarget]        = React.useState<number | null>(null);
  const [reassignTarget, setReassignTarget]     = React.useState<ScheduleRow | null>(null);

  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const instrumentistsQuery = useQuery({
    queryKey: ["instrumentists-all"],
    queryFn: async () => {
      const r = await apiClient.get("/api/instrumentists", { params: { active: true } });
      return r.data.items as Instrumentist[];
    },
    staleTime: 5 * 60_000,
  });

  const missionsQuery = useQuery({
    queryKey: ["planning-schedule", from, to, siteId],
    queryFn: async () => {
      const data = await fetchMissions(1, 500, {
        from, to,
        ...(siteId ? { siteId: siteId as number } : {}),
      });
      return (data.items ?? []) as Mission[];
    },
    enabled: false,
    staleTime: 0,
  });

  async function handleLoad() {
    const result = await missionsQuery.refetch();
    if (result.data) {
      const filtered = result.data.filter((m) => m.status !== "DRAFT" && m.status !== "REJECTED");
      setRows(filtered.map(toRow));
      setLoaded(true);
    }
  }

  function handleUpdated(missionId: number, instId: number | null, name: string | null) {
    setRows((prev) =>
      prev.map((r) =>
        r.missionId === missionId
          ? { ...r, instrumentistId: instId, instrumentistName: name }
          : r,
      ),
    );
  }

  function handleRowClick(row: ScheduleRow) {
    setDrawerMissionId(row.missionId);
    setDrawerOpen(true);
  }

  function invalidateCoverage() {
    if (versionId !== "") {
      queryClient.invalidateQueries({ queryKey: ["coverage-summary", versionId as number] });
    }
  }

  // ── Release mutation ──────────────────────────────────────────────────────

  const releaseMut = useMutation({
    mutationFn: (missionId: number) => releaseMission(missionId),
    onMutate: (missionId) => {
      const prev = rows.find((r) => r.missionId === missionId);
      // Optimistic: ASSIGNED → OPEN, remove instrumentist
      setRows((r) =>
        r.map((row) =>
          row.missionId === missionId
            ? { ...row, status: "OPEN" as MissionStatus, instrumentistId: null, instrumentistName: null,
                canEdit: true, canRelease: false, canCancel: true, canReassign: false }
            : row,
        ),
      );
      return { prev };
    },
    onError: (_e, missionId, ctx) => {
      // Rollback
      if (ctx?.prev) {
        setRows((r) =>
          r.map((row) => (row.missionId === missionId ? ctx.prev! : row)),
        );
      }
      toast.error("Erreur lors de la remise au pool");
    },
    onSuccess: () => {
      toast.success("Mission remise au pool");
      invalidateCoverage();
    },
    onSettled: () => setReleaseTarget(null),
  });

  // ── Cancel mutation ───────────────────────────────────────────────────────

  const cancelMut = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => cancelMission(id, reason),
    onMutate: ({ id }) => {
      const prev = rows.find((r) => r.missionId === id);
      setRows((r) =>
        r.map((row) =>
          row.missionId === id
            ? { ...row, status: "CANCELLED" as MissionStatus,
                canEdit: false, canRelease: false, canCancel: false, canReassign: false }
            : row,
        ),
      );
      return { prev };
    },
    onError: (_e, { id }, ctx) => {
      if (ctx?.prev) {
        setRows((r) => r.map((row) => (row.missionId === id ? ctx.prev! : row)));
      }
      toast.error("Erreur lors de l'annulation");
    },
    onSuccess: () => {
      toast.success("Mission annulée");
      invalidateCoverage();
    },
    onSettled: () => setCancelTarget(null),
  });

  // ── Reassign mutation ─────────────────────────────────────────────────────

  const reassignMut = useMutation({
    mutationFn: ({ id, instrumentistId }: { id: number; instrumentistId: number; instrumentistName: string }) =>
      reassignMission(id, instrumentistId),
    onMutate: ({ id, instrumentistId, instrumentistName }) => {
      const prev = rows.find((r) => r.missionId === id);
      setRows((r) =>
        r.map((row) =>
          row.missionId === id
            ? { ...row, instrumentistId, instrumentistName }
            : row,
        ),
      );
      return { prev };
    },
    onError: (_e, { id }, ctx) => {
      if (ctx?.prev) {
        setRows((r) => r.map((row) => (row.missionId === id ? ctx.prev! : row)));
      }
      toast.error("Erreur lors de la réassignation");
    },
    onSuccess: () => {
      toast.success("Mission réassignée");
      invalidateCoverage();
    },
    onSettled: () => setReassignTarget(null),
  });

  const filteredRows = statusFilter === "ALL" ? rows : rows.filter((r) => r.status === statusFilter);
  const weeks = groupRows(filteredRows);
  const instrumentists = instrumentistsQuery.data ?? [];

  return (
    <Stack spacing={3}>
      <Typography variant="h6" fontWeight={700}>Planning publié</Typography>

      {/* Coverage banner — shown when a versionId is provided */}
      {versionId !== "" && <CoverageBanner versionId={versionId as number} />}

      {/* Filters */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack spacing={2}>
          <Stack direction="row" spacing={2} flexWrap="wrap" alignItems="flex-end">
            <TextField
              label="Du" type="date" size="small" value={from}
              onChange={(e) => setFrom(e.target.value)}
              InputLabelProps={{ shrink: true }}
            />
            <TextField
              label="Au" type="date" size="small" value={to}
              onChange={(e) => setTo(e.target.value)}
              InputLabelProps={{ shrink: true }}
            />
            <Select
              value={siteId} onChange={(e) => setSiteId(e.target.value as number | "")}
              displayEmpty size="small" sx={{ minWidth: 160 }}
            >
              <MenuItem value="">Tous les sites</MenuItem>
              {(sitesQuery.data ?? []).map((s: any) => (
                <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>
              ))}
            </Select>
            <TextField
              label="Version (optionnel)"
              type="number"
              size="small"
              value={versionId}
              onChange={(e) => setVersionId(e.target.value === "" ? "" : Number(e.target.value))}
              InputLabelProps={{ shrink: true }}
              sx={{ width: 140 }}
              inputProps={{ min: 1, "aria-label": "Numéro de version du planning" }}
            />
            <Button
              variant="contained" disableElevation
              onClick={handleLoad}
              disabled={!from || !to || missionsQuery.isFetching}
            >
              {missionsQuery.isFetching ? <CircularProgress size={16} /> : "Charger le planning"}
            </Button>
          </Stack>

          {/* Status filter */}
          <Stack direction="row" alignItems="center" spacing={1}>
            <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 600 }}>
              Statut :
            </Typography>
            <ToggleButtonGroup
              value={statusFilter}
              exclusive
              onChange={(_e, v) => v && setStatusFilter(v as StatusFilter)}
              size="small"
              aria-label="Filtre par statut"
            >
              <ToggleButton value="ALL"       sx={{ fontSize: 11, py: 0.3, px: 1.5 }}>Tous</ToggleButton>
              <ToggleButton value="OPEN"      sx={{ fontSize: 11, py: 0.3, px: 1.5 }}>Ouverts</ToggleButton>
              <ToggleButton value="ASSIGNED"  sx={{ fontSize: 11, py: 0.3, px: 1.5 }}>Assignés</ToggleButton>
              <ToggleButton value="CANCELLED" sx={{ fontSize: 11, py: 0.3, px: 1.5 }}>Annulés</ToggleButton>
            </ToggleButtonGroup>
          </Stack>
        </Stack>
      </Paper>

      {/* Empty states */}
      {!loaded && !missionsQuery.isFetching && (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2 }}>
          <Typography variant="h6" fontWeight={600} color="text.secondary">Aucun planning chargé</Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 360 }}>
            Sélectionnez une période et cliquez sur <strong>Charger le planning</strong>.
          </Typography>
        </Box>
      )}

      {loaded && filteredRows.length === 0 && (
        <Alert severity="info">
          {rows.length === 0
            ? "Aucune mission publiée sur cette période."
            : "Aucune mission ne correspond au filtre sélectionné."}
        </Alert>
      )}

      {/* Planning table */}
      {weeks.map((week) => (
        <Box key={week.weekNumber}>
          <Box sx={{
            px: 2, py: 1,
            bgcolor: week.parity === "PAIR" ? "primary.main" : "secondary.main",
            borderRadius: "8px 8px 0 0",
            color: "white",
          }}>
            <Typography variant="subtitle2" fontWeight={700} sx={{ letterSpacing: 0.3 }}>
              {week.label}
            </Typography>
          </Box>

          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: "0 0 8px 8px", borderTop: "none" }}>
            <Table size="small" sx={{ tableLayout: "fixed" }}>
              <TableHead>
                <TableRow sx={{ bgcolor: "grey.50" }}>
                  <TableCell sx={{ width: 88,  fontWeight: 700, fontSize: 12 }}>Jour</TableCell>
                  <TableCell sx={{ width: 90,  fontWeight: 700, fontSize: 12 }}>Date</TableCell>
                  <TableCell sx={{ width: 150, fontWeight: 700, fontSize: 12 }}>Chirurgien</TableCell>
                  <TableCell sx={{ width: 90,  fontWeight: 700, fontSize: 12 }}>Période</TableCell>
                  <TableCell sx={{ width: 160, fontWeight: 700, fontSize: 12 }}>Instrumentiste</TableCell>
                  <TableCell sx={{ width: 88,  fontWeight: 700, fontSize: 12 }}>Site</TableCell>
                  <TableCell sx={{ width: 110, fontWeight: 700, fontSize: 12 }}>Statut</TableCell>
                  <TableCell sx={{ width: 120, fontWeight: 700, fontSize: 12 }}>Actions</TableCell>
                </TableRow>
              </TableHead>

              <TableBody>
                {week.days.map(({ date, rows: dayRows }) =>
                  dayRows.map((row, idx) => {
                    const isFirst = idx === 0;
                    const isLast  = idx === dayRows.length - 1;
                    const cfg     = STATUS_LABEL[row.status] ?? STATUS_LABEL.OPEN;

                    return (
                      <TableRow
                        key={row.missionId}
                        onClick={() => handleRowClick(row)}
                        sx={{
                          cursor: "pointer",
                          "&:hover": { bgcolor: "grey.50" },
                          "& td": {
                            fontSize: 13, py: 0.7,
                            borderBottom: isLast ? "2px solid" : "1px solid",
                            borderColor: isLast ? "grey.300" : "grey.100",
                          },
                        }}
                      >
                        {isFirst && (
                          <TableCell rowSpan={dayRows.length} sx={{ fontWeight: 700, verticalAlign: "top", pt: "9px !important" }}>
                            {getDayName(date)}
                          </TableCell>
                        )}
                        {isFirst && (
                          <TableCell rowSpan={dayRows.length} sx={{ verticalAlign: "top", pt: "9px !important", color: "text.secondary" }}>
                            {formatDate(date)}
                          </TableCell>
                        )}

                        <TableCell sx={{ fontWeight: 500 }}>{row.surgeonName}</TableCell>
                        <TableCell sx={{ color: "text.secondary" }}>{getPeriod(row.startTime)}</TableCell>

                        <TableCell sx={{ py: "2px !important" }} onClick={(e) => e.stopPropagation()}>
                          <ScheduleInstrumentistCell
                            row={row}
                            instrumentists={instrumentists}
                            onUpdated={handleUpdated}
                          />
                        </TableCell>

                        <TableCell sx={{ color: "text.secondary" }}>{row.siteName ?? "—"}</TableCell>

                        <TableCell>
                          <Chip
                            label={cfg.label}
                            size="small"
                            color={cfg.color}
                            variant={row.status === "OPEN" ? "outlined" : "filled"}
                            sx={{ fontSize: 11, height: 20, ...(cfg.sx ?? {}) }}
                          />
                        </TableCell>

                        {/* Actions column — stop propagation so row click doesn't open drawer */}
                        <TableCell onClick={(e) => e.stopPropagation()}>
                          <Stack direction="row" spacing={0.5}>
                            {row.canRelease && (
                              <Button
                                size="small"
                                variant="outlined"
                                color="warning"
                                startIcon={<LockOpenIcon fontSize="small" />}
                                aria-label="Remettre au pool"
                                onClick={() => setReleaseTarget(row.missionId)}
                                sx={{ fontSize: 11, py: 0.3, px: 1, minWidth: 0 }}
                              >
                                Pool
                              </Button>
                            )}
                            {row.canCancel && (
                              <Button
                                size="small"
                                variant="outlined"
                                color="error"
                                startIcon={<BlockIcon fontSize="small" />}
                                aria-label="Annuler la mission"
                                onClick={() => setCancelTarget(row.missionId)}
                                sx={{ fontSize: 11, py: 0.3, px: 1, minWidth: 0 }}
                              >
                                Annuler
                              </Button>
                            )}
                            {row.canReassign && (
                              <Button
                                size="small"
                                variant="outlined"
                                color="info"
                                startIcon={<SwapHorizIcon fontSize="small" />}
                                aria-label="Réassigner la mission"
                                onClick={() => setReassignTarget(row)}
                                sx={{ fontSize: 11, py: 0.3, px: 1, minWidth: 0 }}
                              >
                                ↔
                              </Button>
                            )}
                          </Stack>
                        </TableCell>
                      </TableRow>
                    );
                  }),
                )}
              </TableBody>
            </Table>
          </TableContainer>
        </Box>
      ))}

      {/* Release confirm dialog */}
      <Dialog open={releaseTarget !== null} onClose={() => setReleaseTarget(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Remettre au pool ?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            L'instrumentiste sera désengagé. La mission repassera en statut "À réserver".
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setReleaseTarget(null)} disabled={releaseMut.isPending}>
            Annuler
          </Button>
          <Button
            onClick={() => releaseTarget !== null && releaseMut.mutate(releaseTarget)}
            disabled={releaseMut.isPending}
            color="warning"
            variant="contained"
            disableElevation
            aria-label="Remettre au pool"
          >
            {releaseMut.isPending ? "En cours…" : "Remettre au pool"}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Cancel dialog */}
      <CancelMissionDialog
        open={cancelTarget !== null}
        loading={cancelMut.isPending}
        onClose={() => setCancelTarget(null)}
        onConfirm={(reason) =>
          cancelTarget !== null && cancelMut.mutate({ id: cancelTarget, reason })
        }
      />

      {/* Reassign dialog */}
      <ReassignMissionDialog
        open={reassignTarget !== null}
        loading={reassignMut.isPending}
        missionId={reassignTarget?.missionId ?? null}
        onClose={() => setReassignTarget(null)}
        onConfirm={(instrumentistId, instrumentistName) =>
          reassignTarget !== null &&
          reassignMut.mutate({ id: reassignTarget.missionId, instrumentistId, instrumentistName })
        }
      />

      {/* Mission history drawer */}
      <MissionHistoryDrawer
        missionId={drawerMissionId}
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
      />
    </Stack>
  );
}
