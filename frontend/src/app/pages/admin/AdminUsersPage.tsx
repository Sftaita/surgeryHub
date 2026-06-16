import * as React from "react";
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  InputAdornment,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from "@mui/material";
import SearchIcon   from "@mui/icons-material/Search";
import AddIcon      from "@mui/icons-material/Add";
import { useQuery } from "@tanstack/react-query";
import { getAdminUsers } from "../../features/admin/api/admin.api";
import { InvitationStatusChip } from "../../features/admin/components/InvitationStatusChip";
import { AdminUserDrawer } from "../../features/admin/components/AdminUserDrawer";
import { AdminCreateUserModal } from "../../features/admin/components/AdminCreateUserModal";
import type { AdminUserListItem } from "../../features/admin/api/admin.types";

const ROLE_OPTIONS = [
  { value: "", label: "Tous les rôles" },
  { value: "ROLE_INSTRUMENTIST", label: "Instrumentiste" },
  { value: "ROLE_SURGEON",       label: "Chirurgien" },
  { value: "ROLE_MANAGER",       label: "Manager" },
  { value: "ROLE_ADMIN",         label: "Admin" },
];

const STATUS_OPTIONS = [
  { value: "",      label: "Tous les statuts" },
  { value: "true",  label: "Actifs" },
  { value: "false", label: "Suspendus" },
];

export default function AdminUsersPage() {
  const [search, setSearch] = React.useState("");
  const [role, setRole]     = React.useState("");
  const [active, setActive] = React.useState("");
  const [debouncedSearch, setDebouncedSearch] = React.useState("");
  const [selectedUserId, setSelectedUserId]   = React.useState<number | null>(null);
  const [createOpen, setCreateOpen]           = React.useState(false);

  React.useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(t);
  }, [search]);

  const query = useQuery({
    queryKey: ["admin-users", debouncedSearch, role, active],
    queryFn: () => getAdminUsers({
      search: debouncedSearch || undefined,
      role:   role || undefined,
      active: active !== "" ? active === "true" : undefined,
    }),
  });

  const items: AdminUserListItem[] = query.data?.items ?? [];

  return (
    <Box>
      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 3 }}>
        <Typography variant="h5" fontWeight={600}>Utilisateurs</Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreateOpen(true)}>
          Créer un utilisateur
        </Button>
      </Stack>

      {/* Filtres */}
      <Stack direction="row" spacing={2} sx={{ mb: 2 }} flexWrap="wrap">
        <TextField
          size="small"
          placeholder="Rechercher…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          InputProps={{ startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment> }}
          sx={{ width: 260 }}
        />
        <Select
          size="small"
          value={role}
          onChange={(e) => setRole(e.target.value)}
          displayEmpty
          sx={{ minWidth: 180 }}
        >
          {ROLE_OPTIONS.map((o) => (
            <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>
          ))}
        </Select>
        <Select
          size="small"
          value={active}
          onChange={(e) => setActive(e.target.value)}
          displayEmpty
          sx={{ minWidth: 160 }}
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
        <Alert severity="error">Impossible de charger les utilisateurs.</Alert>
      )}

      {!query.isLoading && !query.isError && (
        <TableContainer>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Nom</TableCell>
                <TableCell>Email</TableCell>
                <TableCell>Rôle</TableCell>
                <TableCell>Statut</TableCell>
                <TableCell>Invitation</TableCell>
                <TableCell>Sites</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} align="center">
                    <Typography variant="body2" color="text.secondary" sx={{ py: 4 }}>
                      Aucun utilisateur trouvé.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                items.map((u) => (
                  <TableRow
                    key={u.id}
                    hover
                    sx={{ cursor: "pointer" }}
                    onClick={() => setSelectedUserId(u.id)}
                  >
                    <TableCell>
                      <Typography variant="body2" fontWeight={500}>{u.displayName}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" color="text.secondary">{u.email}</Typography>
                    </TableCell>
                    <TableCell>
                      <Chip label={u.role} size="small" variant="outlined" />
                    </TableCell>
                    <TableCell>
                      <Chip
                        label={u.active ? "Actif" : "Suspendu"}
                        color={u.active ? "success" : "error"}
                        size="small"
                      />
                    </TableCell>
                    <TableCell>
                      <InvitationStatusChip status={u.invitationStatus} />
                    </TableCell>
                    <TableCell>
                      <Typography variant="caption" color="text.secondary">
                        {u.sites.map((s) => s.name).join(", ") || "—"}
                      </Typography>
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
          {query.data.total} utilisateur{query.data.total !== 1 ? "s" : ""}
        </Typography>
      )}

      <AdminUserDrawer
        userId={selectedUserId}
        onClose={() => setSelectedUserId(null)}
      />

      <AdminCreateUserModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
      />
    </Box>
  );
}
