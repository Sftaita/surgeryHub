import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress,
  Dialog, DialogActions, DialogContent,
  DialogTitle, Stack, Typography,
} from "@mui/material";
import CheckIcon from "@mui/icons-material/Check";
import { useQuery } from "@tanstack/react-query";
import { fetchMissionEligibleInstrumentists } from "../api/planningV2.api";
import { eligibilityReasonLabel } from "../api/eligibilityReasons";
import type { CandidateEligibility } from "../api/planningV2.types";

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

  function selectCandidate(c: CandidateEligibility) {
    if (!c.selectable) return;
    setSelected(c.id);
  }

  function handleConfirm() {
    if (selected === "" || !data) return;
    const candidate = data.candidates.find((c) => c.id === selected);
    if (!candidate?.selectable) return;
    onConfirm(selected as number, candidate?.name ?? "");
  }

  const candidates = data?.candidates ?? [];
  const noSelectable = data !== undefined && candidates.every((c) => !c.selectable);

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
          <Stack spacing={1.5} sx={{ pt: 0.5 }}>
            {candidates.length === 0 && (
              <Typography variant="body2" color="text.secondary">
                Aucun instrumentiste disponible pour cette mission.
              </Typography>
            )}

            {noSelectable && candidates.length > 0 && (
              <Alert severity="warning">
                Aucun instrumentiste sélectionnable — tous les candidats ont des contraintes.
              </Alert>
            )}

            {candidates.length > 0 && (
              <Stack spacing={0.75} data-testid="reassign-candidate-list">
                {candidates.map((c) => {
                  const isSelected = c.id === selected && c.selectable;
                  return (
                    <Stack
                      key={c.id}
                      direction="row"
                      alignItems="center"
                      spacing={1.25}
                      onClick={() => selectCandidate(c)}
                      aria-disabled={!c.selectable}
                      data-testid={`reassign-candidate-${c.id}`}
                      sx={{
                        p: 1,
                        borderRadius: 1.5,
                        cursor: c.selectable ? "pointer" : "not-allowed",
                        border: "1px solid",
                        borderColor: isSelected ? "primary.main" : "divider",
                        bgcolor: isSelected ? "action.selected" : "transparent",
                        opacity: c.selectable ? 1 : 0.5,
                      }}
                    >
                      <Typography variant="body2" fontWeight={600} sx={{ flex: 1 }}>
                        {c.name}
                      </Typography>
                      {!c.selectable && (
                        <Stack direction="row" spacing={0.5} flexWrap="wrap" justifyContent="flex-end">
                          {c.reasons.map((r) => (
                            <Chip
                              key={r}
                              label={eligibilityReasonLabel(r)}
                              size="small"
                              variant="outlined"
                              sx={{ fontSize: 10, height: 18 }}
                              data-testid={`reason-chip-${r}`}
                            />
                          ))}
                        </Stack>
                      )}
                      {isSelected && <CheckIcon fontSize="small" color="primary" />}
                    </Stack>
                  );
                })}
              </Stack>
            )}
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={loading}>Annuler</Button>
        <Button
          onClick={handleConfirm}
          disabled={loading || selected === "" || !!noSelectable}
          variant="contained"
          disableElevation
        >
          {loading ? "En cours…" : "Réassigner"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
