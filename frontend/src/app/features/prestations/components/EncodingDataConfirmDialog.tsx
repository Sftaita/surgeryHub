import * as React from "react";
import {
  Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, TextField,
} from "@mui/material";

type Props = {
  open: boolean;
  /** Message du serveur (409 MISSION_HAS_ENCODING_DATA). */
  message: string;
  /** Le serveur indique si une confirmation explicite suffit (interventions / matériel seuls). */
  confirmable: boolean;
  loading?: boolean;
  onClose: () => void;
  onConfirm: (reason: string) => void;
};

/**
 * D-156 — réaffectation d'une mission qui porte déjà des données d'encodage : jamais un
 * transfert implicite. Le serveur décide si une confirmation motivée suffit (`confirmable`) ;
 * sinon (heures déjà déclarées) seule une correction contrôlée est possible.
 */
export function EncodingDataConfirmDialog({ open, message, confirmable, loading, onClose, onConfirm }: Props) {
  const [reason, setReason] = React.useState("");
  React.useEffect(() => {
    if (open) setReason("");
  }, [open]);

  return (
    <Dialog open={open} onClose={loading ? undefined : onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Données d'encodage déjà enregistrées</DialogTitle>
      <DialogContent>
        <DialogContentText>{message}</DialogContentText>
        {confirmable && (
          <TextField
            label="Motif de la réaffectation"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            fullWidth
            multiline
            minRows={2}
            sx={{ mt: 2 }}
            helperText="Les interventions et le matériel restent attachés à la mission ; leurs auteurs d'origine sont conservés."
            inputProps={{ "data-testid": "encoding-data-reason" }}
          />
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={loading}>{confirmable ? "Annuler" : "Fermer"}</Button>
        {confirmable && (
          <Button variant="contained" disabled={loading || reason.trim() === ""} onClick={() => onConfirm(reason.trim())}>
            Confirmer la réaffectation
          </Button>
        )}
      </DialogActions>
    </Dialog>
  );
}
