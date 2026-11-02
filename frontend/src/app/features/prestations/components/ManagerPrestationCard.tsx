import * as React from "react";
import {
  Box, Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle,
  Divider, Paper, Stack, TextField, Typography,
} from "@mui/material";
import MeetingRoomIcon from "@mui/icons-material/MeetingRoom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link as RouterLink } from "react-router-dom";

import { useToast } from "../../../ui/toast/useToast";
import { invalidateOperationalPlanning } from "../../missions/dispatch/missionDispatch.api";
import {
  detachPresence, fetchPrestation, formatImputation, formatMinutes, prestationError, updateRoomLabel,
} from "../api/prestations.api";

type Props = {
  missionId: number;
  roomLabel: string | null | undefined;
  /** La mission peut être libellée (non annulée / refusée) — décision serveur côté endpoint. */
  canEditRoomLabel: boolean;
};

/**
 * D-156 — fiche mission manager : libellé de salle facultatif, et pour une prestation
 * regroupée multi-salles, ses missions (toujours distinctes), la présence réelle commune,
 * les parts imputées et la correction contrôlée « détacher de la présence ».
 */
export function ManagerPrestationCard({ missionId, roomLabel, canEditRoomLabel }: Props) {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [label, setLabel] = React.useState(roomLabel ?? "");
  const [detachOpen, setDetachOpen] = React.useState(false);
  const [reason, setReason] = React.useState("");

  React.useEffect(() => setLabel(roomLabel ?? ""), [roomLabel]);

  const prestationQuery = useQuery({
    queryKey: ["prestation", missionId],
    queryFn: () => fetchPrestation(missionId),
  });
  // Shape guard: the card never breaks the mission page on an unexpected payload.
  const prestation = Array.isArray(prestationQuery.data?.missions) ? prestationQuery.data : undefined;
  const self = prestation?.missions.find((m) => m.id === missionId);

  const labelMutation = useMutation({
    mutationFn: () => updateRoomLabel(missionId, label.trim() === "" ? null : label.trim()),
    onSuccess: () => {
      toast.success("Libellé de salle enregistré");
      void invalidateOperationalPlanning(queryClient, missionId);
    },
    onError: (err) => toast.error(prestationError(err).message),
  });

  const detachMutation = useMutation({
    mutationFn: () => detachPresence(missionId, reason.trim()),
    onSuccess: () => {
      setDetachOpen(false);
      setReason("");
      toast.success("Mission détachée de la présence commune");
      void invalidateOperationalPlanning(queryClient, missionId);
    },
    onError: (err) => toast.error(prestationError(err).message),
  });

  const isGrouped = (prestation?.size ?? 1) > 1 || prestation?.presence != null;

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }} data-testid="manager-prestation-card">
      <Stack direction="row" spacing={1} alignItems="center" mb={1.5}>
        <MeetingRoomIcon fontSize="small" color="action" />
        <Typography variant="subtitle2" fontWeight={600}>
          {isGrouped ? `Salle et prestation multi-salles (${prestation?.size} missions)` : "Salle"}
        </Typography>
      </Stack>
      <Divider sx={{ mb: 1.5 }} />

      <Stack direction="row" spacing={1} alignItems="center">
        <TextField
          size="small"
          label="Libellé de salle (facultatif)"
          value={label}
          onChange={(e) => setLabel(e.target.value)}
          disabled={!canEditRoomLabel || labelMutation.isPending}
          inputProps={{ maxLength: 60, "data-testid": "room-label-input" }}
          sx={{ flex: 1 }}
        />
        {canEditRoomLabel && (
          <Button
            size="small"
            variant="outlined"
            disabled={labelMutation.isPending || label.trim() === (roomLabel ?? "")}
            onClick={() => labelMutation.mutate()}
          >
            Enregistrer
          </Button>
        )}
      </Stack>

      {isGrouped && prestation && (
        <Box sx={{ mt: 2 }}>
          <Typography variant="body2" color="text.secondary">
            Temps planifié global {formatMinutes(prestation.planned.unionMinutes)} · présence réelle{" "}
            {prestation.presence ? formatMinutes(prestation.presence.totalMinutes) : "non déclarée"}
          </Typography>
          <Stack component="ul" spacing={0.5} sx={{ listStyle: "none", p: 0, m: "8px 0 0" }}>
            {prestation.missions.map((m) => (
              <Box component="li" key={m.id} sx={{ fontSize: 14 }}>
                {m.id === missionId ? (
                  <strong>{m.label}</strong>
                ) : (
                  <RouterLink to={`/app/m/missions/${m.id}`}>{m.label}</RouterLink>
                )}
                {" · "}
                {formatImputation(m.imputedMinutes)}
              </Box>
            ))}
          </Stack>
          {self?.linkedToPresence && (
            <Button size="small" color="warning" sx={{ mt: 1.5 }} onClick={() => setDetachOpen(true)}>
              Détacher cette mission de la présence commune
            </Button>
          )}
        </Box>
      )}

      <Dialog open={detachOpen} onClose={detachMutation.isPending ? undefined : () => setDetachOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Détacher de la présence commune</DialogTitle>
        <DialogContent>
          <DialogContentText>
            Les heures communes restent celles de l'instrumentiste : rien n'est transféré ni effacé. Cette mission
            n'aura plus d'heures réelles, et les autres missions de la prestation seront réimputées. Correction historisée.
          </DialogContentText>
          <TextField
            label="Motif"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            fullWidth
            multiline
            minRows={2}
            sx={{ mt: 2 }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDetachOpen(false)} disabled={detachMutation.isPending}>Annuler</Button>
          <Button variant="contained" color="warning" disabled={detachMutation.isPending || reason.trim() === ""} onClick={() => detachMutation.mutate()}>
            Détacher
          </Button>
        </DialogActions>
      </Dialog>
    </Paper>
  );
}
