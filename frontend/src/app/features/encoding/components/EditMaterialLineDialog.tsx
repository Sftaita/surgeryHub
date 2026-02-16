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

import type { EncodingMaterialLine } from "../api/encoding.types";

type PatchValues = {
  quantity?: number;
  comment?: string;
};

type Props = {
  open: boolean;
  loading: boolean;
  line: EncodingMaterialLine | null;
  onClose: () => void;
  onSubmit: (values: PatchValues) => void;
};

export default function EditMaterialLineDialog({
  open,
  loading,
  line,
  onClose,
  onSubmit,
}: Props) {
  const [quantity, setQuantity] = React.useState<number>(1);
  const [comment, setComment] = React.useState<string>("");

  React.useEffect(() => {
    if (!open || !line) return;
    setQuantity(Number(line.quantity ?? 1));
    setComment(line.comment ?? "");
  }, [open, line]);

  const submit = () => {
    onSubmit({
      quantity: Number(quantity),
      comment: comment ?? "",
    });
  };

  const canSubmit =
    line != null && Number.isFinite(Number(quantity)) && Number(quantity) > 0;

  return (
    <Dialog
      open={open}
      onClose={loading ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Éditer la ligne matériel</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Typography color="text.secondary">
            Item: {line?.item?.label ?? "—"} — {line?.item?.firm?.name ?? "—"}
          </Typography>

          <TextField
            label="Quantité"
            type="number"
            value={quantity}
            onChange={(e) => setQuantity(Number(e.target.value))}
            disabled={loading}
            fullWidth
            inputProps={{ min: 0, step: 1 }}
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
