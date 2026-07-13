import { useState } from "react";
import {
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
  Typography,
} from "@mui/material";
import { useMutation } from "@tanstack/react-query";

import { patchUserEmail, UserEmailChangeResponseDTO } from "../api/userEmail.api";
import { useToast } from "../../../ui/toast/useToast";
import { extractErrorMessage } from "../utils/instrumentists.utils";

type UserEmailEditorProps = {
  userId: number;
  currentEmail: string;
  onChanged: (user: UserEmailChangeResponseDTO["user"]) => void;
};

/**
 * Shared by InstrumentistDrawer and SurgeonDrawer — the email belongs to the same
 * User aggregate regardless of role, so this is intentionally not duplicated.
 */
export function UserEmailEditor({
  userId,
  currentEmail,
  onChanged,
}: UserEmailEditorProps) {
  const toast = useToast();
  const [editing, setEditing] = useState(false);
  const [draftEmail, setDraftEmail] = useState(currentEmail);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const mutation = useMutation({
    mutationFn: (email: string) => patchUserEmail(userId, email),
    onSuccess: (result) => {
      setConfirmOpen(false);
      setEditing(false);
      onChanged(result.user);

      if (result.warnings.length > 0) {
        toast.warning(
          "Adresse email modifiée, mais une notification n'a pas pu être envoyée.",
        );
      } else {
        toast.success("Adresse email modifiée");
      }
    },
    onError: (err: any) => {
      setConfirmOpen(false);
      toast.error(extractErrorMessage(err));
    },
  });

  function startEditing() {
    setDraftEmail(currentEmail);
    setEditing(true);
  }

  function cancelEditing() {
    setDraftEmail(currentEmail);
    setEditing(false);
  }

  function requestConfirmation() {
    const trimmed = draftEmail.trim();
    if (trimmed === "" || trimmed === currentEmail) {
      return;
    }
    setConfirmOpen(true);
  }

  if (!editing) {
    return (
      <Box>
        <Typography variant="caption" color="text.secondary">
          Adresse email
        </Typography>
        <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between">
          <Typography variant="body2">{currentEmail}</Typography>
          <Button size="small" onClick={startEditing}>
            Modifier
          </Button>
        </Stack>
      </Box>
    );
  }

  return (
    <Box>
      <Dialog open={confirmOpen} onClose={() => (!mutation.isPending ? setConfirmOpen(false) : undefined)} fullWidth maxWidth="sm">
        <DialogTitle>Modifier l'adresse email ?</DialogTitle>
        <DialogContent>
          <Stack spacing={1.5} sx={{ mt: 1 }}>
            <Typography variant="body2">
              L'utilisateur devra désormais utiliser <strong>{draftEmail.trim()}</strong>.
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Une notification sera envoyée à :
            </Typography>
            <Box component="ul" sx={{ m: 0, pl: 3 }}>
              <li>
                <Typography variant="body2" color="text.secondary">
                  {currentEmail}
                </Typography>
              </li>
              <li>
                <Typography variant="body2" color="text.secondary">
                  {draftEmail.trim()}
                </Typography>
              </li>
            </Box>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmOpen(false)} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button
            variant="contained"
            onClick={() => mutation.mutate(draftEmail.trim())}
            disabled={mutation.isPending}
          >
            {mutation.isPending ? "Enregistrement…" : "Confirmer la modification"}
          </Button>
        </DialogActions>
      </Dialog>

      <Typography variant="caption" color="text.secondary">
        Nouvelle adresse email
      </Typography>
      <Stack spacing={1}>
        <TextField
          value={draftEmail}
          onChange={(event) => setDraftEmail(event.target.value)}
          size="small"
          fullWidth
          disabled={mutation.isPending}
          type="email"
        />
        <Stack direction="row" spacing={1} justifyContent="flex-end">
          <Button onClick={cancelEditing} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button
            variant="contained"
            onClick={requestConfirmation}
            disabled={mutation.isPending}
          >
            Enregistrer
          </Button>
        </Stack>
      </Stack>
    </Box>
  );
}
