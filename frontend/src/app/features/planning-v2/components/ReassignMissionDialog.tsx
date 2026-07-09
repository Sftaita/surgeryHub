import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress,
  Dialog, DialogActions, DialogContent,
  DialogTitle, FormControl, InputLabel,
  MenuItem, Select, Stack, Typography,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { fetchMissionEligibleInstrumentists } from "../api/planningV2.api";
import type { EligibilityReason } from "../api/planningV2.types";

const REASON_LABELS: Record<EligibilityReason, string> = {
  INACTIVE:            "Compte inactif",
  NO_SITE_MEMBERSHIP:  "Non affilié au site",
  ABSENT:              "Absent ce jour",
  SCHEDULE_CONFLICT:   "Conflit d'horaire",
  ALREADY_ASSIGNED:    "Déjà assigné",
  INCOMPATIBLE_STATUS: "Statut incompatible",
};

interface ReassignMissionDialogProps {
  open: boolean;
  loading?: boolean;
  missionId: number | null;
  onClose: () => void;
  onConfirm: (instrumentistId: number, instrumentistName: string) => void;
}

export function ReassignMissionDialog({
  open, loading, missionId, onClose, onConfirm,
}: ReassignMissionDialogProps) {
  const [selected, setSelected] = React.useState<number | "">("");

  React.useEffect(() => {
    if (open) setSelected("");
  }, [open]);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["mission-eligibility", missionId],
    queryFn: () => fetchMissionEligibleInstrumentists(missionId!),
    enabled: open && missionId !== null,
    staleTime: 0,
  });

  function handleConfirm() {
    if (selected === "" || !data) return;
    const candidate = data.eligible.find((c) => c.id === selected);
    onConfirm(selected as number, candidate?.name ?? "");
  }

  const noEligible = data && data.eligible.length === 0;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Réassigner la mission</DialogTitle>
      <DialogContent>
        {isLoading && (
          <Box sx={{ display: "flex", justifyContent: "center", py: 3 }}>
            <CircularProgress size={24} />
          </Box>
        )}

        {isError && (
          <Alert severity="error">
            Impossible de charger les instrumentistes éligibles.
          </Alert>
        )}

        {data && (
          <Stack spacing={2} sx={{ pt: 0.5 }}>
            {data.eligible.length === 0 && data.ineligible.length === 0 && (
              <Typography variant="body2" color="text.secondary">
                Aucun instrumentiste disponible pour cette mission.
              </Typography>
            )}

            {noEligible && data.ineligible.length > 0 && (
              <Alert severity="warning">
                Aucun instrumentiste éligible — tous les candidats ont des contraintes.
              </Alert>
            )}

            {data.eligible.length > 0 && (
              <FormControl fullWidth size="small">
                <InputLabel id="reassign-eligible-label">Instrumentiste éligible</InputLabel>
                <Select
                  labelId="reassign-eligible-label"
                  label="Instrumentiste éligible"
                  value={selected}
                  onChange={(e) => setSelected(e.target.value as number)}
                  data-testid="reassign-eligible-select"
                >
                  {data.eligible.map((c) => (
                    <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>
                  ))}
                </Select>
              </FormControl>
            )}

            {data.ineligible.length > 0 && (
              <Box>
                <Typography
                  variant="caption"
                  color="text.secondary"
                  fontWeight={600}
                  sx={{ mb: 0.75, display: "block" }}
                >
                  Non éligibles
                </Typography>
                <Stack spacing={0.5} data-testid="ineligible-list">
                  {data.ineligible.map((c) => (
                    <Stack
                      key={c.id}
                      direction="row"
                      alignItems="center"
                      spacing={1}
                      sx={{ py: 0.25 }}
                    >
                      <Typography variant="body2" color="text.disabled" sx={{ flex: 1 }}>
                        {c.name}
                      </Typography>
                      <Stack direction="row" spacing={0.5} flexWrap="wrap">
                        {c.reasons.map((r) => (
                          <Chip
                            key={r}
                            label={REASON_LABELS[r] ?? r}
                            size="small"
                            variant="outlined"
                            sx={{ fontSize: 10, height: 18 }}
                            data-testid={`reason-chip-${r}`}
                          />
                        ))}
                      </Stack>
                    </Stack>
                  ))}
                </Stack>
              </Box>
            )}
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={loading}>Annuler</Button>
        <Button
          onClick={handleConfirm}
          disabled={loading || selected === "" || !!noEligible}
          variant="contained"
          disableElevation
        >
          {loading ? "En cours…" : "Réassigner"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
