import * as React from "react";
import {
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
} from "@mui/material";
import type { EncodingIntervention } from "../api/encoding.types";

type PatchValues = {
  code?: string;
  label?: string;
  orderIndex?: number;
};

type Props = {
  open: boolean;
  loading: boolean;
  intervention: EncodingIntervention | null;
  onClose: () => void;
  onSubmit: (values: PatchValues) => void;
};

export default function EditInterventionDialog({
  open,
  loading,
  intervention,
  onClose,
  onSubmit,
}: Props) {
  const [code, setCode] = React.useState("");
  const [label, setLabel] = React.useState("");

  React.useEffect(() => {
    if (!open || !intervention) return;
    setCode(intervention.code ?? "");
    setLabel(intervention.label ?? "");
  }, [open, intervention]);

  const submit = () => {
    onSubmit({
      code:       code.trim().toUpperCase(),
      label:      label.trim() || code.trim().toUpperCase(),
      orderIndex: intervention?.orderIndex, // preserve existing
    });
  };

  return (
    <Dialog
      open={open}
      onClose={loading ? undefined : onClose}
      fullWidth
      maxWidth="xs"
    >
      <DialogTitle>Modifier l’intervention</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <TextField
            label="Code"
            value={code}
            onChange={(e) => setCode(e.target.value.toUpperCase())}
            disabled={loading}
            size="small"
            fullWidth
            inputProps={{ style: { fontFamily: "monospace", fontWeight: 700 } }}
          />
          <TextField
            label="Libellé"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            disabled={loading}
            size="small"
            fullWidth
          />
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={loading}>
          Annuler
        </Button>
        <Button variant="contained" onClick={submit} disabled={loading}>
          {loading ? "..." : "Enregistrer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
