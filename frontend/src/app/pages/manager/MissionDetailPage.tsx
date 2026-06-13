import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Button,
  Chip,
  Divider,
  IconButton,
  Paper,
  Stack,
  Typography,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
} from "@mui/material";

import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import CalendarTodayIcon from "@mui/icons-material/CalendarToday";
import PersonIcon from "@mui/icons-material/Person";
import LocalHospitalIcon from "@mui/icons-material/LocalHospital";
import AccessTimeIcon from "@mui/icons-material/AccessTime";

import {
  approveDeclaredMission,
  fetchMissionById,
  rejectDeclaredMission,
} from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import {
  formatBrusselsRange,
  formatPersonLabel,
  formatMissionType,
  formatMissionStatus,
  formatSchedulePrecision,
} from "../../features/missions/utils/missions.format";

import EditMissionDialog from "../../features/missions/components/EditMissionDialog";
import PublishMissionDialog from "../../features/missions/components/PublishMissionDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";
import { useToast } from "../../ui/toast/useToast";

type ChipColor = "default" | "primary" | "secondary" | "error" | "info" | "success" | "warning";

function statusChipColor(status: string): ChipColor {
  switch (status) {
    case "DRAFT": return "default";
    case "OPEN": return "info";
    case "ASSIGNED": return "primary";
    case "SUBMITTED": return "warning";
    case "DECLARED": return "warning";
    case "VALIDATED": return "success";
    case "CLOSED": return "default";
    case "REJECTED": return "error";
    default: return "default";
  }
}

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <Stack direction="row" spacing={1} alignItems="flex-start" py={0.75}>
      <Typography variant="body2" color="text.secondary" sx={{ minWidth: 140 }}>
        {label}
      </Typography>
      <Box sx={{ flex: 1 }}>{children}</Box>
    </Stack>
  );
}

function formatHoursLabel(hours?: string | number | null): string {
  if (hours === null || hours === undefined || hours === "") return "—";
  const n = typeof hours === "string" ? Number(hours) : hours;
  if (!Number.isFinite(n)) return "—";
  return `${n} h`;
}

type MissionDetailContentProps = {
  missionId: number;
  embedded?: boolean;
  onCloseEmbedded?: () => void;
};

export function MissionDetailContent({
  missionId,
  embedded = false,
  onCloseEmbedded,
}: MissionDetailContentProps) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  const { data, isLoading, isError, error } = useQuery<Mission>({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: Number.isFinite(missionId),
  });

  const [openEdit, setOpenEdit] = React.useState(false);
  const [openPublish, setOpenPublish] = React.useState(false);
  const [openEditHours, setOpenEditHours] = React.useState(false);
  const [rejectConfirmOpen, setRejectConfirmOpen] = React.useState(false);
  const [approveConfirmOpen, setApproveConfirmOpen] = React.useState(false);

  async function refreshAfterAction() {
    await queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
    await queryClient.invalidateQueries({ queryKey: ["missions"], exact: false });
    await queryClient.refetchQueries({ queryKey: ["missions"], exact: false });
  }

  const approveMutation = useMutation({
    mutationFn: async () => approveDeclaredMission(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Mission approuvée.");
      if (embedded) onCloseEmbedded?.();
      else navigate("/app/m/missions/to-validate", { replace: true });
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors de l'approbation.");
    },
  });

  const rejectMutation = useMutation({
    mutationFn: async () => rejectDeclaredMission(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Mission rejetée.");
      if (embedded) onCloseEmbedded?.();
      else navigate("/app/m/missions/to-validate", { replace: true });
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors du rejet.");
    },
  });

  if (!Number.isFinite(missionId)) return <Typography sx={{ p: 3 }}>ID invalide</Typography>;
  if (isLoading) return <Typography sx={{ p: 3 }} color="text.secondary">Chargement…</Typography>;

  if (isError) {
    const status = (error as any)?.response?.status;
    if (status === 403) return <Typography sx={{ p: 3 }}>Accès interdit</Typography>;
    if (status === 404) return <Typography sx={{ p: 3 }}>Mission introuvable</Typography>;
    return <Typography sx={{ p: 3 }}>Erreur serveur</Typography>;
  }

  if (!data) return null;

  const allowed = data.allowedActions ?? [];
  const canEdit = allowed.includes("edit");
  const canPublish = allowed.includes("publish");
  const canApprove = allowed.includes("approve");
  const canReject = allowed.includes("reject");
  const canEditHours = allowed.includes("edit_hours");

  const anyLoading = approveMutation.isPending || rejectMutation.isPending;

  return (
    <Box sx={{ maxWidth: embedded ? "none" : 800 }}>
      {/* Header */}
      <Stack direction="row" alignItems="center" spacing={1} mb={3}>
        {!embedded && (
          <IconButton onClick={() => navigate(-1)} size="small">
            <ArrowBackIcon fontSize="small" />
          </IconButton>
        )}

        <Box sx={{ flex: 1 }}>
          <Typography variant="h6" fontWeight={600}>
            Mission #{data.id}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {formatMissionType(data.type)} · {data.site?.name ?? "—"}
          </Typography>
        </Box>

        <Chip
          label={formatMissionStatus(data.status)}
          color={statusChipColor(String(data.status))}
          size="small"
          variant={data.status === "DRAFT" || data.status === "CLOSED" ? "outlined" : "filled"}
        />
      </Stack>

      {/* Actions */}
      {(canEdit || canPublish || canApprove || canReject) && (
        <Stack direction="row" spacing={1} mb={3} flexWrap="wrap">
          {canReject && (
            <Button color="error" variant="outlined" size="small" disabled={anyLoading}
              onClick={() => setRejectConfirmOpen(true)}>
              Rejeter
            </Button>
          )}
          {canApprove && (
            <Button color="success" variant="contained" size="small" disableElevation disabled={anyLoading}
              onClick={() => setApproveConfirmOpen(true)}>
              Approuver
            </Button>
          )}
          {canEdit && (
            <Button variant="outlined" size="small" onClick={() => setOpenEdit(true)}>
              Modifier
            </Button>
          )}
          {canPublish && (
            <Button variant="contained" size="small" disableElevation onClick={() => setOpenPublish(true)}>
              Publier
            </Button>
          )}
        </Stack>
      )}

      <Stack spacing={2}>
        {/* Planification */}
        <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
          <Stack direction="row" spacing={1} alignItems="center" mb={1.5}>
            <CalendarTodayIcon fontSize="small" color="action" />
            <Typography variant="subtitle2" fontWeight={600}>Planification</Typography>
          </Stack>
          <Divider sx={{ mb: 1.5 }} />

          <InfoRow label="Date / heure">
            <Typography variant="body2">{formatBrusselsRange(data.startAt, data.endAt)}</Typography>
          </InfoRow>
          <InfoRow label="Précision horaire">
            <Typography variant="body2">{formatSchedulePrecision(data.schedulePrecision)}</Typography>
          </InfoRow>
          <InfoRow label="Type">
            <Typography variant="body2">{formatMissionType(data.type)}</Typography>
          </InfoRow>
          <InfoRow label="Site">
            <Typography variant="body2">{data.site?.name ?? "—"}</Typography>
          </InfoRow>
        </Paper>

        {/* Personnel */}
        <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
          <Stack direction="row" spacing={1} alignItems="center" mb={1.5}>
            <PersonIcon fontSize="small" color="action" />
            <Typography variant="subtitle2" fontWeight={600}>Personnel</Typography>
          </Stack>
          <Divider sx={{ mb: 1.5 }} />

          <InfoRow label="Chirurgien">
            <Typography variant="body2">{formatPersonLabel(data.surgeon)}</Typography>
          </InfoRow>
          <InfoRow label="Instrumentiste">
            <Typography variant="body2">{formatPersonLabel(data.instrumentist)}</Typography>
          </InfoRow>
        </Paper>

        {/* Service */}
        <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
          <Stack direction="row" spacing={1} alignItems="center" justifyContent="space-between" mb={1.5}>
            <Stack direction="row" spacing={1} alignItems="center">
              <AccessTimeIcon fontSize="small" color="action" />
              <Typography variant="subtitle2" fontWeight={600}>Heures de service</Typography>
            </Stack>
            {canEditHours && (
              <Button variant="text" size="small" onClick={() => setOpenEditHours(true)}>
                Modifier
              </Button>
            )}
          </Stack>
          <Divider sx={{ mb: 1.5 }} />

          <InfoRow label="Heures prestées">
            <Typography variant="body2">{formatHoursLabel(data.service?.hours ?? null)}</Typography>
          </InfoRow>
        </Paper>

        {/* Statut DECLARED */}
        {data.status === "DECLARED" && (
          <Paper variant="outlined" sx={{ p: 2, borderRadius: 2, borderColor: "warning.main", bgcolor: "warning.50" }}>
            <Stack direction="row" spacing={1} alignItems="center" mb={0.5}>
              <LocalHospitalIcon fontSize="small" color="warning" />
              <Typography variant="subtitle2" fontWeight={600} color="warning.dark">
                En attente de validation
              </Typography>
            </Stack>
            <Typography variant="body2" color="text.secondary">
              L'instrumentiste a déclaré cette mission. Vérifiez l'encodage et approuvez ou rejetez.
            </Typography>
          </Paper>
        )}
      </Stack>

      {/* Dialogs */}
      {canEdit && openEdit && (
        <EditMissionDialog open={openEdit} onClose={() => setOpenEdit(false)} mission={data} />
      )}
      {canPublish && openPublish && (
        <PublishMissionDialog open={openPublish} onClose={() => setOpenPublish(false)} mission={data} />
      )}
      {canEditHours && openEditHours && (
        <EditServiceHoursDialog open={openEditHours} onClose={() => setOpenEditHours(false)} mission={data} />
      )}

      <Dialog open={rejectConfirmOpen} onClose={() => setRejectConfirmOpen(false)}>
        <DialogTitle>Rejeter la mission</DialogTitle>
        <DialogContent dividers>
          <Typography>Voulez-vous rejeter cette mission déclarée ?</Typography>
          <Typography variant="body2" color="text.secondary" mt={0.5}>
            Le rejet supprime l'encodage côté serveur.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRejectConfirmOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="error" variant="contained" disabled={anyLoading}
            onClick={() => { setRejectConfirmOpen(false); rejectMutation.mutate(); }}>
            Rejeter
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={approveConfirmOpen} onClose={() => setApproveConfirmOpen(false)}>
        <DialogTitle>Approuver la mission</DialogTitle>
        <DialogContent dividers>
          <Typography>Voulez-vous approuver cette mission déclarée ?</Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setApproveConfirmOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="success" variant="contained" disabled={anyLoading}
            onClick={() => { setApproveConfirmOpen(false); approveMutation.mutate(); }}>
            Approuver
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}

export default function MissionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const missionId = Number(id);
  if (!Number.isFinite(missionId)) return <Typography sx={{ p: 3 }}>ID invalide</Typography>;
  return <MissionDetailContent missionId={missionId} />;
}
