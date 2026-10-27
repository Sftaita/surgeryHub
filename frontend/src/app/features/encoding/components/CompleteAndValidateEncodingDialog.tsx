import * as React from "react";
import { useMutation } from "@tanstack/react-query";
import { Alert, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, TextField, Typography } from "@mui/material";

import { completeAndValidateMissionEncoding } from "../api/encoding.api";
import { encodingIncompleteText } from "../api/encodingIncomplete";

type Props = {
  open: boolean;
  missionId: number;
  /** Aucune instrumentiste : rien n'est dit des heures (D-155). */
  withoutInstrumentist: boolean;
  onClose: () => void;
  onDone: () => void | Promise<void>;
};

function errorText(err: any): string {
  if (err?.response?.status === 403) return "Action impossible pour cette mission (statut ou droits modifiés entre-temps).";
  return encodingIncompleteText(err)
    ?? err?.response?.data?.error?.message
    ?? err?.response?.data?.message
    ?? err?.response?.data?.detail
    ?? "Erreur lors de la validation de l'encodage.";
}

/**
 * D-171 — « Terminer et valider » (manager) : POST .../encoding/complete-and-validate.
 * Proposé seulement si allowedActions contient `complete_and_validate` ; tout contrôle
 * (heures, contenu, justification d'un bloc sans matériel) est fait par le backend et son
 * refus est affiché tel quel dans la fenêtre, rien n'est recalculé ici.
 */
export default function CompleteAndValidateEncodingDialog({ open, missionId, withoutInstrumentist, onClose, onDone }: Props) {
  const [comment, setComment] = React.useState("");
  const mutation = useMutation({
    mutationFn: () => completeAndValidateMissionEncoding(missionId, comment.trim() === "" ? null : comment.trim()),
    onSuccess: async () => {
      setComment("");
      await onDone();
    },
  });

  const close = () => {
    if (mutation.isPending) return;
    mutation.reset();
    onClose();
  };

  return (
    <Dialog open={open} onClose={close} maxWidth="sm" fullWidth>
      <DialogTitle>Terminer et valider l'encodage</DialogTitle>
      <DialogContent dividers>
        <Typography variant="body2" sx={{ mb: 1.5 }}>
          L'encodage sera terminé à votre nom puis validé : il sera verrouillé, plus aucune modification
          possible sauf réouverture explicite ensuite.
        </Typography>
        {!withoutInstrumentist && (
          <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
            Les heures prestées de l'instrumentiste doivent être renseignées (« Corriger les heures » si nécessaire).
          </Typography>
        )}
        <TextField
          label="Commentaire (demandé si aucun matériel n'a été encodé)"
          value={comment}
          onChange={(e) => setComment(e.target.value)}
          multiline
          minRows={2}
          fullWidth
          size="small"
          inputProps={{ "data-testid": "complete-and-validate-comment" }}
        />
        {mutation.isError && (
          <Alert severity="error" sx={{ mt: 1.5 }} data-testid="complete-and-validate-error">
            {errorText(mutation.error)}
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={close} disabled={mutation.isPending}>Annuler</Button>
        <Button
          color="success"
          variant="contained"
          disableElevation
          disabled={mutation.isPending}
          onClick={() => mutation.mutate()}
          data-testid="confirm-complete-and-validate"
        >
          {mutation.isPending ? <CircularProgress size={18} /> : "Terminer et valider"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
