import * as React from "react";
import {
  Alert,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Typography,
} from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { suspendAdminUser, activateAdminUser } from "../api/admin.api";
import type { AdminUserDetail } from "../api/admin.types";

interface Props {
  open: boolean;
  user: AdminUserDetail | null;
  onClose: () => void;
  onSuccess: (result: { id: number; active: boolean }) => void;
}

export function AdminSuspendModal({ open, user, onClose, onSuccess }: Props) {
  const qc = useQueryClient();
  const isSuspending = user?.active ?? false;

  const mutation = useMutation({
    mutationFn: () => {
      if (!user) throw new Error("No user selected");
      return isSuspending ? suspendAdminUser(user.id) : activateAdminUser(user.id);
    },
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ["admin-users"] });
      onSuccess(result);
      onClose();
    },
  });

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle>{isSuspending ? "Suspendre l'utilisateur" : "Réactiver l'utilisateur"}</DialogTitle>
      <DialogContent dividers>
        {mutation.isError && (
          <Alert severity="error" sx={{ mb: 2 }}>
            Une erreur est survenue.
          </Alert>
        )}
        <Typography variant="body2">
          {isSuspending ? (
            <>
              Voulez-vous suspendre le compte de <strong>{user?.displayName}</strong> ?
              L&apos;utilisateur ne pourra plus se connecter.
            </>
          ) : (
            <>
              Voulez-vous réactiver le compte de <strong>{user?.displayName}</strong> ?
              L&apos;utilisateur pourra de nouveau se connecter.
            </>
          )}
        </Typography>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose} disabled={mutation.isPending}>Annuler</Button>
        <Button
          variant="contained"
          color={isSuspending ? "error" : "success"}
          onClick={() => mutation.mutate()}
          disabled={mutation.isPending}
        >
          {mutation.isPending ? (
            <CircularProgress size={18} color="inherit" />
          ) : isSuspending ? "Suspendre" : "Réactiver"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
