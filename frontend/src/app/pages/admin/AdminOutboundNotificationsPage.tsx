import * as React from "react";
import {
  Alert,
  Box,
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
  TablePagination,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from "@mui/material";
import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import { useQuery } from "@tanstack/react-query";
import { getAdminOutboundNotifications } from "../../features/admin/api/admin.api";
import type {
  OutboundNotificationChannel,
  OutboundNotificationListItem,
  OutboundNotificationStatus,
} from "../../features/admin/api/admin.types";
import { AdminOutboundNotificationDrawer } from "../../features/admin/components/AdminOutboundNotificationDrawer";
import { useDebouncedValue } from "../../ui/hooks/useDebouncedValue";

const CHANNEL_OPTIONS: { value: OutboundNotificationChannel | ""; label: string }[] = [
  { value: "",      label: "Tous les canaux" },
  { value: "PUSH",  label: "Push" },
  { value: "EMAIL", label: "Email" },
];

/**
 * Libellés + aide contextuelle honnêtes (D-084) — SENT ne signifie jamais "lu".
 */
const STATUS_OPTIONS: { value: OutboundNotificationStatus | ""; label: string }[] = [
  { value: "",        label: "Tous les statuts" },
  { value: "QUEUED",  label: "En attente" },
  { value: "SENT",    label: "Accepté par le fournisseur" },
  { value: "FAILED",  label: "Échec" },
  { value: "SKIPPED", label: "Non envoyé" },
];

function statusLabel(status: OutboundNotificationStatus): string {
  return STATUS_OPTIONS.find((o) => o.value === status)?.label ?? status;
}

function statusColor(status: OutboundNotificationStatus): "default" | "success" | "error" | "warning" {
  switch (status) {
    case "SENT": return "success";
    case "FAILED": return "error";
    case "SKIPPED": return "warning";
    default: return "default";
  }
}

function formatDate(iso: string) {
  return new Date(iso).toLocaleString("fr-BE", {
    day: "2-digit", month: "2-digit", year: "numeric",
    hour: "2-digit", minute: "2-digit",
  });
}

export default function AdminOutboundNotificationsPage() {
  const [channel, setChannel] = React.useState<OutboundNotificationChannel | "">("");
  const [status, setStatus] = React.useState<OutboundNotificationStatus | "">("");
  const [notificationType, setNotificationType] = React.useState("");
  const [missionId, setMissionId] = React.useState("");
  const [from, setFrom] = React.useState("");
  const [to, setTo] = React.useState("");
  const [searchInput, setSearchInput] = React.useState("");
  const search = useDebouncedValue(searchInput, 300);
  const [page, setPage] = React.useState(0); // MUI TablePagination is 0-indexed
  const [limit, setLimit] = React.useState(25);
  const [selectedId, setSelectedId] = React.useState<number | null>(null);

  // Any filter change resets to page 1 — a stale page number on a shrunk result set
  // would otherwise silently show an empty page instead of the actual first results.
  React.useEffect(() => {
    setPage(0);
  }, [channel, status, notificationType, missionId, from, to, search]);

  const query = useQuery({
    queryKey: ["admin-outbound-notifications", channel, status, notificationType, missionId, from, to, search, page, limit],
    queryFn: () => getAdminOutboundNotifications({
      channel: channel || undefined,
      status: status || undefined,
      notificationType: notificationType || undefined,
      missionId: missionId ? Number(missionId) : undefined,
      from: from || undefined,
      to: to || undefined,
      search: search || undefined,
      page: page + 1,
      limit,
    }),
  });

  const items: OutboundNotificationListItem[] = query.data?.items ?? [];

  return (
    <Box>
      <Typography variant="h5" fontWeight={600} sx={{ mb: 3 }}>
        Historique des notifications
      </Typography>

      <Stack direction="row" spacing={2} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
        <TextField
          size="small"
          label="Recherche"
          placeholder="Email, nom, sujet, titre…"
          value={searchInput}
          onChange={(e) => setSearchInput(e.target.value)}
          sx={{ minWidth: 220 }}
        />

        <Select
          size="small"
          value={channel}
          onChange={(e) => setChannel(e.target.value as OutboundNotificationChannel | "")}
          displayEmpty
          sx={{ minWidth: 160 }}
        >
          {CHANNEL_OPTIONS.map((o) => (
            <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>
          ))}
        </Select>

        <Select
          size="small"
          value={status}
          onChange={(e) => setStatus(e.target.value as OutboundNotificationStatus | "")}
          displayEmpty
          sx={{ minWidth: 220 }}
        >
          {STATUS_OPTIONS.map((o) => (
            <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>
          ))}
        </Select>

        <TextField
          size="small"
          label="Type"
          placeholder="ENCODING_REMINDER_D1"
          value={notificationType}
          onChange={(e) => setNotificationType(e.target.value)}
          sx={{ width: 200 }}
        />

        <TextField
          size="small"
          label="Mission #"
          type="number"
          value={missionId}
          onChange={(e) => setMissionId(e.target.value)}
          sx={{ width: 120 }}
        />

        <TextField
          size="small"
          type="date"
          label="Depuis"
          value={from}
          onChange={(e) => setFrom(e.target.value)}
          InputLabelProps={{ shrink: true }}
          sx={{ width: 160 }}
        />

        <TextField
          size="small"
          type="date"
          label="Jusqu'au"
          value={to}
          onChange={(e) => setTo(e.target.value)}
          InputLabelProps={{ shrink: true }}
          sx={{ width: 160 }}
        />
      </Stack>

      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1 }}>
        <Typography variant="caption" color="text.secondary">
          « Accepté par le fournisseur » ne garantit pas que le message a été lu.
        </Typography>
        <Tooltip title="SurgicalHub trace uniquement la remise au fournisseur (Push) ou au transport (email). Aucun accusé de lecture n'est disponible pour ces canaux.">
          <HelpOutlineIcon fontSize="inherit" sx={{ color: "text.secondary" }} />
        </Tooltip>
      </Stack>

      {query.isLoading && (
        <Box sx={{ display: "flex", justifyContent: "center", mt: 6 }}>
          <CircularProgress size={28} />
        </Box>
      )}

      {query.isError && (
        <Alert severity="error">Impossible de charger l&apos;historique des notifications.</Alert>
      )}

      {!query.isLoading && !query.isError && (
        <>
          <TableContainer>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Date</TableCell>
                  <TableCell>Destinataire</TableCell>
                  <TableCell>Canal</TableCell>
                  <TableCell>Type</TableCell>
                  <TableCell>Statut</TableCell>
                  <TableCell>Sujet / titre</TableCell>
                  <TableCell>Ressource liée</TableCell>
                  <TableCell align="right">Tentatives</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {items.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={8} align="center">
                      <Typography variant="body2" color="text.secondary" sx={{ py: 4 }}>
                        Aucune notification trouvée.
                      </Typography>
                    </TableCell>
                  </TableRow>
                ) : (
                  items.map((n) => (
                    <TableRow
                      key={n.id}
                      hover
                      onClick={() => setSelectedId(n.id)}
                      sx={{ cursor: "pointer" }}
                    >
                      <TableCell sx={{ whiteSpace: "nowrap" }}>
                        <Typography variant="caption">{formatDate(n.createdAt)}</Typography>
                      </TableCell>
                      <TableCell>
                        <Typography variant="body2">{n.recipient?.name ?? "—"}</Typography>
                      </TableCell>
                      <TableCell>
                        <Chip size="small" label={n.channel === "PUSH" ? "Push" : "Email"} variant="outlined" />
                      </TableCell>
                      <TableCell>
                        <Typography variant="caption" sx={{ fontFamily: "monospace" }}>{n.notificationType}</Typography>
                      </TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={0.5} alignItems="center">
                          <Chip size="small" label={statusLabel(n.status)} color={statusColor(n.status)} />
                          {n.fallback && <Chip size="small" label="Repli email" variant="outlined" />}
                        </Stack>
                      </TableCell>
                      <TableCell>
                        <Typography variant="body2" color="text.secondary">
                          {n.subject ?? n.title ?? "—"}
                        </Typography>
                      </TableCell>
                      <TableCell>
                        <Typography variant="body2" color="text.secondary">
                          {n.missionId ? `Mission #${n.missionId}` : "—"}
                        </Typography>
                      </TableCell>
                      <TableCell align="right">{n.attemptCount}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </TableContainer>

          <TablePagination
            component="div"
            count={query.data?.total ?? 0}
            page={page}
            onPageChange={(_, newPage) => setPage(newPage)}
            rowsPerPage={limit}
            onRowsPerPageChange={(e) => { setLimit(Number(e.target.value)); setPage(0); }}
            rowsPerPageOptions={[25, 50, 100]}
            labelRowsPerPage="Par page"
            labelDisplayedRows={({ from: f, to: t, count }) => `${f}–${t} sur ${count}`}
          />
        </>
      )}

      <AdminOutboundNotificationDrawer id={selectedId} onClose={() => setSelectedId(null)} />
    </Box>
  );
}
