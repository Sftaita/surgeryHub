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

import type {
  CatalogFirm,
  CatalogItem,
  EncodingIntervention,
  CreateMaterialLineBody,
} from "../api/encoding.types";
import FirmItemPicker from "./FirmItemPicker";

type Props = {
  open: boolean;
  loading: boolean;
  interventions: EncodingIntervention[];
  catalog?: { items: CatalogItem[]; firms: CatalogFirm[] };

  // NEW: permet d’ouvrir “Encoder matériel” depuis une intervention précise
  preferredInterventionId: number | null;

  onClose: () => void;
  onSubmit: (values: CreateMaterialLineBody) => void;
};

export default function AddMaterialLineDialog({
  open,
  loading,
  interventions,
  catalog,
  preferredInterventionId,
  onClose,
  onSubmit,
}: Props) {
  const [interventionId, setInterventionId] = React.useState<number | "">("");
  const [firmId, setFirmId] = React.useState<number | "">("");
  const [itemId, setItemId] = React.useState<number | "">("");
  const [quantity, setQuantity] = React.useState<string>("1");
  const [comment, setComment] = React.useState<string>("");

  const sortedInterventions = React.useMemo(() => {
    return (interventions ?? [])
      .slice()
      .sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0));
  }, [interventions]);

  React.useEffect(() => {
    if (!open) return;

    const firstIntervention = sortedInterventions[0];

    // priorité à preferredInterventionId
    const initialInterventionId =
      preferredInterventionId ??
      (firstIntervention ? firstIntervention.id : null);

    setInterventionId(initialInterventionId ?? "");
    setFirmId("");
    setItemId("");
    setQuantity("1");
    setComment("");
  }, [open, sortedInterventions, preferredInterventionId]);

  const handleInterventionChange = (e: SelectChangeEvent<string>) => {
    const v = e.target.value;
    setInterventionId(v === "" ? "" : Number(v));
  };

  const firms = catalog?.firms ?? [];
  const items = catalog?.items ?? [];
  const hasCatalog = firms.length > 0 && items.length > 0;

  const submit = () => {
    if (interventionId === "" || itemId === "") return;

    onSubmit({
      missionInterventionId: Number(interventionId),
      itemId: Number(itemId),
      quantity: String(quantity ?? "").trim(), // ✅ string
      comment: comment ?? "",
    });
  };

  const qtyOk = (() => {
    const v = Number(String(quantity).replace(",", "."));
    return Number.isFinite(v) && v > 0;
  })();

  const canSubmit = interventionId !== "" && itemId !== "" && qtyOk;

  return (
    <Dialog
      open={open}
      onClose={loading ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Ajouter une ligne matériel</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <FormControl fullWidth disabled={loading}>
            <InputLabel id="itv-label">Intervention</InputLabel>
            <Select
              labelId="itv-label"
              value={interventionId === "" ? "" : String(interventionId)}
              label="Intervention"
              onChange={handleInterventionChange}
            >
              <MenuItem value="">—</MenuItem>
              {sortedInterventions.map((itv) => (
                <MenuItem key={itv.id} value={String(itv.id)}>
                  {itv.code} — {itv.label}
                </MenuItem>
              ))}
            </Select>
          </FormControl>

          {!hasCatalog ? (
            <Typography color="text.secondary">
              Catalogue indisponible (catalog.firms/items manquant dans le
              payload).
            </Typography>
          ) : (
            <FirmItemPicker
              firms={firms}
              items={items}
              disabled={loading}
              firmId={firmId}
              itemId={itemId}
              onChangeFirmId={setFirmId}
              onChangeItemId={setItemId}
            />
          )}

          <TextField
            label="Quantité"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            disabled={loading}
            fullWidth
            inputProps={{ inputMode: "decimal" }}
            helperText='Envoyer une string (ex: "2", "0.5"). Le backend renvoie "2.00".'
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
          {loading ? "..." : "Créer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
