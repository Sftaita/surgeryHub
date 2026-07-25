import * as React from "react";
import { Box } from "@mui/material";
import { SheetModal } from "../../../ui/sheet/SheetModal";
import { Field } from "../../../ui/sheet/Field";

import type {
  EncodingMaterialLine,
  PatchMaterialLineBody,
} from "../api/encoding.types";

const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const RED_600 = "#E5484D";

type Props = {
  open: boolean;
  loading: boolean;
  line: EncodingMaterialLine | null;
  onClose: () => void;
  onSubmit: (values: PatchMaterialLineBody) => void;
  /** Ouvre la confirmation de suppression (ConfirmDeleteDialog) — jamais de suppression directe ici. */
  onDelete: () => void;
};

export default function EditMaterialLineDialog({
  open,
  loading,
  line,
  onClose,
  onSubmit,
  onDelete,
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
    <SheetModal open={open} title="Modifier la ligne matériel" onClose={onClose} closeDisabled={loading} helpTopicId="mission-encoding">
      {line && (
        <Box sx={{ mt: "14px", fontSize: 14, color: GRAY_500 }}>
          <Box component="span" sx={{ fontWeight: 700, color: "#16202B" }}>{line.item.label}</Box>
          {" "}({line.item.firm.name} / {line.item.referenceCode})
        </Box>
      )}

      <Box sx={{ mt: "16px", display: "flex", flexDirection: "column", gap: "16px" }}>
        <Field
          id="edit-line-qty"
          label="Quantité"
          value={quantity}
          onChange={setQuantity}
          disabled={loading}
          inputMode="decimal"
          helperText='Envoyer une valeur numérique (ex: "3").'
        />

        <Field
          id="edit-line-comment"
          label="Commentaire"
          value={comment}
          onChange={setComment}
          disabled={loading}
          multiline
          minRows={2}
        />
      </Box>

      <Box
        component="button"
        type="button"
        onClick={submit}
        disabled={loading || !canSubmit}
        sx={{
          mt: "20px", width: "100%", height: 52, border: "none", borderRadius: "12px",
          background: GREEN_800, color: "#fff", fontFamily: "inherit", fontSize: 15, fontWeight: 700,
          cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
          "&:hover": { background: "#144D38" }, "&:active": { transform: "translateY(0.5px)" },
          "&:disabled": { opacity: 0.6, cursor: "default", boxShadow: "none" },
        }}
      >
        {loading ? "…" : "Enregistrer"}
      </Box>
      <Box sx={{ mt: "8px", display: "flex", gap: "8px" }}>
        <Box
          component="button"
          type="button"
          onClick={onDelete}
          disabled={loading}
          sx={{
            flex: 1, height: 44, border: "1.5px solid", borderColor: "#FBD5D6", borderRadius: "12px",
            background: "#fff", color: RED_600, fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer",
            "&:hover": { background: "#FDEEEE" },
          }}
        >
          Supprimer
        </Box>
        <Box
          component="button"
          type="button"
          onClick={onClose}
          disabled={loading}
          sx={{ flex: 1, height: 44, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 13.5, fontWeight: 600, cursor: "pointer" }}
        >
          Annuler
        </Box>
      </Box>
    </SheetModal>
  );
}
