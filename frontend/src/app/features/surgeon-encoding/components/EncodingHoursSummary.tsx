import { Box, Stack, Typography } from "@mui/material";
import AccessTimeIcon from "@mui/icons-material/AccessTime";
import type { MissionExecutionInfo } from "../../missions/api/missions.api";
import { formatExecutionHours } from "../../missions/utils/missions.format";

const GRAY_500 = "#727E8C";
const GRAY_900 = "#16202B";
const GREEN_700 = "#2C7D5F";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

/**
 * Lot 6 (D-100) — heures prestées, lecture seule (§4 : le chirurgien voit les heures
 * planifiées/réelles, jamais un contrôle pour les modifier — GET .../execution déjà
 * accessible au chirurgien, aucun changement backend nécessaire pour ce lot).
 */
export function EncodingHoursSummary({ execution }: { execution: MissionExecutionInfo | undefined }) {
  const label = formatExecutionHours(execution);

  return (
    <Box sx={{ background: "#fff", borderRadius: "16px", padding: "14px 16px", boxShadow: SHADOW_XS }}>
      <Stack direction="row" alignItems="center" justifyContent="space-between">
        <Stack direction="row" alignItems="center" spacing={1}>
          <AccessTimeIcon sx={{ fontSize: 18, color: GREEN_700 }} />
          <Typography sx={{ fontSize: 13.5, color: GRAY_500 }}>Heures prestées</Typography>
        </Stack>
        <Typography sx={{ fontSize: 14, fontWeight: 700, color: label === "Non renseigné" ? GRAY_500 : GRAY_900 }}>
          {label}
        </Typography>
      </Stack>
    </Box>
  );
}
