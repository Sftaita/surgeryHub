import { Box } from "@mui/material";
import type { EncodingTrackingSummary } from "../api/encodingTracking.api";

const GRAY_950 = "#0B1320";
const GRAY_500 = "#727E8C";
const GRAY_200 = "#DDE2E8";
const GREEN_700 = "#2C7D5F";
const GREEN_500 = "#42A882";
const AMBER_500 = "#F0A91B";
const AMBER_100 = "#FBEACB";
const BLUE_700 = "#1B5FD0";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";

interface Segment { key: string; label: string; count: number; color: string; }

interface Props {
  summary: EncodingTrackingSummary | undefined;
}

/**
 * "Avancement de la semaine" — maquette avait 5 segments arbitraires (mock statique) ;
 * ici on ventile les 7 EncodingState RÉELS (D-118), jamais un regroupement inventé qui
 * mélangerait des états de nature différente (ex: NOT_APPLICABLE — une consultation qui
 * n'a jamais besoin d'encodage — n'est pas la même chose qu'une mission VALIDATED payée).
 * Chaque segment reste un compteur canonique d'EncodingTrackingSummary, jamais recalculé.
 */
export function WeekFunnel({ summary }: Props) {
  const segments: Segment[] = [
    { key: "upcoming", label: "à venir", count: summary?.upcoming ?? 0, color: GRAY_200 },
    { key: "toEncode", label: "à encoder", count: summary?.toEncode ?? 0, color: AMBER_500 },
    { key: "inProgress", label: "en cours", count: summary?.inProgress ?? 0, color: AMBER_100 },
    { key: "submitted", label: "soumises", count: summary?.submitted ?? 0, color: BLUE_700 },
    { key: "validated", label: "validées", count: summary?.validated ?? 0, color: GREEN_500 },
    { key: "locked", label: "verrouillées", count: summary?.locked ?? 0, color: GREEN_700 },
    { key: "notApplicable", label: "sans objet", count: summary?.notApplicable ?? 0, color: GRAY_500 },
  ];
  const missions = summary?.totalMissions ?? 0;
  const anomalies = summary?.financialAnomalies ?? 0;

  return (
    <Box role="group" aria-label="Avancement de la période" sx={{
      flex: 1, minWidth: 0, borderRadius: "16px", background: "#fff", border: "1px solid",
      borderColor: GRAY_200, boxShadow: SHADOW_SM, padding: "16px 18px",
      display: "flex", flexDirection: "column", gap: "14px",
    }}>
      <Box sx={{ display: "flex", alignItems: "baseline", gap: "10px" }}>
        <Box sx={{ fontSize: 11, fontWeight: 800, letterSpacing: ".09em", color: GRAY_500 }}>
          AVANCEMENT DE LA PÉRIODE
        </Box>
        <Box sx={{ ml: "auto", fontSize: 12, fontWeight: 700, color: GRAY_500, fontVariantNumeric: "tabular-nums" }}>
          {missions} mission{missions > 1 ? "s" : ""} · {anomalies} anomalie{anomalies > 1 ? "s" : ""}
        </Box>
      </Box>
      <Box sx={{ display: "flex", gap: "3px", height: 14 }}>
        {/* Un segment vide n'est pas rendu : avec le gap, il laissait une fine barre
            colorée trompeuse pour un compteur à 0. */}
        {segments.filter((s) => s.count > 0).map((s) => (
          <Box key={s.key} title={`${s.count} ${s.label}`} sx={{ flex: s.count, background: s.color, borderRadius: "4px" }} />
        ))}
      </Box>
      <Box sx={{ display: "flex", gap: "18px", flexWrap: "wrap" }}>
        {segments.map((s) => (
          <Box key={s.key} sx={{ display: "flex", flexDirection: "column", gap: "3px" }}>
            <Box sx={{ display: "flex", alignItems: "center", gap: "7px" }}>
              <Box sx={{ width: 9, height: 9, borderRadius: "3px", background: s.color }} />
              <Box sx={{ fontSize: 19, fontWeight: 800, color: GRAY_950, lineHeight: 1, fontVariantNumeric: "tabular-nums" }}>
                {s.count}
              </Box>
            </Box>
            <Box sx={{ fontSize: 11.5, fontWeight: 700, color: GRAY_500, pl: "16px" }}>{s.label}</Box>
          </Box>
        ))}
      </Box>
    </Box>
  );
}

export default WeekFunnel;
