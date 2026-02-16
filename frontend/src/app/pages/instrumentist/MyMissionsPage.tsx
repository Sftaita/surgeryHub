import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { CircularProgress, Stack, Typography } from "@mui/material";

import { fetchInstrumentistMyMissions } from "../../features/missions/api/missions.api";
import MissionCardMobile from "../../features/missions/components/MissionCardMobile";

export default function MyMissionsPage() {
  const navigate = useNavigate();

  const { data, isLoading } = useQuery({
    queryKey: ["missions", "my"],
    queryFn: () => fetchInstrumentistMyMissions(1, 100),
  });

  const missions = data?.items ?? [];

  if (isLoading) return <CircularProgress />;

  return (
    <Stack spacing={2}>
      <Typography variant="h6">Mes missions</Typography>

      {missions.length === 0 && (
        <Typography>Aucune mission en cours</Typography>
      )}

      {missions.map((m) => (
        <MissionCardMobile
          key={m.id}
          mission={m}
          primaryAction={{
            label: "Voir",
            action: () => navigate(`/app/i/missions/${m.id}`),
            visible: true,
          }}
        />
      ))}
    </Stack>
  );
}
