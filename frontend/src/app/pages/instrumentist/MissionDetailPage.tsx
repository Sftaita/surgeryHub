import * as React from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Button, CircularProgress, Stack, Typography } from "@mui/material";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import SubmitDialog from "../../features/missions/components/SubmitDialog";

export default function MissionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [open, setOpen] = React.useState(false);

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

  const canEncoding =
    mission.allowedActions?.includes("edit_encoding") ||
    mission.allowedActions?.includes("encoding");

  const canSubmit = mission.allowedActions?.includes("submit");

  return (
    <Stack spacing={2}>
      <Typography variant="h6">Mission #{mission.id}</Typography>

      <Typography>Site: {mission.site?.name ?? "—"}</Typography>
      <Typography>
        Horaire: {String(mission.startAt ?? "—")} →{" "}
        {String(mission.endAt ?? "—")}
      </Typography>
      <Typography>Statut: {String(mission.status ?? "—")}</Typography>

      {canEncoding && (
        <Button
          variant="contained"
          onClick={() => navigate(`/app/i/missions/${mission.id}/encoding`)}
        >
          ENCODAGE
        </Button>
      )}

      {canSubmit && (
        <Button variant="contained" onClick={() => setOpen(true)}>
          SUBMIT
        </Button>
      )}

      <SubmitDialog
        open={open}
        missionId={mission.id}
        onClose={() => setOpen(false)}
        onSubmitted={() => {
          // Invalidation "best effort" ciblée
          queryClient.invalidateQueries({ queryKey: ["mission", mission.id] });
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
