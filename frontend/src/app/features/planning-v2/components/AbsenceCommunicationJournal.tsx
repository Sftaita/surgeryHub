import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress, MenuItem, Select, Stack, Table, TableBody,
  TableCell, TableHead, TableRow, TablePagination, TextField, Typography,
} from "@mui/material";
import RefreshOutlinedIcon from "@mui/icons-material/RefreshOutlined";

import {
  getAbsenceCommunicationJournal, getAbsenceCommunicationSettings, extractErrorV2,
  type AbsenceCommunicationJournalFilters,
} from "../api/planningV2.api";
import type {
  AbsenceCommunicationGlobalStatusV2, AbsenceCommunicationListItemV2, AbsenceCommunicationTypeV2,
} from "../api/planningV2.types";
import { useQuery } from "@tanstack/react-query";
import { getSurgeons } from "../../manager-surgeons/api/surgeons.api";
import type { SurgeonListItemDTO } from "../../manager-surgeons/api/surgeons.types";
import { planningV2Colors, planningV2Radii, planningV2Shadows, alertSeverityTokens } from "../theme/tokens";
import { AbsenceCommunicationJournalDrawer } from "./AbsenceCommunicationJournalDrawer";
import { AbsenceCommunicationBackfillDialog } from "./AbsenceCommunicationBackfillDialog";

const TYPE_LABELS: Record<AbsenceCommunicationTypeV2, string> = {
  ROOM_RELEASE: "Libération de salle",
  BLOCK_MANAGEMENT_ABSENCE: "Congé — gestion du bloc",
  BLOCK_MANAGEMENT_MODIFICATION: "Modification de congé",
  BLOCK_MANAGEMENT_CANCELLATION: "Annulation de congé",
};

const STATUS_LABELS: Record<AbsenceCommunicationGlobalStatusV2, string> = {
  SCHEDULED: "Programmé",
  SENT: "Envoyé",
  FAILED: "Échec",
  CANCELLED: "Annulé",
};

const STATUS_TOKENS: Record<AbsenceCommunicationGlobalStatusV2, { fg: string; bg: string }> = {
  SCHEDULED: alertSeverityTokens.info,
  SENT: alertSeverityTokens.ok,
  FAILED: alertSeverityTokens.crit,
  CANCELLED: { fg: planningV2Colors.textSecondary, bg: "#F1F4F7" },
};

function StatusChip({ status }: { status: AbsenceCommunicationGlobalStatusV2 }) {
  const t = STATUS_TOKENS[status];
  return (
    <Chip
      label={STATUS_LABELS[status]}
      size="small"
      sx={{ bgcolor: t.bg, color: t.fg, fontWeight: 700, fontSize: 12 }}
    />
  );
}

/**
 * Communication des absences chirurgiens — Lot C (D-114), §16/§17/§24 de la demande.
 * Journal manager en lecture (liste paginée + filtres) + CTA de rattrapage. Le détail
 * s'ouvre dans un drawer piloté uniquement par `selectedId` (§18).
 */
export function AbsenceCommunicationJournal() {
  const [siteId, setSiteId] = React.useState<number | "">("");
  const [surgeonId, setSurgeonId] = React.useState<number | "">("");
  const [type, setType] = React.useState<AbsenceCommunicationTypeV2 | "">("");
  const [status, setStatus] = React.useState<AbsenceCommunicationGlobalStatusV2 | "">("");
  const [periodFrom, setPeriodFrom] = React.useState("");
  const [periodTo, setPeriodTo] = React.useState("");
  const [page, setPage] = React.useState(0);
  const [limit, setLimit] = React.useState(25);
  const [selectedId, setSelectedId] = React.useState<number | null>(null);
  const [backfillOpen, setBackfillOpen] = React.useState(false);

  // Un changement de filtre invalide la page courante — jamais une page vide silencieuse.
  React.useEffect(() => { setPage(0); }, [siteId, surgeonId, type, status, periodFrom, periodTo]);

  const filters: AbsenceCommunicationJournalFilters = {
    siteId: siteId === "" ? undefined : siteId,
    surgeonId: surgeonId === "" ? undefined : surgeonId,
    type: type === "" ? undefined : type,
    status: status === "" ? undefined : status,
    periodFrom: periodFrom || undefined,
    periodTo: periodTo || undefined,
    page: page + 1,
    limit,
  };

  const query = useQuery({
    queryKey: ["planning-v2", "absence-communications-journal", filters],
    queryFn: () => getAbsenceCommunicationJournal(filters),
  });

  const sitesQuery = useQuery({
    queryKey: ["planning-v2", "absence-communication-settings"],
    queryFn: () => getAbsenceCommunicationSettings(),
  });

  const surgeonsQuery = useQuery({
    queryKey: ["manager-surgeons", "active"],
    queryFn: () => getSurgeons({ active: true }),
  });

  const items = query.data?.items ?? [];

  return (
    <Box>
      <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 2 }}>
        <Typography sx={{ fontSize: 14, color: planningV2Colors.textMuted, maxWidth: 480 }}>
          Historique complet des communications envoyées aux chirurgiens collègues et à la gestion du bloc.
        </Typography>
        <Button
          variant="contained" disableElevation onClick={() => setBackfillOpen(true)}
          sx={{ textTransform: "none", bgcolor: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.brandHover }, whiteSpace: "nowrap" }}
        >
          Traiter les absences existantes
        </Button>
      </Stack>

      <Stack direction="row" spacing={1.25} flexWrap="wrap" useFlexGap sx={{ mb: 2 }}>
        <Select
          size="small" displayEmpty value={siteId} onChange={(e) => setSiteId(e.target.value as number | "")}
          sx={{ minWidth: 160, fontSize: 13.5 }}
        >
          <MenuItem value="">Tous les sites</MenuItem>
          {(sitesQuery.data?.items ?? []).map((s) => (
            <MenuItem key={s.site.id} value={s.site.id}>{s.site.name}</MenuItem>
          ))}
        </Select>
        <Select
          size="small" displayEmpty value={surgeonId} onChange={(e) => setSurgeonId(e.target.value as number | "")}
          sx={{ minWidth: 180, fontSize: 13.5 }}
        >
          <MenuItem value="">Tous les chirurgiens</MenuItem>
          {(surgeonsQuery.data?.items ?? []).map((s: SurgeonListItemDTO) => (
            <MenuItem key={s.id} value={s.id}>{s.displayName}</MenuItem>
          ))}
        </Select>
        <Select
          size="small" displayEmpty value={type} onChange={(e) => setType(e.target.value as AbsenceCommunicationTypeV2 | "")}
          sx={{ minWidth: 190, fontSize: 13.5 }}
        >
          <MenuItem value="">Tous les types</MenuItem>
          {(Object.keys(TYPE_LABELS) as AbsenceCommunicationTypeV2[]).map((t) => (
            <MenuItem key={t} value={t}>{TYPE_LABELS[t]}</MenuItem>
          ))}
        </Select>
        <Select
          size="small" displayEmpty value={status} onChange={(e) => setStatus(e.target.value as AbsenceCommunicationGlobalStatusV2 | "")}
          sx={{ minWidth: 150, fontSize: 13.5 }}
        >
          <MenuItem value="">Tous les statuts</MenuItem>
          {(Object.keys(STATUS_LABELS) as AbsenceCommunicationGlobalStatusV2[]).map((s) => (
            <MenuItem key={s} value={s}>{STATUS_LABELS[s]}</MenuItem>
          ))}
        </Select>
        <TextField
          size="small" type="date" label="Période — du" InputLabelProps={{ shrink: true }}
          value={periodFrom} onChange={(e) => setPeriodFrom(e.target.value)}
          sx={{ minWidth: 150 }}
        />
        <TextField
          size="small" type="date" label="Période — au" InputLabelProps={{ shrink: true }}
          value={periodTo} onChange={(e) => setPeriodTo(e.target.value)}
          sx={{ minWidth: 150 }}
        />
        <Button
          size="small" startIcon={<RefreshOutlinedIcon sx={{ fontSize: 16 }} />}
          onClick={() => query.refetch()}
          sx={{ textTransform: "none" }}
        >
          Actualiser
        </Button>
      </Stack>

      {query.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 4 }}><CircularProgress size={24} /></Box>
      ) : query.isError ? (
        <Alert severity="error">{extractErrorV2(query.error)}</Alert>
      ) : items.length === 0 ? (
        <Alert severity="info">Aucune communication ne correspond à ces filtres.</Alert>
      ) : (
        <Box sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, overflow: "hidden", boxShadow: planningV2Shadows.card }}>
          <Box sx={{ overflowX: "auto" }}>
            <Table size="small">
              <TableHead>
                <TableRow sx={{ bgcolor: "#FAFBFC" }}>
                  <TableCell sx={{ fontWeight: 700, fontSize: 12.5 }}>Créé le</TableCell>
                  <TableCell sx={{ fontWeight: 700, fontSize: 12.5 }}>Chirurgien</TableCell>
                  <TableCell sx={{ fontWeight: 700, fontSize: 12.5 }}>Site</TableCell>
                  <TableCell sx={{ fontWeight: 700, fontSize: 12.5 }}>Type</TableCell>
                  <TableCell sx={{ fontWeight: 700, fontSize: 12.5 }}>Période</TableCell>
                  <TableCell sx={{ fontWeight: 700, fontSize: 12.5 }}>Statut</TableCell>
                  <TableCell sx={{ fontWeight: 700, fontSize: 12.5 }} align="right">Deliveries</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {items.map((item: AbsenceCommunicationListItemV2) => (
                  <TableRow
                    key={item.id} hover sx={{ cursor: "pointer" }}
                    onClick={() => setSelectedId(item.id)}
                  >
                    <TableCell sx={{ fontSize: 13 }}>
                      {item.createdAt ? new Date(item.createdAt).toLocaleDateString("fr-BE") : "—"}
                    </TableCell>
                    <TableCell sx={{ fontSize: 13 }}>{item.surgeon?.name ?? "—"}</TableCell>
                    <TableCell sx={{ fontSize: 13 }}>{item.site?.name ?? "—"}</TableCell>
                    <TableCell sx={{ fontSize: 13 }}>{TYPE_LABELS[item.type]}</TableCell>
                    <TableCell sx={{ fontSize: 13 }}>
                      {new Date(item.absenceDateStart + "T00:00:00").toLocaleDateString("fr-BE")} → {new Date(item.absenceDateEnd + "T00:00:00").toLocaleDateString("fr-BE")}
                    </TableCell>
                    <TableCell><StatusChip status={item.globalStatus} /></TableCell>
                    <TableCell align="right" sx={{ fontSize: 12.5, color: planningV2Colors.textMuted }}>
                      {item.sentCount > 0 && <span>{item.sentCount} envoyé{item.sentCount > 1 ? "s" : ""} </span>}
                      {item.failedCount > 0 && <span style={{ color: alertSeverityTokens.crit.fg }}>{item.failedCount} échec{item.failedCount > 1 ? "s" : ""} </span>}
                      {item.cancelledCount > 0 && <span>{item.cancelledCount} annulé{item.cancelledCount > 1 ? "s" : ""} </span>}
                      {item.scheduledCount > 0 && <span>{item.scheduledCount} programmé{item.scheduledCount > 1 ? "s" : ""}</span>}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
          <TablePagination
            component="div"
            count={query.data?.total ?? 0}
            page={page}
            onPageChange={(_, newPage) => setPage(newPage)}
            rowsPerPage={limit}
            onRowsPerPageChange={(e) => { setLimit(parseInt(e.target.value, 10)); setPage(0); }}
            rowsPerPageOptions={[10, 25, 50, 100]}
            labelRowsPerPage="Lignes par page"
          />
        </Box>
      )}

      <AbsenceCommunicationJournalDrawer id={selectedId} onClose={() => setSelectedId(null)} />
      <AbsenceCommunicationBackfillDialog
        open={backfillOpen}
        onClose={() => setBackfillOpen(false)}
        onExecuted={() => query.refetch()}
      />
    </Box>
  );
}
