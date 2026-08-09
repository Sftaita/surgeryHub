import { useNavigate, useParams } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import { Alert, Box, Chip, CircularProgress, IconButton, Stack, Typography } from "@mui/material";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";

import { fetchMissionById, getMissionExecution } from "../missions/api/missions.api";
import { formatMissionStatus } from "../missions/utils/missions.format";
import { fetchMissionEncoding } from "../encoding/api/encoding.api";
import { EncodingHoursSummary } from "./components/EncodingHoursSummary";
import { ReadOnlyInterventionCard } from "./components/ReadOnlyInterventionCard";
import { AnomalyReportSection } from "../encoding-anomaly-reports/components/AnomalyReportSection";

const GRAY_500 = "#727E8C";
const GRAY_900 = "#16202B";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

/**
 * Lot 6 (D-100) — consultation chirurgien de l'encodage réalisé par l'instrumentiste.
 * Réutilise GET /api/missions/{id}/encoding tel quel (VIEW_ENCODING, §3) : aucun
 * endpoint parallèle. Présentation strictement lecture — aucun composant d'édition
 * réutilisé avec ses boutons masqués (§8-9) : les cartes ci-dessous sont dédiées.
 */
export default function SurgeonEncodingPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const missionId = Number(id);
  const isValidId = Number.isFinite(missionId) && missionId > 0;

  const handleBack = () => navigate(`/app/s/missions/${missionId}`);

  const { data: mission, isLoading: isMissionLoading } = useQuery({
    queryKey: ["mission", missionId],
    queryFn: () => fetchMissionById(missionId),
    enabled: isValidId,
  });

  const canView = mission?.allowedActions?.includes("view_encoding") ?? false;

  const {
    data: encoding,
    isLoading: isEncodingLoading,
    isError: isEncodingError,
    refetch,
  } = useQuery({
    queryKey: ["missionEncoding", missionId],
    queryFn: () => fetchMissionEncoding(missionId),
    enabled: isValidId && !!mission && canView,
  });

  const { data: execution } = useQuery({
    queryKey: ["mission-execution", missionId],
    queryFn: () => getMissionExecution(missionId),
    enabled: isValidId && !!mission && canView,
  });

  if (!isValidId) return <Typography>Identifiant invalide</Typography>;

  const isLoading = isMissionLoading || (canView && isEncodingLoading);

  return (
    <Stack spacing={2}>
      <Stack direction="row" alignItems="center" spacing={1}>
        <IconButton size="small" onClick={handleBack} aria-label="Retour">
          <ArrowBackIcon fontSize="small" />
        </IconButton>
        <Typography variant="subtitle1" sx={{ flex: 1 }}>
          Encodage — Mission #{missionId}
        </Typography>
        {mission && <Chip label={formatMissionStatus(mission.status)} size="small" />}
      </Stack>

      {isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
          <CircularProgress size={28} />
        </Box>
      ) : !mission || !canView ? (
        <Alert severity="warning">
          La consultation de l'encodage n'est pas disponible pour cette mission.
        </Alert>
      ) : isEncodingError ? (
        <Alert
          severity="error"
          action={
            <Box
              component="button"
              type="button"
              onClick={() => refetch()}
              sx={{ border: "none", background: "none", cursor: "pointer", fontWeight: 700, fontFamily: "inherit", color: "inherit" }}
            >
              Réessayer
            </Box>
          }
        >
          Impossible de charger l'encodage de cette mission.
        </Alert>
      ) : encoding ? (
        <>
          <EncodingHoursSummary execution={execution} />

          {encoding.entries.length === 0 ? (
            <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, px: 2, py: 3, textAlign: "center" }}>
              <Typography sx={{ fontSize: 14, fontWeight: 700, color: GRAY_900 }}>
                Aucun encodage disponible pour l'instant
              </Typography>
              <Typography sx={{ fontSize: 13, color: GRAY_500, mt: 0.5 }}>
                Les interventions et le matériel apparaîtront ici une fois encodés par l'instrumentiste.
              </Typography>
            </Box>
          ) : (
            <Stack spacing={1.5}>
              {encoding.entries.map((entry) => (
                <ReadOnlyInterventionCard key={`${entry.kind}:${entry.id}`} entry={entry} />
              ))}
            </Stack>
          )}

          <AnomalyReportSection missionId={missionId} />
        </>
      ) : null}
    </Stack>
  );
}
