import * as React from "react";
import {
  Alert, Box, Button, Chip, CircularProgress,
  Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle,
  Divider, Paper, Stack, Tooltip, Typography,
} from "@mui/material";
import ArrowBackIcon    from "@mui/icons-material/ArrowBack";
import SendIcon         from "@mui/icons-material/Send";
import PictureAsPdfIcon from "@mui/icons-material/PictureAsPdf";
import DeleteIcon       from "@mui/icons-material/Delete";
import AddCircleOutlineIcon    from "@mui/icons-material/AddCircleOutline";
import RemoveCircleOutlineIcon from "@mui/icons-material/RemoveCircleOutline";
import SyncIcon                from "@mui/icons-material/Sync";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useNavigate, useParams } from "react-router-dom";
import {
  getPlanningVersion, getVersionDiff, deployPlanning, previewPlanning,
  deletePlanningVersion, triggerVersionPdfDownload,
  type PlanningVersionSummary, type MissionDiffEntry, type PlanningDiff, type PreviewLine,
} from "../../../features/planning-manager/api/planning.api";
import { DeployModal } from "../../../features/planning-manager/components/DeployModal";
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

type ChipColor = "default" | "success" | "warning" | "error" | "info";

function versionStatusChip(status: string): { label: string; color: ChipColor } {
  switch (status) {
    case "DRAFT":    return { label: "Brouillon", color: "default" };
    case "ACTIVE":   return { label: "Actif",     color: "success" };
    case "ARCHIVED": return { label: "Archivé",   color: "warning" };
    default:         return { label: status,       color: "default" };
  }
}

function deployStatusChip(status: string): { label: string; color: ChipColor } {
  switch (status) {
    case "PENDING":    return { label: "En attente",   color: "info" };
    case "PROCESSING": return { label: "En cours",     color: "warning" };
    case "DONE":       return { label: "Déployé",      color: "success" };
    case "FAILED":     return { label: "Erreur worker", color: "error" };
    default:           return { label: status,          color: "default" };
  }
}

// ── Counter card ──────────────────────────────────────────────────────────────

function CountCard({ label, value, color, tooltip }: {
  label: string; value: number; color?: string; tooltip?: string;
}) {
  return (
    <Tooltip title={tooltip ?? ""}>
      <Box sx={{ textAlign: "center", minWidth: 70 }}>
        <Typography variant="h5" fontWeight={700} color={color ?? "text.primary"}>
          {value}
        </Typography>
        <Typography variant="caption" color="text.secondary">{label}</Typography>
      </Box>
    </Tooltip>
  );
}

// ── Diff section ──────────────────────────────────────────────────────────────

function DiffMissionRow({ mission, changes }: {
  mission: MissionDiffEntry;
  changes?: PlanningDiff["modified"][0]["changes"];
}) {
  return (
    <Box sx={{ py: 0.75, borderBottom: "1px solid", borderColor: "divider" }}>
      <Stack direction="row" spacing={1} alignItems="baseline" flexWrap="wrap">
        <Typography variant="body2" fontWeight={600} sx={{ minWidth: 90 }}>
          {formatDate(mission.date)}{" "}
          <span style={{ fontWeight: 400, color: "#666" }}>
            {mission.period === "AM" ? "Matin" : "Après-midi"}
          </span>
        </Typography>
        <Typography variant="body2">{mission.surgeonName}</Typography>
        {mission.siteName && (
          <Typography variant="caption" color="text.secondary">· {mission.siteName}</Typography>
        )}
        <Typography variant="caption" color="text.secondary">
          {mission.startAt}–{mission.endAt}
        </Typography>
      </Stack>
      {mission.instrumentistName && (
        <Typography variant="caption" color="text.secondary" sx={{ ml: 11 }}>
          {mission.instrumentistName}
        </Typography>
      )}
      {changes && (
        <Stack spacing={0.25} sx={{ ml: 11, mt: 0.25 }}>
          {changes.schedule && (
            <Typography variant="caption" color="warning.dark">
              Horaire : {changes.schedule.from.startAt}–{changes.schedule.from.endAt}
              {" → "}{changes.schedule.to.startAt}–{changes.schedule.to.endAt}
            </Typography>
          )}
          {changes.instrumentist && (
            <Typography variant="caption" color="warning.dark">
              Instr. : {changes.instrumentist.from?.name ?? "Aucun"}
              {" → "}{changes.instrumentist.to?.name ?? "Aucun"}
            </Typography>
          )}
        </Stack>
      )}
    </Box>
  );
}

function DiffSection({ diff }: { diff: PlanningDiff | undefined }) {
  if (!diff) {
    return (
      <Stack alignItems="center" py={3}>
        <CircularProgress size={22} />
        <Typography variant="body2" color="text.secondary" mt={1}>
          Calcul des différences…
        </Typography>
      </Stack>
    );
  }

  const hasDiff = diff.added.length > 0 || diff.removed.length > 0 || diff.modified.length > 0;

  if (!hasDiff) {
    return (
      <Alert severity="success" sx={{ mt: 1 }}>
        Aucune modification par rapport à la version précédente.
      </Alert>
    );
  }

  return (
    <Stack spacing={1.5} mt={1}>
      {diff.added.length > 0 && (
        <Box>
          <Stack direction="row" alignItems="center" spacing={0.75} mb={0.5}>
            <AddCircleOutlineIcon sx={{ fontSize: 16, color: "success.main" }} />
            <Typography variant="subtitle2" color="success.main">
              Ajouts ({diff.added.length})
            </Typography>
          </Stack>
          <Box sx={{ pl: 1 }}>
            {diff.added.map((m, i) => <DiffMissionRow key={i} mission={m} />)}
          </Box>
        </Box>
      )}

      {diff.removed.length > 0 && (
        <Box>
          <Stack direction="row" alignItems="center" spacing={0.75} mb={0.5}>
            <RemoveCircleOutlineIcon sx={{ fontSize: 16, color: "error.main" }} />
            <Typography variant="subtitle2" color="error.main">
              Suppressions ({diff.removed.length})
            </Typography>
          </Stack>
          <Box sx={{ pl: 1 }}>
            {diff.removed.map((m, i) => <DiffMissionRow key={i} mission={m} />)}
          </Box>
        </Box>
      )}

      {diff.modified.length > 0 && (
        <Box>
          <Stack direction="row" alignItems="center" spacing={0.75} mb={0.5}>
            <SyncIcon sx={{ fontSize: 16, color: "warning.main" }} />
            <Typography variant="subtitle2" color="warning.main">
              Modifications ({diff.modified.length})
            </Typography>
          </Stack>
          <Box sx={{ pl: 1 }}>
            {diff.modified.map((entry, i) => (
              <DiffMissionRow key={i} mission={entry.mission} changes={entry.changes} />
            ))}
          </Box>
        </Box>
      )}
    </Stack>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function PlanningVersionDetailPage() {
  const { id }       = useParams<{ id: string }>();
  const versionId    = parseInt(id ?? "0", 10);
  const navigate     = useNavigate();
  const toast        = useToast();
  const queryClient  = useQueryClient();

  // DeployModal state — loads preview lines first, then opens the 2-step modal
  const [deployModalOpen,    setDeployModalOpen]    = React.useState(false);
  const [deployPreviewLines, setDeployPreviewLines] = React.useState<PreviewLine[]>([]);
  const [previewLoading,     setPreviewLoading]     = React.useState(false);
  const [deleteConfirmOpen,  setDeleteConfirmOpen]  = React.useState(false);

  const { data: version, isLoading, isError } = useQuery({
    queryKey: ["planning-version", versionId],
    queryFn:  () => getPlanningVersion(versionId),
    enabled:  versionId > 0,
  });

  const { data: diff } = useQuery({
    queryKey: ["planning-version-diff", versionId],
    queryFn:  () => getVersionDiff(versionId),
    enabled:  versionId > 0,
  });

  async function handleDeployClick() {
    if (!version) return;
    setPreviewLoading(true);
    try {
      const lines = await previewPlanning({
        from:   version.periodStart,
        to:     version.periodEnd,
        siteId: version.site?.id ?? null,
      });
      setDeployPreviewLines(lines);
      setDeployModalOpen(true);
    } catch {
      toast.error("Impossible de charger le planning. Veuillez réessayer.");
    } finally {
      setPreviewLoading(false);
    }
  }

  const deployMutation = useMutation({
    mutationFn: (payload: { selectedUncoveredMissionIds: number[]; sendChangeSummary: boolean }) =>
      deployPlanning({
        from:                        version!.periodStart,
        to:                          version!.periodEnd,
        siteId:                      version!.site?.id ?? null,
        versionId:                   versionId,
        selectedUncoveredMissionIds: payload.selectedUncoveredMissionIds,
        sendChangeSummary:           payload.sendChangeSummary,
      }),
    onSuccess: () => {
      toast.success("Planning déployé. Notifications et documents en cours d'envoi.");
      setDeployModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ["planning-version", versionId] });
      queryClient.invalidateQueries({ queryKey: ["planning-versions"] });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: () => deletePlanningVersion(versionId),
    onSuccess: () => {
      toast.success("Planning supprimé.");
      navigate("/app/m/planning/versions", { replace: true });
    },
    onError: (err) => toast.error(extractError(err)),
  });

  if (isLoading) {
    return (
      <Stack alignItems="center" py={8}><CircularProgress /></Stack>
    );
  }

  if (isError || !version) {
    return (
      <Stack spacing={2}>
        <Button startIcon={<ArrowBackIcon />} onClick={() => navigate(-1)} sx={{ alignSelf: "flex-start" }}>
          Retour
        </Button>
        <Alert severity="error">Planning introuvable.</Alert>
      </Stack>
    );
  }

  const vsChip    = versionStatusChip(version.status);
  const lastDep   = version.lastDeployment;
  const depChip   = lastDep ? deployStatusChip(lastDep.status) : null;
  const canDeploy = version.allowedActions?.deploy ?? version.status === "DRAFT";
  const canDelete = version.allowedActions?.delete ?? version.status === "DRAFT";

  return (
    <Stack spacing={3}>
      {/* Header */}
      <Stack direction="row" alignItems="center" spacing={1}>
        <Button
          startIcon={<ArrowBackIcon />}
          onClick={() => navigate("/app/m/planning/versions")}
          color="inherit" size="small"
        >
          Plannings
        </Button>
        <Typography color="text.disabled">/</Typography>
        <Typography variant="h6" fontWeight={700}>
          Version #{version.versionNumber}
        </Typography>
        <Chip label={vsChip.label} color={vsChip.color} size="small" />
      </Stack>

      {/* Info card */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Stack spacing={1.5}>
          <Stack direction="row" spacing={4} flexWrap="wrap">
            <Box>
              <Typography variant="caption" color="text.secondary">Période</Typography>
              <Typography variant="body2" fontWeight={600}>
                {formatDate(version.periodStart)} → {formatDate(version.periodEnd)}
              </Typography>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary">Site</Typography>
              <Typography variant="body2" fontWeight={600}>
                {version.site?.name ?? "Tous les sites"}
              </Typography>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary">Générateur</Typography>
              <Typography variant="body2" fontWeight={600}>
                {version.generatedBy.email ?? "—"}
              </Typography>
            </Box>
            <Box>
              <Typography variant="caption" color="text.secondary">Créé le</Typography>
              <Typography variant="body2" fontWeight={600}>
                {formatDateTime(version.generatedAt)}
              </Typography>
            </Box>
            {version.deployedAt && (
              <Box>
                <Typography variant="caption" color="text.secondary">Déployé le</Typography>
                <Typography variant="body2" fontWeight={600}>
                  {formatDateTime(version.deployedAt)}
                </Typography>
              </Box>
            )}
            {version.archivedAt && (
              <Box>
                <Typography variant="caption" color="text.secondary">Archivé le</Typography>
                <Typography variant="body2" fontWeight={600}>
                  {formatDateTime(version.archivedAt)}
                </Typography>
              </Box>
            )}
          </Stack>

          {/* Last deployment */}
          {lastDep && depChip && (
            <>
              <Divider />
              <Stack direction="row" spacing={1} alignItems="center">
                <Typography variant="caption" color="text.secondary">Dernier déploiement :</Typography>
                <Chip label={depChip.label} color={depChip.color} size="small" variant="outlined" />
                {lastDep.completedAt && (
                  <Typography variant="caption" color="text.secondary">
                    · {formatDateTime(lastDep.completedAt)}
                  </Typography>
                )}
                {lastDep.hasError && (
                  <Chip label="Erreur worker" color="error" size="small" variant="outlined" />
                )}
              </Stack>
            </>
          )}
        </Stack>
      </Paper>

      {/* Mission counts */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Typography variant="subtitle2" fontWeight={700} gutterBottom>
          État des missions
        </Typography>
        <Stack direction="row" spacing={3} flexWrap="wrap" mt={1}>
          <CountCard
            label="Total" value={version.summary.total}
            tooltip="Toutes les missions non rejetées de la période"
          />
          <CountCard
            label="DRAFT" value={version.summary.draft}
            color="text.secondary"
            tooltip="Missions créées mais pas encore publiées"
          />
          <CountCard
            label="Pool (OPEN)" value={version.summary.open}
            color="info.main"
            tooltip="Missions publiées sans instrumentiste — disponibles en pool"
          />
          <CountCard
            label="Assignées" value={version.summary.assigned}
            color="success.main"
            tooltip="Missions avec instrumentiste confirmé"
          />
          {version.summary.withoutInstrumentist > 0 && (
            <CountCard
              label="Sans instr." value={version.summary.withoutInstrumentist}
              color="warning.main"
              tooltip="DRAFT ou OPEN sans instrumentiste attribué"
            />
          )}
        </Stack>
      </Paper>

      {/* Diff */}
      <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2 }}>
        <Typography variant="subtitle2" fontWeight={700} gutterBottom>
          Différences vs version précédente
        </Typography>
        <DiffSection diff={diff} />
      </Paper>

      {/* Actions */}
      <Stack direction="row" spacing={2} flexWrap="wrap">
        <Tooltip title="PDF généré à partir de l'état actuel du planning.">
          <Button
            variant="outlined" startIcon={<PictureAsPdfIcon />}
            onClick={() => triggerVersionPdfDownload(version)}
          >
            Télécharger PDF actuel
          </Button>
        </Tooltip>

        {canDeploy && (
          <Button
            variant="contained" color="success" disableElevation
            startIcon={previewLoading
              ? <CircularProgress size={14} color="inherit" />
              : <SendIcon />}
            onClick={handleDeployClick}
            disabled={previewLoading}
          >
            {previewLoading ? "Chargement…" : "Déployer ce planning"}
          </Button>
        )}

        {canDelete && (
          <Button
            variant="outlined" color="error"
            startIcon={<DeleteIcon />}
            onClick={() => setDeleteConfirmOpen(true)}
          >
            Supprimer
          </Button>
        )}
      </Stack>

      {/* Deploy modal — 2-step flow identical to PlanningGeneratePage */}
      {version && (
        <DeployModal
          open={deployModalOpen}
          onClose={() => setDeployModalOpen(false)}
          previewLines={deployPreviewLines}
          versionId={versionId}
          from={version.periodStart}
          to={version.periodEnd}
          onDeploy={(selectedUncoveredMissionIds, sendChangeSummary) =>
            deployMutation.mutate({ selectedUncoveredMissionIds, sendChangeSummary })
          }
          isDeploying={deployMutation.isPending}
        />
      )}

      {/* Delete confirmation */}
      <Dialog open={deleteConfirmOpen} onClose={() => setDeleteConfirmOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle fontWeight={700}>Supprimer ce planning ?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            Supprimer ce planning supprimera aussi <strong>toutes les missions liées à cette version</strong>.
            Cette action est irréversible.
          </DialogContentText>
        </DialogContent>
        <DialogActions sx={{ gap: 1, px: 3, pb: 2 }}>
          <Button onClick={() => setDeleteConfirmOpen(false)} color="inherit" disabled={deleteMutation.isPending}>
            Annuler
          </Button>
          <Button
            variant="contained" color="error" disableElevation
            startIcon={deleteMutation.isPending ? <CircularProgress size={14} color="inherit" /> : <DeleteIcon />}
            onClick={() => deleteMutation.mutate()}
            disabled={deleteMutation.isPending}
          >
            {deleteMutation.isPending ? "Suppression…" : "Supprimer définitivement"}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  );
}
