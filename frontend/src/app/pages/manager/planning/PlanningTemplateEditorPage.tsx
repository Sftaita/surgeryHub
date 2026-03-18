import * as React from "react";
import {
  Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, Divider, IconButton, MenuItem, Paper, Select, Stack,
  TextField, Tooltip, Typography,
} from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import AddIcon from "@mui/icons-material/Add";
import DeleteIcon from "@mui/icons-material/Delete";
import { useParams, useNavigate } from "react-router-dom";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import {
  getTemplate, addSlot, deleteSlot,
  DAY_LABELS,
  type PlanningSlot,
} from "../../../features/planning-manager/api/planning.api";
import { useToast } from "../../../ui/toast/useToast";
import { apiClient } from "../../../api/apiClient";

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

function formatTime(t: string) { return t?.slice(0, 5) ?? ""; }

const DAYS = [1, 2, 3, 4, 5, 6, 7];
const PERIODS = [
  { value: "AM", label: "Matin", timeHint: "07:00 – 12:00" },
  { value: "PM", label: "Après-midi", timeHint: "12:00 – 18:00" },
];

interface SlotForm {
  surgeonId: string;
  instrumentistId: string;
  missionType: "BLOCK" | "CONSULTATION";
  startTime: string;
  endTime: string;
}

export default function PlanningTemplateEditorPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const qc = useQueryClient();

  const [addOpen, setAddOpen] = React.useState(false);
  const [addDay, setAddDay] = React.useState(1);
  const [addPeriod, setAddPeriod] = React.useState<"AM" | "PM">("AM");
  const [form, setForm] = React.useState<SlotForm>({
    surgeonId: "", instrumentistId: "", missionType: "BLOCK",
    startTime: "08:00", endTime: "12:00",
  });

  const templateQuery = useQuery({
    queryKey: ["planning-template", Number(id)],
    queryFn: () => getTemplate(Number(id)),
    enabled: !!id,
  });

  const surgeonsQuery = useQuery({
    queryKey: ["surgeons-list"],
    queryFn: async () => {
      const res = await apiClient.get("/api/surgeons");
      return res.data.items as { id: number; firstname: string; lastname: string; email: string }[];
    },
  });

  const instrumentistsQuery = useQuery({
    queryKey: ["instrumentists-list"],
    queryFn: async () => {
      const res = await apiClient.get("/api/instrumentists");
      return res.data.items as { id: number; firstname: string; lastname: string; email: string }[];
    },
  });

  const addMutation = useMutation({
    mutationFn: () => addSlot(Number(id), {
      dayOfWeek: addDay,
      period: addPeriod,
      startTime: form.startTime + ":00",
      endTime: form.endTime + ":00",
      surgeonId: Number(form.surgeonId),
      missionType: form.missionType,
      instrumentistId: form.instrumentistId ? Number(form.instrumentistId) : null,
    }),
    onSuccess: () => {
      toast.success("Créneau ajouté");
      qc.invalidateQueries({ queryKey: ["planning-template", Number(id)] });
      setAddOpen(false);
      setForm({ surgeonId: "", instrumentistId: "", missionType: "BLOCK", startTime: "08:00", endTime: "12:00" });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (slotId: number) => deleteSlot(Number(id), slotId),
    onSuccess: () => { toast.success("Créneau supprimé"); qc.invalidateQueries({ queryKey: ["planning-template", Number(id)] }); },
    onError: (err) => toast.error(extractError(err)),
  });

  const tpl = templateQuery.data;

  // Group slots by dayOfWeek × period
  const slotGrid: Record<number, Record<string, PlanningSlot[]>> = {};
  for (const d of DAYS) {
    slotGrid[d] = { AM: [], PM: [] };
  }
  (tpl?.slots ?? []).forEach((s) => {
    slotGrid[s.dayOfWeek]?.[s.period]?.push(s);
  });

  function openAdd(day: number, period: "AM" | "PM") {
    setAddDay(day);
    setAddPeriod(period);
    setAddOpen(true);
  }

  function surgeonName(u: any) {
    return `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim() || u.email;
  }

  if (templateQuery.isLoading) return <CircularProgress />;
  if (!tpl) return <Typography>Template introuvable</Typography>;

  return (
    <Stack spacing={3}>
      {/* Header */}
      <Stack direction="row" alignItems="center" spacing={1}>
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate("/app/m/planning/templates")} size="small">
          Retour
        </Button>
        <Typography variant="h6" fontWeight={700} sx={{ flex: 1 }}>
          Template {tpl.type === "PAIR" ? "semaines paires" : "semaines impaires"}
        </Typography>
        <Chip label={`Depuis le ${tpl.dateStart}`} size="small" variant="outlined" />
        {tpl.dateEnd && <Chip label={`Jusqu'au ${tpl.dateEnd}`} size="small" color="warning" variant="outlined" />}
      </Stack>

      {/* Grid */}
      <Box sx={{ overflowX: "auto" }}>
        <Box sx={{ display: "grid", gridTemplateColumns: "80px repeat(7, 1fr)", gap: 1, minWidth: 900 }}>
          {/* Header row */}
          <Box />
          {DAYS.map((d) => (
            <Box key={d} sx={{ textAlign: "center", py: 0.5 }}>
              <Typography variant="caption" fontWeight={700} color="text.secondary" textTransform="uppercase">
                {DAY_LABELS[d]}
              </Typography>
            </Box>
          ))}

          {/* AM / PM rows */}
          {PERIODS.map((period) => (
            <React.Fragment key={period.value}>
              {/* Period label */}
              <Box sx={{ display: "flex", flexDirection: "column", justifyContent: "flex-start", pt: 1 }}>
                <Typography variant="caption" fontWeight={700} color="primary">
                  {period.label}
                </Typography>
                <Typography variant="caption" color="text.disabled" fontSize={10}>
                  {period.timeHint}
                </Typography>
              </Box>

              {/* Day cells */}
              {DAYS.map((d) => {
                const slots = slotGrid[d][period.value];
                return (
                  <Paper
                    key={d}
                    variant="outlined"
                    sx={{
                      p: 0.75, borderRadius: 1.5, minHeight: 80,
                      bgcolor: slots.length > 0 ? "grey.50" : "background.paper",
                    }}
                  >
                    <Stack spacing={0.75}>
                      {slots.map((slot) => (
                        <Box
                          key={slot.id}
                          sx={{
                            bgcolor: slot.missionType === "BLOCK" ? "primary.50" : "secondary.50",
                            border: "1px solid",
                            borderColor: slot.missionType === "BLOCK" ? "primary.200" : "secondary.200",
                            borderRadius: 1, p: 0.75, position: "relative",
                          }}
                        >
                          <IconButton
                            size="small" color="error"
                            sx={{ position: "absolute", top: 2, right: 2, p: 0.25 }}
                            onClick={() => deleteMutation.mutate(slot.id)}
                          >
                            <DeleteIcon sx={{ fontSize: 14 }} />
                          </IconButton>
                          <Typography variant="caption" fontWeight={700} display="block" pr={2}>
                            {surgeonName(slot.surgeon)}
                          </Typography>
                          <Typography variant="caption" color="text.secondary" display="block">
                            {formatTime(slot.startTime)} – {formatTime(slot.endTime)}
                          </Typography>
                          <Stack direction="row" spacing={0.5} mt={0.25} flexWrap="wrap">
                            <Chip
                              label={slot.missionType === "BLOCK" ? "Bloc" : "Consult."}
                              size="small"
                              color={slot.missionType === "BLOCK" ? "primary" : "secondary"}
                              sx={{ height: 16, fontSize: 10 }}
                            />
                            {slot.instrumentist && (
                              <Chip
                                label={surgeonName(slot.instrumentist)}
                                size="small" variant="outlined"
                                sx={{ height: 16, fontSize: 10 }}
                              />
                            )}
                          </Stack>
                        </Box>
                      ))}
                      <Button
                        size="small" startIcon={<AddIcon />}
                        onClick={() => openAdd(d, period.value as "AM" | "PM")}
                        sx={{ fontSize: 11, py: 0.25 }}
                      >
                        Ajouter
                      </Button>
                    </Stack>
                  </Paper>
                );
              })}
            </React.Fragment>
          ))}
        </Box>
      </Box>

      {/* Add slot dialog */}
      <Dialog open={addOpen} onClose={() => setAddOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle fontWeight={700}>
          Ajouter un créneau — {DAY_LABELS[addDay]} {addPeriod === "AM" ? "Matin" : "Après-midi"}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <Select
              value={form.surgeonId}
              onChange={(e) => setForm((f) => ({ ...f, surgeonId: e.target.value }))}
              displayEmpty size="small" fullWidth
            >
              <MenuItem value="" disabled>Sélectionner un chirurgien</MenuItem>
              {(surgeonsQuery.data ?? []).map((s) => (
                <MenuItem key={s.id} value={s.id}>{surgeonName(s)}</MenuItem>
              ))}
            </Select>
            <Select
              value={form.missionType}
              onChange={(e) => setForm((f) => ({ ...f, missionType: e.target.value as "BLOCK" | "CONSULTATION" }))}
              size="small" fullWidth
            >
              <MenuItem value="BLOCK">Bloc opératoire</MenuItem>
              <MenuItem value="CONSULTATION">Consultation</MenuItem>
            </Select>
            <Stack direction="row" spacing={1}>
              <TextField
                label="Début" type="time" value={form.startTime}
                onChange={(e) => setForm((f) => ({ ...f, startTime: e.target.value }))}
                size="small" InputLabelProps={{ shrink: true }} fullWidth
              />
              <TextField
                label="Fin" type="time" value={form.endTime}
                onChange={(e) => setForm((f) => ({ ...f, endTime: e.target.value }))}
                size="small" InputLabelProps={{ shrink: true }} fullWidth
              />
            </Stack>
            <Select
              value={form.instrumentistId}
              onChange={(e) => setForm((f) => ({ ...f, instrumentistId: e.target.value }))}
              displayEmpty size="small" fullWidth
            >
              <MenuItem value="">Instrumentiste par défaut (aucun)</MenuItem>
              {(instrumentistsQuery.data ?? []).map((i) => (
                <MenuItem key={i.id} value={i.id}>{surgeonName(i)}</MenuItem>
              ))}
            </Select>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setAddOpen(false)} color="inherit">Annuler</Button>
          <Button
            variant="contained" disableElevation
            onClick={() => addMutation.mutate()}
            disabled={!form.surgeonId || addMutation.isPending}
          >
            {addMutation.isPending ? <CircularProgress size={16} /> : "Ajouter"}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
