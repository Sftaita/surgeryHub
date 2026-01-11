import { useEffect, useMemo, useState } from "react";
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import type {
  PublishMissionRequest,
  PublishScope,
} from "../api/missions.requests";

type Props = {
  open: boolean;
  onClose: () => void;
  onSubmit: (payload: PublishMissionRequest) => void;
  isSubmitting?: boolean;
  errorMessage?: string | null;
};

export default function PublishMissionDialog({
  open,
  onClose,
  onSubmit,
  isSubmitting = false,
  errorMessage = null,
}: Props) {
  const [scope, setScope] = useState<PublishScope>("POOL");
  const [targetUserId, setTargetUserId] = useState<number | "">("");
  const [localError, setLocalError] = useState<string | null>(null);

  const payload = useMemo<PublishMissionRequest | null>(() => {
    if (scope === "POOL") return { scope: "POOL" };
    if (targetUserId === "" || Number.isNaN(Number(targetUserId))) return null;
    return { scope: "TARGETED", targetUserId: Number(targetUserId) };
  }, [scope, targetUserId]);

  useEffect(() => {
    if (open) {
      setScope("POOL");
      setTargetUserId("");
      setLocalError(null);
    }
  }, [open]);

  const handleSubmit = () => {
    if (scope === "TARGETED") {
      if (
        targetUserId === "" ||
        Number.isNaN(Number(targetUserId)) ||
        Number(targetUserId) <= 0
      ) {
        setLocalError(
          "targetUserId est requis et doit être un entier > 0 pour un publish TARGETED."
        );
        return;
      }
    }
    setLocalError(null);
    onSubmit(payload ?? { scope: "POOL" });
  };

  return (
    <Dialog
      open={open}
      onClose={isSubmitting ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Publier la mission</DialogTitle>
      <Divider />
      <DialogContent>
        <Stack spacing={2} sx={{ mt: 2 }}>
          <Typography variant="body2" color="text.secondary">
            Publication selon le scope: POOL (pool global) ou TARGETED (vers un
            utilisateur). Aucun champ patient ni financier n’est impliqué.
          </Typography>

          {(localError || errorMessage) && (
            <Alert severity="error">{localError ?? errorMessage}</Alert>
          )}

          <TextField
            label="scope"
            value={scope}
            onChange={(e) => setScope(e.target.value as PublishScope)}
            disabled={isSubmitting}
            fullWidth
            select
            SelectProps={{ native: true }}
          >
            <option value="POOL">POOL</option>
            <option value="TARGETED">TARGETED</option>
          </TextField>

          {scope === "TARGETED" && (
            <TextField
              label="targetUserId"
              value={targetUserId}
              onChange={(e) =>
                setTargetUserId(
                  e.target.value === "" ? "" : Number(e.target.value)
                )
              }
              disabled={isSubmitting}
              fullWidth
              type="number"
              inputProps={{ min: 1 }}
            />
          )}
        </Stack>
      </DialogContent>

      <DialogActions sx={{ p: 2 }}>
        <Button onClick={onClose} disabled={isSubmitting}>
          Annuler
        </Button>
        <Button
          variant="contained"
          onClick={handleSubmit}
          disabled={isSubmitting || (scope === "TARGETED" && !payload)}
        >
          Publier
        </Button>
      </DialogActions>
    </Dialog>
  );
}
