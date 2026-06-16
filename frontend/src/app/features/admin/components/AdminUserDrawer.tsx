import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Divider,
  Drawer,
  IconButton,
  Stack,
  Tooltip,
  Typography,
} from "@mui/material";
import CloseIcon       from "@mui/icons-material/Close";
import EmailIcon       from "@mui/icons-material/Email";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  getAdminUser,
  resendAdminInvitation,
  removeAdminSiteMembership,
} from "../api/admin.api";
import type { AdminUserDetail } from "../api/admin.types";
import { InvitationStatusChip } from "./InvitationStatusChip";
import { AdminChangeRoleModal } from "./AdminChangeRoleModal";
import { AdminSuspendModal } from "./AdminSuspendModal";

const DRAWER_WIDTH = 420;

interface Props {
  userId: number | null;
  onClose: () => void;
}

export function AdminUserDrawer({ userId, onClose }: Props) {
  const qc = useQueryClient();
  const [changeRoleOpen, setChangeRoleOpen] = React.useState(false);
  const [suspendOpen, setSuspendOpen] = React.useState(false);
  const [localUser, setLocalUser] = React.useState<AdminUserDetail | null>(null);

  const query = useQuery({
    queryKey: ["admin-user", userId],
    queryFn: () => getAdminUser(userId!),
    enabled: userId !== null,
  });

  const user = localUser ?? query.data ?? null;

  React.useEffect(() => {
    setLocalUser(null);
  }, [userId]);

  const resendMutation = useMutation({
    mutationFn: () => resendAdminInvitation(userId!),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-user", userId] });
      qc.invalidateQueries({ queryKey: ["admin-users"] });
      qc.invalidateQueries({ queryKey: ["admin-invitations"] });
    },
  });

  const removeSiteMutation = useMutation({
    mutationFn: (membershipId: number) => removeAdminSiteMembership(userId!, membershipId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-user", userId] });
      qc.invalidateQueries({ queryKey: ["admin-users"] });
    },
  });

  const canResend =
    user !== null && user.invitationStatus !== "used" && user.invitationStatus !== "none";

  return (
    <>
      <Drawer
        anchor="right"
        open={userId !== null}
        onClose={onClose}
        PaperProps={{ sx: { width: DRAWER_WIDTH, p: 0 } }}
      >
        <Stack sx={{ height: "100%", overflow: "hidden" }}>
          {/* Header */}
          <Stack
            direction="row"
            alignItems="center"
            justifyContent="space-between"
            sx={{ px: 3, py: 2, borderBottom: "1px solid", borderColor: "divider" }}
          >
            <Typography variant="h6">Utilisateur</Typography>
            <IconButton onClick={onClose} size="small"><CloseIcon /></IconButton>
          </Stack>

          {/* Content */}
          <Box sx={{ flex: 1, overflowY: "auto", px: 3, py: 2 }}>
            {query.isLoading && (
              <Box sx={{ display: "flex", justifyContent: "center", mt: 4 }}>
                <CircularProgress size={28} />
              </Box>
            )}

            {query.isError && (
              <Alert severity="error">Impossible de charger cet utilisateur.</Alert>
            )}

            {user && (
              <Stack spacing={3}>
                {/* Identity */}
                <Box>
                  <Typography variant="subtitle2" color="text.secondary" gutterBottom>
                    Identité
                  </Typography>
                  <Typography variant="body1" fontWeight={600}>{user.displayName}</Typography>
                  <Typography variant="body2" color="text.secondary">{user.email}</Typography>
                  {user.phone && (
                    <Typography variant="body2" color="text.secondary">{user.phone}</Typography>
                  )}
                </Box>

                <Divider />

                {/* Role & Status */}
                <Box>
                  <Typography variant="subtitle2" color="text.secondary" gutterBottom>
                    Rôle & Statut
                  </Typography>
                  <Stack direction="row" spacing={1} flexWrap="wrap">
                    <Chip label={user.role} size="small" variant="outlined" />
                    <Chip
                      label={user.active ? "Actif" : "Suspendu"}
                      color={user.active ? "success" : "error"}
                      size="small"
                    />
                  </Stack>
                </Box>

                <Divider />

                {/* Invitation */}
                <Box>
                  <Typography variant="subtitle2" color="text.secondary" gutterBottom>
                    Invitation
                  </Typography>
                  <Stack spacing={1}>
                    <InvitationStatusChip status={user.invitationStatus} />
                    {user.invitationExpiresAt && user.invitationStatus === "pending" && (
                      <Typography variant="caption" color="text.secondary">
                        Expire le {new Date(user.invitationExpiresAt).toLocaleString("fr-BE")}
                      </Typography>
                    )}
                    {user.invitationLastSentAt && (
                      <Typography variant="caption" color="text.secondary">
                        Dernier envoi : {new Date(user.invitationLastSentAt).toLocaleString("fr-BE")}
                      </Typography>
                    )}
                  </Stack>
                </Box>

                <Divider />

                {/* Sites */}
                <Box>
                  <Typography variant="subtitle2" color="text.secondary" gutterBottom>
                    Sites ({user.siteMemberships.length})
                  </Typography>
                  <Stack spacing={0.5}>
                    {user.siteMemberships.length === 0 ? (
                      <Typography variant="body2" color="text.secondary">Aucun site assigné.</Typography>
                    ) : (
                      user.siteMemberships.map((m) => (
                        <Stack key={m.id} direction="row" alignItems="center" justifyContent="space-between">
                          <Typography variant="body2">{m.site.name}</Typography>
                          <Tooltip title="Retirer ce site">
                            <IconButton
                              size="small"
                              color="error"
                              onClick={() => removeSiteMutation.mutate(m.id)}
                              disabled={removeSiteMutation.isPending}
                            >
                              <CloseIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                        </Stack>
                      ))
                    )}
                  </Stack>
                </Box>
              </Stack>
            )}
          </Box>

          {/* Footer actions */}
          {user && (
            <Box sx={{ px: 3, py: 2, borderTop: "1px solid", borderColor: "divider" }}>
              <Stack spacing={1}>
                {canResend && (
                  <Button
                    startIcon={resendMutation.isPending ? <CircularProgress size={16} /> : <EmailIcon />}
                    variant="outlined"
                    size="small"
                    onClick={() => resendMutation.mutate()}
                    disabled={resendMutation.isPending}
                    fullWidth
                  >
                    Renvoyer l&apos;invitation
                  </Button>
                )}
                <Button
                  variant="outlined"
                  size="small"
                  onClick={() => setChangeRoleOpen(true)}
                  disabled={user.role === "ADMIN"}
                  fullWidth
                >
                  Changer le rôle
                </Button>
                <Button
                  variant="outlined"
                  color={user.active ? "error" : "success"}
                  size="small"
                  onClick={() => setSuspendOpen(true)}
                  fullWidth
                >
                  {user.active ? "Suspendre" : "Réactiver"}
                </Button>
              </Stack>
            </Box>
          )}
        </Stack>
      </Drawer>

      <AdminChangeRoleModal
        open={changeRoleOpen}
        user={user}
        onClose={() => setChangeRoleOpen(false)}
        onSuccess={(updated) => {
          setLocalUser(updated);
          qc.invalidateQueries({ queryKey: ["admin-user", userId] });
        }}
      />

      <AdminSuspendModal
        open={suspendOpen}
        user={user}
        onClose={() => setSuspendOpen(false)}
        onSuccess={(result) => {
          if (user) setLocalUser({ ...user, active: result.active });
          qc.invalidateQueries({ queryKey: ["admin-user", userId] });
        }}
      />
    </>
  );
}
