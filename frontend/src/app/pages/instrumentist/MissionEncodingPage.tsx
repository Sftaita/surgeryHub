import { useNavigate, useParams } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Button, CircularProgress, Stack, Typography } from "@mui/material";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import { fetchMissionEncoding } from "../../features/encoding/api/encoding.api";
import InterventionsSection from "../../features/encoding/components/InterventionsSection";

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

export default function MissionEncodingPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

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
    mission?.allowedActions?.includes("edit_encoding") ||
    mission?.allowedActions?.includes("encoding");

  const {
    data: encoding,
    isLoading: isEncodingLoading,
    error: encodingError,
  } = useQuery({
    queryKey: ["missionEncoding", missionId],
    queryFn: () => fetchMissionEncoding(missionId),
    enabled: isValidId && !!mission && !!canEncoding,
  });

  if (!isValidId) {
    return <Typography>Identifiant de mission invalide</Typography>;
  }

  if (isMissionLoading) return <CircularProgress />;

  if (missionError) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography color="error">
          {extractErrorMessage(missionError)}
        </Typography>
      </Stack>
    );
  }

  if (!mission) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography>Mission introuvable</Typography>
      </Stack>
    );
  }

  if (!canEncoding) {
    return (
      <Stack spacing={2}>
        <Button variant="outlined" onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography variant="h6">Encodage — Mission #{mission.id}</Typography>
        <Typography color="text.secondary">
          Accès non autorisé (allowedActions ne contient pas edit_encoding).
        </Typography>
      </Stack>
    );
  }

  if (isEncodingLoading) return <CircularProgress />;

  if (encodingError) {
    return (
      <Stack spacing={2}>
        <Stack direction="row" spacing={1} alignItems="center">
          <Button variant="outlined" onClick={() => navigate(-1)}>
            Retour
          </Button>
          <Typography variant="h6">Encodage — Mission #{mission.id}</Typography>
        </Stack>

        <Typography color="error">
          {extractErrorMessage(encodingError)}
        </Typography>
      </Stack>
    );
  }

  if (!encoding) {
    return (
      <Stack spacing={2}>
        <Stack direction="row" spacing={1} alignItems="center">
          <Button variant="outlined" onClick={() => navigate(-1)}>
            Retour
          </Button>
          <Typography variant="h6">Encodage — Mission #{mission.id}</Typography>
        </Stack>
        <Typography>Encodage introuvable</Typography>
      </Stack>
    );
  }

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Button variant="outlined" onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography variant="h6">Encodage — Mission #{mission.id}</Typography>
      </Stack>

      <Typography color="text.secondary">
        Type: {String(encoding.missionType)} — Statut:{" "}
        {String(encoding.missionStatus)}
      </Typography>

      <InterventionsSection
        missionId={mission.id}
        canEdit={true}
        interventions={encoding.interventions ?? []}
      />
    </Stack>
  );
}
