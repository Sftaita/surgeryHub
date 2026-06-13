import * as React from "react";
import {
  Alert,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  Typography,
} from "@mui/material";
import { DateTimePicker } from "@mui/x-date-pickers/DateTimePicker";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import dayjs, { type Dayjs } from "dayjs";

import type { Mission } from "../api/missions.types";
import { patchMissionService, type ServiceUpdateBody } from "../api/missions.api";

type Props = {
  open: boolean;
  onClose: () => void;
  mission: Mission;
};

function extractApiError(err: unknown): { status?: number; message: string } {
  const status = (err as any)?.response?.status as number | undefined;
  const data   = (err as any)?.response?.data;
  const message =
    (typeof data === "string" && data) || data?.message || data?.detail || "Une erreur est survenue";
  return { status, message };
}

function formatDuration(hours: number): string {
  if (!Number.isFinite(hours) || hours < 0) return "—";
  const h = Math.floor(hours);
  const m = Math.round((hours - h) * 60);
  return m === 0 ? `${h}h` : `${h}h${String(m).padStart(2, "0")}`;
}

// Force mobile dialog on any screen size (consistent with DeclareMissionPage)
const FORCE_MOBILE = "@media (min-width: 999999px)";

export default function EditServiceHoursDialog({ open, onClose, mission }: Props) {
  const queryClient = useQueryClient();

  const [serviceStart, setServiceStart] = React.useState<Dayjs | null>(null);
  const [serviceEnd,   setServiceEnd]   = React.useState<Dayjs | null>(null);
  const [startOpen,    setStartOpen]    = React.useState(false);
  const [endOpen,      setEndOpen]      = React.useState(false);
  const [formError,    setFormError]    = React.useState<string | null>(null);

  // Initialize from mission planned times on open
  React.useEffect(() => {
    if (!open) return;
    setServiceStart(mission.startAt ? dayjs(mission.startAt) : null);
    setServiceEnd(mission.endAt ? dayjs(mission.endAt) : null);
    setFormError(null);
  }, [open, mission.id]);

  const diffHours =
    serviceStart && serviceEnd && serviceEnd.isAfter(serviceStart)
      ? serviceEnd.diff(serviceStart, "minute") / 60
      : null;

  const hasOrderError = serviceStart && serviceEnd && !serviceEnd.isAfter(serviceStart);

  const mutation = useMutation({
    mutationFn: (body: ServiceUpdateBody) => patchMissionService(mission.id, body),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
      onClose();
    },
    onError: (err) => {
      const { status, message } = extractApiError(err);
      if (status === 401) return setFormError("Session expirée. Veuillez vous reconnecter.");
      if (status === 403) return setFormError("Accès interdit.");
      if (status === 404) return setFormError("Mission introuvable.");
      if (status === 422) return setFormError(message || "Données invalides.");
      setFormError(message || "Erreur serveur.");
    },
  });

  function handleSubmit() {
    setFormError(null);
    if (!serviceStart || !serviceEnd) {
      setFormError("Veuillez renseigner le début et la fin de prestation.");
      return;
    }
    if (!serviceEnd.isAfter(serviceStart)) {
      setFormError("La fin doit être après le début.");
      return;
    }
    const hours = Math.round((serviceEnd.diff(serviceStart, "minute") / 60) * 4) / 4; // round to 0.25h
    mutation.mutate({ hours, hoursSource: "INSTRUMENTIST" });
  }

  return (
    <Dialog open={open} onClose={mutation.isPending ? undefined : onClose} fullWidth maxWidth="xs">
      <DialogTitle>Heures prestées — Mission #{mission.id}</DialogTitle>

      <DialogContent>
        <Stack spacing={2.5} sx={{ mt: 1 }}>
          {formError && <Alert severity="error">{formError}</Alert>}

          <DateTimePicker
            label="Début de prestation"
            value={serviceStart}
            onChange={(v) => {
              setServiceStart(v);
              if (v && serviceEnd) {
                // Sync end date to same day if end time was on the old day
                const synced = v.hour(serviceEnd.hour()).minute(serviceEnd.minute()).second(0).millisecond(0);
                setServiceEnd(synced.isAfter(v) ? synced : v.add(1, "hour"));
              }
            }}
            open={startOpen}
            onOpen={() => setStartOpen(true)}
            onClose={() => setStartOpen(false)}
            disabled={mutation.isPending}
            desktopModeMediaQuery={FORCE_MOBILE}
            slotProps={{
              dialog: { fullScreen: true },
              textField: {
                fullWidth: true,
                size: "small",
                onClick: () => setStartOpen(true),
                inputProps: { readOnly: true },
              },
            }}
          />

          <DateTimePicker
            label="Fin de prestation"
            value={serviceEnd}
            onChange={(v) => setServiceEnd(v)}
            open={endOpen}
            onOpen={() => setEndOpen(true)}
            onClose={() => setEndOpen(false)}
            disabled={mutation.isPending}
            desktopModeMediaQuery={FORCE_MOBILE}
            slotProps={{
              dialog: { fullScreen: true },
              textField: {
                fullWidth: true,
                size: "small",
                onClick: () => setEndOpen(true),
                inputProps: { readOnly: true },
                error: !!hasOrderError,
                helperText: hasOrderError ? "La fin doit être après le début." : " ",
              },
            }}
          />

          {/* Computed duration */}
          <Stack direction="row" justifyContent="space-between" alignItems="center"
            sx={{ bgcolor: "grey.50", borderRadius: 1.5, px: 2, py: 1.25 }}>
            <Typography variant="body2" color="text.secondary">Durée calculée</Typography>
            <Typography variant="body2" fontWeight={700} color={diffHours ? "primary.main" : "text.disabled"}>
              {diffHours !== null ? formatDuration(diffHours) : "—"}
            </Typography>
          </Stack>
        </Stack>
      </DialogContent>

      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={mutation.isPending}>Annuler</Button>
        <Button variant="contained" onClick={handleSubmit} disabled={mutation.isPending}>
          {mutation.isPending ? <CircularProgress size={16} /> : "Enregistrer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
