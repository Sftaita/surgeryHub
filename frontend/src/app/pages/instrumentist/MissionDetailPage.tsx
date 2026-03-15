import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Button,
  CircularProgress,
  Stack,
  Typography,
  IconButton,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Box,
  Divider,
  Chip,
  Paper,
} from "@mui/material";

import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";

import dayjs from "dayjs";
import "dayjs/locale/fr";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import SubmitDialog from "../../features/missions/components/SubmitDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";

dayjs.locale("fr");

type ChipColor =
  | "default"
  | "info"
  | "primary"
  | "warning"
  | "error"
  | "success";

type StatusUi = {
  chipLabel: string;
  chipColor: ChipColor;
  message?: string;
  dialogTitle?: string;
  dialogBody?: string[];
};

function getStatusUi(status: string): StatusUi {
  const s = String(status ?? "");

  switch (s) {
    case "DRAFT":
      return { chipLabel: "Brouillon", chipColor: "default" };
    case "OPEN":
      return { chipLabel: "Disponible", chipColor: "info" };
    case "ASSIGNED":
      return { chipLabel: "En cours", chipColor: "primary" };
    case "DECLARED":
      return {
        chipLabel: "À valider",
        chipColor: "warning",
        message: "Mission en attente de validation par le manager.",
        dialogTitle: "Mission déclarée",
        dialogBody: [
          "Mission en attente de validation par le manager.",
          "Vous pouvez consulter la mission. Certaines actions peuvent être indisponibles tant que la validation n'est pas faite.",
        ],
      };
    case "REJECTED":
      return {
        chipLabel: "Rejetée",
        chipColor: "error",
        message: "Mission rejetée par le manager. Encodage supprimé.",
        dialogTitle: "Mission rejetée",
        dialogBody: [
          "Mission rejetée par le manager.",
          "L'encodage a été supprimé et aucune action d'édition n'est disponible.",
        ],
      };
    case "SUBMITTED":
      return { chipLabel: "Soumis", chipColor: "success" };
    case "VALIDATED":
      return { chipLabel: "Validée", chipColor: "success" };
    case "CLOSED":
      return { chipLabel: "Clôturée", chipColor: "default" };
    default:
      return { chipLabel: s || "—", chipColor: "default" };
  }
}

function formatDateLong(d: dayjs.Dayjs): string {
  const raw = d.format("dddd D MMMM YYYY");
  return raw.charAt(0).toUpperCase() + raw.slice(1);
}

function formatTimeShort(d: dayjs.Dayjs): string {
  return d.format("HH[h]mm");
}

function formatHoursLabel(hours?: string | number | null): string {
  if (hours === null || hours === undefined || hours === "") return "—";
  const n = typeof hours === "string" ? Number(hours) : hours;
  if (!Number.isFinite(n)) return "—";
  return `${n} h`;
}

function missionTypeLabel(type?: string | null): string {
  if (type === "BLOCK") return "Bloc opératoire";
  if (type === "CONSULTATION") return "Consultation";
  return type ?? "—";
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
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [openSubmit, setOpenSubmit] = React.useState(false);
  const [statusInfoOpen, setStatusInfoOpen] = React.useState(false);
  const [openEditHours, setOpenEditHours] = React.useState(false);

  const isValidId = Number.isFinite(missionId) && missionId > 0;

  const { data: mission, isLoading } = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: isValidId,
  });

  if (!isValidId) {
    return <Typography>Identifiant de mission invalide</Typography>;
  }

  if (isLoading) return <CircularProgress />;
  if (!mission) return <Typography>Mission introuvable</Typography>;

  const canEncoding =
    mission.allowedActions?.includes("encoding") ||
    mission.allowedActions?.includes("edit_encoding");

  const canSubmit = mission.allowedActions?.includes("submit");
  const canEditHours = mission.allowedActions?.includes("edit_hours");

  const statusUi = getStatusUi(String(mission.status ?? ""));

  const start = mission.startAt ? dayjs(String(mission.startAt)) : null;
  const end = mission.endAt ? dayjs(String(mission.endAt)) : null;

  const hasBothDates = !!start && !!end && start.isValid() && end.isValid();

  const dateLine = hasBothDates ? formatDateLong(start!) : "—";
  const timeLine = hasBothDates
    ? `${formatTimeShort(start!)} → ${formatTimeShort(end!)}`
    : "—";

  const siteName = (mission.site?.name ?? "").trim() || "—";

  const surgeonRef = (mission as any).surgeon;
  const surgeonLabel = (() => {
    if (!surgeonRef) return null;
    const fn = (surgeonRef.firstname ?? "").toString().trim();
    const ln = (surgeonRef.lastname ?? "").toString().trim();
    const full = `${fn} ${ln}`.trim();
    if (full) return `Dr. ${full}`;
    const dn = (surgeonRef.displayName ?? "").toString().trim();
    if (dn) return dn;
    return surgeonRef.email ?? null;
  })();

  const missionType = missionTypeLabel((mission as any).type ?? null);

  const hasStatusDialog = !!statusUi.dialogTitle;
  const hoursLabel = formatHoursLabel(mission.service?.hours ?? null);

  return (
    <Stack spacing={2}>
      {!embedded ? (
        <Box sx={{ display: "flex", alignItems: "center", gap: 1 }}>
          <Button
            variant="text"
            startIcon={<ArrowBackIcon />}
            onClick={() => navigate("/app/i/my-missions")}
          >
            Mes missions
          </Button>
        </Box>
      ) : null}

      <Typography variant="h5">Mission #{mission.id}</Typography>

      <Divider />

      <Paper variant="outlined" sx={{ p: 2 }}>
        <Stack spacing={1.5}>
          <Stack spacing={0.25}>
            <Typography variant="caption" color="text.secondary">
              Site
            </Typography>
            <Typography variant="body1" fontWeight={600}>
              {siteName}
            </Typography>
          </Stack>

          <Stack spacing={0.25}>
            <Typography variant="caption" color="text.secondary">
              Date
            </Typography>
            <Typography variant="body1" fontWeight={600}>
              {dateLine}
            </Typography>
          </Stack>

          <Stack spacing={0.25}>
            <Typography variant="caption" color="text.secondary">
              Horaire
            </Typography>
            <Typography variant="body1" fontWeight={600}>
              {timeLine}
            </Typography>
          </Stack>

          {surgeonLabel ? (
            <Stack spacing={0.25}>
              <Typography variant="caption" color="text.secondary">
                Chirurgien
              </Typography>
              <Typography variant="body1" fontWeight={600}>
                {surgeonLabel}
              </Typography>
            </Stack>
          ) : null}

          <Stack spacing={0.25}>
            <Typography variant="caption" color="text.secondary">
              Type
            </Typography>
            <Typography variant="body1" fontWeight={600}>
              {missionType}
            </Typography>
          </Stack>
        </Stack>
      </Paper>

      <Stack spacing={0.75}>
        <Stack direction="row" spacing={1} alignItems="center">
          <Chip
            label={statusUi.chipLabel}
            color={statusUi.chipColor}
            size="small"
          />

          {hasStatusDialog && (
            <IconButton
              aria-label="Information statut"
              size="small"
              onClick={() => setStatusInfoOpen(true)}
            >
              <HelpOutlineIcon fontSize="small" />
            </IconButton>
          )}
        </Stack>

        {statusUi.message ? (
          <Typography variant="body2" color="text.secondary">
            {statusUi.message}
          </Typography>
        ) : null}
      </Stack>

      <Divider />

      <Stack spacing={0.75}>
        <Stack
          direction="row"
          alignItems="center"
          justifyContent="space-between"
        >
          <Typography variant="subtitle2" color="text.secondary">
            Heures prestées
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

      {canEncoding && (
        <Button
          variant="contained"
          onClick={() => navigate(`/app/i/missions/${mission.id}/encoding`)}
        >
          Encoder la mission
        </Button>
      )}

      {canSubmit && (
        <Button variant="contained" onClick={() => setOpenSubmit(true)}>
          Soumettre
        </Button>
      )}

      <SubmitDialog
        open={openSubmit}
        missionId={mission.id}
        onClose={() => setOpenSubmit(false)}
        onSubmitted={() => {
          queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
          queryClient.invalidateQueries({ queryKey: ["missions"] });
          queryClient.invalidateQueries({ queryKey: ["missions", "offers"] });
          queryClient.invalidateQueries({
            queryKey: ["missions", "my-missions"],
          });

          if (embedded && onCloseEmbedded) {
            onCloseEmbedded();
          }
        }}
      />

      {canEditHours && openEditHours ? (
        <EditServiceHoursDialog
          open={openEditHours}
          onClose={() => setOpenEditHours(false)}
          mission={mission}
        />
      ) : null}

      <Dialog
        open={statusInfoOpen}
        onClose={() => setStatusInfoOpen(false)}
        aria-labelledby="status-dialog-title"
      >
        <DialogTitle id="status-dialog-title">
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
    </Stack>
  );
}

export default function MissionDetailPage() {
  const { id } = useParams<{ id: string }>();

  const missionId = Number(id);
  const isValidId = Number.isFinite(missionId) && missionId > 0;

  if (!isValidId) {
    return <Typography>Identifiant de mission invalide</Typography>;
  }

  return <MissionDetailContent missionId={missionId} />;
}
