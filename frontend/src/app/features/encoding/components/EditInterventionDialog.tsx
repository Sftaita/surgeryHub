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
} from "@mui/material";
import type { CatalogFirm, CatalogInterventionType, EncodingIntervention, PatchInterventionBody } from "../api/encoding.types";

type Props = {
  open: boolean;
  loading: boolean;
  intervention: EncodingIntervention | null;
  interventionTypes: CatalogInterventionType[];
  firms: CatalogFirm[];
  onClose: () => void;
  onSubmit: (values: PatchInterventionBody) => void;
};

/**
 * Lot 5 (D-068) : le code/libellé ne sont plus édités directement (instantané dérivé du
 * référentiel) — seul le type et la firme principale sont modifiables ici.
 */
export default function EditInterventionDialog({
  open,
  loading,
  intervention,
  interventionTypes,
  firms,
  onClose,
  onSubmit,
}: Props) {
  const [type, setType] = React.useState<CatalogInterventionType | null>(null);
  const [firmId, setFirmId] = React.useState<number | "">("");

  React.useEffect(() => {
    if (!open || !intervention) return;
    setType(intervention.interventionType ?? null);
    setFirmId(intervention.primaryFirm?.id ?? "");
  }, [open, intervention]);

  const submit = () => {
    if (!type) return;
    onSubmit({
      interventionTypeId: type.id,
      primaryFirmId: firmId === "" ? null : Number(firmId),
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

          <FormControl fullWidth size="small" disabled={loading}>
            <InputLabel id="edit-itv-firm-label">Firme principale (optionnel)</InputLabel>
            <Select
              labelId="edit-itv-firm-label"
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
        <Button onClick={onClose} disabled={loading}>
          Annuler
        </Button>
        <Button variant="contained" onClick={submit} disabled={loading || !type}>
          {loading ? "..." : "Enregistrer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
