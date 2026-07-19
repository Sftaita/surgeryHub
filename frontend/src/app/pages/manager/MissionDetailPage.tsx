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
  TextField,
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
  getMissionExecution,
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
import FinancialCalculationCard from "../../features/financial-calculation/components/FinancialCalculationCard";
import {
  fetchMissionEncoding,
  reopenMissionEncoding,
  rejectMissionEncoding,
  validateMissionEncoding,
} from "../../features/encoding/api/encoding.api";
import { EncodingStatusPanel } from "../../features/encoding/components/EncodingStatusPanel";
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

  /** EPIC Exécution & Valorisation, Lot 1 — le RÉALISÉ, distinct des "heures de service" legacy ci-dessus. */
  const executionQuery = useQuery({
    queryKey: ["mission-execution", missionId],
    queryFn: () => getMissionExecution(missionId),
    enabled: Number.isFinite(missionId),
  });

  /**
   * Lot 7 (D-070) — coherenceSummary + encodingComments. GET .../encoding refuse
   * (MissionEncodingGuard) une fois la mission REJECTED ou verrouillée
   * (encodingLockedAt, càd VALIDATED/CLOSED) : on ne l'interroge donc que dans les
   * statuts où l'appel réussit réellement, jamais en devinant côté client.
   */
  const encodingQuery = useQuery({
    queryKey: ["missionEncoding", missionId],
    queryFn: () => fetchMissionEncoding(missionId),
    enabled:
      Number.isFinite(missionId) &&
      !!data &&
      !["VALIDATED", "CLOSED", "REJECTED"].includes(String(data.status)),
  });

  const [openEdit, setOpenEdit] = React.useState(false);
  const [openPublish, setOpenPublish] = React.useState(false);
  const [openEditHours, setOpenEditHours] = React.useState(false);
  const [rejectConfirmOpen, setRejectConfirmOpen] = React.useState(false);
  const [approveConfirmOpen, setApproveConfirmOpen] = React.useState(false);
  const [validateConfirmOpen, setValidateConfirmOpen] = React.useState(false);
  const [rejectEncodingOpen, setRejectEncodingOpen] = React.useState(false);
  const [rejectEncodingComment, setRejectEncodingComment] = React.useState("");
  const [reopenOpen, setReopenOpen] = React.useState(false);
  const [reopenComment, setReopenComment] = React.useState("");

  async function refreshAfterAction() {
    await queryClient.invalidateQueries({ queryKey: ["mission", missionId] });
    await queryClient.invalidateQueries({ queryKey: ["missionEncoding", missionId] });
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

  /** Lot 7 (D-070) — validate/reject/reopen de l'encodage, distincts d'approve/reject
   *  ci-dessus qui ne concernent que le statut DECLARED. */
  const validateEncodingMutation = useMutation({
    mutationFn: async () => validateMissionEncoding(missionId),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Encodage validé.");
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors de la validation.");
    },
  });

  const rejectEncodingMutation = useMutation({
    mutationFn: async (comment: string) => rejectMissionEncoding(missionId, comment),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Encodage rejeté, renvoyé à l'instrumentiste.");
      setRejectEncodingComment("");
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors du rejet de l'encodage.");
    },
  });

  const reopenEncodingMutation = useMutation({
    mutationFn: async (comment: string) => reopenMissionEncoding(missionId, comment || undefined),
    onSuccess: async () => {
      await refreshAfterAction();
      toast.success("Encodage rouvert.");
      setReopenComment("");
    },
    onError: (err: any) => {
      toast.error(err?.response?.status === 403 ? "Accès interdit." : "Erreur lors de la réouverture.");
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
  const canEditHours = allowed.includes("edit_hours");
  /** `reject` est un libellé d'action partagé entre DECLARED (rejectDeclaredMission)
   *  et SUBMITTED (rejectMissionEncoding, Lot 7) — un statut à la fois, jamais ambigu. */
  const canReject = allowed.includes("reject") && data.status === "DECLARED";
  const canRejectEncoding = allowed.includes("reject") && data.status === "SUBMITTED";
  const canValidateEncoding = allowed.includes("validate");
  const canReopenEncoding = allowed.includes("reopen");

  const anyLoading =
    approveMutation.isPending ||
    rejectMutation.isPending ||
    validateEncodingMutation.isPending ||
    rejectEncodingMutation.isPending ||
    reopenEncodingMutation.isPending;

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
      {(canEdit || canPublish || canApprove || canReject || canValidateEncoding || canRejectEncoding || canReopenEncoding) && (
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
          {canRejectEncoding && (
            <Button color="error" variant="outlined" size="small" disabled={anyLoading}
              onClick={() => setRejectEncodingOpen(true)}>
              Rejeter l'encodage
            </Button>
          )}
          {canValidateEncoding && (
            <Button color="success" variant="contained" size="small" disableElevation disabled={anyLoading}
              onClick={() => setValidateConfirmOpen(true)}>
              Valider l'encodage
            </Button>
          )}
          {canReopenEncoding && (
            <Button color="warning" variant="outlined" size="small" disabled={anyLoading}
              onClick={() => setReopenOpen(true)}>
              Rouvrir l'encodage
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

        {/* Exécution réelle (EPIC Exécution & Valorisation, Lot 1) — distinct du planifié et des heures de service legacy ci-dessus ; source de la durée utilisée par la valorisation financière (FinancialCalculationLine). */}
        {executionQuery.data && (
          <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
            <Stack direction="row" spacing={1} alignItems="center" mb={1.5}>
              <AccessTimeIcon fontSize="small" color="action" />
              <Typography variant="subtitle2" fontWeight={600}>Exécution réelle</Typography>
              {!executionQuery.data.hasExecutionRecord && (
                <Chip label="Non renseignée — repli sur le planifié" size="small" variant="outlined" />
              )}
            </Stack>
            <Divider sx={{ mb: 1.5 }} />
            <InfoRow label="Début réel">
              <Typography variant="body2">
                {executionQuery.data.actualStartAt ? new Date(executionQuery.data.actualStartAt).toLocaleString("fr-BE") : "—"}
              </Typography>
            </InfoRow>
            <InfoRow label="Fin réelle">
              <Typography variant="body2">
                {executionQuery.data.actualEndAt ? new Date(executionQuery.data.actualEndAt).toLocaleString("fr-BE") : "—"}
              </Typography>
            </InfoRow>
            <InfoRow label="Durée effective">
              <Typography variant="body2">
                {executionQuery.data.effectiveDurationMinutes} min
                {" "}
                <Typography component="span" variant="caption" color="text.secondary">
                  ({executionQuery.data.effectiveDurationSource === "ACTUAL_TIMES" ? "horaires réels"
                    : executionQuery.data.effectiveDurationSource === "ACTUAL_EXPLICIT" ? "durée déclarée"
                    : "repli planifié"})
                </Typography>
              </Typography>
            </InfoRow>
            {executionQuery.data.disputes.length > 0 && (
              <InfoRow label="Contestations">
                <Stack spacing={0.5}>
                  {executionQuery.data.disputes.map((d) => (
                    <Chip
                      key={d.id}
                      size="small"
                      label={`${d.reasonCode} — ${d.status}`}
                      color={d.status === "OPEN" ? "warning" : "default"}
                      variant="outlined"
                      sx={{ alignSelf: "flex-start" }}
                    />
                  ))}
                </Stack>
              </InfoRow>
            )}
          </Paper>
        )}

        {/* Cycle de vie de l'encodage (Lot 7, D-070) : statut réel, signaux de cohérence
            informationnels, et historique des rejets/réouvertures. Absent (query désactivée)
            si VALIDATED/CLOSED/REJECTED — MissionEncodingGuard refuse la lecture une fois
            verrouillé, on garde alors juste le chip de statut affiché plus haut. */}
        {["ASSIGNED", "IN_PROGRESS", "ENCODING_IN_PROGRESS", "SUBMITTED"].includes(String(data.status)) && (
          <EncodingStatusPanel
            status={String(data.status)}
            coherenceSummary={encodingQuery.data?.coherenceSummary}
            comments={encodingQuery.data?.encodingComments}
          />
        )}

        {/* Calcul financier (EPIC Exécution & Valorisation, Lot 3) — nécessite une mission validée. */}
        {(data.status === "VALIDATED" || data.status === "CLOSED") && (
          <FinancialCalculationCard missionId={data.id} />
        )}

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

      <Dialog open={validateConfirmOpen} onClose={() => setValidateConfirmOpen(false)}>
        <DialogTitle>Valider l'encodage</DialogTitle>
        <DialogContent dividers>
          <Typography>
            Voulez-vous valider cet encodage ? Il sera définitivement verrouillé — plus aucune
            modification possible, sauf réouverture explicite ensuite.
          </Typography>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setValidateConfirmOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="success" variant="contained" disabled={anyLoading}
            onClick={() => { setValidateConfirmOpen(false); validateEncodingMutation.mutate(); }}>
            Valider
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={rejectEncodingOpen} onClose={() => setRejectEncodingOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Rejeter l'encodage</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" mb={1.5}>
            L'encodage repart chez l'instrumentiste pour correction. Le commentaire est
            obligatoire (ex : matériel manquant, quantité incorrecte, mauvaise firme,
            intervention incomplète...).
          </Typography>
          <TextField
            autoFocus
            fullWidth
            multiline
            minRows={3}
            label="Motif du rejet"
            value={rejectEncodingComment}
            onChange={(e) => setRejectEncodingComment(e.target.value)}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRejectEncodingOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="error" variant="contained"
            disabled={anyLoading || rejectEncodingComment.trim() === ""}
            onClick={() => { setRejectEncodingOpen(false); rejectEncodingMutation.mutate(rejectEncodingComment.trim()); }}>
            Rejeter
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={reopenOpen} onClose={() => setReopenOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Rouvrir l'encodage</DialogTitle>
        <DialogContent dividers>
          <Typography variant="body2" color="text.secondary" mb={1.5}>
            La mission repasse en encodage modifiable côté instrumentiste. Commentaire
            optionnel.
          </Typography>
          <TextField
            autoFocus
            fullWidth
            multiline
            minRows={3}
            label="Commentaire (optionnel)"
            value={reopenComment}
            onChange={(e) => setReopenComment(e.target.value)}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setReopenOpen(false)} disabled={anyLoading}>Annuler</Button>
          <Button color="warning" variant="contained" disabled={anyLoading}
            onClick={() => { setReopenOpen(false); reopenEncodingMutation.mutate(reopenComment.trim()); }}>
            Rouvrir
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
