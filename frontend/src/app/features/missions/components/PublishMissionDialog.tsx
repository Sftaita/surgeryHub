import * as React from "react";
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormHelperText,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import type { Mission } from "../api/missions.types";
import { publishMission } from "../api/missions.api";
import type {
  PublishMissionBody,
  PublishScope,
} from "../api/missions.requests";

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

type Props = {
  open: boolean;
  onClose: () => void;
  mission: Mission;
};

function scopeLabel(scope: PublishScope) {
  if (scope === "POOL") return "Publier dans le pool (visible à tous)";
  return "Publier ciblé (vers un utilisateur)";
}

export default function PublishMissionDialog({
  open,
  onClose,
  mission,
}: Props) {
  const queryClient = useQueryClient();

  const [scope, setScope] = React.useState<PublishScope>("POOL");
  const [targetUserId, setTargetUserId] = React.useState<string>("");
  const [formError, setFormError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!open) return;
    setScope("POOL");
    setTargetUserId("");
    setFormError(null);
  }, [open]);

  // Quand on change de scope, on reset le champ ciblage pour éviter un état incohérent
  React.useEffect(() => {
    setFormError(null);
    if (scope === "POOL") setTargetUserId("");
  }, [scope]);

  const mutation = useMutation({
    mutationFn: async (body: PublishMissionBody) =>
      publishMission(mission.id, body),
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
        return setFormError(
          "Conflit : la mission a peut-être déjà été publiée, ou a été modifiée entre-temps."
        );
      if (status === 422) return setFormError(message || "Données invalides.");

      setFormError(message || "Erreur serveur.");
    },
  });

  const trimmedTarget = targetUserId.trim();
  const parsedTargetUserId = Number(trimmedTarget);
  const targetedIdValid =
    scope === "TARGETED" &&
    trimmedTarget.length > 0 &&
    Number.isFinite(parsedTargetUserId) &&
    parsedTargetUserId > 0;

  function handleSubmit() {
    setFormError(null);

    if (scope === "POOL") {
      mutation.mutate({ scope: "POOL" });
      return;
    }

    if (!targetedIdValid) {
      setFormError("Veuillez renseigner un Target User ID valide.");
      return;
    }

    mutation.mutate({ scope: "TARGETED", targetUserId: parsedTargetUserId });
  }

  const submitDisabled =
    mutation.isPending || (scope === "TARGETED" && !targetedIdValid);

  return (
    <Dialog
      open={open}
      onClose={mutation.isPending ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Publier la mission #{mission.id}</DialogTitle>

      <DialogContent>
        <Stack spacing={2} mt={1}>
          {formError ? <Alert severity="error">{formError}</Alert> : null}

          <Typography variant="body2" color="text.secondary">
            La publication rend la mission disponible selon le scope choisi.
          </Typography>

          <FormControl fullWidth disabled={mutation.isPending}>
            <InputLabel id="scope-label">Scope</InputLabel>
            <Select
              labelId="scope-label"
              label="Scope"
              value={scope}
              onChange={(e) => setScope(e.target.value as PublishScope)}
            >
              <MenuItem value="POOL">{scopeLabel("POOL")}</MenuItem>
              <MenuItem value="TARGETED">{scopeLabel("TARGETED")}</MenuItem>
            </Select>
            <FormHelperText>
              {scope === "POOL"
                ? "La mission sera visible dans la liste globale."
                : "La mission sera publiée pour un utilisateur spécifique."}
            </FormHelperText>
          </FormControl>

          {scope === "TARGETED" ? (
            <TextField
              label="Target User ID"
              value={targetUserId}
              onChange={(e) => setTargetUserId(e.target.value)}
              fullWidth
              required
              inputMode="numeric"
              disabled={mutation.isPending}
              helperText="Identifiant utilisateur (numérique) du destinataire."
            />
          ) : null}
        </Stack>
      </DialogContent>

      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={mutation.isPending}>
          Annuler
        </Button>
        <Button
          variant="contained"
          onClick={handleSubmit}
          disabled={submitDisabled}
        >
          Publier
        </Button>
      </DialogActions>
    </Dialog>
  );
}
