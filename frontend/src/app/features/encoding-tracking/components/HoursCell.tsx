import { Box, Tooltip, Typography } from "@mui/material";
import type { EncodingTrackingHours } from "../api/encodingTracking.api";
import { EFFECTIVE_SOURCE_LABEL, HOURS_COMPARISON_TONE, formatMinutes } from "../encodingStateMeta";

/**
 * Suivi des encodages (D-118) — affiche planifié + effectif + source, sans jamais
 * masquer une heure réelle au motif que la mission n'est pas SUBMITTED (voir D-118,
 * décision 3 : SUBMITTED et la source des heures sont deux axes indépendants).
 *
 * Aucun champ "encodé" n'existe dans le contrat backend : `hours.effectiveMinutes` peut
 * être un simple repli sur le planifié (source PLANNED), ce que `hasRealHours` indique.
 *
 * D-136 — code couleur piloté par `hours.comparison` (backend) : vert dans le planifié,
 * orange au-delà, neutre sans heure réelle.
 */
export function HoursCell({ hours }: { hours: EncodingTrackingHours }) {
  const sourceLabel = EFFECTIVE_SOURCE_LABEL[hours.effectiveSource];
  const tone = HOURS_COMPARISON_TONE[hours.comparison];

  if (!hours.hasRealHours) {
    return (
      <Box data-hours-comparison="NO_REAL_HOURS">
        <Typography variant="body2">{formatMinutes(hours.plannedMinutes)} planifiées</Typography>
        <Typography variant="caption" sx={{ color: tone.fg, fontStyle: "italic" }}>{tone.label}</Typography>
      </Box>
    );
  }

  return (
    <Tooltip title={`${tone.label} · ${sourceLabel}`}>
      <Box data-hours-comparison={hours.comparison}>
        <Typography variant="body2">
          {formatMinutes(hours.plannedMinutes)} planifiées ·{" "}
          <Box component="span" data-testid="hours-effective" sx={{ color: tone.fg, fontWeight: 700 }}>
            {formatMinutes(hours.effectiveMinutes)} effectives
          </Box>
        </Typography>
        <Typography variant="caption" color="text.secondary">{sourceLabel}</Typography>
      </Box>
    </Tooltip>
  );
}

export default HoursCell;
