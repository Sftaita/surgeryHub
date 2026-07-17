import * as React from "react";
import {
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
  Typography,
} from "@mui/material";

type Props = {
  open: boolean;
  loading: boolean;
  onClose: () => void;
  onSubmit: (values: { label: string; suggestedCode?: string; comment?: string }) => void;
};

/**
 * "Demande de nouveau type" (Lot 5, D-068) — miroir de MaterialItemRequestDialog pour le
 * référentiel InterventionType : soumise quand aucun type actif ne correspond au besoin,
 * au lieu d'un fallback texte accepté comme s'il était catalogué. Aucune intervention
 * n'est créée tant que le manager n'a pas résolu la demande.
 */
export default function InterventionTypeRequestDialog({ open, loading, onClose, onSubmit }: Props) {
  const [label, setLabel] = React.useState("");
  const [suggestedCode, setSuggestedCode] = React.useState("");
  const [comment, setComment] = React.useState("");

  React.useEffect(() => {
    if (!open) return;
    setLabel("");
    setSuggestedCode("");
    setComment("");
  }, [open]);

  const canSubmit = !loading && label.trim() !== "";

  function handleSubmit() {
    if (!canSubmit) return;
    onSubmit({
      label: label.trim(),
      suggestedCode: suggestedCode.trim() || undefined,
      comment: comment.trim() || undefined,
    });
  }

  return (
    <Dialog open={open} onClose={loading ? undefined : onClose} fullWidth maxWidth="sm">
      <DialogTitle>Demander un nouveau type d'intervention</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Typography variant="body2" color="text.secondary">
            La demande sera transmise au manager. Aucune intervention n'est créée tant
            qu'elle n'a pas été validée.
          </Typography>

          <TextField
            label="Type d'intervention souhaité *"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            disabled={loading}
            fullWidth
            placeholder="ex: Prothèse épaule inversée"
          />

          <TextField
            label="Code suggéré"
            value={suggestedCode}
            onChange={(e) => setSuggestedCode(e.target.value.toUpperCase())}
            disabled={loading}
            fullWidth
            placeholder="ex: PTE-INV"
            inputProps={{ style: { fontFamily: "monospace" } }}
          />

          <TextField
            label="Commentaire"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            disabled={loading}
            fullWidth
            multiline
            minRows={2}
            placeholder="Informations complémentaires pour le manager"
          />
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={loading}>
          Annuler
        </Button>
        <Button variant="contained" onClick={handleSubmit} disabled={!canSubmit}>
          {loading ? "..." : "Envoyer la demande"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
