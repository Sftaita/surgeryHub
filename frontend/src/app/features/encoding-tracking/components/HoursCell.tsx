import { Box, Tooltip, Typography } from "@mui/material";
import type { EncodingTrackingHours } from "../api/encodingTracking.api";
import { EFFECTIVE_SOURCE_LABEL, formatMinutes } from "../encodingStateMeta";

/**
 * Suivi des encodages (D-118) — affiche planifié + effectif + source, sans jamais
 * masquer une heure réelle au motif que la mission n'est pas SUBMITTED (voir D-118,
 * décision 3 : SUBMITTED et la source des heures sont deux axes indépendants).
 *
 * Aucun champ "encodé" n'existe dans le contrat backend : `hours.effectiveMinutes` peut
 * être un simple repli sur le planifié (source PLANNED), ce que `hasRealHours` indique.
 */
export function HoursCell({ hours }: { hours: EncodingTrackingHours }) {
  const sourceLabel = EFFECTIVE_SOURCE_LABEL[hours.effectiveSource];

  if (!hours.hasRealHours) {
    return (
      <Box>
        <Typography variant="body2">{formatMinutes(hours.plannedMinutes)} planifiées</Typography>
        <Typography variant="caption" color="text.secondary">{sourceLabel}</Typography>
      </Box>
    );
  }

  return (
    <Tooltip title={sourceLabel}>
      <Box>
        <Typography variant="body2">
          {formatMinutes(hours.plannedMinutes)} planifiées · {formatMinutes(hours.effectiveMinutes)} effectives
        </Typography>
        <Typography variant="caption" color="text.secondary">{sourceLabel}</Typography>
      </Box>
    </Tooltip>
  );
}

export default HoursCell;
