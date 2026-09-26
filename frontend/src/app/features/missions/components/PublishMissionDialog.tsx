import * as React from "react";
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
} from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import type { Mission } from "../api/missions.types";
import MissionDispatchFields from "../dispatch/MissionDispatchFields";
import {
  DEFAULT_DISPATCH_CHOICE,
  dispatchMission,
  invalidateOperationalPlanning,
  isDispatchChoiceComplete,
  type MissionDispatchChoice,
} from "../dispatch/missionDispatch.api";

function extractApiError(err: unknown): { status?: number; message: string } {
  const status = (err as any)?.response?.status as number | undefined;
  const data = (err as any)?.response?.data;

  const message =
    (typeof data === "string" && data) ||
    data?.error?.message ||
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

/**
 * D-125 — "Diffuser la mission": pool, demande nominative ou attribution directe, via the
 * shared MissionDispatchFields (same component as the creation wizard and the surgeon
 * request acceptance). The instrumentist is picked by name among the mission site's
 * instrumentists — never typed as an id.
 */
export default function PublishMissionDialog({
  open,
  onClose,
  mission,
}: Props) {
  const queryClient = useQueryClient();

  const [choice, setChoice] = React.useState<MissionDispatchChoice>(DEFAULT_DISPATCH_CHOICE);
  const [formError, setFormError] = React.useState<string | null>(null);

  React.useEffect(() => {
    if (!open) return;
    setChoice(DEFAULT_DISPATCH_CHOICE);
    setFormError(null);
  }, [open]);

  const mutation = useMutation({
    mutationFn: (c: MissionDispatchChoice) => dispatchMission(mission.id, c),
    onSuccess: async () => {
      await invalidateOperationalPlanning(queryClient, mission.id);
      onClose();
    },
    onError: (err) => {
      const { status, message } = extractApiError(err);

      if (status === 401)
        return setFormError("Session expirée. Veuillez vous reconnecter.");
      if (status === 403) return setFormError("Accès interdit.");
      if (status === 404) return setFormError("Mission ou instrumentiste introuvable.");
      // 409: ineligible instrumentist (reason from the backend), or the mission changed meanwhile.
      if (status === 409 || status === 422) return setFormError(message);

      setFormError(message || "Erreur serveur.");
    },
  });

  const slot = mission.site?.id
    ? { siteId: mission.site.id, startAt: mission.startAt, endAt: mission.endAt, missionId: mission.id }
    : null;

  return (
    <Dialog
      open={open}
      onClose={mutation.isPending ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Diffuser la mission #{mission.id}</DialogTitle>

      <DialogContent>
        <Stack spacing={2} mt={1}>
          {formError ? <Alert severity="error">{formError}</Alert> : null}

          <MissionDispatchFields
            value={choice}
            onChange={(c) => { setFormError(null); setChoice(c); }}
            slot={slot}
            disabled={mutation.isPending}
          />
        </Stack>
      </DialogContent>

      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={mutation.isPending}>
          Annuler
        </Button>
        <Button
          variant="contained"
          onClick={() => mutation.mutate(choice)}
          disabled={mutation.isPending || !isDispatchChoiceComplete(choice)}
        >
          {choice.mode === "DIRECT" ? "Attribuer" : choice.mode === "TARGETED" ? "Envoyer la demande" : "Proposer au pool"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
