import * as React from "react";
import {
  Alert,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Radio,
  RadioGroup,
  Typography,
} from "@mui/material";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { changeAdminUserRole } from "../api/admin.api";
import type { AdminChangeRolePayload, AdminUserDetail } from "../api/admin.types";

const ROLES: Array<{ value: AdminChangeRolePayload["newRole"]; label: string }> = [
  { value: "ROLE_INSTRUMENTIST", label: "Instrumentiste" },
  { value: "ROLE_SURGEON",       label: "Chirurgien" },
  { value: "ROLE_MANAGER",       label: "Manager" },
];

const ROLE_TO_SYMFONY: Record<string, AdminChangeRolePayload["newRole"]> = {
  INSTRUMENTIST: "ROLE_INSTRUMENTIST",
  SURGEON:       "ROLE_SURGEON",
  MANAGER:       "ROLE_MANAGER",
};

interface Props {
  open: boolean;
  user: AdminUserDetail | null;
  onClose: () => void;
  onSuccess: (updated: AdminUserDetail) => void;
}

export function AdminChangeRoleModal({ open, user, onClose, onSuccess }: Props) {
  const qc = useQueryClient();
  const initialRole: AdminChangeRolePayload["newRole"] =
    (user ? ROLE_TO_SYMFONY[user.role] : undefined) ?? "ROLE_INSTRUMENTIST";
  const [newRole, setNewRole] = React.useState<AdminChangeRolePayload["newRole"]>(initialRole);

  React.useEffect(() => {
    if (user) setNewRole(ROLE_TO_SYMFONY[user.role] ?? "ROLE_INSTRUMENTIST");
  }, [user]);

  const mutation = useMutation({
    mutationFn: (payload: AdminChangeRolePayload) => changeAdminUserRole(user!.id, payload),
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ["admin-users"] });
      onSuccess(updated);
      onClose();
    },
  });

  function handleSubmit() {
    if (!user) return;
    mutation.mutate({ newRole });
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle>Changer le rôle</DialogTitle>
      <DialogContent dividers>
        {mutation.isError && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {(mutation.error as Error & { response?: { data?: { detail?: string } } })
              ?.response?.data?.detail ?? "Erreur lors du changement de rôle."}
          </Alert>
        )}
        <Typography variant="body2" sx={{ mb: 2 }}>
          Rôle actuel de <strong>{user?.displayName}</strong> : {user?.role}
        </Typography>
        <RadioGroup
          value={newRole}
          onChange={(e) => setNewRole(e.target.value as AdminChangeRolePayload["newRole"])}
        >
          {ROLES.map((r) => (
            <FormControlLabel
              key={r.value}
              value={r.value}
              control={<Radio size="small" />}
              label={r.label}
              disabled={ROLE_TO_SYMFONY[user?.role ?? ""] === r.value}
            />
          ))}
        </RadioGroup>
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button onClick={onClose} disabled={mutation.isPending}>Annuler</Button>
        <Button
          variant="contained"
          color="warning"
          onClick={handleSubmit}
          disabled={mutation.isPending || ROLE_TO_SYMFONY[user?.role ?? ""] === newRole}
        >
          {mutation.isPending ? <CircularProgress size={18} color="inherit" /> : "Confirmer"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
