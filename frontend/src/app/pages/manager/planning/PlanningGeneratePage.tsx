import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, IconButton, MenuItem, Paper, Select, Stack, TextField, Tooltip, Typography,
} from "@mui/material";
import CheckCircleIcon from "@mui/icons-material/CheckCircle";
import WarningIcon from "@mui/icons-material/Warning";
import SyncIcon from "@mui/icons-material/Sync";
import ErrorIcon from "@mui/icons-material/Error";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import SendIcon from "@mui/icons-material/Send";
import { useMutation, useQuery } from "@tanstack/react-query";
import {
  previewPlanning, generatePlanning, deployPlanning,
  type PreviewLine, type CoverageStatus,
} from "../../../features/planning-manager/api/planning.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

const STATUS_CONFIG: Record<CoverageStatus, { icon: React.ReactNode; label: string; color: string }> = {
  COVERED:   { icon: <CheckCircleIcon fontSize="small" />, label: "Couvert",   color: "success.main" },
  UNCOVERED: { icon: <WarningIcon fontSize="small" />,     label: "Non couvert", color: "warning.main" },
  MODIFIED:  { icon: <SyncIcon fontSize="small" />,        label: "Modifié",   color: "info.main" },
  CONFLICT:  { icon: <ErrorIcon fontSize="small" />,       label: "Conflit",   color: "error.main" },
  SKIPPED:   { icon: null,                                 label: "Ignoré",    color: "text.disabled" },
};

function groupByDate(lines: PreviewLine[]): Record<string, PreviewLine[]> {
  return lines.reduce((acc, line) => {
    (acc[line.date] ??= []).push(line);
    return acc;
  }, {} as Record<string, PreviewLine[]>);
}

function dayStats(lines: PreviewLine[]) {
  const counts = { COVERED: 0, UNCOVERED: 0, MODIFIED: 0, CONFLICT: 0, SKIPPED: 0 };
  lines.forEach((l) => counts[l.status]++);
  return counts;
}

function dayBgColor(lines: PreviewLine[]): string {
  const s = dayStats(lines);
  if (s.CONFLICT > 0) return "#FFEBEE";
  if (s.UNCOVERED > 0) return "#FFF8E1";
  if (s.MODIFIED > 0) return "#E3F2FD";
  return "#E8F5E9";
}

export default function PlanningGeneratePage() {
  const toast = useToast();

  const today = new Date().toISOString().slice(0, 10);
  const [from, setFrom] = React.useState(today);
  const [to, setTo] = React.useState(() => {
    const d = new Date(); d.setMonth(d.getMonth() + 2);
    return d.toISOString().slice(0, 10);
  });
  const [siteId, setSiteId] = React.useState<number | "">("");
  const [surgeonId, setSurgeonId] = React.useState<number | "">("");
  const [previewLines, setPreviewLines] = React.useState<PreviewLine[] | null>(null);
  const [selectedDate, setSelectedDate] = React.useState<string | null>(null);
  const [tutorialOpen, setTutorialOpen] = React.useState(false);
  const [deployOpen, setDeployOpen] = React.useState(false);
  const [generateResult, setGenerateResult] = React.useState<{ created: number; updated: number; skipped: number } | null>(null);

  const sitesQuery = useQuery({
    queryKey: ["sites-list"],
    queryFn: async () => { const r = await apiClient.get("/api/sites"); return r.data as { id: number; name: string }[]; },
  });
  const surgeonsQuery = useQuery({
    queryKey: ["surgeons-list"],
    queryFn: async () => { const r = await apiClient.get("/api/surgeons"); return r.data.items as { id: number; firstname: string; lastname: string; email: string }[]; },
  });

  const previewMutation = useMutation({
    mutationFn: () => previewPlanning({ from, to, siteId: siteId || null, surgeonId: surgeonId || null }),
    onSuccess: (data) => { setPreviewLines(data); setSelectedDate(null); setGenerateResult(null); },
    onError: (err) => toast.error(extractError(err)),
  });

  const generateMutation = useMutation({
    mutationFn: () => generatePlanning({ from, to, siteId: siteId || null, surgeonId: surgeonId || null }),
    onSuccess: (data) => { setGenerateResult(data); toast.success(`Planning généré : ${data.created} créé(es), ${data.updated} mis à jour`); },
    onError: (err) => toast.error(extractError(err)),
  });

  const deployMutation = useMutation({
    mutationFn: () => deployPlanning({ from, to, siteId: siteId || null }),
    onSuccess: (data) => {
      toast.success(`Planning déployé — ${data.instrumentistsPdfsSent} instrumentistes, ${data.surgeonsPdfsSent} chirurgiens notifiés`);
      setDeployOpen(false);
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const grouped = previewLines ? groupByDate(previewLines) : null;
  const sortedDates = grouped ? Object.keys(grouped).sort() : [];
  const selectedLines = selectedDate && grouped ? (grouped[selectedDate] ?? []) : [];

  function surgeonName(u: any) { return `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim() || u.email; }

  const totalStats = previewLines ? {
    COVERED: previewLines.filter((l) => l.status === "COVERED").length,
    UNCOVERED: previewLines.filter((l) => l.status === "UNCOVERED").length,
    MODIFIED: previewLines.filter((l) => l.status === "MODIFIED").length,
    CONFLICT: previewLines.filter((l) => l.status === "CONFLICT").length,
  } : null;

  return (
    <Stack spacing={3}>
      {/* Header */}
      <Stack direction="row" alignItems="center" spacing={1}>
        <Typography variant="h6" fontWeight={700}>Générer le planning</Typography>
        <IconButton size="small" onClick={() => setTutorialOpen(true)} color="primary" sx={{ opacity: 0.7 }}>
          <HelpOutlineIcon fontSize="small" />
        </IconButton>
      </Stack>

      {/* Filter form */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack direction="row" spacing={2} flexWrap="wrap" alignItems="flex-end">
          <TextField
            label="Du" type="date" value={from}
            onChange={(e) => setFrom(e.target.value)}
            size="small" InputLabelProps={{ shrink: true }}
          />
          <TextField
            label="Au" type="date" value={to}
            onChange={(e) => setTo(e.target.value)}
            size="small" InputLabelProps={{ shrink: true }}
          />
          <Select
            value={siteId} onChange={(e) => setSiteId(e.target.value as number | "")}
            displayEmpty size="small" sx={{ minWidth: 160 }}
          >
            <MenuItem value="">Tous les sites</MenuItem>
            {(sitesQuery.data ?? []).map((s: any) => <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>)}
          </Select>
          <Select
            value={surgeonId} onChange={(e) => setSurgeonId(e.target.value as number | "")}
            displayEmpty size="small" sx={{ minWidth: 180 }}
          >
            <MenuItem value="">Tous les chirurgiens</MenuItem>
            {(surgeonsQuery.data ?? []).map((s) => <MenuItem key={s.id} value={s.id}>{surgeonName(s)}</MenuItem>)}
          </Select>
          <Button
            variant="outlined" onClick={() => previewMutation.mutate()}
            disabled={!from || !to || previewMutation.isPending}
          >
            {previewMutation.isPending ? <CircularProgress size={16} /> : "Prévisualiser"}
          </Button>
        </Stack>
      </Paper>

      {/* Summary stats */}
      {totalStats && (
        <Stack direction="row" spacing={2} flexWrap="wrap">
          {Object.entries(totalStats).map(([status, count]) => count > 0 && (
            <Chip
              key={status}
              icon={STATUS_CONFIG[status as CoverageStatus].icon as any}
              label={`${count} ${STATUS_CONFIG[status as CoverageStatus].label}`}
              sx={{ color: STATUS_CONFIG[status as CoverageStatus].color }}
              variant="outlined"
            />
          ))}
        </Stack>
      )}

      {/* Result: calendar + detail */}
      {grouped && (
        <Box sx={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 2, alignItems: "start" }}>
          {/* Calendar — list by date */}
          <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden", maxHeight: 500, overflowY: "auto" }}>
            <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50", borderBottom: "1px solid", borderColor: "divider" }}>
              <Typography variant="subtitle2" fontWeight={700}>Vue calendrier ({sortedDates.length} jours)</Typography>
            </Box>
            {sortedDates.map((date) => {
              const lines = grouped[date];
              const stats = dayStats(lines);
              const bg = dayBgColor(lines);
              const isSelected = selectedDate === date;
              return (
                <Box
                  key={date}
                  onClick={() => setSelectedDate(isSelected ? null : date)}
                  sx={{
                    px: 2, py: 1, cursor: "pointer", bgcolor: isSelected ? "primary.50" : bg,
                    borderBottom: "1px solid", borderColor: "divider",
                    "&:hover": { filter: "brightness(0.97)" },
                    transition: "background 0.15s",
                  }}
                >
                  <Stack direction="row" justifyContent="space-between" alignItems="center">
                    <Typography variant="body2" fontWeight={isSelected ? 700 : 500}>
                      {new Date(date + "T00:00:00").toLocaleDateString("fr-BE", { weekday: "short", day: "numeric", month: "short" })}
                    </Typography>
                    <Stack direction="row" spacing={0.5}>
                      {stats.COVERED > 0 && <Chip label={stats.COVERED} size="small" sx={{ height: 18, bgcolor: "success.100", color: "success.dark", fontSize: 11 }} />}
                      {stats.UNCOVERED > 0 && <Chip label={stats.UNCOVERED} size="small" sx={{ height: 18, bgcolor: "warning.100", color: "warning.dark", fontSize: 11 }} />}
                      {stats.MODIFIED > 0 && <Chip label={stats.MODIFIED} size="small" sx={{ height: 18, bgcolor: "info.100", color: "info.dark", fontSize: 11 }} />}
                      {stats.CONFLICT > 0 && <Chip label={stats.CONFLICT} size="small" sx={{ height: 18, bgcolor: "error.100", color: "error.dark", fontSize: 11 }} />}
                    </Stack>
                  </Stack>
                </Box>
              );
            })}
          </Paper>

          {/* Detail panel */}
          <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden", maxHeight: 500, overflowY: "auto" }}>
            <Box sx={{ px: 2, py: 1.5, bgcolor: "grey.50", borderBottom: "1px solid", borderColor: "divider" }}>
              <Typography variant="subtitle2" fontWeight={700}>
                {selectedDate
                  ? new Date(selectedDate + "T00:00:00").toLocaleDateString("fr-BE", { weekday: "long", day: "numeric", month: "long" })
                  : "Cliquez sur un jour"}
              </Typography>
            </Box>
            {selectedLines.length === 0 ? (
              <Box sx={{ p: 3, textAlign: "center", color: "text.secondary" }}>
                <Typography variant="body2">Sélectionnez un jour pour voir le détail</Typography>
              </Box>
            ) : (
              <Stack divider={<Box sx={{ borderBottom: "1px solid", borderColor: "divider" }} />}>
                {selectedLines.map((line, idx) => {
                  const cfg = STATUS_CONFIG[line.status];
                  return (
                    <Box key={idx} sx={{ px: 2, py: 1.5 }}>
                      <Stack direction="row" justifyContent="space-between" alignItems="flex-start">
                        <Box>
                          <Typography variant="body2" fontWeight={600}>{line.surgeonName}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            {line.startTime?.slice(0, 5)} – {line.endTime?.slice(0, 5)} · {line.siteName ?? "—"}
                          </Typography>
                          <Stack direction="row" spacing={0.5} mt={0.5}>
                            <Chip
                              label={line.missionType === "BLOCK" ? "Bloc" : "Consult."}
                              size="small"
                              color={line.missionType === "BLOCK" ? "primary" : "secondary"}
                              variant="outlined"
                            />
                            {line.instrumentistName && (
                              <Chip label={line.instrumentistName} size="small" variant="outlined" />
                            )}
                          </Stack>
                        </Box>
                        <Tooltip title={cfg.label}>
                          <Box sx={{ color: cfg.color, mt: 0.5 }}>{cfg.icon}</Box>
                        </Tooltip>
                      </Stack>
                    </Box>
                  );
                })}
              </Stack>
            )}
          </Paper>
        </Box>
      )}

      {/* Action buttons */}
      {previewLines && (
        <Stack direction="row" spacing={2}>
          <Button
            variant="contained" disableElevation
            onClick={() => generateMutation.mutate()}
            disabled={generateMutation.isPending}
          >
            {generateMutation.isPending ? <CircularProgress size={16} /> : "Générer le planning"}
          </Button>
          {generateResult && (
            <Button
              variant="outlined" color="success" startIcon={<SendIcon />}
              onClick={() => setDeployOpen(true)}
            >
              Déployer et envoyer les PDFs
            </Button>
          )}
        </Stack>
      )}

      {generateResult && (
        <Alert severity="success">
          Planning généré : <strong>{generateResult.created}</strong> mission(s) créée(s),{" "}
          <strong>{generateResult.updated}</strong> mise(s) à jour,{" "}
          <strong>{generateResult.skipped}</strong> ignorée(s).
        </Alert>
      )}

      {/* Tutorial dialog */}
      <Dialog open={tutorialOpen} onClose={() => setTutorialOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle fontWeight={700}>Comment générer un planning ?</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={2.5}>
            {[
              { n: 1, title: "Définissez la période", desc: "Choisissez les dates de début et de fin. Vous pouvez filtrer par site ou par chirurgien pour une génération partielle." },
              { n: 2, title: "Prévisualisez", desc: "SurgicalHub projette les créneaux des templates PAIR/IMPAIR sur la période et compare avec les missions existantes. Chaque ligne reçoit un statut : ✔ Couvert, ⚠ Non couvert, 🔄 Modifié, ❗ Conflit." },
              { n: 3, title: "Consultez la vue calendrier", desc: "Cliquez sur un jour coloré pour voir le détail des créneaux. Vert = tout couvert, orange = instrumentiste manquant, rouge = conflit." },
              { n: 4, title: "Générez", desc: "Cliquez sur \"Générer le planning\" pour créer/mettre à jour les missions dans le système. Les missions non couvertes sont créées sans instrumentiste (statut À résoudre)." },
              { n: 5, title: "Déployez", desc: "Une fois satisfait, déployez : les PDFs personnalisés sont générés et envoyés par email à chaque instrumentiste et chirurgien." },
            ].map(({ n, title, desc }) => (
              <Stack key={n} direction="row" spacing={2} alignItems="flex-start">
                <Box sx={{ minWidth: 32, height: 32, borderRadius: "50%", bgcolor: "primary.main", color: "white", display: "flex", alignItems: "center", justifyContent: "center", fontWeight: 700, fontSize: 14, flexShrink: 0 }}>
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
          <Button onClick={() => setTutorialOpen(false)} variant="contained" disableElevation>J'ai compris</Button>
        </DialogActions>
      </Dialog>

      {/* Deploy confirm dialog */}
      <Dialog open={deployOpen} onClose={() => setDeployOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle fontWeight={700}>Déployer le planning</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary">
            Cette action va générer un PDF personnalisé et envoyer un email à chaque instrumentiste et chirurgien concerné par la période <strong>{from}</strong> → <strong>{to}</strong>.
          </Typography>
          <Typography variant="body2" color="warning.main" sx={{ mt: 1 }}>
            Cette opération est irréversible.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeployOpen(false)} color="inherit">Annuler</Button>
          <Button
            variant="contained" color="success" disableElevation startIcon={<SendIcon />}
            onClick={() => deployMutation.mutate()}
            disabled={deployMutation.isPending}
          >
            {deployMutation.isPending ? <CircularProgress size={16} /> : "Confirmer le déploiement"}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
