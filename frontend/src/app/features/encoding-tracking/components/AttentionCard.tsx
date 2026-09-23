import { Box } from "@mui/material";
import type { EncodingTrackingSummary } from "../api/encodingTracking.api";

const GREEN_900 = "#144D38";
const GREEN_300 = "#8FDABF";
const GREEN_200 = "#BCE9D6";
const AMBER_100 = "#FBEACB";
const BLUE_100 = "#D6E6FE";
const SHADOW_MD = "0 2px 6px rgba(22,32,43,.06), 0 8px 20px rgba(22,32,43,.08)";

interface Props {
  summary: EncodingTrackingSummary | undefined;
  onSelectStale?: () => void;
  onSelectMissing?: () => void;
  onSelectSubmitted?: () => void;
}

/**
 * "À traiter maintenant" — maquette docs/design/Instruction design/Suivi-encodages-admin.
 * Les 3 tuiles et le total sont lus TELS QUELS depuis EncodingTrackingSummary (D-118,
 * champs déjà calculés côté backend) : jamais un recalcul depuis `items` (paginé), voir
 * EncodingTrackingSummary::toTreat()/missingEncoding() côté backend.
 *
 * "à relancer" = missingEncoding (toEncode + staleInProgress) : missions terminées sans
 * encodage soumis, que l'encodage n'ait jamais démarré ou soit resté en cours — c'est le
 * vrai bassin des relances utiles, distinct de "en retard" (staleInProgress, un
 * sous-ensemble : un encodage entamé sur une mission déjà finie).
 */
export function AttentionCard({ summary, onSelectStale, onSelectMissing, onSelectSubmitted }: Props) {
  const total = summary?.toTreat ?? 0;

  return (
    <Box role="group" aria-label="À traiter maintenant" sx={{
      flex: { xs: "1 1 100%", md: "0 0 330px" },
      borderRadius: "16px", background: GREEN_900, color: "#fff",
      padding: "16px 18px", display: "flex", flexDirection: "column", gap: "12px", boxShadow: SHADOW_MD,
    }}>
      <Box sx={{ fontSize: 11.5, fontWeight: 800, letterSpacing: ".09em", color: GREEN_300 }}>
        À TRAITER MAINTENANT
      </Box>
      <Box sx={{ display: "flex", alignItems: "baseline", gap: "10px" }}>
        <Box sx={{ fontSize: 44, fontWeight: 800, letterSpacing: "-.03em", lineHeight: 1, fontVariantNumeric: "tabular-nums" }}>
          {total}
        </Box>
        <Box sx={{ fontSize: 13, fontWeight: 600, color: GREEN_200 }}>
          mission{total > 1 ? "s" : ""} demande{total > 1 ? "nt" : ""} une action de votre part
        </Box>
      </Box>
      <Box sx={{ display: "flex", gap: "8px" }}>
        <Tile label="en retard" value={summary?.staleInProgress} color={AMBER_100} onClick={onSelectStale} />
        <Tile label="à relancer" value={summary?.missingEncoding} color={GREEN_200} onClick={onSelectMissing} />
        <Tile label="à valider" value={summary?.submitted} color={BLUE_100} onClick={onSelectSubmitted} />
      </Box>
    </Box>
  );
}

function Tile({ label, value, color, onClick }: { label: string; value: number | undefined; color: string; onClick?: () => void }) {
  return (
    <Box
      component={onClick ? "button" : "div"}
      type={onClick ? "button" : undefined}
      onClick={onClick}
      sx={{
        flex: 1, display: "flex", flexDirection: "column", gap: "3px", padding: "9px 11px",
        borderRadius: "11px", background: "rgba(255,255,255,.12)", border: 0, textAlign: "left",
        fontFamily: "inherit", cursor: onClick ? "pointer" : "default",
        transition: "background 120ms",
        "&:hover": onClick ? { background: "rgba(255,255,255,.18)" } : undefined,
      }}
    >
      <Box sx={{ fontSize: 19, fontWeight: 800, color: "#fff", fontVariantNumeric: "tabular-nums" }}>
        {value ?? "—"}
      </Box>
      <Box sx={{ fontSize: 11.5, fontWeight: 700, color }}>{label}</Box>
    </Box>
  );
}

export default AttentionCard;
