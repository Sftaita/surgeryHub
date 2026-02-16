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
  const [orderIndex, setOrderIndex] = React.useState<number>(1);

  React.useEffect(() => {
    if (!open || !intervention) return;
    setCode(intervention.code ?? "");
    setLabel(intervention.label ?? "");
    setOrderIndex(Number(intervention.orderIndex ?? 1));
  }, [open, intervention]);

  const submit = () => {
    onSubmit({
      code: code.trim(),
      label: label.trim(),
      orderIndex: Number(orderIndex),
    });
  };

  return (
    <Dialog
      open={open}
      onClose={loading ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Éditer l’intervention</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <TextField
            label="Code"
            value={code}
            onChange={(e) => setCode(e.target.value)}
            disabled={loading}
            fullWidth
          />
          <TextField
            label="Libellé"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            disabled={loading}
            fullWidth
          />
          <TextField
            label="Order index"
            type="number"
            value={orderIndex}
            onChange={(e) => setOrderIndex(Number(e.target.value))}
            disabled={loading}
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
