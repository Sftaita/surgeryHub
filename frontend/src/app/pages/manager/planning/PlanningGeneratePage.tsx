import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress,
  Dialog, DialogActions, DialogContent, DialogTitle,
  IconButton, MenuItem, Paper, Select,
  Stack, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import CheckCircleIcon from "@mui/icons-material/CheckCircle";
import WarningIcon     from "@mui/icons-material/Warning";
import SyncIcon        from "@mui/icons-material/Sync";
import ErrorIcon       from "@mui/icons-material/Error";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import SendIcon        from "@mui/icons-material/Send";
import EditIcon        from "@mui/icons-material/Edit";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  previewPlanning, generatePlanning, deployPlanning, getPlanningVersion,
  assignInstrumentist,
  createMission,
  type PreviewLine, type CoverageStatus, type PlanningVersionSummary,
} from "../../../features/planning-manager/api/planning.api";
import { fetchSites } from "../../../features/sites/api/sites.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";
import { DeployModal } from "../../../features/planning-manager/components/DeployModal";

// ── Helpers ───────────────────────────────────────────────────────────────────

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

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

function timeToMin(t: string): number {
  const [h, m] = t.split(":").map(Number);
  return h * 60 + (m || 0);
}

/** Build ISO 8601 datetime string from date (YYYY-MM-DD) + time (HH:MM). */
function buildISO(date: string, time: string): string {
  const month  = parseInt(date.slice(5, 7), 10);
  const offset = month >= 4 && month <= 10 ? "+02:00" : "+01:00";
  return `${date}T${time.slice(0, 5)}:00${offset}`;
}

// ── Freed-instrumentist helpers ───────────────────────────────────────────────

interface FreedInstrumentist { id: number; name: string; reason: string }

function getFreedInstrumentists(lines: PreviewLine[], target: PreviewLine): FreedInstrumentist[] {
  const tStart = timeToMin(target.startTime);
  const tEnd   = timeToMin(target.endTime);

  const freed = new Map<number, FreedInstrumentist>();
  for (const l of lines) {
    if (l.date === target.date && l.status === "SKIPPED" && l.instrumentistId && l.instrumentistName) {
      freed.set(l.instrumentistId, {
        id:     l.instrumentistId,
        name:   l.instrumentistName,
        reason: `Libéré — ${l.surgeonName} est absent ce jour-là`,
      });
    }
  }

  for (const l of lines) {
    if (
      l.date === target.date &&
      l.status !== "SKIPPED" &&
      l.instrumentistId &&
      timeToMin(l.startTime) < tEnd &&
      timeToMin(l.endTime)   > tStart
    ) {
      freed.delete(l.instrumentistId);
    }
  }

  return Array.from(freed.values());
}

// ── Grouping ──────────────────────────────────────────────────────────────────

interface DayGroup  { date: string; lines: PreviewLine[] }
interface WeekGroup {
  weekNumber: number;
  parity: "PAIR" | "IMPAIR";
  label: string;
  days: DayGroup[];
}

function groupIntoWeeks(lines: PreviewLine[]): WeekGroup[] {
  const byDate: Record<string, PreviewLine[]> = {};
  for (const l of lines) (byDate[l.date] ??= []).push(l);

  for (const date of Object.keys(byDate)) {
    byDate[date].sort((a, b) => {
      const nameCmp = (a.surgeonName ?? "").localeCompare(b.surgeonName ?? "", "fr");
      if (nameCmp !== 0) return nameCmp;
      const periodA = parseInt(a.startTime.split(":")[0], 10) < 12 ? 0 : 1;
      const periodB = parseInt(b.startTime.split(":")[0], 10) < 12 ? 0 : 1;
      return periodA - periodB;
    });
  }

  const byWeek: Record<number, WeekGroup> = {};
  for (const date of Object.keys(byDate).sort()) {
    const wn = getISOWeek(date);
    const parity: "PAIR" | "IMPAIR" = wn % 2 === 0 ? "PAIR" : "IMPAIR";
    if (!byWeek[wn]) byWeek[wn] = { weekNumber: wn, parity, label: "", days: [] };
    byWeek[wn].days.push({ date, lines: byDate[date] });
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

const STATUS_CFG: Record<CoverageStatus, { icon: React.ReactNode; label: string; color: string; rowBg: string }> = {
  COVERED:   { icon: <CheckCircleIcon sx={{ fontSize: 16 }} />, label: "Couvert",     color: "success.main",  rowBg: "transparent" },
  UNCOVERED: { icon: <WarningIcon     sx={{ fontSize: 16 }} />, label: "Non couvert", color: "warning.dark",  rowBg: "#FFF8E1"      },
  MODIFIED:  { icon: <SyncIcon        sx={{ fontSize: 16 }} />, label: "Modifié",     color: "info.main",     rowBg: "#E3F2FD"      },
  CONFLICT:  { icon: <ErrorIcon       sx={{ fontSize: 16 }} />, label: "Conflit",     color: "error.main",    rowBg: "#FFEBEE"      },
  SKIPPED:   { icon: null,                                      label: "Ignoré",      color: "text.disabled", rowBg: "#F5F5F5"      },
};

// ── InstrumentistCell ─────────────────────────────────────────────────────────

interface Instrumentist { id: number; displayName: string }

interface InstrumentistCellProps {
  line: PreviewLine;
  instrumentists: Instrumentist[];
  onAssigned: (lineKey: string, instrumentistId: number | null, name: string) => void;
}

function lineKey(line: PreviewLine) {
  return `${line.date}-${line.slotId}`;
}

function InstrumentistCell({ line, instrumentists, onAssigned }: InstrumentistCellProps) {
  const canEdit = line.status !== "SKIPPED";

  const assignMutation = useMutation({
    mutationFn: ({ instrumentistId }: { instrumentistId: number }) =>
      assignInstrumentist(line.existingMissionId!, instrumentistId),
  });

  function handleChange(newId: number | "") {
    const id   = newId === "" ? null : newId;
    const name = id ? (instrumentists.find((i) => i.id === id)?.displayName ?? "—") : "";

    if (line.existingMissionId && id !== null) {
      assignMutation.mutate(
        { instrumentistId: id },
        { onSuccess: () => onAssigned(lineKey(line), id, name) },
      );
    } else {
      onAssigned(lineKey(line), id, name);
    }
  }

  if (!canEdit) {
    return line.instrumentistName ? (
      <Stack direction="row" alignItems="center" spacing={0.5}>
        <Typography variant="body2" color="text.secondary">{line.instrumentistName}</Typography>
        <Chip label="Libéré" size="small" color="info" variant="outlined"
          sx={{ height: 16, fontSize: 10 }} />
      </Stack>
    ) : (
      <Typography variant="body2" color="text.disabled">—</Typography>
    );
  }

  return (
    <Stack direction="row" alignItems="center" spacing={0.5}>
      <Select
        size="small"
        value={line.instrumentistId ?? ""}
        onChange={(e) => handleChange(e.target.value as number | "")}
        displayEmpty
        disabled={assignMutation.isPending}
        sx={{
          fontSize: 13,
          "& .MuiSelect-select": { py: "3px", pr: "28px !important" },
          "& fieldset": { border: "none" },
          "&:hover fieldset": { border: "1px solid" },
          "&.Mui-focused fieldset": { border: "1px solid" },
          color: line.instrumentistId ? "text.primary" : "text.disabled",
          minWidth: 120,
          maxWidth: "100%",
        }}
      >
        <MenuItem value=""><em>Non assigné</em></MenuItem>
        {instrumentists.map((i) => (
          <MenuItem key={i.id} value={i.id}>{i.displayName}</MenuItem>
        ))}
      </Select>

      {line.freedFrom && (
        <Tooltip title="Auto-assigné — instrumentiste libéré suite à l'absence de son chirurgien">
          <Chip label="Libéré" size="small" color="success" variant="outlined"
            sx={{ height: 18, fontSize: 10, flexShrink: 0, cursor: "help" }} />
        </Tooltip>
      )}
    </Stack>
  );
}


// ── ResolveModal ──────────────────────────────────────────────────────────────


function ResolveModal({
  open, onClose, previewLines, onResolved,
}: {
  open:         boolean;
  onClose:      () => void;
  previewLines: PreviewLine[];
  onResolved:   (key: string, type: "assigned" | "open", missionId: number, freed?: FreedInstrumentist) => void;
}) {
  const toast = useToast();
  const [pendingKey,  setPendingKey]  = React.useState<string | null>(null);
  const [creatingAll, setCreatingAll] = React.useState(false);

  const uncoveredLines = previewLines.filter((l) => l.status === "UNCOVERED");

  async function handleAssign(line: PreviewLine, freed: FreedInstrumentist) {
    if (!line.siteId) return;
    setPendingKey(lineKey(line));
    try {
      const mission = await createMission({
        siteId: line.siteId, type: line.missionType,
        startAt: buildISO(line.date, line.startTime),
        endAt:   buildISO(line.date, line.endTime),
        surgeonUserId:      line.surgeonId,
        instrumentistUserId: freed.id,
      });
      onResolved(lineKey(line), "assigned", mission.id, freed);
      toast.success(`${freed.name} directement attribué`);
    } catch {
      toast.error("Erreur lors de l'assignation");
    } finally {
      setPendingKey(null);
    }
  }

  async function handleCreate(line: PreviewLine) {
    if (!line.siteId) return;
    setPendingKey(lineKey(line));
    try {
      const mission = await createMission({
        siteId: line.siteId, type: line.missionType,
        startAt: buildISO(line.date, line.startTime),
        endAt:   buildISO(line.date, line.endTime),
        surgeonUserId: line.surgeonId,
      });
      onResolved(lineKey(line), "open", mission.id);
      toast.success("Mission créée");
    } catch {
      toast.error("Erreur lors de la création");
    } finally {
      setPendingKey(null);
    }
  }

  async function handleCreateAll() {
    setCreatingAll(true);
    let count = 0;
    for (const line of uncoveredLines) {
      if (!line.siteId) continue;
      try {
        const mission = await createMission({
          siteId: line.siteId, type: line.missionType,
          startAt: buildISO(line.date, line.startTime),
          endAt:   buildISO(line.date, line.endTime),
          surgeonUserId: line.surgeonId,
        });
        onResolved(lineKey(line), "open", mission.id);
        count++;
      } catch { /* continue */ }
    }
    setCreatingAll(false);
    if (count > 0) toast.success(`${count} mission(s) créée(s)`);
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth scroll="paper">
      <DialogTitle fontWeight={700} sx={{ pb: 1 }}>
        Résoudre les non-attribués ({uncoveredLines.length})
      </DialogTitle>
      <DialogContent dividers>
        {uncoveredLines.length === 0 ? (
          <Typography variant="body2" color="text.secondary" sx={{ py: 1 }}>
            Tous les créneaux non-attribués ont été traités.
          </Typography>
        ) : (
          <Stack spacing={2}>
            {uncoveredLines.length > 0 && (
              <Button
                fullWidth variant="outlined" color="warning" disableElevation
                disabled={creatingAll || !!pendingKey}
                onClick={handleCreateAll}
                sx={{ borderRadius: 2, fontWeight: 700 }}
              >
                {creatingAll
                  ? <><CircularProgress size={14} sx={{ mr: 1 }} />Création en cours…</>
                  : `Créer toutes les missions (${uncoveredLines.length})`}
              </Button>
            )}

            {uncoveredLines.map((line) => {
              const freed  = getFreedInstrumentists(previewLines, line);
              const key    = lineKey(line);
              const busy   = pendingKey === key;

              return (
                <Box key={key} sx={{ p: 2, border: "1px solid", borderColor: "divider", borderRadius: 2, bgcolor: "grey.50" }}>
                  <Typography variant="subtitle2" fontWeight={700}>{line.surgeonName}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {getDayName(line.date)} {formatDate(line.date)} · {getPeriod(line.startTime)} · {line.siteName ?? "—"}
                  </Typography>

                  {freed.length > 0 ? (
                    <Stack spacing={0.75} mt={1.5}>
                      <Typography variant="caption" fontWeight={600} color="text.secondary">
                        Instrumentiste(s) disponible(s) :
                      </Typography>
                      {freed.map((f) => (
                        <Stack key={f.id} direction="row" alignItems="center" justifyContent="space-between"
                          sx={{ px: 1.5, py: 1, bgcolor: "#F0FDF4", border: "1px solid", borderColor: "#BBF7D0", borderRadius: 1.5 }}>
                          <Box>
                            <Typography variant="body2" fontWeight={600}>{f.name}</Typography>
                            <Typography variant="caption" color="success.dark">{f.reason}</Typography>
                          </Box>
                          <Button
                            size="small" variant="contained" color="success" disableElevation
                            disabled={busy}
                            onClick={() => handleAssign(line, f)}
                            sx={{ borderRadius: 2, minWidth: 80 }}
                          >
                            {busy ? <CircularProgress size={14} color="inherit" /> : "Envoyer"}
                          </Button>
                        </Stack>
                      ))}
                    </Stack>
                  ) : (
                    <Stack direction="row" alignItems="center" justifyContent="space-between" mt={1.5}>
                      <Typography variant="body2" color="text.secondary" fontStyle="italic">
                        Aucun instrumentiste libéré disponible
                      </Typography>
                      <Button
                        size="small" variant="outlined"
                        disabled={busy}
                        onClick={() => handleCreate(line)}
                        sx={{ borderRadius: 2 }}
                      >
                        {busy ? <CircularProgress size={14} /> : "Créer une mission"}
                      </Button>
                    </Stack>
                  )}
                </Box>
              );
            })}
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} color="inherit">Fermer</Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function PlanningGeneratePage() {
  const toast = useToast();

  const today = new Date().toISOString().slice(0, 10);
  const [from, setFrom]       = React.useState(today);
  const [to,   setTo]         = React.useState(() => {
    const d = new Date(); d.setMonth(d.getMonth() + 2); return d.toISOString().slice(0, 10);
  });
  const [siteId,    setSiteId]    = React.useState<number | "">("");
  const [surgeonId, setSurgeonId] = React.useState<number | "">("");

  const [previewLines,   setPreviewLines]   = React.useState<PreviewLine[] | null>(null);
  const [generateResult, setGenerateResult] = React.useState<{ versionId: number; created: number; updated: number; skipped: number } | null>(null);
  const [versionSummary, setVersionSummary] = React.useState<PlanningVersionSummary | null>(null);
  const [deployModalOpen, setDeployModalOpen] = React.useState(false);
  const [deployed,       setDeployed]       = React.useState(false);
  const [tutorialOpen,   setTutorialOpen]   = React.useState(false);
  const [resolveOpen,    setResolveOpen]    = React.useState(false);
  const [openRequestKeys, setOpenRequestKeys] = React.useState<Set<string>>(new Set());

  const sitesQuery    = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const surgeonsQuery = useQuery({
    queryKey: ["surgeons-list"],
    queryFn:  async () => {
      const r = await apiClient.get("/api/surgeons");
      return r.data.items as { id: number; firstname: string; lastname: string; email: string }[];
    },
  });
  const instrumentistsQuery = useQuery({
    queryKey: ["instrumentists-all"],
    queryFn:  async () => {
      const r = await apiClient.get("/api/instrumentists", { params: { active: true } });
      return r.data.items as Instrumentist[];
    },
    staleTime: 5 * 60_000,
  });

  const previewMutation = useMutation({
    mutationFn: () => previewPlanning({ from, to, siteId: siteId || null, surgeonId: surgeonId || null }),
    onSuccess:  (data) => {
      setPreviewLines(data);
      setGenerateResult(null);
      setVersionSummary(null);
      setOpenRequestKeys(new Set());
      setDeployed(false);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const generateMutation = useMutation({
    mutationFn: () => generatePlanning({ from, to, siteId: siteId || null, surgeonId: surgeonId || null }),
    onSuccess:  async (data) => {
      setGenerateResult(data);
      toast.success(`Planning généré : ${data.created} créé(es), ${data.updated} mis à jour`);
      try {
        const summary = await getPlanningVersion(data.versionId);
        setVersionSummary(summary);
      } catch { /* non-blocking */ }
      try {
        const fresh = await previewPlanning({ from, to, siteId: siteId || null, surgeonId: surgeonId || null });
        setPreviewLines(fresh);
      } catch { /* garder l'état local en cas d'échec */ }
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const deployMutation = useMutation({
    mutationFn: (payload: { selectedUncoveredMissionIds: number[]; sendChangeSummary: boolean }) =>
      deployPlanning({
        from,
        to,
        siteId:                      siteId || null,
        versionId:                   generateResult?.versionId ?? null,
        selectedUncoveredMissionIds: payload.selectedUncoveredMissionIds,
        sendChangeSummary:           payload.sendChangeSummary,
      }),
    onSuccess: () => {
      toast.success("Planning déployé. Notifications et documents en cours d'envoi.");
      setDeployModalOpen(false);
      setDeployed(true);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  function handleAssigned(key: string, instrumentistId: number | null, name: string) {
    if (instrumentistId !== null) toast.success("Instrumentiste assigné");
    setPreviewLines((prev) =>
      prev
        ? prev.map((l) =>
            lineKey(l) === key
              ? {
                  ...l,
                  status: (instrumentistId ? "COVERED" : "UNCOVERED") as CoverageStatus,
                  instrumentistId: instrumentistId ?? null,
                  instrumentistName: name,
                }
              : l,
          )
        : prev,
    );
  }

  function handleResolved(
    key: string,
    type: "assigned" | "open",
    missionId: number,
    freed?: FreedInstrumentist,
  ) {
    if (type === "open") {
      setOpenRequestKeys((prev) => new Set([...prev, key]));
    }
    setPreviewLines((prev) =>
      prev
        ? prev.map((l) =>
            lineKey(l) === key
              ? {
                  ...l,
                  existingMissionId: missionId,
                  status: "COVERED" as CoverageStatus,
                  ...(freed ? { instrumentistId: freed.id, instrumentistName: freed.name } : {}),
                }
              : l,
          )
        : prev,
    );
  }

  function surgeonName(u: { firstname?: string; lastname?: string; email: string }) {
    return `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim() || u.email;
  }

  const instrumentists: Instrumentist[] = instrumentistsQuery.data ?? [];
  const weeks = previewLines ? groupIntoWeeks(previewLines) : [];

  const totalStats = previewLines
    ? {
        COVERED:   previewLines.filter((l) => l.status === "COVERED").length,
        UNCOVERED: previewLines.filter((l) => l.status === "UNCOVERED").length,
        MODIFIED:  previewLines.filter((l) => l.status === "MODIFIED").length,
        CONFLICT:  previewLines.filter((l) => l.status === "CONFLICT").length,
      }
    : null;

  const noInstrCount = previewLines
    ? previewLines.filter((l) => l.status !== "SKIPPED" && l.instrumentistId === null).length
    : 0;

  return (
    <Stack spacing={3}>
      {/* ── Header ── */}
      <Stack direction="row" alignItems="center" spacing={1}>
        <Typography variant="h6" fontWeight={700}>Générer le planning</Typography>
        <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
          <HelpOutlineIcon fontSize="small" />
        </IconButton>
      </Stack>

      {/* ── Filters ── */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
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
          <Select value={siteId} onChange={(e) => setSiteId(e.target.value as number | "")}
            displayEmpty size="small" sx={{ minWidth: 160 }}>
            <MenuItem value="">Tous les sites</MenuItem>
            {(sitesQuery.data ?? []).map((s: any) => (
              <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>
            ))}
          </Select>
          <Select value={surgeonId} onChange={(e) => setSurgeonId(e.target.value as number | "")}
            displayEmpty size="small" sx={{ minWidth: 200 }}>
            <MenuItem value="">Tous les chirurgiens</MenuItem>
            {(surgeonsQuery.data ?? []).map((s) => (
              <MenuItem key={s.id} value={s.id}>{surgeonName(s)}</MenuItem>
            ))}
          </Select>
          <Button
            variant="outlined"
            onClick={() => previewMutation.mutate()}
            disabled={!from || !to || previewMutation.isPending}
          >
            {previewMutation.isPending ? <CircularProgress size={16} /> : "Prévisualiser"}
          </Button>
        </Stack>
      </Paper>

      {/* ── Empty state ── */}
      {previewLines === null && !previewMutation.isPending && (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2 }}>
          <img
            src="https://cdn.undraw.co/illustration/schedule_r409.svg"
            alt="" style={{ width: 200, opacity: 0.75 }}
          />
          <Typography variant="h6" fontWeight={600} color="text.secondary">
            Aucune prévisualisation
          </Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 360 }}>
            Choisissez une période et cliquez sur <strong>Prévisualiser</strong> pour visualiser le planning.
          </Typography>
        </Box>
      )}

      {/* ── Summary chips ── */}
      {totalStats && (
        <Stack direction="row" spacing={1} flexWrap="wrap">
          {(Object.entries(totalStats) as [CoverageStatus, number][]).map(([status, count]) =>
            count > 0 ? (
              <Chip
                key={status}
                icon={STATUS_CFG[status].icon as any}
                label={`${count} ${STATUS_CFG[status].label}`}
                sx={{ color: STATUS_CFG[status].color }}
                variant="outlined" size="small"
              />
            ) : null,
          )}
          {noInstrCount > 0 && (
            <Tooltip title="Créneaux sans instrumentiste attribué — inclut les missions déjà créées mais non pourvues">
              <Chip
                icon={<WarningIcon sx={{ fontSize: 16 }} />}
                label={`${noInstrCount} sans instrumentiste`}
                sx={{ color: "warning.dark" }}
                variant="outlined"
                size="small"
              />
            </Tooltip>
          )}
        </Stack>
      )}

      {/* ── Résoudre les non-attribués ── */}
      {totalStats && totalStats.UNCOVERED > 0 && (
        <Box>
          <Button
            variant="contained" color="warning" disableElevation
            startIcon={<WarningIcon />}
            onClick={() => setResolveOpen(true)}
            sx={{ borderRadius: 2 }}
          >
            Résoudre les non-attribués ({totalStats.UNCOVERED})
          </Button>
        </Box>
      )}

      {/* ── Planning table ── */}
      {weeks.map((week) => (
        <Box key={week.weekNumber}>
          <Box
            sx={{
              px: 2, py: 1,
              bgcolor: week.parity === "PAIR" ? "primary.main" : "secondary.main",
              borderRadius: "8px 8px 0 0",
              color: "white",
            }}
          >
            <Typography variant="subtitle2" fontWeight={700} sx={{ letterSpacing: 0.3 }}>
              {week.label}
            </Typography>
          </Box>

          <TableContainer component={Paper} variant="outlined"
            sx={{ borderRadius: "0 0 8px 8px", borderTop: "none" }}>
            <Table size="small" sx={{ tableLayout: "fixed" }}>
              <TableHead>
                <TableRow sx={{ bgcolor: "grey.50" }}>
                  <TableCell sx={{ width: 88,  fontWeight: 700, fontSize: 12 }}>Jour</TableCell>
                  <TableCell sx={{ width: 90,  fontWeight: 700, fontSize: 12 }}>Date</TableCell>
                  <TableCell sx={{ width: 150, fontWeight: 700, fontSize: 12 }}>Chirurgien</TableCell>
                  <TableCell sx={{ width: 90,  fontWeight: 700, fontSize: 12 }}>Période</TableCell>
                  <TableCell sx={{ width: 160, fontWeight: 700, fontSize: 12 }}>Instrumentiste</TableCell>
                  <TableCell sx={{ width: 88,  fontWeight: 700, fontSize: 12 }}>Site</TableCell>
                  <TableCell sx={{ width: 44,  fontWeight: 700, fontSize: 12, textAlign: "center" }}>État</TableCell>
                </TableRow>
              </TableHead>

              <TableBody>
                {week.days.map(({ date, lines }) => {
                  const instPeriodCount: Record<string, number> = {};
                  for (const l of lines) {
                    if (l.instrumentistId !== null && l.status !== "SKIPPED") {
                      const p = parseInt(l.startTime.split(":")[0], 10) < 12 ? "AM" : "PM";
                      const k = `${l.instrumentistId}_${p}`;
                      instPeriodCount[k] = (instPeriodCount[k] ?? 0) + 1;
                    }
                  }
                  const dupKeys = new Set(
                    Object.entries(instPeriodCount).filter(([, n]) => n > 1).map(([k]) => k),
                  );
                  function isDup(l: PreviewLine) {
                    if (!l.instrumentistId) return false;
                    const p = parseInt(l.startTime.split(":")[0], 10) < 12 ? "AM" : "PM";
                    return dupKeys.has(`${l.instrumentistId}_${p}`);
                  }

                  return lines.map((line, idx) => {
                    const cfg     = STATUS_CFG[line.status];
                    const dup     = isDup(line);
                    const isFirst = idx === 0;
                    const isLast  = idx === lines.length - 1;
                    const isOpenReq = openRequestKeys.has(lineKey(line));

                    return (
                      <TableRow
                        key={`${date}-${idx}`}
                        sx={{
                          bgcolor: dup ? "#FFCDD2" : isOpenReq ? "#F0FDF4" : cfg.rowBg,
                          opacity: line.status === "SKIPPED" ? 0.55 : 1,
                          "& td": {
                            fontSize: 13, py: 0.7,
                            borderBottom: isLast ? "2px solid" : "1px solid",
                            borderColor: isLast ? "grey.300" : "grey.100",
                          },
                        }}
                      >
                        {isFirst && (
                          <TableCell rowSpan={lines.length}
                            sx={{ fontWeight: 700, verticalAlign: "top", pt: "9px !important" }}>
                            {getDayName(date)}
                          </TableCell>
                        )}
                        {isFirst && (
                          <TableCell rowSpan={lines.length}
                            sx={{ verticalAlign: "top", pt: "9px !important", color: "text.secondary" }}>
                            {formatDate(date)}
                          </TableCell>
                        )}

                        <TableCell sx={{ fontWeight: 500 }}>
                          {line.surgeonName || "—"}
                          {line.status === "SKIPPED" && (
                            <Box component="span" sx={{ color: "text.disabled", fontWeight: 400, ml: 0.5, fontSize: 12 }}>
                              (absent)
                            </Box>
                          )}
                        </TableCell>

                        <TableCell sx={{ color: "text.secondary" }}>
                          {getPeriod(line.startTime)}
                        </TableCell>

                        <TableCell sx={{ py: "2px !important" }}>
                          {isOpenReq ? (
                            <Stack direction="row" alignItems="center" spacing={0.5} sx={{ color: "text.secondary" }}>
                              <CheckCircleIcon sx={{ fontSize: 14, color: "success.main" }} />
                              <Typography variant="body2" fontStyle="italic">Mission créée</Typography>
                            </Stack>
                          ) : (
                            <Stack direction="row" alignItems="center" spacing={0.5}>
                              <InstrumentistCell
                                line={line}
                                instrumentists={instrumentists}
                                onAssigned={handleAssigned}
                              />
                              {dup && (
                                <Chip label="Doublon" size="small" color="error" variant="outlined"
                                  sx={{ height: 18, fontSize: 10, flexShrink: 0 }} />
                              )}
                            </Stack>
                          )}
                        </TableCell>

                        <TableCell sx={{ color: "text.secondary" }}>{line.siteName ?? "—"}</TableCell>

                        <TableCell sx={{ textAlign: "center" }}>
                          <Tooltip
                            placement="left"
                            title={
                              line.status === "MODIFIED" && line.existingInstrumentistName
                                ? `Modifié — Mission existante : ${line.existingInstrumentistName} → Gabarit : ${line.instrumentistName || "aucun"}`
                                : line.status === "MODIFIED"
                                ? "Modifié — la mission existante a un instrumentiste différent du gabarit"
                                : cfg.label
                            }
                          >
                            <Box component="span" sx={{ color: cfg.color, display: "inline-flex", verticalAlign: "middle" }}>
                              {cfg.icon}
                            </Box>
                          </Tooltip>
                        </TableCell>
                      </TableRow>
                    );
                  });
                })}
              </TableBody>
            </Table>
          </TableContainer>
        </Box>
      ))}

      {/* ── Action buttons ── */}
      {previewLines && (
        <Stack direction="row" spacing={2}>
          <Button
            variant="contained" disableElevation
            onClick={() => generateMutation.mutate()}
            disabled={generateMutation.isPending}
          >
            {generateMutation.isPending ? <CircularProgress size={16} /> : "Générer le planning"}
          </Button>
          {generateResult && !deployed && (
            <Button
              variant="outlined" color="success" startIcon={<SendIcon />}
              onClick={() => setDeployModalOpen(true)}
            >
              Déployer et envoyer les PDFs
            </Button>
          )}
        </Stack>
      )}

      {/* ── Generate result banner ── */}
      {generateResult && (
        <Alert severity="success">
          Planning généré :{" "}
          {generateResult.created > 0 && <><strong>{generateResult.created}</strong> nouvelle(s) mission(s) créée(s),{" "}</>}
          {generateResult.updated > 0 && <><strong>{generateResult.updated}</strong> mission(s) mise(s) à jour,{" "}</>}
          {generateResult.created === 0 && generateResult.updated === 0
            ? <><strong>{generateResult.skipped}</strong> mission(s) existantes préservées — aucun créneau nouveau à créer.</>
            : generateResult.skipped > 0 && <><strong>{generateResult.skipped}</strong> créneau(x) avec mission existante préservé(s).</>
          }
          {(generateResult.created > 0 || generateResult.updated > 0) && (
            <>{" "}Cliquez sur l'instrumentiste (icône <EditIcon sx={{ fontSize: 13, verticalAlign: "middle" }} />) pour en changer un.</>
          )}
        </Alert>
      )}

      {/* ── Version summary ── */}
      {versionSummary && (
        <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
          <Typography variant="subtitle2" fontWeight={700} gutterBottom>
            État de la période — version #{versionSummary.versionNumber}
          </Typography>
          <Typography variant="caption" color="text.secondary" display="block" mb={1.5}>
            Toutes les missions de la période ({versionSummary.periodStart} → {versionSummary.periodEnd})
          </Typography>
          <Stack direction="row" spacing={2} flexWrap="wrap" alignItems="flex-start">
            <Tooltip title="Nombre total de missions dans la période (tous statuts sauf Rejeté)">
              <Box sx={{ textAlign: "center", minWidth: 70 }}>
                <Typography variant="h6" fontWeight={700}>{versionSummary.summary.total}</Typography>
                <Typography variant="caption" color="text.secondary">Total</Typography>
              </Box>
            </Tooltip>
            <Tooltip title="Missions en statut DRAFT — créées mais pas encore publiées">
              <Box sx={{ textAlign: "center", minWidth: 70 }}>
                <Typography variant="h6" fontWeight={700} color="text.secondary">{versionSummary.summary.draft}</Typography>
                <Typography variant="caption" color="text.secondary">DRAFT</Typography>
              </Box>
            </Tooltip>
            <Tooltip title="Missions publiées disponibles au pool (OPEN)">
              <Box sx={{ textAlign: "center", minWidth: 70 }}>
                <Typography variant="h6" fontWeight={700} color="info.main">{versionSummary.summary.open}</Typography>
                <Typography variant="caption" color="text.secondary">Publiées (pool)</Typography>
              </Box>
            </Tooltip>
            <Tooltip title="Missions avec un instrumentiste confirmé (ASSIGNED+)">
              <Box sx={{ textAlign: "center", minWidth: 70 }}>
                <Typography variant="h6" fontWeight={700} color="success.main">{versionSummary.summary.assigned}</Typography>
                <Typography variant="caption" color="text.secondary">Avec instr.</Typography>
              </Box>
            </Tooltip>
            {versionSummary.summary.withoutInstrumentist > 0 && (
              <Tooltip title="Missions DRAFT ou OPEN sans instrumentiste — nécessitent une action">
                <Box sx={{ textAlign: "center", minWidth: 70 }}>
                  <Typography variant="h6" fontWeight={700} color="warning.main">{versionSummary.summary.withoutInstrumentist}</Typography>
                  <Typography variant="caption" color="text.secondary">Sans instr.</Typography>
                </Box>
              </Tooltip>
            )}
            <Box sx={{ width: 1, display: { xs: "block", sm: "none" } }} />
            <Tooltip title="Nombre de chirurgiens distincts concernés">
              <Box sx={{ textAlign: "center", minWidth: 70 }}>
                <Typography variant="h6" fontWeight={700}>{versionSummary.summary.surgeonCount}</Typography>
                <Typography variant="caption" color="text.secondary">Chirurgiens</Typography>
              </Box>
            </Tooltip>
            <Tooltip title="Nombre d'instrumentistes distincts assignés">
              <Box sx={{ textAlign: "center", minWidth: 70 }}>
                <Typography variant="h6" fontWeight={700}>{versionSummary.summary.instrumentistCount}</Typography>
                <Typography variant="caption" color="text.secondary">Instrumentistes</Typography>
              </Box>
            </Tooltip>
          </Stack>
        </Paper>
      )}

      {generateResult && !deployed && (
        <Alert severity="warning" icon={<WarningIcon />}>
          <strong>Missions en attente de déploiement.</strong> Les missions sont créées en statut DRAFT
          et ne sont pas encore visibles par les instrumentistes.
          Cliquez sur <strong>Déployer et envoyer les PDFs</strong> pour les publier.
        </Alert>
      )}

      {/* ── Resolve modal (pre-deploy assignment) ── */}
      {previewLines && (
        <ResolveModal
          open={resolveOpen}
          onClose={() => setResolveOpen(false)}
          previewLines={previewLines}
          onResolved={handleResolved}
        />
      )}

      {/* ── Tutorial dialog ── */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle fontWeight={700}>Comment générer un planning ?</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "Définissez la période", desc: "Choisissez les dates de début et de fin. Filtrez par site ou chirurgien pour une génération partielle." },
              { n: 2, title: "Prévisualisez", desc: "SurgicalHub projette les templates PAIR/IMPAIR sur la période. Chaque ligne reçoit un statut : ✅ Couvert, ⚠ Non couvert, 🔄 Modifié, ❗ Conflit." },
              { n: 3, title: "Générez", desc: "Cliquez sur \"Générer le planning\" pour créer les missions en base. Les lignes non couvertes restent à assigner." },
              { n: 4, title: "Assignez directement dans le tableau", desc: "Après génération, cliquez sur le nom de l'instrumentiste (icône crayon) pour en changer un." },
              { n: 5, title: "Déployez", desc: "Publie les missions et envoie les PDFs personnalisés. Choisissez quels postes sans instrumentiste publier en pool." },
            ].map(({ n, title, desc }) => (
              <Stack key={n} direction="row" spacing={2} alignItems="flex-start">
                <Box sx={{
                  minWidth: 32, height: 32, borderRadius: "50%",
                  bgcolor: "primary.main", color: "white",
                  display: "flex", alignItems: "center", justifyContent: "center",
                  fontWeight: 700, fontSize: 14, flexShrink: 0,
                }}>
                  {n}
                </Box>
                <Box>
                  <Typography variant="subtitle2" fontWeight={700}>{title}</Typography>
                  <Typography variant="body2" color="text.secondary">{desc}</Typography>
                </Box>
              </Stack>
            ))}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTutorialOpen(false)} variant="contained" disableElevation>
            J'ai compris
          </Button>
        </DialogActions>
      </Dialog>

      {/* ── Deploy modal (2-step) ── */}
      {previewLines && generateResult && (
        <DeployModal
          open={deployModalOpen}
          onClose={() => setDeployModalOpen(false)}
          previewLines={previewLines}
          versionId={generateResult.versionId}
          from={from}
          to={to}
          onDeploy={(selectedUncoveredMissionIds, sendChangeSummary) =>
            deployMutation.mutate({ selectedUncoveredMissionIds, sendChangeSummary })
          }
          isDeploying={deployMutation.isPending}
        />
      )}
    </Stack>
  );
}
