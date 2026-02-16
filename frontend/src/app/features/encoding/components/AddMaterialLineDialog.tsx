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
} from "../api/encoding.types";
import FirmItemPicker from "./FirmItemPicker";

type Values = {
  interventionId: number;
  itemId: number;
  quantity: number;
  comment?: string;
};

type Props = {
  open: boolean;
  loading: boolean;
  interventions: EncodingIntervention[];
  catalog?: { items: CatalogItem[]; firms: CatalogFirm[] };
  onClose: () => void;
  onSubmit: (values: Values) => void;
};

export default function AddMaterialLineDialog({
  open,
  loading,
  interventions,
  catalog,
  onClose,
  onSubmit,
}: Props) {
  const [interventionId, setInterventionId] = React.useState<number | "">("");
  const [firmId, setFirmId] = React.useState<number | "">("");
  const [itemId, setItemId] = React.useState<number | "">("");
  const [quantity, setQuantity] = React.useState<number>(1);
  const [comment, setComment] = React.useState<string>("");

  React.useEffect(() => {
    if (!open) return;
    // defaults
    const firstIntervention = (interventions ?? [])[0];
    setInterventionId(firstIntervention?.id ?? "");
    setFirmId("");
    setItemId("");
    setQuantity(1);
    setComment("");
  }, [open, interventions]);

  const handleInterventionChange = (e: SelectChangeEvent<string>) => {
    const v = e.target.value;
    setInterventionId(v === "" ? "" : Number(v));
  };

  const firms = catalog?.firms ?? [];
  const items = catalog?.items ?? [];

  const submit = () => {
    if (interventionId === "" || itemId === "") return;

    onSubmit({
      interventionId: Number(interventionId),
      itemId: Number(itemId),
      quantity: Number(quantity),
      comment: comment ?? "",
    });
  };

  const canSubmit =
    interventionId !== "" &&
    itemId !== "" &&
    Number.isFinite(Number(quantity)) &&
    Number(quantity) > 0;

  const sortedInterventions = (interventions ?? [])
    .slice()
    .sort((a, b) => (a.orderIndex ?? 0) - (b.orderIndex ?? 0));

  const hasCatalog = firms.length > 0 && items.length > 0;

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
          {loading ? "..." : "Créer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
