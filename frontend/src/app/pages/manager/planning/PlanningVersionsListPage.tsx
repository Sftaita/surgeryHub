import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogContentText, DialogTitle, MenuItem, Paper,
  Select, Stack, Table, TableBody, TableCell, TableContainer, TableHead,
  TableRow, TextField, Tooltip, Typography,
} from "@mui/material";
import AddIcon          from "@mui/icons-material/Add";
import VisibilityIcon   from "@mui/icons-material/Visibility";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
import DeleteIcon       from "@mui/icons-material/Delete";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import {
  listPlanningVersions, deletePlanningVersion, triggerVersionPdfDownload,
  type PlanningVersionSummary,
} from "../../../features/planning-manager/api/planning.api";
import { fetchSites } from "../../../features/sites/api/sites.api";
import { useToast } from "../../../ui/toast/useToast";

// ── Helpers ───────────────────────────────────────────────────────────────────

function extractError(err: unknown): string {
  const e = err as any;
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

function formatDate(iso: string): string {
  return new Date(iso + "T00:00:00").toLocaleDateString("fr-BE", {
    day: "2-digit", month: "2-digit", year: "numeric",
  });
}

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString("fr-BE", {
    day: "2-digit", month: "2-digit", year: "numeric",
    hour: "2-digit", minute: "2-digit",
  });
}

type StatusColor = "default" | "success" | "warning" | "error" | "info";

function versionStatusChip(status: string): { label: string; color: StatusColor } {
  switch (status) {
    case "DRAFT":    return { label: "Brouillon", color: "default" };
    case "ACTIVE":   return { label: "Actif",     color: "success" };
    case "ARCHIVED": return { label: "Archivé",   color: "warning" };
    default:         return { label: status,       color: "default" };
  }
}

function deployStatusChip(status: string): { label: string; color: StatusColor } {
  switch (status) {
    case "PENDING":    return { label: "En attente",        color: "info" };
    case "PROCESSING": return { label: "En cours",          color: "warning" };
    case "DONE":       return { label: "Déployé",           color: "success" };
    case "FAILED":     return { label: "Déploiement échoué", color: "error" };
    default:           return { label: status,              color: "default" };
  }
}

// ── Delete confirmation dialog ────────────────────────────────────────────────

function DeleteConfirmDialog({
  open, version, onClose, onConfirm, isDeleting,
}: {
  open:      boolean;
  version:   PlanningVersionSummary | null;
  onClose:   () => void;
  onConfirm: () => void;
  isDeleting:boolean;
}) {
  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle fontWeight={700}>Supprimer ce planning ?</DialogTitle>
      <DialogContent>
        <DialogContentText>
          Supprimer ce planning supprimera aussi <strong>toutes les missions
          liées à cette version</strong>. Cette action est irréversible.
        </DialogContentText>
        {version && (
          <Box sx={{ mt: 2, p: 1.5, bgcolor: "grey.50", borderRadius: 1, fontSize: 13 }}>
            Version #{version.versionNumber} — {formatDate(version.periodStart)} → {formatDate(version.periodEnd)}
            {version.site && ` — ${version.site.name}`}
          </Box>
        )}
      </DialogContent>
      <DialogActions sx={{ gap: 1, px: 3, pb: 2 }}>
        <Button onClick={onClose} color="inherit" disabled={isDeleting}>
          Annuler
        </Button>
        <Button
          variant="contained" color="error" disableElevation
          startIcon={isDeleting ? <CircularProgress size={14} color="inherit" /> : <DeleteIcon />}
          onClick={onConfirm}
          disabled={isDeleting}
        >
          {isDeleting ? "Suppression…" : "Supprimer définitivement"}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function PlanningVersionsListPage() {
  const navigate     = useNavigate();
  const toast        = useToast();
  const queryClient  = useQueryClient();

  const [page]                              = React.useState(1);
  const [statusFilter, setStatusFilter]     = React.useState<string>("");
  const [periodFrom, setPeriodFrom]         = React.useState<string>("");
  const [periodTo,   setPeriodTo]           = React.useState<string>("");
  const [siteFilter, setSiteFilter]         = React.useState<number | "">("");

  const [deleteTarget, setDeleteTarget]     = React.useState<PlanningVersionSummary | null>(null);

  const sitesQuery = useQuery({ queryKey: ["sites"], queryFn: fetchSites });

  const { data, isLoading, isError } = useQuery({
    queryKey: ["planning-versions", { page, statusFilter, periodFrom, periodTo, siteFilter }],
    queryFn:  () => listPlanningVersions({
      page,
      limit:      50,
      status:     statusFilter || undefined,
      periodFrom: periodFrom   || undefined,
      periodTo:   periodTo     || undefined,
      siteId:     siteFilter   || undefined,
    }),
  });

  const deleteMutation = useMutation({
    mutationFn: () => deletePlanningVersion(deleteTarget!.id),
    onSuccess: () => {
      toast.success("Planning supprimé.");
      setDeleteTarget(null);
      queryClient.invalidateQueries({ queryKey: ["planning-versions"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const versions = data?.items ?? [];

  return (
    <Stack spacing={3}>
      {/* Header */}
      <Stack direction="row" alignItems="center" justifyContent="space-between">
        <Typography variant="h6" fontWeight={700}>Plannings</Typography>
        <Button
          variant="contained" disableElevation startIcon={<AddIcon />}
          onClick={() => navigate("/app/m/planning/generate")}
        >
          Générer un planning
        </Button>
      </Stack>

      {/* Filters */}
      <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
        <Stack direction="row" spacing={2} flexWrap="wrap" alignItems="flex-end">
          <Select
            value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}
            displayEmpty size="small" sx={{ minWidth: 150 }}
          >
            <MenuItem value="">Tous les statuts</MenuItem>
            <MenuItem value="DRAFT">Brouillon</MenuItem>
            <MenuItem value="ACTIVE">Actif</MenuItem>
            <MenuItem value="ARCHIVED">Archivé</MenuItem>
          </Select>

          <Select
            value={siteFilter} onChange={(e) => setSiteFilter(e.target.value as number | "")}
            displayEmpty size="small" sx={{ minWidth: 160 }}
          >
            <MenuItem value="">Tous les sites</MenuItem>
            {(sitesQuery.data ?? []).map((s: any) => (
              <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>
            ))}
          </Select>

          <TextField
            label="Période — du" type="date" size="small" value={periodFrom}
            onChange={(e) => setPeriodFrom(e.target.value)}
            InputLabelProps={{ shrink: true }}
          />
          <TextField
            label="au" type="date" size="small" value={periodTo}
            onChange={(e) => setPeriodTo(e.target.value)}
            InputLabelProps={{ shrink: true }}
          />

          {(statusFilter || siteFilter || periodFrom || periodTo) && (
            <Button size="small" color="inherit" onClick={() => {
              setStatusFilter(""); setSiteFilter(""); setPeriodFrom(""); setPeriodTo("");
            }}>
              Réinitialiser
            </Button>
          )}
        </Stack>
      </Paper>

      {/* Content */}
      {isLoading && (
        <Stack alignItems="center" py={6}><CircularProgress /></Stack>
      )}

      {isError && (
        <Alert severity="error">Erreur lors du chargement des plannings.</Alert>
      )}

      {!isLoading && !isError && versions.length === 0 && (
        <Paper variant="outlined" sx={{ p: 6, textAlign: "center", borderRadius: 2 }}>
          <Typography variant="h6" color="text.secondary" gutterBottom>
            Aucun planning
          </Typography>
          <Typography variant="body2" color="text.secondary" mb={2}>
            Aucune version ne correspond aux filtres sélectionnés.
          </Typography>
          <Button
            variant="outlined" startIcon={<AddIcon />}
            onClick={() => navigate("/app/m/planning/generate")}
          >
            Générer un premier planning
          </Button>
        </Paper>
      )}

      {!isLoading && versions.length > 0 && (
        <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2 }}>
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: "grey.50" }}>
                <TableCell sx={{ fontWeight: 700, fontSize: 12, width: 60 }}>Version</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12, width: 100 }}>Statut</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Période</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Site</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12, textAlign: "center", width: 70 }}>Total</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12, textAlign: "center", width: 80 }}>Sans instr.</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12, textAlign: "center", width: 80 }}>Assigné</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Générateur</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Créé le</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12 }}>Déploiement</TableCell>
                <TableCell sx={{ fontWeight: 700, fontSize: 12, width: 130 }}>Actions</TableCell>
              </TableRow>
            </TableHead>

            <TableBody>
              {versions.map((v) => {
                const vsChip   = versionStatusChip(v.status);
                const lastDep  = v.lastDeployment;
                const depChip  = lastDep ? deployStatusChip(lastDep.status) : null;
                const canDelete = v.allowedActions?.delete ?? v.status === "DRAFT";

                return (
                  <TableRow
                    key={v.id}
                    hover
                    sx={{ cursor: "pointer", "& td": { fontSize: 13, py: 0.8 } }}
                    onClick={() => navigate(`/app/m/planning/versions/${v.id}`)}
                  >
                    <TableCell>
                      <Typography variant="body2" fontWeight={600}>#{v.versionNumber}</Typography>
                    </TableCell>

                    <TableCell onClick={(e) => e.stopPropagation()}>
                      <Chip label={vsChip.label} color={vsChip.color} size="small" />
                    </TableCell>

                    <TableCell>
                      <Typography variant="body2">
                        {formatDate(v.periodStart)} → {formatDate(v.periodEnd)}
                      </Typography>
                    </TableCell>

                    <TableCell>
                      <Typography variant="body2" color="text.secondary">
                        {v.site?.name ?? "Tous"}
                      </Typography>
                    </TableCell>

                    <TableCell sx={{ textAlign: "center" }}>
                      <Typography variant="body2">{v.summary.total}</Typography>
                    </TableCell>

                    <TableCell>
                      {v.summary.withoutInstrumentist > 0 ? (
                        <Chip
                          label={`${v.summary.withoutInstrumentist} non couvert(s)`}
                          color="warning" size="small" variant="outlined"
                        />
                      ) : (
                        <Chip label="Tout couvert" color="success" size="small" variant="outlined" />
                      )}
                    </TableCell>

                    <TableCell sx={{ textAlign: "center" }}>
                      <Typography variant="body2" color="success.main">
                        {v.summary.assigned}
                      </Typography>
                    </TableCell>

                    <TableCell>
                      <Typography variant="body2" color="text.secondary" sx={{ fontSize: 12 }}>
                        {v.generatedBy.email ?? "—"}
                      </Typography>
                    </TableCell>

                    <TableCell>
                      <Typography variant="body2" color="text.secondary" sx={{ fontSize: 12 }}>
                        {formatDateTime(v.generatedAt)}
                      </Typography>
                    </TableCell>

                    <TableCell>
                      {depChip ? (
                        <Tooltip title={lastDep?.completedAt ? formatDateTime(lastDep.completedAt) : ""}>
                          <Chip
                            label={depChip.label}
                            color={depChip.color}
                            size="small"
                            variant="outlined"
                          />
                        </Tooltip>
                      ) : (
                        <Typography variant="body2" color="text.disabled" sx={{ fontSize: 12 }}>—</Typography>
                      )}
                    </TableCell>

                    <TableCell onClick={(e) => e.stopPropagation()}>
                      <Stack direction="row" spacing={0.5}>
                        <Tooltip title="Voir le détail">
                          <Button
                            size="small" variant="text"
                            onClick={() => navigate(`/app/m/planning/versions/${v.id}`)}
                            sx={{ minWidth: 0, px: 0.75 }}
                          >
                            <VisibilityIcon sx={{ fontSize: 18 }} />
                          </Button>
                        </Tooltip>

                        <Tooltip title="PDF généré à partir de l'état actuel du planning.">
                          <Button
                            size="small" variant="text" color="primary"
                            onClick={() => triggerVersionPdfDownload(v)}
                            sx={{ minWidth: 0, px: 0.75 }}
                            aria-label="Télécharger PDF actuel"
                          >
                            <PictureAsPdfIcon sx={{ fontSize: 18 }} />
                          </Button>
                        </Tooltip>

                        {canDelete && (
                          <Tooltip title="Supprimer (DRAFT uniquement)">
                            <Button
                              size="small" variant="text" color="error"
                              onClick={() => setDeleteTarget(v)}
                              sx={{ minWidth: 0, px: 0.75 }}
                            >
                              <DeleteIcon sx={{ fontSize: 18 }} />
                            </Button>
                          </Tooltip>
                        )}
                      </Stack>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      {data && data.total > 0 && (
        <Typography variant="caption" color="text.secondary" textAlign="right">
          {data.total} planning(s) au total
        </Typography>
      )}

      {/* Delete confirmation */}
      <DeleteConfirmDialog
        open={deleteTarget !== null}
        version={deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={() => deleteMutation.mutate()}
        isDeleting={deleteMutation.isPending}
      />
    </Stack>
  );
}
