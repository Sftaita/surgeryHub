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
} from "@mui/material";

import HelpOutlineIcon from "@mui/icons-material/HelpOutline";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";

import dayjs from "dayjs";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import SubmitDialog from "../../features/missions/components/SubmitDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";

function formatDateShort(d: dayjs.Dayjs): string {
  return d.format("DD.MM.YY");
}

function formatTimeShort(d: dayjs.Dayjs): string {
  return d.format("HH[h]mm");
}

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
        "Vous pouvez consulter la mission. Certaines actions peuvent être indisponibles tant que la validation n’est pas faite.",
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
        "L’encodage a été supprimé et aucune action d’édition n’est disponible.",
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

  // Lot F5
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

  // Lot 4/5/6 — strictement piloté par allowedActions
  const canEncoding =
    mission.allowedActions?.includes("encoding") ||
    mission.allowedActions?.includes("edit_encoding");

  const canSubmit = mission.allowedActions?.includes("submit");

  // Lot F5 — strictement piloté par allowedActions
  const canEditHours = mission.allowedActions?.includes("edit_hours");

  const statusUi = getStatusUi(String(mission.status ?? "—"));

  // --- Horaire (format utilisateur) ---
  // On parse via dayjs; la timezone par défaut est Europe/Brussels (AppProviders)
  const start = mission.startAt ? dayjs(String(mission.startAt)) : null;
  const end = mission.endAt ? dayjs(String(mission.endAt)) : null;

  const hasBothDates = !!start && !!end && start.isValid() && end.isValid();
  const sameDay = hasBothDates ? start!.isSame(end!, "day") : false;

  const dateLine = (() => {
    if (!hasBothDates) return "—";
    if (sameDay) return formatDateShort(start!);
    return `${formatDateShort(start!)} - ${formatDateShort(end!)}`;
  })();

  const startTimeLine = hasBothDates ? formatTimeShort(start!) : "—";
  const endTimeLine = hasBothDates ? formatTimeShort(end!) : "—";

  // Lieu: éviter les doublons
  const siteName = (mission.site?.name ?? "").trim() || "—";

  const hasStatusDialog = !!statusUi.dialogTitle;

  // Lot F5 — service (heures prestées)
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

      <Stack spacing={0.5}>
        <Typography variant="subtitle2" color="text.secondary">
          Lieu
        </Typography>
        <Typography>{siteName}</Typography>
      </Stack>

      <Stack spacing={0.5}>
        <Typography variant="subtitle2" color="text.secondary">
          Horaire
        </Typography>

        <Typography>Date : {dateLine}</Typography>

        <Stack direction="row" spacing={2}>
          <Typography>Début : {startTimeLine}</Typography>
          <Typography>Fin : {endTimeLine}</Typography>
        </Stack>
      </Stack>

      <Stack spacing={0.75}>
        <Stack direction="row" spacing={1} alignItems="center">
          <Typography>Statut :</Typography>
          <StatusBadge text={statusUi.badgeText} tone={statusUi.badgeTone} />

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
          Encodage
        </Button>
      )}

      {canSubmit && (
        <Button variant="contained" onClick={() => setOpenSubmit(true)}>
          Submit
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
