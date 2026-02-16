import * as React from "react";
import {
  Button,
  Checkbox,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Stack,
  TextField,
} from "@mui/material";

import { submitMission } from "../api/missions.api";
import { useToast } from "../../../ui/toast/useToast";

type Props = {
  open: boolean;
  missionId: number;
  onClose: () => void;
  onSubmitted?: () => void;
};

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

export default function SubmitDialog({
  open,
  missionId,
  onClose,
  onSubmitted,
}: Props) {
  const toast = useToast();
  const [noMaterial, setNoMaterial] = React.useState<boolean>(true);
  const [comment, setComment] = React.useState<string>("RAS");
  const [loading, setLoading] = React.useState<boolean>(false);

  React.useEffect(() => {
    if (!open) return;
    setNoMaterial(true);
    setComment("RAS");
    setLoading(false);
  }, [open]);

  const handleSubmit = async () => {
    if (loading) return;
    setLoading(true);

    try {
      await submitMission(missionId, { noMaterial, comment });
      toast.success("Mission soumise");
      onClose();
      onSubmitted?.();
    } catch (err: any) {
      // Règle: afficher le message brut backend
      toast.error(extractErrorMessage(err));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog
      open={open}
      onClose={loading ? undefined : onClose}
      fullWidth
      maxWidth="sm"
    >
      <DialogTitle>Submit</DialogTitle>

      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <FormControlLabel
            control={
              <Checkbox
                checked={noMaterial}
                onChange={(e) => setNoMaterial(e.target.checked)}
                disabled={loading}
              />
            }
            label="Aucun matériel (noMaterial)"
          />

          <TextField
            label="Commentaire"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
            disabled={loading}
            fullWidth
            multiline
            minRows={3}
            placeholder="RAS"
          />
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={loading}>
          Annuler
        </Button>
        <Button variant="contained" onClick={handleSubmit} disabled={loading}>
          {loading ? "..." : "Valider"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
