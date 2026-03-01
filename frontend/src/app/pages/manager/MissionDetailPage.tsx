import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Button,
  Chip,
  Divider,
  Stack,
  Typography,
  IconButton,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
} from "@mui/material";

import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";

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
  formatSchedulePrecision,
} from "../../features/missions/utils/missions.format";

import EditMissionDialog from "../../features/missions/components/EditMissionDialog";
import PublishMissionDialog from "../../features/missions/components/PublishMissionDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";
import { useToast } from "../../ui/toast/useToast";

type StatusUi = {
  badgeText: string;
  badgeTone: "neutral" | "warning" | "error";
  message?: string;
  dialogTitle?: string;
  dialogBody?: string[];
};

function getStatusUi(status: string): StatusUi {
  const s = String(status ?? "—");

  if (s === "DECLARED") {
    return {
      badgeText: "DECLARED",
      badgeTone: "warning",
      message: "Mission en attente de validation par le manager.",
      dialogTitle: "Mission déclarée",
      dialogBody: [
        "Mission en attente de validation par le manager.",
        "Vous pouvez approuver ou rejeter selon les actions autorisées.",
      ],
    };
  }

  if (s === "REJECTED") {
    return {
      badgeText: "REJECTED",
      badgeTone: "error",
      message: "Mission rejetée par le manager. Encodage supprimé.",
      dialogTitle: "Mission rejetée",
      dialogBody: [
        "Mission rejetée par le manager.",
        "L’encodage a été supprimé. La mission est en statut terminal.",
      ],
    };
  }

  return {
    badgeText: s,
    badgeTone: "neutral",
  };
}

function StatusBadge({
  text,
  tone,
}: {
  text: string;
  tone: "neutral" | "warning" | "error";
}) {
  const sx =
    tone === "warning"
      ? { bgcolor: "warning.light", color: "warning.contrastText" }
      : tone === "error"
        ? { bgcolor: "error.light", color: "error.contrastText" }
        : { bgcolor: "grey.200", color: "text.primary" };

  return (
    <Box
      component="span"
      sx={{
        display: "inline-flex",
        alignItems: "center",
        px: 1,
        py: 0.25,
        borderRadius: 1,
        fontSize: 12,
        fontWeight: 700,
        letterSpacing: 0.2,
        ...sx,
      }}
    >
      {text}
    </Box>
  );
}

function formatHoursLabel(hours?: string | number | null): string {
  if (hours === null || hours === undefined || hours === "") return "—";
  const n = typeof hours === "string" ? Number(hours) : hours;
  if (!Number.isFinite(n)) return "—";
  return `${n} h`;
}

export default function MissionDetailPage() {
  const toast = useToast();
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  const { id } = useParams<{ id: string }>();
  const missionId = Number(id);

  const { data, isLoading, isError, error } = useQuery<Mission>({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: Number.isFinite(missionId),
  });

  const [openEdit, setOpenEdit] = React.useState(false);
  const [openPublish, setOpenPublish] = React.useState(false);
  const [statusInfoOpen, setStatusInfoOpen] = React.useState(false);

  // Lot F5
  const [openEditHours, setOpenEditHours] = React.useState(false);

  // Lot F4 — Confirmations
  const [rejectConfirmOpen, setRejectConfirmOpen] = React.useState(false);
  const [approveConfirmOpen, setApproveConfirmOpen] = React.useState(false);

  // Si data disparaît, on ferme les dialogs
  React.useEffect(() => {
    if (!data) {
      setOpenEdit(false);
      setOpenPublish(false);
      setStatusInfoOpen(false);
      setOpenEditHours(false);
      setRejectConfirmOpen(false);
      setApproveConfirmOpen(false);
    }
  }, [data]);

  async function refreshAfterAction() {
    await queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
    await queryClient.invalidateQueries({
      queryKey: ["missions"],
      exact: false,
    });
    await queryClient.refetchQueries({ queryKey: ["missions"], exact: false });
  }

  const approveMutation = useMutation({
    mutationFn: async () => approveDeclaredMission(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Mission approuvée.");
      navigate("/app/m/missions/to-validate", { replace: true });
    },
    onError: (err: any) => {
      const status = err?.response?.status;
      if (status === 403) toast.error("Accès interdit.");
      else toast.error("Erreur lors de l’approbation.");
    },
  });

  const rejectMutation = useMutation({
    mutationFn: async () => rejectDeclaredMission(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Mission rejetée.");
      navigate("/app/m/missions/to-validate", { replace: true });
    },
    onError: (err: any) => {
      const status = err?.response?.status;
      if (status === 403) toast.error("Accès interdit.");
      else toast.error("Erreur lors du rejet.");
    },
  });

  if (!Number.isFinite(missionId)) return <div>ID invalide</div>;
  if (isLoading) return <div>Chargement…</div>;

  if (isError) {
    const status = (error as any)?.response?.status;
    if (status === 403) return <div>Accès interdit</div>;
    if (status === 404) return <div>Mission introuvable</div>;
    if (status === 401) return <div>Non authentifié</div>;
    return <div>Erreur serveur</div>;
  }

  if (!data) return <div>Aucune donnée</div>;

  const allowed = data.allowedActions ?? [];
  const canEdit = allowed.includes("edit");
  const canPublish = allowed.includes("publish");

  // Lot F4 — visibles uniquement si allowedActions le permet
  const canApprove = allowed.includes("approve");
  const canReject = allowed.includes("reject");

  // Lot F5 — strictement piloté par allowedActions
  const canEditHours = allowed.includes("edit_hours");

  const precisionLabel = formatSchedulePrecision(data.schedulePrecision);
  const typeLabel = formatMissionType(data.type);

  const statusUi = getStatusUi(String(data.status ?? "—"));
  const hasStatusDialog = !!statusUi.dialogTitle;

  const precisionHelp =
    data.schedulePrecision === "EXACT"
      ? "Créneau confirmé : l’horaire est considéré comme fixe."
      : data.schedulePrecision === "APPROXIMATE"
        ? "Créneau estimé : l’horaire peut encore bouger."
        : null;

  const anyLoading = approveMutation.isPending || rejectMutation.isPending;

  // Lot F5 — service (heures prestées)
  const hoursLabel = formatHoursLabel(data.service?.hours ?? null);

  return (
    <Box sx={{ p: 2, maxWidth: 900 }}>
      <Stack direction="row" alignItems="center" spacing={1} mb={1}>
        {/* ✅ Retour */}
        <IconButton
          aria-label="Retour"
          onClick={() => navigate(-1)}
          size="small"
        >
          <ArrowBackIcon fontSize="small" />
        </IconButton>

        <Typography variant="h6">Mission #{data.id}</Typography>

        <Box sx={{ flex: 1 }} />

        <Stack direction="row" spacing={1}>
          {/* ✅ Approve/Reject en FR + confirmation dialog */}
          {canReject ? (
            <Button
              color="error"
              variant="outlined"
              disabled={anyLoading}
              onClick={() => setRejectConfirmOpen(true)}
            >
              Rejeter
            </Button>
          ) : null}

          {canApprove ? (
            <Button
              color="success"
              variant="contained"
              disabled={anyLoading}
              onClick={() => setApproveConfirmOpen(true)}
            >
              Approuver
            </Button>
          ) : null}

          {canEdit ? (
            <Button variant="outlined" onClick={() => setOpenEdit(true)}>
              Éditer
            </Button>
          ) : null}

          {canPublish ? (
            <Button variant="contained" onClick={() => setOpenPublish(true)}>
              Publier
            </Button>
          ) : null}
        </Stack>
      </Stack>

      <Stack spacing={1.5} mt={2}>
        <Typography>
          <strong>Site :</strong> {data.site?.name ?? "—"}
        </Typography>

        <Typography>
          <strong>Date / heure :</strong>{" "}
          {formatBrusselsRange(data.startAt, data.endAt)}
        </Typography>

        <Stack spacing={0.5}>
          <Typography>
            <strong>Précision :</strong> {precisionLabel}
          </Typography>
          {precisionHelp ? (
            <Typography variant="body2" color="text.secondary">
              {precisionHelp}
            </Typography>
          ) : null}
        </Stack>

        <Typography>
          <strong>Type :</strong> {typeLabel}
        </Typography>

        {/* ✅ Statut : garder uniquement le sticker (pas de doublon texte) */}
        <Stack spacing={0.75}>
          <Stack direction="row" spacing={1} alignItems="center">
            <Typography>
              <strong>Statut :</strong>
            </Typography>

            <StatusBadge text={statusUi.badgeText} tone={statusUi.badgeTone} />

            {hasStatusDialog ? (
              <IconButton
                aria-label="Information statut"
                size="small"
                onClick={() => setStatusInfoOpen(true)}
              >
                <HelpOutlineIcon fontSize="small" />
              </IconButton>
            ) : null}
          </Stack>

          {statusUi.message ? (
            <Typography variant="body2" color="text.secondary">
              {statusUi.message}
            </Typography>
          ) : null}
        </Stack>

        <Typography>
          <strong>Chirurgien :</strong> {formatPersonLabel(data.surgeon)}
        </Typography>

        <Typography>
          <strong>Instrumentiste :</strong>{" "}
          {formatPersonLabel(data.instrumentist)}
        </Typography>

        {/* Lot F5 — Heures prestées (sans champs financiers) */}
        <Stack spacing={0.75}>
          <Stack
            direction="row"
            alignItems="center"
            justifyContent="space-between"
          >
            <Typography>
              <strong>Heures prestées :</strong>
            </Typography>

            {canEditHours ? (
              <Button
                variant="outlined"
                size="small"
                onClick={() => setOpenEditHours(true)}
              >
                Modifier
              </Button>
            ) : null}
          </Stack>

          <Typography>{hoursLabel}</Typography>
        </Stack>

        <Divider />

        <Box>
          <Typography>
            <strong>Actions autorisées</strong>
          </Typography>
          <Stack direction="row" spacing={1} mt={1}>
            {allowed.length === 0
              ? "—"
              : allowed.map((a) => (
                  <Chip key={a} label={a} size="small" variant="outlined" />
                ))}
          </Stack>
        </Box>
      </Stack>

      {canEdit && openEdit ? (
        <EditMissionDialog
          open={openEdit}
          onClose={() => setOpenEdit(false)}
          mission={data}
        />
      ) : null}

      {canPublish && openPublish ? (
        <PublishMissionDialog
          open={openPublish}
          onClose={() => setOpenPublish(false)}
          mission={data}
        />
      ) : null}

      {canEditHours && openEditHours ? (
        <EditServiceHoursDialog
          open={openEditHours}
          onClose={() => setOpenEditHours(false)}
          mission={data}
        />
      ) : null}

      <Dialog
        open={statusInfoOpen}
        onClose={() => setStatusInfoOpen(false)}
        aria-labelledby="manager-status-dialog-title"
      >
        <DialogTitle id="manager-status-dialog-title">
          {statusUi.dialogTitle ?? "Statut"}
        </DialogTitle>

        <DialogContent dividers>
          <Stack spacing={1}>
            {(statusUi.dialogBody ?? []).map((line, idx) => (
              <Typography key={idx}>{line}</Typography>
            ))}
          </Stack>
        </DialogContent>

        <DialogActions>
          <Button onClick={() => setStatusInfoOpen(false)} autoFocus>
            OK
          </Button>
        </DialogActions>
      </Dialog>

      {/* ✅ Confirm Reject */}
      <Dialog
        open={rejectConfirmOpen}
        onClose={() => setRejectConfirmOpen(false)}
        aria-labelledby="reject-dialog-title"
      >
        <DialogTitle id="reject-dialog-title">Confirmer le rejet</DialogTitle>

        <DialogContent dividers>
          <Stack spacing={1}>
            <Typography>
              Voulez-vous rejeter cette mission déclarée ?
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Le rejet supprime l’encodage côté backend.
            </Typography>
          </Stack>
        </DialogContent>

        <DialogActions>
          <Button
            onClick={() => setRejectConfirmOpen(false)}
            disabled={anyLoading}
          >
            Annuler
          </Button>

          <Button
            color="error"
            variant="contained"
            onClick={() => {
              setRejectConfirmOpen(false);
              rejectMutation.mutate();
            }}
            disabled={anyLoading}
          >
            Rejeter
          </Button>
        </DialogActions>
      </Dialog>

      {/* ✅ Confirm Approve */}
      <Dialog
        open={approveConfirmOpen}
        onClose={() => setApproveConfirmOpen(false)}
        aria-labelledby="approve-dialog-title"
      >
        <DialogTitle id="approve-dialog-title">
          Confirmer l’approbation
        </DialogTitle>

        <DialogContent dividers>
          <Stack spacing={1}>
            <Typography>
              Voulez-vous approuver cette mission déclarée ?
            </Typography>
          </Stack>
        </DialogContent>

        <DialogActions>
          <Button
            onClick={() => setApproveConfirmOpen(false)}
            disabled={anyLoading}
          >
            Annuler
          </Button>

          <Button
            color="success"
            variant="contained"
            onClick={() => {
              setApproveConfirmOpen(false);
              approveMutation.mutate();
            }}
            disabled={anyLoading}
          >
            Approuver
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}
