import * as React from "react";
import {
  Autocomplete,
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
import type { CatalogFirm, CatalogInterventionType } from "../api/encoding.types";

type Values = {
  interventionTypeId: number;
  primaryFirmId?: number;
  orderIndex: number;
};

type Props = {
  open:          boolean;
  loading:       boolean;
  interventionTypes: CatalogInterventionType[]; // catalogue actif (Lot 5)
  firms:         CatalogFirm[];                 // catalog firms for this mission
  existingCount: number;                        // used to auto-assign orderIndex
  onClose:       () => void;
  onSubmit:      (values: Values) => void;
  /** Aucun type ne correspond au besoin — ouvre la demande de nouveau type (D-068). */
  onRequestNewType: () => void;
};

export default function AddInterventionDialog({
  open, loading, interventionTypes, firms, existingCount, onClose, onSubmit, onRequestNewType,
}: Props) {
  const [type, setType] = React.useState<CatalogInterventionType | null>(null);
  const [firmId, setFirmId] = React.useState<number | "">("");

  React.useEffect(() => {
    if (!open) return;
    setType(null);
    setFirmId("");
  }, [open]);

  const canSubmit = !!type && !loading;

  function handleSubmit() {
    if (!type) return;
    onSubmit({
      interventionTypeId: type.id,
      primaryFirmId: firmId === "" ? undefined : Number(firmId),
      orderIndex: existingCount,
    });
  }

  return (
    <Dialog open={open} onClose={loading ? undefined : onClose} fullWidth maxWidth="xs">
      <DialogTitle>Ajouter une intervention</DialogTitle>

      <DialogContent>
        <Stack spacing={2.5} sx={{ mt: 1 }}>

          {/* Référentiel fermé (Lot 5) — plus de code libre. */}
          <Autocomplete<CatalogInterventionType, false, false, false>
            options={interventionTypes}
            getOptionLabel={(opt) => `${opt.code} — ${opt.label}`}
            isOptionEqualToValue={(a, b) => a.id === b.id}
            value={type}
            onChange={(_, v) => setType(v)}
            disabled={loading}
            renderInput={(params) => (
              <TextField {...params} label="Type d'intervention *" size="small" />
            )}
            noOptionsText="Aucun type actif dans le catalogue"
          />

          {interventionTypes.length === 0 && (
            <Typography variant="caption" color="text.secondary">
              Le catalogue est vide pour le moment.
            </Typography>
          )}

          <Typography variant="caption" color="text.secondary">
            Type introuvable ?{" "}
            <Typography
              component="button"
              type="button"
              variant="caption"
              onClick={onRequestNewType}
              sx={{ border: "none", background: "none", p: 0, color: "primary.main", cursor: "pointer", textDecoration: "underline", font: "inherit" }}
            >
              Faire une demande au manager
            </Typography>
          </Typography>

          {/* Firme principale — facultative */}
          <FormControl fullWidth size="small" disabled={loading}>
            <InputLabel id="add-itv-firm-label">Firme principale (optionnel)</InputLabel>
            <Select
              labelId="add-itv-firm-label"
              value={firmId === "" ? "" : String(firmId)}
              label="Firme principale (optionnel)"
              onChange={(e: SelectChangeEvent<string>) => {
                const v = e.target.value;
                setFirmId(v === "" ? "" : Number(v));
              }}
            >
              <MenuItem value="">—</MenuItem>
              {firms.map((f) => (
                <MenuItem key={f.id} value={String(f.id)}>{f.name}</MenuItem>
              ))}
            </Select>
          </FormControl>
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={loading}>Annuler</Button>
        <Button variant="contained" onClick={handleSubmit} disabled={!canSubmit}>
          {loading ? "..." : "Ajouter"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
