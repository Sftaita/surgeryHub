import * as React from "react";
import {
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  SelectChangeEvent,
  Stack,
  TextField,
  Typography,
} from "@mui/material";

import type { EncodingIntervention } from "../api/encoding.types";

type Props = {
  open: boolean;
  loading: boolean;
  interventions: EncodingIntervention[];
  preferredInterventionId: number | null;
  onClose: () => void;
  onSubmit: (values: {
    missionInterventionId: number;
    label: string;
    referenceCode?: string;
    comment?: string;
  }) => void;
};

export default function MaterialItemRequestDialog({
  open,
  loading,
  interventions,
  preferredInterventionId,
  onClose,
  onSubmit,
}: Props) {
  const [interventionId, setInterventionId] = React.useState<number | "">("");
  const [label, setLabel] = React.useState("");
  const [referenceCode, setReferenceCode] = React.useState("");
  const [comment, setComment] = React.useState("");

  const sorted = React.useMemo(
    () => interventions.slice().sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0)),
    [interventions],
  );

  React.useEffect(() => {
    if (!open) return;
    setInterventionId(preferredInterventionId ?? (sorted[0]?.id ?? ""));
    setLabel("");
    setReferenceCode("");
    setComment("");
  }, [open, preferredInterventionId, sorted]);

  const canSubmit = !loading && interventionId !== "" && label.trim() !== "";

  function handleSubmit() {
    if (!canSubmit) return;
    onSubmit({
      missionInterventionId: Number(interventionId),
      label: label.trim(),
      referenceCode: referenceCode.trim() || undefined,
      comment: comment.trim() || undefined,
    });
  }

  return (
    <Dialog open={open} onClose={loading ? undefined : onClose} fullWidth maxWidth="sm">
      <DialogTitle>Déclarer un matériel manquant</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Typography variant="body2" color="text.secondary">
            Le matériel sera signalé au manager. Il sera ajouté au catalogue et associé à votre mission.
          </Typography>

          <FormControl fullWidth disabled={loading}>
            <InputLabel id="req-itv-label">Intervention</InputLabel>
            <Select
              labelId="req-itv-label"
              value={interventionId === "" ? "" : String(interventionId)}
              label="Intervention"
              onChange={(e: SelectChangeEvent<string>) => {
                const v = e.target.value;
                setInterventionId(v === "" ? "" : Number(v));
              }}
            >
              <MenuItem value="">—</MenuItem>
              {sorted.map((itv) => (
                <MenuItem key={itv.id} value={String(itv.id)}>
                  {itv.code} — {itv.label}
                </MenuItem>
              ))}
            </Select>
          </FormControl>

          <TextField
            label="Nom du matériel *"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            disabled={loading}
            fullWidth
            placeholder="ex: Anchor suture 5.5mm Smith & Nephew"
          />

          <TextField
            label="Référence"
            value={referenceCode}
            onChange={(e) => setReferenceCode(e.target.value)}
            disabled={loading}
            fullWidth
            placeholder="ex: REF-12345"
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
