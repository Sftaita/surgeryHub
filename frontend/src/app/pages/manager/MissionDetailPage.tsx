import { useParams } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Box, Chip, Stack, Typography } from "@mui/material";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import {
  formatBrusselsRange,
  formatPersonLabel,
} from "../../features/missions/utils/missions.format";

export default function MissionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const missionId = Number(id);

  const { data, isLoading, isError, error } = useQuery<Mission>({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: Number.isFinite(missionId),
  });

  if (!Number.isFinite(missionId)) return <div>ID invalide</div>;
  if (isLoading) return <div>Chargement…</div>;

  if (isError) {
    const status = (error as any)?.response?.status;
    if (status === 403) return <div>Accès interdit</div>;
    if (status === 404) return <div>Mission introuvable</div>;
    return <div>Erreur serveur</div>;
  }

  if (!data) return <div>Aucune donnée</div>;

  const allowed = data.allowedActions ?? [];

  return (
    <Box sx={{ p: 2, maxWidth: 900 }}>
      <Typography variant="h6">Mission #{data.id}</Typography>

      <Stack spacing={1.5} mt={2}>
        <Typography>
          <strong>Site :</strong> {data.site?.name ?? "—"}
        </Typography>
        <Typography>
          <strong>Date / heure :</strong>{" "}
          {formatBrusselsRange(data.startAt, data.endAt)}
        </Typography>
        <Typography>
          <strong>Précision :</strong> {data.schedulePrecision}
        </Typography>
        <Typography>
          <strong>Type :</strong> {data.type}
        </Typography>
        <Typography>
          <strong>Statut :</strong> {data.status ?? "—"}
        </Typography>
        <Typography>
          <strong>Chirurgien :</strong> {formatPersonLabel(data.surgeon)}
        </Typography>
        <Typography>
          <strong>Instrumentiste :</strong>{" "}
          {formatPersonLabel(data.instrumentist)}
        </Typography>

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
    </Box>
  );
}
