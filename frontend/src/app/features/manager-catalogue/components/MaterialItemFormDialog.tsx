import * as React from "react";
import {
  Alert,
  Autocomplete,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Stack,
  Switch,
  TextField,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { getFirms } from "../api/catalogue.api";
import type { FirmDTO, MaterialItemDTO } from "../api/catalogue.types";

export type MaterialItemFormValues = {
  firmId: number | null;
  label: string;
  unit: string;
  referenceCode: string;
  isImplant: boolean;
};

type Props = {
  open: boolean;
  headerExtra?: React.ReactNode;
  title: string;
  initial?: Partial<MaterialItemFormValues>;
  submitLabel: string;
  loading: boolean;
  error: string | null;
  onClose: () => void;
  onSubmit: (values: MaterialItemFormValues) => void;
};

const DEFAULT_VALUES: MaterialItemFormValues = {
  firmId: null,
  label: "",
  unit: "",
  referenceCode: "",
  isImplant: false,
};

export function MaterialItemFormDialog({
  open,
  title,
  initial,
  submitLabel,
  loading,
  error,
  headerExtra,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = React.useState<MaterialItemFormValues>({
    ...DEFAULT_VALUES,
    ...initial,
  });

  React.useEffect(() => {
    if (open) {
      setValues({ ...DEFAULT_VALUES, ...initial });
    }
  }, [open]);

  const firmsQuery = useQuery({
    queryKey: ["firms"],
    queryFn: getFirms,
    staleTime: 5 * 60 * 1000,
  });

  const firms: FirmDTO[] = firmsQuery.data ?? [];

  const selectedFirm =
    values.firmId !== null
      ? (firms.find((f) => f.id === values.firmId) ?? null)
      : null;

  const canSubmit =
    !loading && values.firmId !== null && values.label.trim() !== "" && values.unit.trim() !== "";

  function handleSubmit() {
    if (!canSubmit) return;
    onSubmit(values);
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>{title}</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ pt: 1 }}>
          {headerExtra ?? null}
          {error ? <Alert severity="error">{error}</Alert> : null}

          <Autocomplete<FirmDTO>
            options={firms}
            loading={firmsQuery.isLoading}
            getOptionLabel={(f) => f.name}
            isOptionEqualToValue={(a, b) => a.id === b.id}
            value={selectedFirm}
            onChange={(_, value) =>
              setValues((prev) => ({ ...prev, firmId: value?.id ?? null }))
            }
            renderInput={(params) => (
              <TextField
                {...params}
                label="Firme *"
                size="small"
                InputProps={{
                  ...params.InputProps,
                  endAdornment: (
                    <>
                      {firmsQuery.isLoading ? (
                        <CircularProgress size={16} />
                      ) : null}
                      {params.InputProps.endAdornment}
                    </>
                  ),
                }}
              />
            )}
          />

          <TextField
            label="Nom *"
            value={values.label}
            onChange={(e) =>
              setValues((prev) => ({ ...prev, label: e.target.value }))
            }
            size="small"
            fullWidth
            disabled={loading}
          />

          <TextField
            label="Unité *"
            value={values.unit}
            onChange={(e) =>
              setValues((prev) => ({ ...prev, unit: e.target.value }))
            }
            size="small"
            fullWidth
            disabled={loading}
            placeholder="ex: pièce, boîte, ml"
          />

          <TextField
            label="Référence"
            value={values.referenceCode}
            onChange={(e) =>
              setValues((prev) => ({ ...prev, referenceCode: e.target.value }))
            }
            size="small"
            fullWidth
            disabled={loading}
          />

          <Box>
            <FormControlLabel
              control={
                <Switch
                  checked={values.isImplant}
                  onChange={(e) =>
                    setValues((prev) => ({
                      ...prev,
                      isImplant: e.target.checked,
                    }))
                  }
                  disabled={loading}
                />
              }
              label="Implant"
            />
          </Box>
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={loading}>
          Annuler
        </Button>
        <Button
          variant="contained"
          onClick={handleSubmit}
          disabled={!canSubmit}
        >
          {loading ? <CircularProgress size={18} /> : submitLabel}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
