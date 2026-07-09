import * as React from "react";
import {
  Alert, Box, Button, Checkbox, Chip, CircularProgress,
  Dialog, DialogActions, DialogContent, DialogTitle,
  Divider, FormControlLabel, IconButton, MenuItem, Paper, Select,
  Stack, Switch, Table, TableBody, TableCell, TableContainer,
  TableHead, TableRow, TextField, Typography,
} from "@mui/material";
import CheckCircleIcon    from "@mui/icons-material/CheckCircle";
import WarningIcon        from "@mui/icons-material/Warning";
import SyncIcon           from "@mui/icons-material/Sync";
import ErrorIcon          from "@mui/icons-material/Error";
import HelpOutlineIcon    from "@mui/icons-material/HelpOutline";
import CloseIcon          from "@mui/icons-material/Close";
import KeyboardArrowUpIcon   from "@mui/icons-material/KeyboardArrowUp";
import KeyboardArrowDownIcon from "@mui/icons-material/KeyboardArrowDown";
import SearchIcon         from "@mui/icons-material/Search";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  previewPlanningV2, generatePlanningV2, deployPlanningV2,
  extractErrorV2,
  type GenerationTargetInput,
} from "../../../features/planning-v2/api/planningV2.api";
import type { PreviewLineV2, PreviewLineStatus } from "../../../features/planning-v2/api/planningV2.types";
import { fetchSites } from "../../../features/sites/api/sites.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";

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

function timeToMin(t: string): number {
  const [h, m] = t.split(":").map(Number);
  return h * 60 + (m || 0);
}

/** Unique key for a V2 preview line — date + postId (never slotId). */
function lineKeyV2(line: PreviewLineV2): string {
  return `${line.date}-${line.postId}`;
}

// ── Freed-instrumentist helpers ───────────────────────────────────────────────

interface FreedInstrumentist { id: number; name: string; reason: string }

function getFreedInstrumentists(lines: PreviewLineV2[], target: PreviewLineV2): FreedInstrumentist[] {
  const tStart = timeToMin(target.startTime);
  const tEnd   = timeToMin(target.endTime);
  const freed  = new Map<number, FreedInstrumentist>();

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

interface DayGroupV2  { date: string; lines: PreviewLineV2[] }
interface WeekGroupV2 {
  weekNumber: number;
  parity: "PAIR" | "IMPAIR";
  label: string;
  days: DayGroupV2[];
}

function groupIntoWeeks(lines: PreviewLineV2[]): WeekGroupV2[] {
  const byDate: Record<string, PreviewLineV2[]> = {};
  for (const l of lines) (byDate[l.date] ??= []).push(l);

  for (const date of Object.keys(byDate)) {
    byDate[date].sort((a, b) => {
      const nameCmp = (a.surgeonName ?? "").localeCompare(b.surgeonName ?? "", "fr");
      if (nameCmp !== 0) return nameCmp;
      return (parseInt(a.startTime.split(":")[0], 10) < 12 ? 0 : 1) -
             (parseInt(b.startTime.split(":")[0], 10) < 12 ? 0 : 1);
    });
  }

  const byWeek: Record<number, WeekGroupV2> = {};
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

const STATUS_CFG: Record<PreviewLineStatus, { icon: React.ReactNode; label: string; color: string; rowBg: string }> = {
  COVERED:   { icon: <CheckCircleIcon sx={{ fontSize: 16 }} />, label: "Couvert",     color: "success.main",  rowBg: "transparent" },
  UNCOVERED: { icon: <WarningIcon     sx={{ fontSize: 16 }} />, label: "Non couvert", color: "warning.dark",  rowBg: "#FFF8E1"      },
  MODIFIED:  { icon: <SyncIcon        sx={{ fontSize: 16 }} />, label: "Modifié",     color: "info.main",     rowBg: "#E3F2FD"      },
  CONFLICT:  { icon: <ErrorIcon       sx={{ fontSize: 16 }} />, label: "Conflit",     color: "error.main",    rowBg: "#FFEBEE"      },
  SKIPPED:   { icon: null,                                      label: "Ignoré",      color: "text.disabled", rowBg: "#F5F5F5"      },
};

// ── Statistics bar ─────────────────────────────────────────────────────────────

interface Stats {
  total: number;
  covered: number;
  uncovered: number;
  conflicts: number;
  modified: number;
  skipped: number;
  dirtyCount: number;
  coveragePct: number | null;
}

function computeStats(lines: PreviewLineV2[], editedKeys: Set<string>): Stats {
  const active    = lines.filter((l) => l.status !== "SKIPPED");
  const covered   = lines.filter((l) => l.status === "COVERED" || l.status === "MODIFIED");
  const uncovered = lines.filter((l) => l.status === "UNCOVERED");
  const conflicts = lines.filter((l) => l.status === "CONFLICT");
  return {
    total:       active.length,
    covered:     covered.length,
    uncovered:   uncovered.length,
    conflicts:   conflicts.length,
    modified:    lines.filter((l) => l.status === "MODIFIED").length,
    skipped:     lines.filter((l) => l.status === "SKIPPED").length,
    dirtyCount:  editedKeys.size,
    coveragePct: active.length > 0 ? Math.round((covered.length / active.length) * 1000) / 10 : null,
  };
}

interface StatsBadgeProps { label: string; value: string | number; color?: string }
function StatsBadge({ label, value, color }: StatsBadgeProps) {
  return (
    <Box sx={{ textAlign: "center", px: 1.5 }}>
      <Typography variant="h6" fontWeight={700} color={color ?? "text.primary"} lineHeight={1.1}>
        {value}
      </Typography>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
    </Box>
  );
}

// ── Deploy confirm dialog ─────────────────────────────────────────────────────

interface DeployDialogProps {
  open: boolean;
  isDeploying: boolean;
  versionId: number | null;
  onConfirm: (sendPdf: boolean) => void;
  onClose: () => void;
}

function DeployDialog({ open, isDeploying, versionId, onConfirm, onClose }: DeployDialogProps) {
  const [sendPdf, setSendPdf] = React.useState(true);
  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle>Déployer le planning</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary" mb={2}>
          Le planning (version #{versionId}) sera déployé et les missions ouvertes au planning opérationnel.
          Cette action est irréversible.
        </Typography>
        <FormControlLabel
          control={<Switch checked={sendPdf} onChange={(e) => setSendPdf(e.target.checked)} />}
          label="Envoyer les PDF aux chirurgiens"
        />
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={isDeploying} color="inherit">Annuler</Button>
        <Button
          onClick={() => onConfirm(sendPdf)}
          disabled={isDeploying}
          variant="contained"
          color="primary"
          disableElevation
        >
          {isDeploying ? <CircularProgress size={16} color="inherit" /> : "Déployer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Inspector panel ───────────────────────────────────────────────────────────

interface Instrumentist { id: number; displayName: string }

interface InspectorProps {
  line: PreviewLineV2;
  isDirty: boolean;
  instrumentists: Instrumentist[];
  freedInstrumentists: FreedInstrumentist[];
  visibleKeys: string[];
  onEdit: (patch: Partial<PreviewLineV2>) => void;
  onReset: () => void;
  onNavigate: (key: string) => void;
  onClose: () => void;
}

function Inspector({
  line, isDirty, instrumentists, freedInstrumentists,
  visibleKeys, onEdit, onReset, onNavigate, onClose,
}: InspectorProps) {
  const key   = lineKeyV2(line);
  const idx   = visibleKeys.indexOf(key);
  const prevKey = idx > 0 ? visibleKeys[idx - 1] : null;
  const nextKey = idx < visibleKeys.length - 1 ? visibleKeys[idx + 1] : null;
  const isSkipped = line.status === "SKIPPED";
  const cfg = STATUS_CFG[line.status];

  function handleInstrumentistChange(newId: number | "") {
    if (newId === "") {
      onEdit({
        instrumentistId:   null,
        instrumentistName: null,
        status:            "UNCOVERED",
      });
    } else {
      const inst = instrumentists.find((i) => i.id === newId);
      onEdit({
        instrumentistId:   newId,
        instrumentistName: inst?.displayName ?? null,
        status:            isSkipped ? "UNCOVERED" : (line.status === "UNCOVERED" || line.status === "CONFLICT" ? "COVERED" : line.status),
      });
    }
  }

  return (
    <Paper
      variant="outlined"
      sx={{ width: 312, flexShrink: 0, borderRadius: 2, overflow: "hidden", position: "sticky", top: 16, maxHeight: "calc(100vh - 120px)", overflowY: "auto" }}
    >
      {/* Header */}
      <Stack direction="row" alignItems="center" justifyContent="space-between"
        sx={{ px: 2, py: 1.5, bgcolor: "grey.50", borderBottom: "1px solid", borderColor: "divider" }}>
        <Typography variant="subtitle2" fontWeight={700}>Détail de la ligne</Typography>
        <Stack direction="row" spacing={0.5}>
          <IconButton size="small" disabled={!prevKey} onClick={() => prevKey && onNavigate(prevKey)}>
            <KeyboardArrowUpIcon fontSize="small" />
          </IconButton>
          <IconButton size="small" disabled={!nextKey} onClick={() => nextKey && onNavigate(nextKey)}>
            <KeyboardArrowDownIcon fontSize="small" />
          </IconButton>
          <IconButton size="small" onClick={onClose}><CloseIcon fontSize="small" /></IconButton>
        </Stack>
      </Stack>

      <Stack spacing={2} sx={{ p: 2 }}>
        {/* Status */}
        <Stack direction="row" alignItems="center" spacing={1}>
          <Chip
            icon={cfg.icon as any}
            label={cfg.label}
            size="small"
            sx={{ bgcolor: cfg.rowBg, color: cfg.color, fontWeight: 600 }}
          />
          {isDirty && <Chip label="Modifié" size="small" color="warning" variant="outlined" />}
        </Stack>

        {/* Line info */}
        <Box>
          <Typography variant="caption" color="text.secondary">Date</Typography>
          <Typography variant="body2" fontWeight={600}>
            {getDayName(line.date)} {formatDate(line.date)}
          </Typography>
        </Box>
        <Box>
          <Typography variant="caption" color="text.secondary">Chirurgien</Typography>
          <Typography variant="body2" fontWeight={600}>{line.surgeonName}</Typography>
        </Box>
        <Box>
          <Typography variant="caption" color="text.secondary">Site · Période · Type</Typography>
          <Typography variant="body2">
            {line.siteName ?? "—"} · {getPeriod(line.startTime)} · {line.missionType}
          </Typography>
        </Box>
        <Box>
          <Typography variant="caption" color="text.secondary">Horaire</Typography>
          <Typography variant="body2">{line.startTime} – {line.endTime}</Typography>
        </Box>

        <Divider />

        {/* Edit: SKIPPED toggle */}
        <FormControlLabel
          control={
            <Switch
              size="small"
              checked={isSkipped}
              onChange={(e) => {
                if (e.target.checked) {
                  onEdit({ status: "SKIPPED" });
                } else {
                  onEdit({
                    status: line.instrumentistId ? "COVERED" : "UNCOVERED",
                  });
                }
              }}
            />
          }
          label={<Typography variant="body2">Ignorer cette ligne</Typography>}
        />

        {/* Edit: instrumentist */}
        {!isSkipped && (
          <Box>
            <Typography variant="caption" color="text.secondary" display="block" mb={0.5}>
              Instrumentiste
            </Typography>
            <Select
              size="small"
              fullWidth
              value={line.instrumentistId ?? ""}
              onChange={(e) => handleInstrumentistChange(e.target.value as number | "")}
              displayEmpty
            >
              <MenuItem value=""><em>Non assigné</em></MenuItem>
              {instrumentists.map((i) => (
                <MenuItem key={i.id} value={i.id}>{i.displayName}</MenuItem>
              ))}
            </Select>

            {/* Freed instrumentists */}
            {freedInstrumentists.length > 0 && (
              <Box mt={1}>
                <Typography variant="caption" fontWeight={600} color="text.secondary">
                  Libérés disponibles :
                </Typography>
                {freedInstrumentists.map((f) => (
                  <Stack key={f.id} direction="row" alignItems="center" justifyContent="space-between"
                    mt={0.5} px={1} py={0.75}
                    sx={{ bgcolor: "#F0FDF4", border: "1px solid #BBF7D0", borderRadius: 1 }}>
                    <Box>
                      <Typography variant="caption" fontWeight={600}>{f.name}</Typography>
                      <br />
                      <Typography variant="caption" color="success.dark" sx={{ fontSize: 10 }}>{f.reason}</Typography>
                    </Box>
                    <Button size="small" variant="text" color="success"
                      onClick={() => handleInstrumentistChange(f.id)}>
                      Assigner
                    </Button>
                  </Stack>
                ))}
              </Box>
            )}
          </Box>
        )}

        <Divider />

        {/* Reset line */}
        <Button
          size="small"
          variant="outlined"
          color="inherit"
          disabled={!isDirty}
          onClick={onReset}
          sx={{ borderRadius: 1.5 }}
        >
          Réinitialiser la ligne
        </Button>
      </Stack>
    </Paper>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function PlanningGeneratePage() {
  const toast = useToast();

  // ── Filter state ────────────────────────────────────────────────────────────
  const now = new Date();
  const [year,     setYear]     = React.useState(now.getFullYear());
  const [month,    setMonth]    = React.useState(now.getMonth() + 1);
  const [siteId,   setSiteId]   = React.useState<number | "">("");
  const [surgeonId, setSurgeonId] = React.useState<number | "">("");

  // ── Preview state ────────────────────────────────────────────────────────────
  const [originalLines,   setOriginalLines]   = React.useState<PreviewLineV2[] | null>(null);
  const [previewVersion,  setPreviewVersion]  = React.useState<string | null>(null);

  // ── Dirty state (sparse overrides over originalLines) ────────────────────────
  const [editedLines, setEditedLines] = React.useState<Map<string, Partial<PreviewLineV2>>>(new Map());

  // ── Inspector & selection ────────────────────────────────────────────────────
  const [inspectorKey,  setInspectorKey]  = React.useState<string | null>(null);
  const [selectedKeys,  setSelectedKeys]  = React.useState<Set<string>>(new Set());

  // ── Search & filters ─────────────────────────────────────────────────────────
  const [searchTerm,    setSearchTerm]    = React.useState("");
  const [activeFilters, setActiveFilters] = React.useState<Set<string>>(new Set());

  // ── Bulk assign ──────────────────────────────────────────────────────────────
  const [bulkInstrumentistId, setBulkInstrumentistId] = React.useState<number | "">("");

  // ── Post-generate / deploy state ─────────────────────────────────────────────
  const [generateResult, setGenerateResult] = React.useState<{ versionId: number; created: number; updated: number; skipped: number } | null>(null);
  const [deployOpen,     setDeployOpen]     = React.useState(false);
  const [deployed,       setDeployed]       = React.useState(false);
  const [tutorialOpen,   setTutorialOpen]   = React.useState(false);
  const [expiredOpen,    setExpiredOpen]    = React.useState(false);

  // ── Effective lines (originalLines merged with local edits) ──────────────────
  const effectiveLines = React.useMemo<PreviewLineV2[]>(() => {
    if (!originalLines) return [];
    return originalLines.map((line) => {
      const patch = editedLines.get(lineKeyV2(line));
      return patch ? { ...line, ...patch } : line;
    });
  }, [originalLines, editedLines]);

  // ── Statistics (live, local) ──────────────────────────────────────────────────
  const stats = React.useMemo(
    () => computeStats(effectiveLines, new Set(editedLines.keys())),
    [effectiveLines, editedLines],
  );

  // ── Search + filter (applied to effectiveLines) ───────────────────────────────
  const visibleLines = React.useMemo<PreviewLineV2[]>(() => {
    let lines = effectiveLines;
    if (surgeonId !== "") {
      lines = lines.filter((l) => l.surgeonId === surgeonId);
    }
    if (searchTerm.trim()) {
      const q = searchTerm.trim().toLowerCase();
      lines = lines.filter(
        (l) =>
          l.surgeonName?.toLowerCase().includes(q) ||
          l.instrumentistName?.toLowerCase().includes(q) ||
          l.siteName?.toLowerCase().includes(q),
      );
    }
    if (activeFilters.size > 0) {
      lines = lines.filter((l) => {
        if (activeFilters.has("COVERED")      && (l.status === "COVERED" || l.status === "MODIFIED")) return true;
        if (activeFilters.has("UNCOVERED")    && l.status === "UNCOVERED") return true;
        if (activeFilters.has("SKIPPED")      && l.status === "SKIPPED") return true;
        if (activeFilters.has("CONFLICT")     && l.status === "CONFLICT") return true;
        if (activeFilters.has("OVERRIDE")     && editedLines.has(lineKeyV2(l))) return true;
        if (activeFilters.has("BLOCK")        && l.missionType === "BLOCK") return true;
        if (activeFilters.has("CONSULTATION") && l.missionType === "CONSULTATION") return true;
        return false;
      });
    }
    return lines;
  }, [effectiveLines, surgeonId, searchTerm, activeFilters, editedLines]);

  const visibleKeys = React.useMemo(
    () => visibleLines.map(lineKeyV2),
    [visibleLines],
  );

  const weeks = React.useMemo(
    () => (visibleLines.length > 0 ? groupIntoWeeks(visibleLines) : []),
    [visibleLines],
  );

  // ── Data queries ──────────────────────────────────────────────────────────────
  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const surgeonsQuery = useQuery({
    queryKey: ["surgeons-list"],
    queryFn:  async () => {
      const r = await apiClient.get("/api/surgeons");
      return r.data.items as { id: number; firstname?: string; lastname?: string; email: string }[];
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
  const instrumentists: Instrumentist[] = instrumentistsQuery.data ?? [];

  function surgeonName(u: { firstname?: string; lastname?: string; email: string }) {
    return `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim() || u.email;
  }

  // ── Mutations ─────────────────────────────────────────────────────────────────

  const target: GenerationTargetInput = {
    siteId:   siteId   !== "" ? siteId   : null,
    year,
    month,
  };

  const previewMutation = useMutation({
    mutationFn: () => previewPlanningV2(target),
    onSuccess:  (data) => {
      setOriginalLines(data.lines);
      setPreviewVersion(data.previewVersion);
      setEditedLines(new Map());
      setSelectedKeys(new Set());
      setInspectorKey(null);
      setSearchTerm("");
      setActiveFilters(new Set());
      setGenerateResult(null);
      setDeployed(false);
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  const generateMutation = useMutation({
    mutationFn: () =>
      generatePlanningV2({
        ...target,
        previewVersion: previewVersion ?? undefined,
        lines:          effectiveLines.length > 0 ? effectiveLines : undefined,
      }),
    onSuccess: (data) => {
      setGenerateResult(data);
      toast.success(`Planning généré : ${data.created} créé(es), ${data.updated} mis à jour, ${data.skipped} ignorée(s).`);
    },
    onError: (err: any) => {
      if (err?.response?.status === 409 && err?.response?.data?.code === "PREVIEW_EXPIRED") {
        setExpiredOpen(true);
      } else {
        toast.error(extractErrorV2(err));
      }
    },
  });

  const deployMutation = useMutation({
    mutationFn: (sendPdf: boolean) => deployPlanningV2(generateResult!.versionId, sendPdf),
    onSuccess: () => {
      toast.success("Planning déployé. Notifications et documents en cours d'envoi.");
      setDeployOpen(false);
      setDeployed(true);
    },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  // ── Line edit helpers ─────────────────────────────────────────────────────────

  function handleEditLine(key: string, patch: Partial<PreviewLineV2>) {
    setEditedLines((prev) => {
      const next   = new Map(prev);
      const existing = next.get(key) ?? {};
      next.set(key, { ...existing, ...patch });
      return next;
    });
  }

  function handleResetLine(key: string) {
    setEditedLines((prev) => {
      const next = new Map(prev);
      next.delete(key);
      return next;
    });
  }

  function handleResetAll() {
    setEditedLines(new Map());
  }

  // ── Bulk actions ──────────────────────────────────────────────────────────────

  function handleBulkSkip() {
    setEditedLines((prev) => {
      const next = new Map(prev);
      for (const key of selectedKeys) {
        next.set(key, { ...(next.get(key) ?? {}), status: "SKIPPED" as PreviewLineStatus });
      }
      return next;
    });
    setSelectedKeys(new Set());
  }

  function handleBulkAssign() {
    if (bulkInstrumentistId === "") return;
    const inst = instrumentists.find((i) => i.id === bulkInstrumentistId);
    if (!inst) return;
    setEditedLines((prev) => {
      const next = new Map(prev);
      for (const key of selectedKeys) {
        const line = effectiveLines.find((l) => lineKeyV2(l) === key);
        if (!line || line.status === "SKIPPED") continue;
        next.set(key, {
          ...(next.get(key) ?? {}),
          instrumentistId:   inst.id,
          instrumentistName: inst.displayName,
          status:            line.status === "UNCOVERED" || line.status === "CONFLICT"
            ? "COVERED"
            : line.status,
        });
      }
      return next;
    });
    setSelectedKeys(new Set());
    setBulkInstrumentistId("");
  }

  // ── Selection helpers ─────────────────────────────────────────────────────────

  function toggleSelected(key: string) {
    setSelectedKeys((prev) => {
      const next = new Set(prev);
      next.has(key) ? next.delete(key) : next.add(key);
      return next;
    });
  }

  const allVisibleSelected = visibleKeys.length > 0 && visibleKeys.every((k) => selectedKeys.has(k));
  const someVisibleSelected = visibleKeys.some((k) => selectedKeys.has(k));

  function toggleSelectAll() {
    if (allVisibleSelected) {
      setSelectedKeys((prev) => {
        const next = new Set(prev);
        for (const k of visibleKeys) next.delete(k);
        return next;
      });
    } else {
      setSelectedKeys((prev) => {
        const next = new Set(prev);
        for (const k of visibleKeys) next.add(k);
        return next;
      });
    }
  }

  // ── Filter chip toggle ────────────────────────────────────────────────────────
  function toggleFilter(f: string) {
    setActiveFilters((prev) => {
      const next = new Set(prev);
      next.has(f) ? next.delete(f) : next.add(f);
      return next;
    });
  }

  // ── Render ────────────────────────────────────────────────────────────────────

  const hasPreview    = originalLines !== null;
  const hasGenerate   = generateResult !== null;
  const canGenerate   = hasPreview && !deployed && siteId !== "";
  const canDeploy     = hasGenerate && !deployed;
  const isDirty       = editedLines.size > 0;

  return (
    <Stack spacing={3}>
      {/* ── Header ── */}
      <Stack direction="row" alignItems="center" spacing={1}>
        <Typography variant="h6" fontWeight={700}>Générer le planning (V2)</Typography>
        <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
          <HelpOutlineIcon fontSize="small" />
        </IconButton>
      </Stack>

      {/* ── Filters ── */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack direction="row" spacing={2} flexWrap="wrap" alignItems="flex-end">
          <TextField
            label="Année" type="number" size="small" value={year}
            onChange={(e) => setYear(parseInt(e.target.value, 10) || year)}
            inputProps={{ min: 2020, max: 2040, style: { width: 72 } }}
          />
          <Select
            value={month} onChange={(e) => setMonth(Number(e.target.value))}
            size="small" sx={{ minWidth: 120 }}
          >
            {["Janvier","Février","Mars","Avril","Mai","Juin","Juillet","Août","Septembre","Octobre","Novembre","Décembre"].map((m, i) => (
              <MenuItem key={i + 1} value={i + 1}>{m}</MenuItem>
            ))}
          </Select>
          <Select
            value={siteId} onChange={(e) => setSiteId(e.target.value as number | "")}
            displayEmpty size="small" sx={{ minWidth: 160 }}
          >
            <MenuItem value="">Tous les sites</MenuItem>
            {(sitesQuery.data ?? []).map((s: any) => (
              <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>
            ))}
          </Select>
          <Select
            value={surgeonId} onChange={(e) => setSurgeonId(e.target.value as number | "")}
            displayEmpty size="small" sx={{ minWidth: 200 }}
          >
            <MenuItem value="">Tous les chirurgiens</MenuItem>
            {(surgeonsQuery.data ?? []).map((s) => (
              <MenuItem key={s.id} value={s.id}>{surgeonName(s)}</MenuItem>
            ))}
          </Select>
          <Button
            variant="outlined"
            onClick={() => previewMutation.mutate()}
            disabled={!siteId || previewMutation.isPending}
          >
            {previewMutation.isPending ? <CircularProgress size={16} /> : "Prévisualiser"}
          </Button>
        </Stack>
      </Paper>

      {/* ── Empty state ── */}
      {!hasPreview && !previewMutation.isPending && (
        <Box sx={{ display: "flex", flexDirection: "column", alignItems: "center", py: 8, gap: 2 }}>
          <Typography variant="h6" fontWeight={600} color="text.secondary">
            Aucune prévisualisation
          </Typography>
          <Typography variant="body2" color="text.secondary" textAlign="center" sx={{ maxWidth: 360 }}>
            Sélectionnez un site, une année et un mois, puis cliquez sur <strong>Prévisualiser</strong>.
          </Typography>
        </Box>
      )}

      {/* ── Statistics bar ── */}
      {hasPreview && (
        <Paper variant="outlined" sx={{ borderRadius: 2 }}>
          <Stack
            direction="row" alignItems="center" justifyContent="center"
            divider={<Divider orientation="vertical" flexItem />}
            spacing={0} flexWrap="wrap"
            sx={{ py: 1.5 }}
          >
            <StatsBadge label="Total" value={stats.total} />
            <StatsBadge label="Couverts" value={stats.covered} color="success.main" />
            <StatsBadge label="Non couverts" value={stats.uncovered} color="warning.dark" />
            <StatsBadge label="Ignorés" value={stats.skipped} color="text.disabled" />
            {stats.conflicts > 0 && <StatsBadge label="Conflits" value={stats.conflicts} color="error.main" />}
            {stats.dirtyCount > 0 && <StatsBadge label="Modifiés localement" value={stats.dirtyCount} color="warning.main" />}
            {stats.coveragePct !== null && (
              <StatsBadge
                label="Couverture"
                value={`${stats.coveragePct}%`}
                color={stats.coveragePct >= 90 ? "success.main" : stats.coveragePct >= 70 ? "warning.dark" : "error.main"}
              />
            )}
          </Stack>
        </Paper>
      )}

      {/* ── Search + filters ── */}
      {hasPreview && (
        <Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap">
          <TextField
            size="small"
            placeholder="Rechercher chirurgien, instrumentiste, site…"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            InputProps={{ startAdornment: <SearchIcon sx={{ mr: 0.5, color: "text.disabled", fontSize: 18 }} /> }}
            sx={{ minWidth: 300 }}
          />
          {[
            { key: "COVERED",      label: "Couverts"           },
            { key: "UNCOVERED",    label: "Non couverts"       },
            { key: "SKIPPED",      label: "Ignorés"            },
            { key: "CONFLICT",     label: "Conflits"           },
            { key: "OVERRIDE",     label: "Modifiés manager"   },
            { key: "BLOCK",        label: "Bloc"               },
            { key: "CONSULTATION", label: "Consultation"       },
          ].map(({ key, label }) => (
            <Chip
              key={key}
              label={label}
              size="small"
              clickable
              color={activeFilters.has(key) ? "primary" : "default"}
              variant={activeFilters.has(key) ? "filled" : "outlined"}
              onClick={() => toggleFilter(key)}
            />
          ))}
          {(searchTerm || activeFilters.size > 0) && (
            <Button size="small" color="inherit" onClick={() => { setSearchTerm(""); setActiveFilters(new Set()); }}>
              Effacer
            </Button>
          )}
        </Stack>
      )}

      {/* ── Bulk action bar ── */}
      {selectedKeys.size > 0 && (
        <Paper variant="outlined" sx={{ p: 1.5, borderRadius: 2, bgcolor: "primary.50" }}>
          <Stack direction="row" alignItems="center" spacing={2} flexWrap="wrap">
            <Typography variant="body2" fontWeight={600}>
              {selectedKeys.size} ligne(s) sélectionnée(s)
            </Typography>
            <Button size="small" variant="outlined" color="inherit" onClick={handleBulkSkip}>
              Ignorer la sélection
            </Button>
            <Stack direction="row" spacing={1} alignItems="center">
              <Select
                size="small"
                displayEmpty
                value={bulkInstrumentistId}
                onChange={(e) => setBulkInstrumentistId(e.target.value as number | "")}
                sx={{ minWidth: 160 }}
              >
                <MenuItem value=""><em>Choisir un instrumentiste</em></MenuItem>
                {instrumentists.map((i) => (
                  <MenuItem key={i.id} value={i.id}>{i.displayName}</MenuItem>
                ))}
              </Select>
              <Button
                size="small" variant="contained" disableElevation
                disabled={bulkInstrumentistId === ""}
                onClick={handleBulkAssign}
              >
                Assigner
              </Button>
            </Stack>
            <Button size="small" color="inherit" onClick={() => setSelectedKeys(new Set())}>
              Désélectionner tout
            </Button>
          </Stack>
        </Paper>
      )}

      {/* ── Main content: table + inspector ── */}
      {hasPreview && (
        <Box sx={{ display: "flex", gap: 2, alignItems: "flex-start" }}>
          {/* Table */}
          <Box sx={{ flex: 1, overflow: "hidden" }}>
            {isDirty && (
              <Stack direction="row" alignItems="center" justifyContent="flex-end" mb={1} spacing={1}>
                <Typography variant="caption" color="warning.main" fontWeight={600}>
                  {stats.dirtyCount} modification(s) locale(s) non enregistrée(s)
                </Typography>
                <Button size="small" color="inherit" onClick={handleResetAll}>
                  Tout réinitialiser
                </Button>
              </Stack>
            )}

            {weeks.map((week) => (
              <Box key={week.weekNumber} mb={3}>
                {/* Week header */}
                <Stack direction="row" alignItems="center" spacing={1} mb={1}>
                  <Chip
                    label={week.parity}
                    size="small"
                    color={week.parity === "PAIR" ? "primary" : "secondary"}
                    variant="outlined"
                    sx={{ fontWeight: 700, fontSize: 11 }}
                  />
                  <Typography variant="body2" fontWeight={600} color="text.secondary">
                    {week.label}
                  </Typography>
                </Stack>

                <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2 }}>
                  <Table size="small">
                    <TableHead>
                      <TableRow sx={{ bgcolor: "grey.50" }}>
                        <TableCell padding="checkbox" sx={{ width: 40 }}>
                          <Checkbox
                            size="small"
                            indeterminate={someVisibleSelected && !allVisibleSelected}
                            checked={allVisibleSelected}
                            onChange={toggleSelectAll}
                          />
                        </TableCell>
                        <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Date</TableCell>
                        <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Chirurgien</TableCell>
                        <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Période</TableCell>
                        <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Instrumentiste</TableCell>
                        <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Site</TableCell>
                        <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>État</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {week.days.flatMap(({ date, lines }) =>
                        lines.map((line, i) => {
                          const key      = lineKeyV2(line);
                          const selected = selectedKeys.has(key);
                          const dirty    = editedLines.has(key);
                          const active   = inspectorKey === key;
                          const cfg      = STATUS_CFG[line.status];
                          return (
                            <TableRow
                              key={key}
                              hover
                              selected={selected || active}
                              sx={{
                                bgcolor: active ? "action.selected" : cfg.rowBg,
                                cursor: "pointer",
                                "&:hover": { bgcolor: "action.hover" },
                              }}
                              onClick={() => setInspectorKey(active ? null : key)}
                            >
                              <TableCell padding="checkbox" onClick={(e) => e.stopPropagation()}>
                                <Checkbox
                                  size="small"
                                  checked={selected}
                                  onChange={() => toggleSelected(key)}
                                />
                              </TableCell>
                              <TableCell sx={{ fontSize: 12, whiteSpace: "nowrap" }}>
                                {i === 0 ? (
                                  <>
                                    <Typography variant="caption" fontWeight={700} display="block">
                                      {getDayName(date)}
                                    </Typography>
                                    <Typography variant="caption" color="text.secondary">
                                      {formatDateShort(date)}
                                    </Typography>
                                  </>
                                ) : null}
                              </TableCell>
                              <TableCell sx={{ fontSize: 12 }}>
                                <Typography variant="body2" fontSize={12}>{line.surgeonName}</Typography>
                              </TableCell>
                              <TableCell sx={{ fontSize: 12, whiteSpace: "nowrap" }}>
                                {getPeriod(line.startTime)}
                                <Typography variant="caption" color="text.secondary" display="block">
                                  {line.missionType}
                                </Typography>
                              </TableCell>
                              <TableCell sx={{ fontSize: 12, maxWidth: 200 }}>
                                <Stack direction="row" alignItems="center" spacing={0.5} flexWrap="wrap">
                                  <Typography variant="body2" fontSize={12} noWrap>
                                    {line.instrumentistName || <em style={{ color: "#9e9e9e" }}>Non assigné</em>}
                                  </Typography>
                                  {dirty && (
                                    <Chip
                                      label="Édité"
                                      size="small"
                                      color="warning"
                                      variant="outlined"
                                      sx={{ height: 16, fontSize: 10, fontWeight: 700, flexShrink: 0 }}
                                      data-testid="edited-badge"
                                    />
                                  )}
                                  {line.freedFrom && (
                                    <Chip label="Libéré" size="small" color="success" variant="outlined"
                                      sx={{ height: 16, fontSize: 10, flexShrink: 0 }} />
                                  )}
                                </Stack>
                              </TableCell>
                              <TableCell sx={{ fontSize: 12 }}>
                                <Typography variant="body2" fontSize={12} noWrap>{line.siteName ?? "—"}</Typography>
                              </TableCell>
                              <TableCell>
                                <Chip
                                  icon={cfg.icon as any}
                                  label={cfg.label}
                                  size="small"
                                  sx={{ color: cfg.color, fontSize: 11, fontWeight: 600, height: 22 }}
                                />
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

            {visibleLines.length === 0 && originalLines !== null && (
              <Typography variant="body2" color="text.secondary" textAlign="center" py={4}>
                Aucune ligne ne correspond aux filtres actifs.
              </Typography>
            )}
          </Box>

          {/* Inspector panel */}
          {inspectorKey && (() => {
            const line = effectiveLines.find((l) => lineKeyV2(l) === inspectorKey);
            if (!line) return null;
            return (
              <Inspector
                key={inspectorKey}
                line={line}
                isDirty={editedLines.has(inspectorKey)}
                instrumentists={instrumentists}
                freedInstrumentists={getFreedInstrumentists(effectiveLines, line)}
                visibleKeys={visibleKeys}
                onEdit={(patch) => handleEditLine(inspectorKey, patch)}
                onReset={() => handleResetLine(inspectorKey)}
                onNavigate={(k) => setInspectorKey(k)}
                onClose={() => setInspectorKey(null)}
              />
            );
          })()}
        </Box>
      )}

      {/* ── Generate result summary ── */}
      {hasGenerate && (
        <Alert severity="success" sx={{ borderRadius: 2 }}>
          Planning généré — version #{generateResult.versionId} ·{" "}
          {generateResult.created} mission(s) créée(s) · {generateResult.updated} mise(s) à jour ·{" "}
          {generateResult.skipped} ignorée(s)
        </Alert>
      )}

      {/* ── Action buttons ── */}
      {hasPreview && !deployed && (
        <Stack direction="row" spacing={2}>
          {canGenerate && (
            <Button
              variant="contained" disableElevation
              onClick={() => generateMutation.mutate()}
              disabled={generateMutation.isPending}
            >
              {generateMutation.isPending ? <CircularProgress size={16} color="inherit" sx={{ mr: 1 }} /> : null}
              {isDirty ? "Générer avec modifications" : "Générer le planning"}
            </Button>
          )}
          {canDeploy && (
            <Button
              variant="contained" color="success" disableElevation
              onClick={() => setDeployOpen(true)}
            >
              Déployer le planning
            </Button>
          )}
        </Stack>
      )}

      {/* ── Deployed success ── */}
      {deployed && (
        <Alert severity="success" sx={{ borderRadius: 2 }}>
          Planning déployé avec succès. Les missions sont maintenant accessibles dans le planning opérationnel.
        </Alert>
      )}

      {/* ── Deploy dialog ── */}
      <DeployDialog
        open={deployOpen}
        isDeploying={deployMutation.isPending}
        versionId={generateResult?.versionId ?? null}
        onConfirm={(sendPdf) => deployMutation.mutate(sendPdf)}
        onClose={() => setDeployOpen(false)}
      />

      {/* ── Preview expired dialog ── */}
      <Dialog open={expiredOpen} onClose={() => setExpiredOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Aperçu expiré</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Le planning a changé depuis votre dernière prévisualisation (nouveau post, absence ou configuration de plage horaire modifié).
            Régénérez l&apos;aperçu pour obtenir les données à jour.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setExpiredOpen(false)} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation
            onClick={() => { setExpiredOpen(false); previewMutation.mutate(); }}
          >
            Régénérer l&apos;aperçu
          </Button>
        </DialogActions>
      </Dialog>

      {/* ── Tutorial dialog ── */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Comment utiliser le Planning V2</DialogTitle>
        <DialogContent>
          <Stack spacing={2} mt={1}>
            <Typography variant="body2">
              <strong>1. Prévisualiser</strong> — Sélectionnez un site, une année et un mois, puis cliquez sur « Prévisualiser ».
              Le système calcule les lignes du planning à partir des postes actifs du chirurgien et des règles de récurrence.
            </Typography>
            <Typography variant="body2">
              <strong>2. Éditer l&apos;aperçu</strong> — Cliquez sur une ligne pour ouvrir le panneau d&apos;inspection à droite.
              Assignez un instrumentiste ou ignorez la ligne. Utilisez les cases à cocher pour des actions groupées (ignorer/assigner en masse).
            </Typography>
            <Typography variant="body2">
              <strong>3. Générer</strong> — Cliquez sur « Générer le planning ». Les lignes éditées sont envoyées telles quelles.
              Si le planning a changé depuis la prévisualisation, vous serez invité à régénérer l&apos;aperçu.
            </Typography>
            <Typography variant="body2">
              <strong>4. Déployer</strong> — Une fois satisfait, cliquez sur « Déployer ». Les missions passent au planning opérationnel.
            </Typography>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTutorialOpen(false)}>Fermer</Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
