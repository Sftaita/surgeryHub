import * as React from "react";
import {
  Button, Dialog, DialogActions, DialogContent,
  DialogContentText, DialogTitle, TextField,
} from "@mui/material";

interface CancelMissionDialogProps {
  open: boolean;
  loading?: boolean;
  onClose: () => void;
  onConfirm: (reason?: string) => void;
}

export function CancelMissionDialog({ open, loading, onClose, onConfirm }: CancelMissionDialogProps) {
  const [reason, setReason] = React.useState("");

  function handleConfirm() {
    onConfirm(reason.trim() || undefined);
  }

  function handleClose() {
    setReason("");
    onClose();
  }

  return (
    <Dialog open={open} onClose={handleClose} maxWidth="sm" fullWidth>
      <DialogTitle>Annuler la mission ?</DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: 2 }}>
          Cette mission sera marquée comme annulée et retirée du planning actif.
        </DialogContentText>
        <TextField
          label="Motif (optionnel)"
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          multiline
          rows={3}
          fullWidth
          size="small"
          placeholder="Ex : Chirurgien absent, salle indisponible…"
          inputProps={{ "aria-label": "Motif d'annulation" }}
        />
      </DialogContent>
      <DialogActions>
        <Button onClick={handleClose} disabled={loading}>Annuler</Button>
        <Button
          onClick={handleConfirm}
          disabled={loading}
          color="error"
          variant="contained"
          disableElevation
        >
          {loading ? "En cours…" : "Confirmer l'annulation"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
