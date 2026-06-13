import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Button,
  CircularProgress,
  Divider,
  Stack,
  Typography,
} from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import AccessTimeIcon from "@mui/icons-material/AccessTime";
import InfoOutlinedIcon from "@mui/icons-material/InfoOutlined";
import { MobileCard } from "../../ui/mobile/MobileCard";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import { fetchMissionEncoding } from "../../features/encoding/api/encoding.api";
import InterventionsSection from "../../features/encoding/components/InterventionsSection";
import SubmitDialog from "../../features/missions/components/SubmitDialog";
import EditServiceHoursDialog from "../../features/missions/components/EditServiceHoursDialog";

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

function formatHours(hours?: string | number | null): string {
  if (hours === null || hours === undefined || hours === "") return "Non renseigné";
  const n = typeof hours === "string" ? Number(hours) : hours;
  if (!Number.isFinite(n)) return "Non renseigné";
  return `${n} h`;
}

export default function MissionEncodingPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [openSubmit, setOpenSubmit] = React.useState(false);
  const [openEditHours, setOpenEditHours] = React.useState(false);

  const missionId = Number(id);
  const isValidId = Number.isFinite(missionId) && missionId > 0;

  const {
    data: mission,
    isLoading: isMissionLoading,
    error: missionError,
  } = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: isValidId,
  });

  const canEncoding =
    mission?.allowedActions?.includes("encoding") ||
    mission?.allowedActions?.includes("edit_encoding");

  const canSubmit = mission?.allowedActions?.includes("submit") ?? false;
  const canEditHours = mission?.allowedActions?.includes("edit_hours") ?? false;

  const {
    data: encoding,
    isLoading: isEncodingLoading,
    error: encodingError,
  } = useQuery({
    queryKey: ["missionEncoding", missionId],
    queryFn: () => fetchMissionEncoding(missionId),
    enabled: isValidId && !!mission && !!canEncoding,
  });

  if (!isValidId) return <Typography>Identifiant invalide</Typography>;
  if (isMissionLoading) return <CircularProgress />;

  if (missionError) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography color="error">{extractErrorMessage(missionError)}</Typography>
      </Stack>
    );
  }

  if (!mission) return <Typography>Mission introuvable</Typography>;

  if (!canEncoding) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography color="text.secondary">
          L'encodage n'est pas disponible pour cette mission.
        </Typography>
      </Stack>
    );
  }

  if (isEncodingLoading) return <CircularProgress />;

  if (encodingError) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" size="small" startIcon={<ArrowBackIcon />} onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography color="error">{extractErrorMessage(encodingError)}</Typography>
      </Stack>
    );
  }

  if (!encoding) return <Typography>Données d'encodage introuvables</Typography>;

  const canEdit =
    encoding.mission?.allowedActions?.includes("encoding") ||
    encoding.mission?.allowedActions?.includes("edit_encoding") ||
    false;

  const hoursLabel = formatHours(mission.service?.hours ?? null);

  return (
    <Stack spacing={2}>
      {/* Header */}
      <Stack direction="row" spacing={1} alignItems="center">
        <Button
          variant="text"
          size="small"
          startIcon={<ArrowBackIcon />}
          onClick={() => navigate(-1)}
          sx={{ minWidth: 0 }}
        >
          Mission
        </Button>
        <Typography variant="subtitle1" fontWeight={600} sx={{ flex: 1 }}>
          Encodage #{mission.id}
        </Typography>
      </Stack>

      {/* Bandeau aide */}
      <Stack
        direction="row"
        spacing={1}
        alignItems="flex-start"
        sx={{
          px: 1.5,
          py: 1.25,
          bgcolor: "#EFF6FF",
          borderRadius: 2,
          border: "1px solid #DBEAFE",
        }}
      >
        <InfoOutlinedIcon sx={{ fontSize: 16, color: "primary.main", mt: 0.1, flexShrink: 0 }} />
        <Typography variant="caption" color="primary.dark">
          Pour que les heures soient comptabilisées, l'encodage de la mission doit être terminé.
        </Typography>
      </Stack>

      {/* Zone heures */}
      <MobileCard>
        <Stack
          direction="row"
          alignItems="center"
          sx={{ px: 2, py: 1.5, borderBottom: "1px solid", borderColor: "divider" }}
        >
          <Box sx={{ color: "primary.main", display: "flex", mr: 1 }}>
            <AccessTimeIcon fontSize="small" />
          </Box>
          <Typography variant="subtitle2" sx={{ flex: 1 }}>
            Heures prestées
          </Typography>
          {canEditHours && (
            <Button size="small" variant="text" onClick={() => setOpenEditHours(true)}>
              Modifier
            </Button>
          )}
        </Stack>
        <Box sx={{ px: 2, py: 2 }}>
          <Typography
            variant="h4"
            fontWeight={800}
            color={hoursLabel === "Non renseigné" ? "text.disabled" : "primary.main"}
          >
            {hoursLabel}
          </Typography>
        </Box>
      </MobileCard>

      {/* Interventions */}
      <InterventionsSection
        missionId={mission.id}
        canEdit={canEdit}
        interventions={encoding.interventions ?? []}
        catalog={encoding.catalog}
      />

      <Divider />

      {/* Terminer l'encodage */}
      {canSubmit && (
        <Button
          variant="contained"
          disableElevation
          fullWidth
          size="large"
          onClick={() => setOpenSubmit(true)}
          sx={{ borderRadius: 2, fontWeight: 700 }}
        >
          Terminer l'encodage
        </Button>
      )}

      <SubmitDialog
        open={openSubmit}
        missionId={mission.id}
        onClose={() => setOpenSubmit(false)}
        onSubmitted={() => {
          queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
          queryClient.invalidateQueries({ queryKey: ["missionEncoding", mission.id] });
          queryClient.invalidateQueries({ queryKey: ["missions"] });
          navigate(-1);
        }}
      />

      {canEditHours && openEditHours && (
        <EditServiceHoursDialog
          open={openEditHours}
          onClose={() => setOpenEditHours(false)}
          mission={mission}
        />
      )}
    </Stack>
  );
}
