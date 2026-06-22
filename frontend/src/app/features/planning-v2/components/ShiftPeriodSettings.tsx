import * as React from "react";
import {
  Alert, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, FormControl, IconButton, InputLabel, MenuItem, Select, Stack,
  TextField, Typography,
} from "@mui/material";
import AddIcon from "@mui/icons-material/Add";
import EditOutlinedIcon from "@mui/icons-material/EditOutlined";
import AccessTimeOutlinedIcon from "@mui/icons-material/AccessTimeOutlined";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { fetchSites } from "../../sites/api/sites.api";
import { getShiftPeriods, createShiftPeriod, updateShiftPeriod, deactivateShiftPeriod, extractErrorV2 } from "../api/planningV2.api";
import type { ShiftPeriod, ShiftPeriodConfigV2 } from "../api/planningV2.types";
import { useToast } from "../../../ui/toast/useToast";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

const PERIOD_LABELS: Record<ShiftPeriod, string> = {
  MATIN: "Matin",
  APRES_MIDI: "Après-midi",
  JOURNEE: "Journée",
};

export function ShiftPeriodSettings() {
  const toast = useToast();
  const qc = useQueryClient();

  const [dialogOpen, setDialogOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<ShiftPeriodConfigV2 | null>(null);
  const [siteId, setSiteId] = React.useState<number | "">("");
  const [period, setPeriod] = React.useState<ShiftPeriod>("MATIN");
  const [startTime, setStartTime] = React.useState("08:00");
  const [endTime, setEndTime] = React.useState("13:00");

  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });
  const periodsQuery = useQuery({ queryKey: ["planning-v2", "shift-periods"], queryFn: () => getShiftPeriods() });

  function invalidate() {
    qc.invalidateQueries({ queryKey: ["planning-v2", "shift-periods"] });
  }

  const createMutation = useMutation({
    mutationFn: () => createShiftPeriod({ siteId: siteId as number, period, startTime, endTime }),
    onSuccess: () => { toast.success("Période créée"); invalidate(); setDialogOpen(false); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const updateMutation = useMutation({
    mutationFn: () => updateShiftPeriod(editing!.id, { period, startTime, endTime }),
    onSuccess: () => { toast.success("Période mise à jour"); invalidate(); setDialogOpen(false); setEditing(null); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });
  const deactivateMutation = useMutation({
    mutationFn: (id: number) => deactivateShiftPeriod(id),
    onSuccess: () => { toast.success("Période désactivée"); invalidate(); setDialogOpen(false); setEditing(null); },
    onError: (err) => toast.error(extractErrorV2(err)),
  });

  function openCreate() {
    setEditing(null);
    setSiteId("");
    setPeriod("MATIN");
    setStartTime("08:00");
    setEndTime("13:00");
    setDialogOpen(true);
  }

  function openEdit(cfg: ShiftPeriodConfigV2) {
    setEditing(cfg);
    setSiteId(cfg.site.id);
    setPeriod(cfg.period);
    setStartTime(cfg.startTime);
    setEndTime(cfg.endTime);
    setDialogOpen(true);
  }

  const bySite = React.useMemo(() => {
    const map = new Map<number, { name: string; items: ShiftPeriodConfigV2[] }>();
    for (const cfg of periodsQuery.data?.items ?? []) {
      if (!cfg.active) continue;
      if (!map.has(cfg.site.id)) map.set(cfg.site.id, { name: cfg.site.name, items: [] });
      map.get(cfg.site.id)!.items.push(cfg);
    }
    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
  }, [periodsQuery.data]);

  return (
    <Box>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 2 }}>
        <Typography sx={{ fontSize: 14.5, fontWeight: 700 }}>Périodes horaires par site</Typography>
        <Button
          size="small" startIcon={<AddIcon sx={{ fontSize: 17 }} />} onClick={openCreate}
          sx={{ height: 34, px: 1.5, borderRadius: planningV2Radii.button, border: "1px solid #DDE2E8", color: planningV2Colors.textStrong, textTransform: "none", fontWeight: 600, fontSize: 12.5 }}
        >
          Ajouter
        </Button>
      </Stack>

      {periodsQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}><CircularProgress size={24} /></Box>
      ) : periodsQuery.isError ? (
        <Alert severity="error">{extractErrorV2(periodsQuery.error)}</Alert>
      ) : bySite.length === 0 ? (
        <Alert severity="info">Aucune période configurée.</Alert>
      ) : (
        <Stack spacing={2.25}>
          {bySite.map((group) => (
            <Box key={group.name} sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, overflow: "hidden", boxShadow: planningV2Shadows.card }}>
              <Stack direction="row" alignItems="center" spacing={1.25} sx={{ px: 2.25, py: 1.75, borderBottom: `1px solid ${planningV2Colors.divider}` }}>
                <Box sx={{ width: 30, height: 30, borderRadius: planningV2Radii.button, bgcolor: planningV2Colors.infoBg, display: "flex", alignItems: "center", justifyContent: "center" }}>
                  <AccessTimeOutlinedIcon sx={{ fontSize: 15, color: planningV2Colors.brand }} />
                </Box>
                <Typography sx={{ fontSize: 14.5, fontWeight: 700 }}>{group.name}</Typography>
              </Stack>
              {group.items.map((cfg, idx) => (
                <Stack
                  key={cfg.id} direction="row" alignItems="center" justifyContent="space-between"
                  sx={{ px: 2.25, py: 1.6, borderBottom: idx < group.items.length - 1 ? `1px solid ${planningV2Colors.divider}` : "none" }}
                >
                  <Box sx={{ fontSize: 12, fontWeight: 700, color: planningV2Colors.brand, bgcolor: planningV2Colors.infoBg, px: 1.1, py: 0.4, borderRadius: planningV2Radii.pill }}>
                    {PERIOD_LABELS[cfg.period]}
                  </Box>
                  <Stack direction="row" alignItems="center" spacing={1}>
                    <Typography sx={{ fontSize: 13.5, fontWeight: 600, fontVariantNumeric: "tabular-nums" }}>{cfg.startTime}–{cfg.endTime}</Typography>
                    <IconButton size="small" onClick={() => openEdit(cfg)} sx={{ color: planningV2Colors.textSecondary, "&:hover": { color: planningV2Colors.brand, bgcolor: "#F1F4F7" } }}>
                      <EditOutlinedIcon sx={{ fontSize: 15 }} />
                    </IconButton>
                  </Stack>
                </Stack>
              ))}
            </Box>
          ))}
        </Stack>
      )}

      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: planningV2Radii.modal } } }}>
        <DialogTitle>{editing ? "Modifier la période" : "Nouvelle période horaire"}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 0.5 }}>
            <FormControl fullWidth disabled={!!editing}>
              <InputLabel id="sp-site-label">Site</InputLabel>
              <Select labelId="sp-site-label" label="Site" value={siteId} onChange={(e) => setSiteId(e.target.value as number)}>
                {(sitesQuery.data ?? []).map((s) => (
                  <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>
                ))}
              </Select>
            </FormControl>
            <FormControl fullWidth>
              <InputLabel id="sp-period-label">Période</InputLabel>
              <Select labelId="sp-period-label" label="Période" value={period} onChange={(e) => setPeriod(e.target.value as ShiftPeriod)}>
                <MenuItem value="MATIN">Matin</MenuItem>
                <MenuItem value="APRES_MIDI">Après-midi</MenuItem>
                <MenuItem value="JOURNEE">Journée</MenuItem>
              </Select>
            </FormControl>
            <Stack direction="row" spacing={2}>
              <TextField label="Début" type="time" fullWidth value={startTime} onChange={(e) => setStartTime(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
              <TextField label="Fin" type="time" fullWidth value={endTime} onChange={(e) => setEndTime(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
            </Stack>
          </Stack>
        </DialogContent>
        <DialogActions>
          {editing && (
            <Button color="error" disabled={deactivateMutation.isPending} onClick={() => deactivateMutation.mutate(editing.id)} sx={{ mr: "auto", textTransform: "none" }}>
              Désactiver
            </Button>
          )}
          <Button onClick={() => setDialogOpen(false)}>Annuler</Button>
          <Button
            variant="contained" disableElevation
            disabled={siteId === "" || createMutation.isPending || updateMutation.isPending}
            onClick={() => (editing ? updateMutation.mutate() : createMutation.mutate())}
            sx={{ bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover } }}
          >
            {editing ? "Enregistrer" : "Créer"}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
