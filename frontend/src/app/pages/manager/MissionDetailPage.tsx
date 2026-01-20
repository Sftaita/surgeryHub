import * as React from "react";
import { useParams } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Box, Button, Chip, Divider, Stack, Typography } from "@mui/material";

import { fetchMissionById } from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import {
  formatBrusselsRange,
  formatPersonLabel,
  formatMissionStatus,
  formatMissionType,
  formatSchedulePrecision,
} from "../../features/missions/utils/missions.format";

import EditMissionDialog from "../../features/missions/components/EditMissionDialog";
import PublishMissionDialog from "../../features/missions/components/PublishMissionDialog";

export default function MissionDetailPage() {
  const { id } = useParams<{ id: string }>();
  const missionId = Number(id);

  const { data, isLoading, isError, error } = useQuery<Mission>({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: Number.isFinite(missionId),
  });

  const [openEdit, setOpenEdit] = React.useState(false);
  const [openPublish, setOpenPublish] = React.useState(false);

  // Si data disparaît (ex: 401 + logout/refresh), on ferme les dialogs
  React.useEffect(() => {
    if (!data) {
      setOpenEdit(false);
      setOpenPublish(false);
    }
  }, [data]);

  if (!Number.isFinite(missionId)) return <div>ID invalide</div>;
  if (isLoading) return <div>Chargement…</div>;

  if (isError) {
    const status = (error as any)?.response?.status;
    if (status === 403) return <div>Accès interdit</div>;
    if (status === 404) return <div>Mission introuvable</div>;
    if (status === 401) return <div>Non authentifié</div>;
    return <div>Erreur serveur</div>;
  }

  if (!data) return <div>Aucune donnée</div>;

  const allowed = data.allowedActions ?? [];
  const canEdit = allowed.includes("edit");
  const canPublish = allowed.includes("publish");

  const precisionLabel = formatSchedulePrecision(data.schedulePrecision);
  const typeLabel = formatMissionType(data.type);
  const statusLabel = formatMissionStatus(data.status);

  const precisionHelp =
    data.schedulePrecision === "EXACT"
      ? "Créneau confirmé : l’horaire est considéré comme fixe."
      : data.schedulePrecision === "APPROXIMATE"
      ? "Créneau estimé : l’horaire peut encore bouger."
      : null;

  return (
    <Box sx={{ p: 2, maxWidth: 900 }}>
      <Stack
        direction="row"
        justifyContent="space-between"
        alignItems="center"
        gap={2}
      >
        <Typography variant="h6">Mission #{data.id}</Typography>

        <Stack direction="row" spacing={1}>
          {canEdit ? (
            <Button variant="outlined" onClick={() => setOpenEdit(true)}>
              Éditer
            </Button>
          ) : null}

          {canPublish ? (
            <Button variant="contained" onClick={() => setOpenPublish(true)}>
              Publier
            </Button>
          ) : null}
        </Stack>
      </Stack>

      <Stack spacing={1.5} mt={2}>
        <Typography>
          <strong>Site :</strong> {data.site?.name ?? "—"}
        </Typography>

        <Typography>
          <strong>Date / heure :</strong>{" "}
          {formatBrusselsRange(data.startAt, data.endAt)}
        </Typography>

        <Stack spacing={0.5}>
          <Typography>
            <strong>Précision :</strong> {precisionLabel}
          </Typography>
          {precisionHelp ? (
            <Typography variant="body2" color="text.secondary">
              {precisionHelp}
            </Typography>
          ) : null}
        </Stack>

        <Typography>
          <strong>Type :</strong> {typeLabel}
        </Typography>

        <Typography>
          <strong>Statut :</strong> {statusLabel}
        </Typography>

        <Typography>
          <strong>Chirurgien :</strong> {formatPersonLabel(data.surgeon)}
        </Typography>

        <Typography>
          <strong>Instrumentiste :</strong>{" "}
          {formatPersonLabel(data.instrumentist)}
        </Typography>

        <Divider />

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

      {/* IMPORTANT: dialogs montés uniquement sur action utilisateur */}
      {canEdit && openEdit ? (
        <EditMissionDialog
          open={openEdit}
          onClose={() => setOpenEdit(false)}
          mission={data}
        />
      ) : null}

      {canPublish && openPublish ? (
        <PublishMissionDialog
          open={openPublish}
          onClose={() => setOpenPublish(false)}
          mission={data}
        />
      ) : null}
    </Box>
  );
}
