import * as React from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  Typography,
} from "@mui/material";
import dayjs from "dayjs";
import "dayjs/locale/fr";

import { refuseMissionAssignment, requestMissionAssignmentReview } from "../api/missions.api";
import type { CurrentAssignment, Mission } from "../api/missions.types";
import { useToast } from "../../../ui/toast/useToast";

dayjs.locale("fr");

function errorMessage(err: unknown): string {
  const e = err as { response?: { data?: { error?: { message?: string } } }; message?: string };
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

function formatDateTime(iso: string | null | undefined): string {
  return iso ? dayjs(iso).format("dddd D MMMM YYYY [à] HH[h]mm") : "—";
}

/** D-151 — why a direct refusal is not offered: text only, the rule itself stays on the server. */
const BLOCKED_REASON_TEXT: Record<NonNullable<CurrentAssignment["refusalBlockedReason"]>, string> = {
  REFUSAL_WINDOW_EXPIRED: "Le délai de refus de 24 heures après l'acceptation est écoulé.",
  REFUSAL_REQUIRES_MANAGER: "La mission est imminente, commencée ou déjà en cours d'encodage : un refus passe par le manager.",
  ASSIGNMENT_NOT_REFUSABLE: "Cette affectation a été décidée par le manager ou le planning, elle n'a pas été acceptée par l'instrumentiste.",
  ASSIGNMENT_ALREADY_ENDED: "Cette affectation n'est plus en cours.",
};

type Props = { mission: Mission };

/**
 * D-151 — « Affectation » sur le détail de mission du chirurgien : qui a accepté, quand, jusqu'à
 * quand il peut refuser. Les boutons suivent strictement allowedActions (refuse_assignment /
 * request_assignment_review) : aucun délai n'est recalculé ici.
 */
export default function SurgeonAssignmentCard({ mission }: Props) {
  const assignment = mission.currentAssignment;
  const allowed = mission.allowedActions ?? [];
  const canRefuse = allowed.includes("refuse_assignment");
  const canRequestReview = allowed.includes("request_assignment_review");

  const queryClient = useQueryClient();
  const toast = useToast();
  const [confirmOpen, setConfirmOpen] = React.useState(false);

  const refresh = (updated: Mission) => {
    queryClient.setQueryData(["mission", mission.id], updated);
    queryClient.invalidateQueries({ queryKey: ["missions"] });
  };

  const refuse = useMutation({
    mutationFn: () => refuseMissionAssignment(mission.id, assignment!.id),
    onSuccess: (updated) => {
      setConfirmOpen(false);
      refresh(updated);
      toast.success("Instrumentiste refusé pour cette mission : elle est de nouveau proposée aux autres instrumentistes.");
    },
    onError: (err) => {
      setConfirmOpen(false);
      toast.error(errorMessage(err));
      queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
    },
  });

  const review = useMutation({
    mutationFn: () => requestMissionAssignmentReview(mission.id),
    onSuccess: (updated) => {
      refresh(updated);
      toast.success("Votre demande a été transmise aux managers.");
    },
    onError: (err) => toast.error(errorMessage(err)),
  });

  if (!assignment) return null;

  return (
    <Box data-testid="surgeon-assignment-card" sx={{ border: "1px solid", borderColor: "divider", borderRadius: 2, p: 2 }}>
      <Typography variant="subtitle2" sx={{ mb: 1 }}>
        Affectation
      </Typography>
      <Stack spacing={0.75}>
        <Typography variant="body2">
          <strong>{assignment.instrumentist.name}</strong>
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Prise en charge le {formatDateTime(assignment.assignedAt)}
        </Typography>
        {assignment.refusalDeadlineAt && (
          <Typography variant="body2" color="text.secondary">
            Refus possible jusqu'au {formatDateTime(assignment.refusalDeadlineAt)}
          </Typography>
        )}
        {!canRefuse && assignment.refusalBlockedReason && assignment.refusalBlockedReason !== "ASSIGNMENT_ALREADY_ENDED" && (
          <Typography variant="caption" color="text.secondary">
            {BLOCKED_REASON_TEXT[assignment.refusalBlockedReason]}
          </Typography>
        )}
        {assignment.reviewRequestedAt && (
          <Alert severity="info" sx={{ mt: 1 }}>
            Intervention du manager demandée le {formatDateTime(assignment.reviewRequestedAt)}.
          </Alert>
        )}
      </Stack>

      {(canRefuse || canRequestReview) && (
        <Stack spacing={1} sx={{ mt: 1.5 }}>
          {canRefuse && (
            <Button variant="outlined" color="error" onClick={() => setConfirmOpen(true)} disabled={refuse.isPending}>
              Refuser cet instrumentiste
            </Button>
          )}
          {canRequestReview && (
            <Button variant="outlined" onClick={() => review.mutate()} disabled={review.isPending}>
              Demander l'intervention du manager
            </Button>
          )}
        </Stack>
      )}

      <Dialog open={confirmOpen} onClose={() => !refuse.isPending && setConfirmOpen(false)}>
        <DialogTitle>Refuser pour cette mission</DialogTitle>
        <DialogContent dividers>
          <Stack spacing={1.5}>
            <Typography variant="body2">
              {assignment.instrumentist.name} ne sera plus affecté·e à cette mission, qui sera de nouveau proposée aux
              autres instrumentistes éligibles. {assignment.instrumentist.name} ne pourra pas la reprendre.
            </Typography>
            <Typography variant="body2" color="text.secondary">
              La mission n'est pas annulée. L'instrumentiste reçoit un message neutre, sans mention de votre refus.
            </Typography>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmOpen(false)} disabled={refuse.isPending}>
            Annuler
          </Button>
          <Button color="error" variant="contained" disableElevation onClick={() => refuse.mutate()} disabled={refuse.isPending}>
            Refuser pour cette mission
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
