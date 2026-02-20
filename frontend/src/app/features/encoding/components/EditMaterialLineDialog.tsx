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

import type {
  EncodingMaterialLine,
  PatchMaterialLineBody,
} from "../api/encoding.types";

type Props = {
  open: boolean;
  loading: boolean;
  line: EncodingMaterialLine | null;
  onClose: () => void;
  onSubmit: (values: PatchMaterialLineBody) => void;
};

export default function EditMaterialLineDialog({
  open,
  loading,
  line,
  onClose,
  onSubmit,
}: Props) {
  const [quantity, setQuantity] = React.useState<string>("");
  const [comment, setComment] = React.useState<string>("");

  React.useEffect(() => {
    if (!open) return;
    setQuantity(line?.quantity ?? "");
    setComment(line?.comment ?? "");
  }, [open, line]);

  const qtyOk = (() => {
    const v = Number(String(quantity).replace(",", "."));
    return Number.isFinite(v) && v > 0;
  })();

  const canSubmit = !!line && qtyOk;

  const submit = () => {
    if (!line) return;

    onSubmit({
      quantity: String(quantity ?? "").trim(),
      comment: comment ?? "",
    });
  };

  return (
    <Dialog
      open={open}
      onClose={loading ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Modifier la ligne matériel</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          {line ? (
            <Typography color="text.secondary">
              {line.item.label}{" "}
              <Typography component="span" color="text.secondary">
                ({line.item.firm.name} / {line.item.referenceCode})
              </Typography>
            </Typography>
          ) : null}

          <TextField
            label="Quantité"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            disabled={loading}
            fullWidth
            inputProps={{ inputMode: "decimal" }}
            helperText='Envoyer une string (ex: "3"). Le backend renvoie "3.00".'
          />

          <TextField
            label="Commentaire"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            disabled={loading}
            fullWidth
            multiline
            minRows={2}
          />
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={loading}>
          Annuler
        </Button>
        <Button
          variant="contained"
          onClick={submit}
          disabled={loading || !canSubmit}
        >
          {loading ? "..." : "Enregistrer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
