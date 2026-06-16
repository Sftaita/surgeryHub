import * as React from "react";
import {
  Alert,
  Box,
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
  TextField,
  Typography,
} from "@mui/material";
import { useQuery } from "@tanstack/react-query";
import { getAdminAudit } from "../../features/admin/api/admin.api";
import type { AdminAuditEvent } from "../../features/admin/api/admin.types";

const EVENT_TYPE_OPTIONS = [
  { value: "",                          label: "Tous les événements" },
  { value: "USER_CREATED",              label: "Création" },
  { value: "USER_INVITATION_SENT",      label: "Invitation envoyée" },
  { value: "USER_INVITATION_RESENT",    label: "Invitation renvoyée" },
  { value: "USER_INVITATION_COMPLETED", label: "Invitation complétée" },
  { value: "USER_SUSPENDED",            label: "Suspension" },
  { value: "USER_REACTIVATED",          label: "Réactivation" },
  { value: "USER_ROLE_CHANGED",         label: "Rôle modifié" },
  { value: "USER_SITE_ADDED",           label: "Site ajouté" },
  { value: "USER_SITE_REMOVED",         label: "Site retiré" },
];

function formatDate(iso: string) {
  return new Date(iso).toLocaleString("fr-BE", {
    day: "2-digit", month: "2-digit", year: "numeric",
    hour: "2-digit", minute: "2-digit",
  });
}

function EventTypeLabel({ value }: { value: string }) {
  const opt = EVENT_TYPE_OPTIONS.find((o) => o.value === value);
  return (
    <Typography variant="caption" sx={{ fontFamily: "monospace" }}>
      {opt?.label ?? value}
    </Typography>
  );
}

export default function AdminAuditPage() {
  const [eventType, setEventType] = React.useState("");
  const [from, setFrom]           = React.useState("");
  const [to, setTo]               = React.useState("");

  const query = useQuery({
    queryKey: ["admin-audit", eventType, from, to],
    queryFn: () => getAdminAudit({
      eventType: eventType || undefined,
      from:      from || undefined,
      to:        to   || undefined,
      limit:     200,
    }),
  });

  const items: AdminAuditEvent[] = query.data?.items ?? [];

  return (
    <Box>
      <Typography variant="h5" fontWeight={600} sx={{ mb: 3 }}>
        Journal d&apos;audit
      </Typography>

      <Stack direction="row" spacing={2} sx={{ mb: 2 }} flexWrap="wrap">
        <Select
          size="small"
          value={eventType}
          onChange={(e) => setEventType(e.target.value)}
          displayEmpty
          sx={{ minWidth: 220 }}
        >
          {EVENT_TYPE_OPTIONS.map((o) => (
            <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>
          ))}
        </Select>

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

      {query.isLoading && (
        <Box sx={{ display: "flex", justifyContent: "center", mt: 6 }}>
          <CircularProgress size={28} />
        </Box>
      )}

      {query.isError && (
        <Alert severity="error">Impossible de charger le journal d&apos;audit.</Alert>
      )}

      {!query.isLoading && !query.isError && (
        <TableContainer>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Date</TableCell>
                <TableCell>Événement</TableCell>
                <TableCell>Acteur</TableCell>
                <TableCell>Utilisateur cible</TableCell>
                <TableCell>Description</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {items.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5} align="center">
                    <Typography variant="body2" color="text.secondary" sx={{ py: 4 }}>
                      Aucun événement trouvé.
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                items.map((e) => (
                  <TableRow key={e.id}>
                    <TableCell sx={{ whiteSpace: "nowrap" }}>
                      <Typography variant="caption">{formatDate(e.createdAt)}</Typography>
                    </TableCell>
                    <TableCell>
                      <EventTypeLabel value={e.eventType} />
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">{e.actor?.displayName ?? "—"}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2">{e.targetUser?.displayName ?? "—"}</Typography>
                    </TableCell>
                    <TableCell>
                      <Typography variant="body2" color="text.secondary">{e.description}</Typography>
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
          {query.data.total} événement{query.data.total !== 1 ? "s" : ""}
          {query.data.total >= 200 && " (limité à 200 — affinez les filtres)"}
        </Typography>
      )}
    </Box>
  );
}
