import * as React from "react";
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import type { Mission } from "../api/missions.types";
import {
  patchMissionService,
  type ServiceUpdateBody,
} from "../api/missions.api";

type Props = {
  open: boolean;
  onClose: () => void;
  mission: Mission;
};

function extractApiError(err: unknown): { status?: number; message: string } {
  const status = (err as any)?.response?.status as number | undefined;
  const data = (err as any)?.response?.data;

  const message =
    (typeof data === "string" && data) ||
    data?.message ||
    data?.detail ||
    "Une erreur est survenue";

  return { status, message };
}

function toNumberOrNull(value: string): number | null {
  const v = value.trim();
  if (!v) return null;
  const n = Number(v);
  if (!Number.isFinite(n)) return null;
  return n;
}

function formatHoursLabel(hours?: string | number | null): string {
  if (hours === null || hours === undefined || hours === "") return "—";
  const n = typeof hours === "string" ? Number(hours) : hours;
  if (!Number.isFinite(n)) return "—";
  return `${n} h`;
}

export default function EditServiceHoursDialog({
  open,
  onClose,
  mission,
}: Props) {
  const queryClient = useQueryClient();

  const currentHours = mission.service?.hours ?? null;

  const [hoursStr, setHoursStr] = React.useState<string>("");
  const [formError, setFormError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!open) return;
    const initial =
      currentHours === null || currentHours === undefined
        ? ""
        : String(currentHours);
    setHoursStr(initial);
    setFormError(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, mission.id]);

  const mutation = useMutation({
    mutationFn: async (body: ServiceUpdateBody) =>
      patchMissionService(mission.id, body),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ["mission", mission.id],
      });
      onClose();
    },
    onError: (err) => {
      const { status, message } = extractApiError(err);
      if (status === 401)
        return setFormError("Session expirée. Veuillez vous reconnecter.");
      if (status === 403) return setFormError("Accès interdit.");
      if (status === 404) return setFormError("Mission introuvable.");
      if (status === 409)
        return setFormError("Conflit. Recharge puis réessaye.");
      if (status === 422)
        return setFormError(
          message || "Données invalides. Corrige le formulaire.",
        );
      setFormError(message || "Erreur serveur.");
    },
  });

  function handleSubmit() {
    setFormError(null);

    const parsed = toNumberOrNull(hoursStr);

    // Backend: hours float|null >= 0
    if (parsed === null) {
      setFormError("Veuillez renseigner un nombre d’heures valide.");
      return;
    }
    if (parsed < 0) {
      setFormError("Le nombre d’heures doit être ≥ 0.");
      return;
    }

    mutation.mutate({
      hours: parsed,
      hoursSource: "INSTRUMENTIST",
    });
  }

  return (
    <Dialog
      open={open}
      onClose={mutation.isPending ? undefined : onClose}
      fullWidth
      maxWidth="xs"
    >
      <DialogTitle>Heures prestées — Mission #{mission.id}</DialogTitle>

      <DialogContent>
        <Stack spacing={2} mt={1}>
          {formError ? <Alert severity="error">{formError}</Alert> : null}

          <Stack spacing={0.5}>
            <Typography variant="body2" color="text.secondary">
              Valeur actuelle
            </Typography>
            <Typography>{formatHoursLabel(currentHours)}</Typography>
          </Stack>

          <TextField
            label="Heures prestées"
            type="number"
            value={hoursStr}
            onChange={(e) => setHoursStr(e.target.value)}
            disabled={mutation.isPending}
            inputProps={{ min: 0, step: 0.25 }}
            helperText="Ex: 5.5 (heures)."
            fullWidth
            required
          />
        </Stack>
      </DialogContent>

      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={mutation.isPending}>
          Annuler
        </Button>
        <Button
          variant="contained"
          onClick={handleSubmit}
          disabled={mutation.isPending}
        >
          Enregistrer
        </Button>
      </DialogActions>
    </Dialog>
  );
}
