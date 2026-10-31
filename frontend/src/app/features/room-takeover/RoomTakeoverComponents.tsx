import * as React from "react";
import {
  Alert, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle,
  Drawer, IconButton, Stack, TextField, Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getReleasedRoomHistory, liftMissionPriority } from "./roomTakeover.api";
import { describeHistoryEvent, formatDateTime, formatDeadline, OPERATIONAL_STATE_LABELS } from "./roomTakeoverFormat";
import type { RoomOperationalState } from "./roomTakeover.types";

/**
 * D-177 — état opérationnel d'une salle reprise, tel que décidé par le backend : libellé,
 * destinataire et échéance d'une priorité, alerte si la mission commence avant l'échéance.
 */
export function RoomOperationalStatus({ operational, instrumentistName }: { operational: RoomOperationalState; instrumentistName?: string | null }) {
  const p = operational.priority;

  return (
    <Box>
      <Typography component="span" sx={{ display: "block", fontSize: 13.5, fontWeight: 600 }} data-testid="room-operational-state">
        {OPERATIONAL_STATE_LABELS[operational.state] ?? operational.state}
      </Typography>
      {p && (
        <Typography component="span" sx={{ display: "block", fontSize: 12, color: "text.secondary" }}>
          {p.instrumentist?.name ?? "—"} · jusqu'au {formatDeadline(p.expiresAt)}
        </Typography>
      )}
      {operational.state === "INSTRUMENTIST_CONFIRMED" && instrumentistName && (
        <Typography component="span" sx={{ display: "block", fontSize: 12, color: "text.secondary" }}>{instrumentistName}</Typography>
      )}
      {p?.startsBeforeExpiry && (
        <Alert severity="warning" sx={{ mt: 0.75, py: 0, fontSize: 12 }} data-testid="room-priority-imminent">
          L'intervention commence avant la fin de la priorité.
        </Alert>
      )}
    </Box>
  );
}

/** D-177 (Cas D) — lever la priorité et ouvrir le pool, justification obligatoire. */
export function LiftPriorityDialog({
  missionId, recipientName, open, onClose, onLifted,
}: {
  missionId: number | null;
  recipientName?: string | null;
  open: boolean;
  onClose: () => void;
  onLifted?: () => void;
}) {
  const queryClient = useQueryClient();
  const [justification, setJustification] = React.useState("");
  React.useEffect(() => { if (open) setJustification(""); }, [open]);

  const mutation = useMutation({
    mutationFn: () => liftMissionPriority(missionId as number, justification.trim()),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ["planning-v2", "available-rooms"] }),
        queryClient.invalidateQueries({ queryKey: ["released-room-history"] }),
        queryClient.invalidateQueries({ queryKey: ["missions"] }),
      ]);
      onLifted?.();
      onClose();
    },
  });
  const error = (mutation.error as any)?.response?.data?.error?.message ?? (mutation.isError ? "La priorité n'a pas pu être levée." : null);

  return (
    <Dialog open={open} onClose={mutation.isPending ? undefined : onClose} fullWidth maxWidth="xs">
      <DialogTitle>Lever la priorité</DialogTitle>
      <DialogContent>
        <Stack spacing={1.5} sx={{ pt: 0.5 }}>
          {error && <Alert severity="error">{error}</Alert>}
          <Typography variant="body2">
            La proposition réservée à <strong>{recipientName ?? "l'instrumentiste habituelle"}</strong> prend fin et la mission
            est ouverte tout de suite au pool des instrumentistes éligibles.
          </Typography>
          <TextField
            label="Justification" size="small" multiline minRows={2} required autoFocus
            value={justification} onChange={(e) => setJustification(e.target.value)} disabled={mutation.isPending}
            inputProps={{ maxLength: 500, "data-testid": "lift-priority-justification" }}
          />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={mutation.isPending}>Annuler</Button>
        <Button variant="contained" color="warning" disabled={mutation.isPending || justification.trim() === "" || missionId === null} onClick={() => mutation.mutate()}>
          {mutation.isPending ? <CircularProgress size={18} aria-label="Enregistrement en cours" /> : "Lever et ouvrir le pool"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

/** D-177 — historique chronologique complet d'une salle libérée (manager). */
export function ReleasedRoomHistoryDrawer({ slotId, onClose }: { slotId: number | null; onClose: () => void }) {
  const query = useQuery({
    queryKey: ["released-room-history", slotId],
    queryFn: () => getReleasedRoomHistory(slotId as number),
    enabled: slotId !== null,
  });
  const history = query.data;

  return (
    <Drawer anchor="right" open={slotId !== null} onClose={onClose} PaperProps={{ sx: { width: { xs: "100%", sm: 480 } } }}>
      <Box sx={{ p: 2.5 }}>
        <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1.5 }}>
          <Typography variant="h6" sx={{ fontSize: 17, fontWeight: 700 }}>Historique de la salle</Typography>
          <IconButton aria-label="Fermer" onClick={onClose} size="small">×</IconButton>
        </Stack>

        {query.isLoading && <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}><CircularProgress size={24} /></Box>}
        {query.isError && <Alert severity="error">Impossible de charger l'historique.</Alert>}

        {history && (
          <>
            <Alert severity={history.current.state === "PRIORITY_PENDING" ? "info" : history.current.state === "POOL_OPEN" ? "warning" : "success"} sx={{ mb: 2 }}>
              <strong>{OPERATIONAL_STATE_LABELS[history.current.state] ?? history.current.state}</strong>
              {history.current.priority && (
                <> — réservée à {history.current.priority.instrumentist?.name ?? "—"} jusqu'au {formatDeadline(history.current.priority.expiresAt)}</>
              )}
              {history.current.nextAutomaticAction && (
                <Typography sx={{ fontSize: 12.5, mt: 0.5 }}>
                  Prochaine action automatique : ouverture au pool le {formatDeadline(history.current.nextAutomaticAction.at)} sans réponse d'ici là.
                </Typography>
              )}
            </Alert>

            <Stack component="ol" spacing={1.25} sx={{ listStyle: "none", m: 0, p: 0 }} data-testid="room-history-events">
              {history.events.map((e) => {
                const { title, detail } = describeHistoryEvent(e);
                return (
                  <Box component="li" key={e.key} sx={{ borderLeft: "2px solid", borderColor: "divider", pl: 1.5 }}>
                    <Typography sx={{ fontSize: 12, color: "text.secondary" }}>
                      {formatDateTime(e.at)}
                      {e.actor ? ` · ${e.actor.system ? "automatique" : e.actor.name}` : ""}
                      {e.missionId ? ` · mission #${e.missionId}` : ""}
                    </Typography>
                    <Typography sx={{ fontSize: 13.5, fontWeight: 600 }}>{title}</Typography>
                    {detail && <Typography sx={{ fontSize: 12.5, color: "text.secondary" }}>{detail}</Typography>}
                  </Box>
                );
              })}
            </Stack>
          </>
        )}
      </Box>
    </Drawer>
  );
}
