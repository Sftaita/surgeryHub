import * as React from "react";
import { useMutation } from "@tanstack/react-query";
import { Alert, Button, Checkbox, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, TextField, Typography } from "@mui/material";

import { completeAndValidateMissionEncoding, isEncodingConcurrentModification } from "../api/encoding.api";
import { encodingIncompleteText } from "../api/encodingIncomplete";

type Props = {
  open: boolean;
  missionId: number;
  /** `encodingRevision` du détail de mission : l'encodage que le manager a sous les yeux. */
  revision: string | null;
  /** `encodingTakeover` du détail de mission : non nul = reprise, confirmation explicite exigée. */
  takeover: { instrumentistName: string; hoursMissing: boolean } | null;
  onClose: () => void;
  onDone: () => void | Promise<void>;
  /**
   * L'encodage a changé entre-temps (409) : la fenêtre s'est déjà refermée, sa confirmation est
   * annulée ; l'écran relit la mission et explique pourquoi (ENCODING_CHANGED_BEFORE_VALIDATION).
   */
  onConflict?: () => void | Promise<void>;
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
 * Proposé seulement si allowedActions contient `complete_and_validate`. Le backend dit s'il
 * s'agit d'une reprise (`encodingTakeover`) et ce qui manque ; tout contrôle (contenu,
 * justification d'un bloc sans matériel, modification concurrente) est fait par le backend et
 * son refus est affiché tel quel dans la fenêtre, rien n'est recalculé ici.
 */
export default function CompleteAndValidateEncodingDialog({ open, missionId, revision, takeover, onClose, onDone, onConflict }: Props) {
  const [comment, setComment] = React.useState("");
  const [confirmed, setConfirmed] = React.useState(false);
  // La révision confirmée est celle que le manager avait sous les yeux À L'OUVERTURE : une relecture
  // de la mission pendant que la fenêtre est ouverte ne la remplace jamais (sinon une confirmation
  // donnée pour un contenu vaudrait pour un autre, jamais relu).
  const [openedRevision, setOpenedRevision] = React.useState<string | null>(revision);
  const mutation = useMutation({
    mutationFn: () => completeAndValidateMissionEncoding(missionId, {
      comment: comment.trim() === "" ? null : comment.trim(),
      expectedRevision: openedRevision,
      takeoverConfirmed: takeover !== null && confirmed,
    }),
    onSuccess: async () => {
      setComment("");
      setConfirmed(false);
      await onDone();
    },
    onError: async (err) => {
      if (!isEncodingConcurrentModification(err)) return;
      // Conflit : le contexte de confirmation est annulé, pas seulement le bouton désactivé.
      setComment("");
      setConfirmed(false);
      mutation.reset();
      onClose();
      await onConflict?.();
    },
  });

  // Chaque ouverture repart de zéro, sur la révision relue à cet instant.
  React.useEffect(() => {
    if (!open) return;
    setOpenedRevision(revision);
    setComment("");
    setConfirmed(false);
    mutation.reset();
  }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  const close = () => {
    if (mutation.isPending) return;
    mutation.reset();
    setConfirmed(false);
    onClose();
  };

  return (
    <Dialog open={open} onClose={close} maxWidth="sm" fullWidth>
      <DialogTitle>{takeover ? "Reprendre, terminer et valider l'encodage" : "Terminer et valider l'encodage"}</DialogTitle>
      <DialogContent dividers>
        <Typography variant="body2" sx={{ mb: 1.5 }}>
          L'encodage sera terminé à votre nom puis validé : interventions et matériel seront verrouillés,
          plus aucune modification possible sauf réouverture explicite ensuite.
        </Typography>
        {takeover && (
          <Alert severity="warning" sx={{ mb: 1.5 }} data-testid="complete-and-validate-takeover">
            <Typography variant="body2" sx={{ mb: 0.5 }}>
              {takeover.instrumentistName} n'a pas soumis son encodage. Vous le reprenez : tout ce qui a déjà été
              encodé est conservé tel quel, et une notification de la reprise lui sera envoyée.
            </Typography>
            <Typography variant="body2">
              {takeover.hoursMissing
                ? "Ses heures prestées ne sont pas déclarées : elles ne seront ni inventées ni validées, et resteront déclarables après la validation."
                : "Ses heures prestées déclarées seront validées avec l'encodage."}
            </Typography>
          </Alert>
        )}
        {takeover && (
          <FormControlLabel
            sx={{ mb: 1 }}
            control={
              <Checkbox
                checked={confirmed}
                onChange={(e) => setConfirmed(e.target.checked)}
                inputProps={{ "data-testid": "complete-and-validate-takeover-confirm" } as React.InputHTMLAttributes<HTMLInputElement>}
              />
            }
            label={`Je confirme reprendre l'encodage de ${takeover.instrumentistName}`}
          />
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
          disabled={mutation.isPending || (takeover !== null && !confirmed)}
          onClick={() => mutation.mutate()}
          data-testid="confirm-complete-and-validate"
        >
          {mutation.isPending ? <CircularProgress size={18} /> : "Terminer et valider"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
