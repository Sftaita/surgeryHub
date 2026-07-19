import { Box, Chip, Divider, Paper, Stack, Typography } from "@mui/material";
import InfoOutlinedIcon from "@mui/icons-material/InfoOutlined";
import HistoryIcon from "@mui/icons-material/History";

import type {
  EncodingComment,
  MissionEncodingCoherenceSummary,
} from "../api/encoding.types";

type ChipColor = "default" | "info" | "warning" | "success";

function statusLabelAndColor(status: string): { label: string; color: ChipColor } {
  switch (status) {
    case "ASSIGNED":
    case "IN_PROGRESS":
      return { label: "Brouillon", color: "default" };
    case "ENCODING_IN_PROGRESS":
      return { label: "Encodage en cours", color: "info" };
    case "SUBMITTED":
      return { label: "Soumis — en attente de validation manager", color: "warning" };
    case "VALIDATED":
      return { label: "Validé — encodage verrouillé", color: "success" };
    default:
      return { label: status, color: "default" };
  }
}

const COHERENCE_LABELS: Record<keyof MissionEncodingCoherenceSummary, string> = {
  hasNoInterventions: "Aucune intervention encodée",
  hasInterventionsWithNoMaterial: "Au moins une intervention sans matériel",
  hasUnusedSuggestions: "Matériels suggérés non utilisés",
  hasMaterialFromOtherFirm: "Matériel d'une firme différente de la firme principale",
  hasMissingPrimaryFirm: "Firme principale non renseignée sur au moins une intervention",
};

type EncodingStatusPanelProps = {
  status: string;
  coherenceSummary?: MissionEncodingCoherenceSummary;
  comments?: EncodingComment[];
};

/**
 * Lot 7 (D-070) — panneau de lecture partagé instrumentiste/manager : statut réel du
 * cycle de vie de l'encodage, signaux de cohérence (Lot 6, informationnels, jamais
 * bloquants) et historique des commentaires manager (reject/reopen). Ne porte aucune
 * action — chaque page ajoute ses propres boutons selon allowedActions.
 */
export function EncodingStatusPanel({ status, coherenceSummary, comments }: EncodingStatusPanelProps) {
  const { label, color } = statusLabelAndColor(status);
  const activeCoherenceFlags = coherenceSummary
    ? (Object.keys(coherenceSummary) as (keyof MissionEncodingCoherenceSummary)[]).filter(
        (key) => coherenceSummary[key],
      )
    : [];

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
      <Stack direction="row" spacing={1} alignItems="center" mb={1.5}>
        <Typography variant="subtitle2" fontWeight={600}>Encodage</Typography>
        <Chip label={label} color={color} size="small" />
      </Stack>
      <Divider sx={{ mb: 1.5 }} />

      {activeCoherenceFlags.length > 0 && (
        <Box mb={comments?.length ? 1.5 : 0}>
          <Stack direction="row" spacing={0.75} alignItems="center" mb={0.75}>
            <InfoOutlinedIcon fontSize="inherit" color="action" />
            <Typography variant="caption" color="text.secondary">
              Signaux informationnels — n'empêchent pas la validation
            </Typography>
          </Stack>
          <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap>
            {activeCoherenceFlags.map((key) => (
              <Chip
                key={key}
                label={COHERENCE_LABELS[key]}
                size="small"
                color="warning"
                variant="outlined"
              />
            ))}
          </Stack>
        </Box>
      )}

      {comments && comments.length > 0 && (
        <Box>
          <Stack direction="row" spacing={0.75} alignItems="center" mb={0.75}>
            <HistoryIcon fontSize="inherit" color="action" />
            <Typography variant="caption" color="text.secondary">
              Historique des commentaires manager
            </Typography>
          </Stack>
          <Stack spacing={1}>
            {[...comments].reverse().map((c) => (
              <Box key={c.id} sx={{ borderLeft: "3px solid", borderColor: "divider", pl: 1.25, py: 0.25 }}>
                <Typography variant="body2">{c.comment}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {c.authorDisplayName} · {new Date(c.createdAt).toLocaleString("fr-BE")}
                </Typography>
              </Box>
            ))}
          </Stack>
        </Box>
      )}
    </Paper>
  );
}

export default EncodingStatusPanel;
