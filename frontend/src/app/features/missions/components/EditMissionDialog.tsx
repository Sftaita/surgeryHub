import { useEffect, useMemo, useState } from "react";
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import type { UpdateMissionRequest } from "../api/missions.requests";
import type {
  Mission,
  MissionType,
  SchedulePrecision,
} from "../api/missions.types";

type Props = {
  open: boolean;
  onClose: () => void;
  mission: Mission;
  onSubmit: (payload: UpdateMissionRequest) => void;
  isSubmitting?: boolean;
  errorMessage?: string | null;
};

function isIsoWithOffset(value: string): boolean {
  // Validation "souple" (on ne fait pas de parsing strict côté FE)
  // Exemples attendus: 2026-01-05T08:00:00+01:00, 2026-01-05T08:00:00Z
  return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(
    value
  );
}

export default function EditMissionDialog({
  open,
  onClose,
  mission,
  onSubmit,
  isSubmitting = false,
  errorMessage = null,
}: Props) {
  const initial = useMemo<UpdateMissionRequest>(
    () => ({
      startAt: mission.startAt,
      endAt: mission.endAt,
      schedulePrecision: mission.schedulePrecision as SchedulePrecision,
      type: mission.type as MissionType,
      siteId: mission.site?.id ?? mission.siteId ?? 0,
    }),
    [mission]
  );

  const [form, setForm] = useState<UpdateMissionRequest>(initial);
  const [localError, setLocalError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setForm(initial);
      setLocalError(null);
    }
  }, [open, initial]);

  const update = <K extends keyof UpdateMissionRequest>(
    key: K,
    value: UpdateMissionRequest[K]
  ) => {
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  const validate = (): boolean => {
    if (!form.siteId || Number.isNaN(Number(form.siteId))) {
      setLocalError("Le site est requis (siteId).");
      return false;
    }
    if (!form.startAt || !isIsoWithOffset(form.startAt)) {
      setLocalError(
        "startAt doit être un ISO 8601 avec timezone (ex: 2026-01-05T08:00:00+01:00)."
      );
      return false;
    }
    if (!form.endAt || !isIsoWithOffset(form.endAt)) {
      setLocalError(
        "endAt doit être un ISO 8601 avec timezone (ex: 2026-01-05T13:00:00+01:00)."
      );
      return false;
    }
    if (!form.schedulePrecision) {
      setLocalError("schedulePrecision est requis.");
      return false;
    }
    if (!form.type) {
      setLocalError("type est requis.");
      return false;
    }
    setLocalError(null);
    return true;
  };

  const handleSubmit = () => {
    if (!validate()) return;
    onSubmit({
      ...form,
      siteId: Number(form.siteId),
    });
  };

  return (
    <Dialog
      open={open}
      onClose={isSubmitting ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Éditer la mission</DialogTitle>
      <Divider />
      <DialogContent>
        <Stack spacing={2} sx={{ mt: 2 }}>
          <Typography variant="body2" color="text.secondary">
            Saisir des dates au format ISO 8601 avec timezone (ex:
            2026-01-05T08:00:00+01:00). Aucun champ patient ni financier n’est
            géré ici.
          </Typography>

          {(localError || errorMessage) && (
            <Alert severity="error">{localError ?? errorMessage}</Alert>
          )}

          <TextField
            label="startAt (ISO + timezone)"
            value={form.startAt}
            onChange={(e) => update("startAt", e.target.value)}
            disabled={isSubmitting}
            fullWidth
            placeholder="2026-01-05T08:00:00+01:00"
          />

          <TextField
            label="endAt (ISO + timezone)"
            value={form.endAt}
            onChange={(e) => update("endAt", e.target.value)}
            disabled={isSubmitting}
            fullWidth
            placeholder="2026-01-05T13:00:00+01:00"
          />

          <Box sx={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 2 }}>
            <TextField
              label="schedulePrecision"
              value={form.schedulePrecision}
              onChange={(e) =>
                update("schedulePrecision", e.target.value as SchedulePrecision)
              }
              disabled={isSubmitting}
              fullWidth
              select
              SelectProps={{ native: true }}
            >
              <option value="APPROXIMATE">APPROXIMATE</option>
              <option value="EXACT">EXACT</option>
            </TextField>

            <TextField
              label="type"
              value={form.type}
              onChange={(e) => update("type", e.target.value as MissionType)}
              disabled={isSubmitting}
              fullWidth
              select
              SelectProps={{ native: true }}
            >
              <option value="BLOCK">BLOCK</option>
              <option value="CONSULTATION">CONSULTATION</option>
            </TextField>
          </Box>

          <TextField
            label="siteId"
            value={form.siteId}
            onChange={(e) => update("siteId", Number(e.target.value) as any)}
            disabled={isSubmitting}
            fullWidth
            type="number"
            inputProps={{ min: 1 }}
          />
        </Stack>
      </DialogContent>

      <DialogActions sx={{ p: 2 }}>
        <Button onClick={onClose} disabled={isSubmitting}>
          Annuler
        </Button>
        <Button
          variant="contained"
          onClick={handleSubmit}
          disabled={isSubmitting}
        >
          Enregistrer
        </Button>
      </DialogActions>
    </Dialog>
  );
}
