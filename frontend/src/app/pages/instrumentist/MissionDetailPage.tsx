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

function formatDateShort(d: dayjs.Dayjs): string {
  return d.format("DD.MM.YY");
}

function formatTimeShort(d: dayjs.Dayjs): string {
  return d.format("HH[h]mm");
}

export default function MissionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [openSubmit, setOpenSubmit] = React.useState(false);
  const [statusInfoOpen, setStatusInfoOpen] = React.useState(false);

  const missionId = Number(id);
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

  // Lot 4 — strictement piloté par allowedActions
  const canEncoding =
    mission.allowedActions?.includes("encoding") ||
    mission.allowedActions?.includes("edit_encoding");

  const canSubmit = mission.allowedActions?.includes("submit");

  const isDeclared = mission.status === "DECLARED";
  const rawStatus = String(mission.status ?? "—");
  const statusLabel = isDeclared ? "En cours de validation" : rawStatus;

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

  return (
    <Stack spacing={2}>
      {/* Header UX: retour clair */}
      <Box sx={{ display: "flex", alignItems: "center", gap: 1 }}>
        <Button
          variant="text"
          startIcon={<ArrowBackIcon />}
          onClick={() => navigate("/app/i/my-missions")}
        >
          Mes missions
        </Button>
      </Box>

      <Typography variant="h5">Mission #{mission.id}</Typography>

      <Divider />

      {/* Lieu */}
      <Stack spacing={0.5}>
        <Typography variant="subtitle2" color="text.secondary">
          Lieu
        </Typography>
        <Typography>{siteName}</Typography>
      </Stack>

      {/* Horaire */}
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

      {/* Statut */}
      <Stack direction="row" spacing={1} alignItems="center">
        <Typography>Statut : {statusLabel}</Typography>

        {isDeclared && (
          <IconButton
            aria-label="Information statut"
            size="small"
            onClick={() => setStatusInfoOpen(true)}
          >
            <HelpOutlineIcon fontSize="small" />
          </IconButton>
        )}
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
        }}
      />

      {/* Explication statut: orientée utilisateur */}
      <Dialog
        open={statusInfoOpen}
        onClose={() => setStatusInfoOpen(false)}
        aria-labelledby="declared-status-dialog-title"
      >
        <DialogTitle id="declared-status-dialog-title">
          En cours de validation
        </DialogTitle>

        <DialogContent dividers>
          <Stack spacing={1}>
            <Typography>
              Cette mission a été déclarée et est en attente de validation.
            </Typography>
            <Typography>
              Vous pouvez consulter la mission. Certaines actions peuvent être
              indisponibles tant que la validation n’est pas faite.
            </Typography>
            <Typography>
              Si elle est refusée, elle passera en statut “Refusée”.
            </Typography>
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
