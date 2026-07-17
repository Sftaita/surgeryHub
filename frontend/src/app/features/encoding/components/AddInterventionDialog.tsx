import * as React from "react";
import {
  Autocomplete,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { getFirmPricingRules } from "../../../features/billing-firm/api/firmBilling.api";

type FirmRef = { id: number; name: string };

type InterventionOption = {
  code: string;
  firms: FirmRef[];
};

type Values = {
  code: string;
  label: string;
  orderIndex: number;
};

type Props = {
  open:          boolean;
  loading:       boolean;
  firms:         FirmRef[];          // catalog firms for this mission
  existingCount: number;             // used to auto-assign orderIndex
  onClose:       () => void;
  onSubmit:      (values: Values) => void;
};

export default function AddInterventionDialog({
  open, loading, firms, existingCount, onClose, onSubmit,
}: Props) {
  const [options,        setOptions]        = React.useState<InterventionOption[]>([]);
  const [loadingOptions, setLoadingOptions] = React.useState(false);
  const [selected,       setSelected]       = React.useState<InterventionOption | null>(null);
  const [customCode,     setCustomCode]     = React.useState("");
  const [label,          setLabel]          = React.useState("");

  // Fetch intervention codes from pricing rules of catalog firms
  React.useEffect(() => {
    if (!open) return;
    // Reset form
    setSelected(null);
    setCustomCode("");
    setLabel("");
    if (!firms.length) return;

    setLoadingOptions(true);
    Promise.all(firms.map((f) => getFirmPricingRules(f.id).catch(() => [])))
      .then((results) => {
        const map = new Map<string, FirmRef[]>();
        results.forEach((rules, i) => {
          const firm = firms[i];
          rules
            .filter((r) => r.ruleType === "INTERVENTION_FEE" && r.active && r.interventionType)
            .forEach((r) => {
              // Lot 1 : interventionCode (texte libre) a été remplacé par interventionType
              // (référentiel fermé) — cet écran d'encodage n'est pas encore branché dessus
              // (Lot 5), on garde ici une simple adaptation mécanique pour rester compilable.
              const code = r.interventionType!.code;
              if (!map.has(code)) map.set(code, []);
              map.get(code)!.push({ id: firm.id, name: firm.name });
            });
        });
        setOptions(Array.from(map.entries()).map(([code, firmList]) => ({ code, firms: firmList })));
      })
      .finally(() => setLoadingOptions(false));
  }, [open, firms]);

  const effectiveCode = selected ? selected.code : customCode.trim().toUpperCase();
  const canSubmit = !!effectiveCode && !loading;

  function handleSubmit() {
    if (!effectiveCode) return;
    onSubmit({
      code:       effectiveCode,
      label:      label.trim() || effectiveCode,
      orderIndex: existingCount, // auto-assign (0-indexed array position)
    });
  }

  const associatedFirms = selected?.firms ?? [];

  return (
    <Dialog open={open} onClose={loading ? undefined : onClose} fullWidth maxWidth="xs">
      <DialogTitle>Ajouter une intervention</DialogTitle>

      <DialogContent>
        <Stack spacing={2.5} sx={{ mt: 1 }}>

          {/* Intervention selector */}
          <Autocomplete<InterventionOption, false, false, true>
            freeSolo
            options={options}
            loading={loadingOptions}
            getOptionLabel={(opt) => (typeof opt === "string" ? opt : opt.code)}
            value={selected}
            inputValue={selected ? selected.code : customCode}
            onInputChange={(_, v, reason) => {
              if (reason === "input") {
                setCustomCode(v.toUpperCase());
                setSelected(null);
              }
            }}
            onChange={(_, v) => {
              if (v && typeof v !== "string") {
                setSelected(v);
                setCustomCode(v.code);
              } else if (typeof v === "string") {
                setCustomCode(v.toUpperCase());
                setSelected(null);
              } else {
                setSelected(null);
                setCustomCode("");
              }
            }}
            renderInput={(params) => (
              <TextField
                {...params}
                label="Type d'intervention *"
                size="small"
                InputProps={{
                  ...params.InputProps,
                  endAdornment: (
                    <>
                      {loadingOptions && <CircularProgress size={14} />}
                      {params.InputProps.endAdornment}
                    </>
                  ),
                }}
                helperText={options.length === 0 && !loadingOptions ? "Aucune règle tarifaire configurée — saisissez un code libre" : " "}
              />
            )}
            renderOption={(props, opt) => (
              <li {...props} key={opt.code}>
                <Stack>
                  <Typography variant="body2" fontWeight={600}>{opt.code}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {opt.firms.map((f) => f.name).join(", ")}
                  </Typography>
                </Stack>
              </li>
            )}
            noOptionsText="Aucune intervention disponible"
          />

          {/* Associated firms — informational */}
          {associatedFirms.length > 0 && (
            <Stack spacing={0.5}>
              <Typography variant="caption" color="text.secondary">
                Facturation via :
              </Typography>
              <Stack direction="row" spacing={0.75} flexWrap="wrap">
                {associatedFirms.map((f) => (
                  <Chip key={f.id} label={f.name} size="small" variant="outlined" color="primary" />
                ))}
              </Stack>
            </Stack>
          )}

          {/* Optional label */}
          <TextField
            label="Libellé (optionnel)"
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            disabled={loading}
            size="small"
            fullWidth
            helperText="Laissez vide pour utiliser le code comme libellé"
          />
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={loading}>Annuler</Button>
        <Button variant="contained" onClick={handleSubmit} disabled={!canSubmit || loading}>
          {loading ? <CircularProgress size={16} /> : "Ajouter"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
