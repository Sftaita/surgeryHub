import * as React from "react";
import { Alert, Box, Button, CircularProgress, Dialog, DialogActions, DialogContent, DialogTitle, TextField } from "@mui/material";

export type EncodingCorrectionKind = "RETURN" | "REOPEN";

/**
 * D-155 — qui reçoit l'encodage renvoyé / rouvert : l'instrumentiste affectée, ou le
 * chirurgien de la mission pour une activité réalisée sans instrumentiste (le backend le
 * notifie déjà ; l'écran ne fait que le dire).
 */
export type EncodingCorrectionRecipient = { role: "INSTRUMENTIST" | "SURGEON"; name: string };

interface Props {
  kind: EncodingCorrectionKind | null;
  missionLabel: string;
  recipient: EncodingCorrectionRecipient;
  statusLabel: string;
  pending: boolean;
  error: string | null;
  onConfirm: (reason: string) => void;
  onCancel: () => void;
}

const TITLES: Record<EncodingCorrectionKind, { title: string; confirm: string }> = {
  RETURN: { title: "Renvoyer en correction", confirm: "Confirmer le renvoi" },
  REOPEN: { title: "Rouvrir l'encodage", confirm: "Confirmer la réouverture" },
};

const COPY: Record<EncodingCorrectionRecipient["role"], { label: string; intro: Record<EncodingCorrectionKind, string>; placeholder: string }> = {
  INSTRUMENTIST: {
    label: "Instrumentiste",
    intro: {
      RETURN: "L'encodage est renvoyé à l'instrumentiste, qui le retrouve tel quel pour le compléter ou le corriger, puis le soumettre à nouveau.",
      REOPEN: "La validation reste dans l'historique ; l'encodage redevient modifiable par l'instrumentiste, qui devra le soumettre à nouveau.",
    },
    placeholder: "Merci de renseigner vos horaires réels de début et de fin.",
  },
  SURGEON: {
    label: "Chirurgien (destinataire)",
    intro: {
      RETURN: "Activité réalisée sans instrumentiste : l'encodage est renvoyé au chirurgien, qui le retrouve tel quel pour le compléter ou le corriger, puis le soumettre à nouveau.",
      REOPEN: "Activité réalisée sans instrumentiste : la validation reste dans l'historique ; l'encodage redevient modifiable par le chirurgien, qui devra le soumettre à nouveau.",
    },
    placeholder: "Ex. intervention manquante, matériel incorrect, quantité à vérifier, mauvaise firme…",
  },
};

/**
 * D-148 — confirmation d'un renvoi en correction (SUBMITTED) ou d'une réouverture (VALIDATED)
 * depuis « Suivi des encodages ». Rien n'est envoyé avant « Confirmer » ; la fenêtre reste
 * ouverte pendant l'enregistrement et en cas d'erreur (motif conservé, nouvelle tentative
 * possible) — c'est le parent qui la ferme, sur succès confirmé par le serveur.
 */
export function EncodingCorrectionDialog({ kind, missionLabel, recipient, statusLabel, pending, error, onConfirm, onCancel }: Props) {
  const [reason, setReason] = React.useState("");
  React.useEffect(() => { if (kind !== null) setReason(""); }, [kind]);

  if (kind === null) return null;
  const copy = { ...TITLES[kind], intro: COPY[recipient.role].intro[kind] };
  const trimmed = reason.trim();

  return (
    <Dialog open onClose={pending ? undefined : onCancel} maxWidth="sm" fullWidth>
      <DialogTitle>{copy.title}</DialogTitle>
      <DialogContent dividers>
        <Box component="dl" sx={{ m: 0, mb: 1.5, display: "grid", gridTemplateColumns: "auto 1fr", columnGap: 2, rowGap: 0.5, fontSize: 14 }}>
          <Box component="dt" sx={{ color: "text.secondary" }}>Mission</Box>
          <Box component="dd" sx={{ m: 0, fontWeight: 600 }}>{missionLabel}</Box>
          <Box component="dt" sx={{ color: "text.secondary" }}>{COPY[recipient.role].label}</Box>
          <Box component="dd" sx={{ m: 0, fontWeight: 600 }} data-testid="correction-recipient">{recipient.name}</Box>
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
          placeholder={COPY[recipient.role].placeholder}
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
