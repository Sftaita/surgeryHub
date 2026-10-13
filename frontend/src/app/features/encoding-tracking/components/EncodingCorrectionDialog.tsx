import * as React from "react";
import { Alert, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, TextField } from "@mui/material";

export type EncodingCorrectionKind = "RETURN" | "REOPEN";

interface Props {
  kind: EncodingCorrectionKind | null;
  missionLabel: string;
  instrumentistName: string;
  statusLabel: string;
  pending: boolean;
  error: string | null;
  onConfirm: (reason: string) => void;
  onCancel: () => void;
}

const COPY: Record<EncodingCorrectionKind, { title: string; intro: string; confirm: string }> = {
  RETURN: {
    title: "Renvoyer en correction",
    intro: "L'encodage est renvoyé à l'instrumentiste, qui le retrouve tel quel pour le compléter ou le corriger, puis le soumettre à nouveau.",
    confirm: "Confirmer le renvoi",
  },
  REOPEN: {
    title: "Rouvrir l'encodage",
    intro: "La validation reste dans l'historique ; l'encodage redevient modifiable par l'instrumentiste, qui devra le soumettre à nouveau.",
    confirm: "Confirmer la réouverture",
  },
};

/**
 * D-148 — confirmation d'un renvoi en correction (SUBMITTED) ou d'une réouverture (VALIDATED)
 * depuis « Suivi des encodages ». Rien n'est envoyé avant « Confirmer » ; la fenêtre reste
 * ouverte pendant l'enregistrement et en cas d'erreur (motif conservé, nouvelle tentative
 * possible) — c'est le parent qui la ferme, sur succès confirmé par le serveur.
 */
export function EncodingCorrectionDialog({ kind, missionLabel, instrumentistName, statusLabel, pending, error, onConfirm, onCancel }: Props) {
  const [reason, setReason] = React.useState("");
  React.useEffect(() => { if (kind !== null) setReason(""); }, [kind]);

  if (kind === null) return null;
  const copy = COPY[kind];
  const trimmed = reason.trim();

  return (
    <Dialog open onClose={pending ? undefined : onCancel} maxWidth="sm" fullWidth>
      <DialogTitle>{copy.title}</DialogTitle>
      <DialogContent dividers>
        <Box component="dl" sx={{ m: 0, mb: 1.5, display: "grid", gridTemplateColumns: "auto 1fr", columnGap: 2, rowGap: 0.5, fontSize: 14 }}>
          <Box component="dt" sx={{ color: "text.secondary" }}>Mission</Box>
          <Box component="dd" sx={{ m: 0, fontWeight: 600 }}>{missionLabel}</Box>
          <Box component="dt" sx={{ color: "text.secondary" }}>Instrumentiste</Box>
          <Box component="dd" sx={{ m: 0, fontWeight: 600 }}>{instrumentistName}</Box>
          <Box component="dt" sx={{ color: "text.secondary" }}>Statut actuel</Box>
          <Box component="dd" sx={{ m: 0, fontWeight: 600 }}>{statusLabel}</Box>
        </Box>
        <Box sx={{ fontSize: 13.5, color: "text.secondary", mb: 1.5 }}>{copy.intro}</Box>
        {error && <Alert severity="error" sx={{ mb: 1.5 }}>{error}</Alert>}
        <TextField
          autoFocus
          fullWidth
          multiline
          minRows={3}
          required
          label="Motif de la correction"
          placeholder="Merci de renseigner vos horaires réels de début et de fin."
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          disabled={pending}
        />
      </DialogContent>
      <DialogActions>
        <Button onClick={onCancel} disabled={pending}>Annuler</Button>
        <Button
          variant="contained"
          color="warning"
          disabled={pending || trimmed === ""}
          onClick={() => onConfirm(trimmed)}
        >
          {pending ? <CircularProgress size={18} aria-label="Enregistrement en cours" /> : copy.confirm}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
