import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Button, CircularProgress, Stack, Typography } from "@mui/material";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import { fetchMissionEncoding } from "../../features/encoding/api/encoding.api";
import InterventionsSection from "../../features/encoding/components/InterventionsSection";
import MaterialLinesSection from "../../features/encoding/components/MaterialLinesSection";
import SubmitDialog from "../../features/missions/components/SubmitDialog";

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
  const queryClient = useQueryClient();

  const [openSubmit, setOpenSubmit] = React.useState(false);

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

  // Lot 4 — strictement piloté par allowedActions
  const canEncoding = mission?.allowedActions?.includes("encoding");

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
          Accès non autorisé (allowedActions ne contient pas encoding).
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

  // Source de vérité : allowedActions dans le payload encoding
  const canEdit =
    encoding.mission?.allowedActions?.includes("encoding") ?? false;
  const canSubmit =
    encoding.mission?.allowedActions?.includes("submit") ?? false;

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} alignItems="center">
        <Button variant="outlined" onClick={() => navigate(-1)}>
          Retour
        </Button>
        <Typography variant="h6">Encodage — Mission #{mission.id}</Typography>

        {canSubmit && (
          <Button
            variant="contained"
            onClick={() => setOpenSubmit(true)}
            sx={{ marginLeft: "auto" }}
          >
            SUBMIT
          </Button>
        )}
      </Stack>

      <Typography color="text.secondary">
        Type: {String(encoding.mission?.type ?? "—")} — Statut:{" "}
        {String(encoding.mission?.status ?? "—")}
      </Typography>

      <InterventionsSection
        missionId={mission.id}
        canEdit={canEdit}
        interventions={encoding.interventions ?? []}
      />

      <MaterialLinesSection
        missionId={mission.id}
        canEdit={canEdit}
        interventions={encoding.interventions ?? []}
        catalog={encoding.catalog}
      />

      <SubmitDialog
        open={openSubmit}
        missionId={mission.id}
        onClose={() => setOpenSubmit(false)}
        onSubmitted={() => {
          // Invalidation ciblée après submit (status/allowedActions peuvent changer)
          queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
          queryClient.invalidateQueries({
            queryKey: ["missionEncoding", mission.id],
          });

          // Listes globales (best-effort)
          queryClient.invalidateQueries({ queryKey: ["missions"] });
          queryClient.invalidateQueries({ queryKey: ["missions", "offers"] });
          queryClient.invalidateQueries({
            queryKey: ["missions", "my-missions"],
          });
        }}
      />
    </Stack>
  );
}
