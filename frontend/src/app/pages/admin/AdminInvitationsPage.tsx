import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
} from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { getAdminInvitations, resendAdminInvitation } from "../../features/admin/api/admin.api";
import { InvitationStatusChip } from "../../features/admin/components/InvitationStatusChip";
import type { AdminInvitationItem, InvitationStatus } from "../../features/admin/api/admin.types";

const STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: "",               label: "Tous les statuts" },
  { value: "pending",        label: "En attente" },
  { value: "expired",        label: "Expirés" },
  { value: "email_not_sent", label: "Email non envoyé" },
  { value: "used",           label: "Activés" },
  { value: "none",           label: "Sans invitation" },
];

function ResendButton({ item }: { item: AdminInvitationItem }) {
  const qc = useQueryClient();
  const mutation = useMutation({
    mutationFn: () => resendAdminInvitation(item.id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["admin-invitations"] });
      qc.invalidateQueries({ queryKey: ["admin-users"] });
    },
  });

  const canResend = item.invitationStatus !== "used" && item.invitationStatus !== "none";
  if (!canResend) return null;

  return (
    <Button
      size="small"
      variant="text"
      onClick={(e) => { e.stopPropagation(); mutation.mutate(); }}
      disabled={mutation.isPending}
    >
      {mutation.isPending ? <CircularProgress size={14} /> : "Renvoyer"}
    </Button>
  );
}

export default function AdminInvitationsPage() {
  const [status, setStatus] = React.useState<string>("");

  const query = useQuery({
    queryKey: ["admin-invitations", status],
    queryFn: () => getAdminInvitations(status ? { status } : undefined),
  });

  const items: AdminInvitationItem[] = query.data?.items ?? [];

  return (
    <Box>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 3 }}>
        <Typography variant="h5" fontWeight={600}>Invitations</Typography>
      </Stack>

      <Stack direction="row" spacing={2} sx={{ mb: 2 }}>
        <Select
          size="small"
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          displayEmpty
          sx={{ minWidth: 200 }}
        >
          {STATUS_OPTIONS.map((o) => (
            <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>
          ))}
        </Select>
      </Stack>

      {query.isLoading && (
        <Box sx={{ display: "flex", justifyContent: "center", mt: 6 }}>
          <CircularProgress size={28} />
        </Box>
      )}

      {query.isError && (
        <Alert severity="error">Impossible de charger les invitations.</Alert>
      )}

      {!query.isLoading && !query.isError && (
        <TableContainer>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Utilisateur</TableCell>
                <TableCell>Rôle</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell>Expiration</TableCell>
                <TableCell>Dernier envoi</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} align="center">
                    <Typography variant="body2" color="text.secondary" sx={{ py: 4 }}>
                      Aucune invitation trouvée.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                items.map((inv) => (
                  <TableRow key={inv.id}>
                    <TableCell>
                      <Typography variant="body2" fontWeight={500}>{inv.displayName}</Typography>
                      <Typography variant="caption" color="text.secondary">{inv.email}</Typography>
                    </TableCell>
                    <TableCell>
                      <Chip label={inv.role} size="small" variant="outlined" />
                    </TableCell>
                    <TableCell>
                      <InvitationStatusChip status={inv.invitationStatus as InvitationStatus} />
                    </TableCell>
                    <TableCell>
                      <Typography variant="caption" color="text.secondary">
                        {inv.invitationExpiresAt
                          ? new Date(inv.invitationExpiresAt).toLocaleString("fr-BE")
                          : "—"}
                      </Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="caption" color="text.secondary">
                        {inv.invitationLastSentAt
                          ? new Date(inv.invitationLastSentAt).toLocaleString("fr-BE")
                          : "—"}
                      </Typography>
                    </TableCell>
                    <TableCell align="right">
                      <ResendButton item={inv} />
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      {!query.isLoading && query.data && (
        <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: "block" }}>
          {query.data.total} entrée{query.data.total !== 1 ? "s" : ""}
        </Typography>
      )}
    </Box>
  );
}
