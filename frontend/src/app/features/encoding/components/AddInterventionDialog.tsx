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

type Values = {
  code: string;
  label: string;
  orderIndex: number;
};

type Props = {
  open: boolean;
  loading: boolean;
  onClose: () => void;
  onSubmit: (values: Values) => void;
};

export default function AddInterventionDialog({
  open,
  loading,
  onClose,
  onSubmit,
}: Props) {
  const [code, setCode] = React.useState("LCA");
  const [label, setLabel] = React.useState("Ligament croisé antérieur");
  const [orderIndex, setOrderIndex] = React.useState<number>(1);

  React.useEffect(() => {
    if (!open) return;
    setCode("LCA");
    setLabel("Ligament croisé antérieur");
    setOrderIndex(1);
  }, [open]);

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
      <DialogTitle>Ajouter une intervention</DialogTitle>

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
          {loading ? "..." : "Créer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
