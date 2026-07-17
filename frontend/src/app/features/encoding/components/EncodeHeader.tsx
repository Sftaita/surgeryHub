import { Box } from "@mui/material";

const GREEN_300 = "#8FDABF";
const ENCODE_GRADIENT = "linear-gradient(150deg, #123F30 0%, #1E634A 55%, #2E7D5F 120%)";

// Prototype's shEncWave1/shEncWave2 — genuine 3-stop @keyframes (0% → 45% → 100%,
// the 45% is an intentional overshoot/counter-wave), not a 2-point transition.
// Plays once on mount ("both" keeps the 100% end state), no JS/state needed.
const WAVE_1_KEYFRAMES = {
  "0%": { d: "path('M0 96 C 90 152, 200 94, 300 152 S 380 128, 400 136 L400 190 L0 190 Z')" },
  "45%": { d: "path('M0 138 C 90 78, 200 176, 300 106 S 380 86, 400 94 L400 190 L0 190 Z')" },
  "100%": { d: "path('M0 128 C 90 88, 200 168, 300 118 S 380 96, 400 104 L400 190 L0 190 Z')" },
};
const WAVE_2_KEYFRAMES = {
  "0%": { d: "path('M0 154 C 100 104, 210 164, 310 116 S 385 134, 400 140')" },
  "45%": { d: "path('M0 118 C 100 150, 210 102, 310 148 S 385 108, 400 114')" },
  "100%": { d: "path('M0 132 C 100 128, 210 128, 310 132 S 385 122, 400 126')" },
};

function BackIcon() {
  return (
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
      <path d="m15 18-6-6 6-6" />
    </svg>
  );
}
function CalendarIcon() {
  return (
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <rect x="3" y="4" width="18" height="17" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" />
    </svg>
  );
}

interface EncodeHeaderProps {
  missionId: number;
  siteName: string;
  personLine?: string | null;
  dateLabel: string;
  typeLabel: string;
  onBack: () => void;
}

/**
 * Header propre à l'écran d'encodage (screens/encodage/README.md) — remplace le
 * bandeau de marque principal plutôt que de s'empiler dessous (voir MobileLayout,
 * qui masque BrandBand sur cette route). Vague fixe animée une seule fois à
 * l'arrivée, pas de morphing par onglet.
 */
export function EncodeHeader({ missionId, siteName, personLine, dateLabel, typeLabel, onBack }: EncodeHeaderProps) {
  return (
    <Box
      sx={{
        position: "relative",
        overflow: "hidden",
        background: ENCODE_GRADIENT,
        borderRadius: "0 0 26px 26px",
        padding: "12px 20px 46px",
      }}
    >
      <Box
        component="svg"
        sx={{ position: "absolute", inset: 0, width: "100%", height: "100%", pointerEvents: "none" }}
        viewBox="0 0 400 190"
        preserveAspectRatio="none"
        fill="none"
      >
        <Box
          component="path"
          fill="rgba(255,255,255,.05)"
          sx={{
            animation: "shEncWave1 1.1s cubic-bezier(0.16, 1, 0.3, 1) both",
            "@keyframes shEncWave1": WAVE_1_KEYFRAMES,
          }}
        />
        <Box
          component="path"
          stroke="rgba(255,255,255,.16)"
          strokeWidth={1.5}
          strokeDasharray="10 9"
          fill="none"
          sx={{
            animation: "shEncWave2 1.35s cubic-bezier(0.16, 1, 0.3, 1) both",
            "@keyframes shEncWave2": WAVE_2_KEYFRAMES,
          }}
        />
      </Box>

      <Box sx={{ display: "flex", alignItems: "center", gap: "11px", position: "relative" }}>
        <Box
          component="button"
          type="button"
          aria-label="Retour"
          onClick={onBack}
          sx={{
            width: 40, height: 40, border: "none", background: "rgba(255,255,255,.12)", borderRadius: "12px",
            cursor: "pointer", color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0,
            transition: "background 150ms", "&:hover": { background: "rgba(255,255,255,.2)" },
            "&:active": { transform: "scale(.96)" },
          }}
        >
          <BackIcon />
        </Box>
        <Box sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_300 }}>
          ENCODAGE MISSION
        </Box>
      </Box>

      <Box sx={{ mt: "14px", position: "relative" }}>
        <Box component="h1" sx={{ m: 0, fontSize: 24, fontWeight: 800, letterSpacing: "-0.02em", color: "#fff" }}>
          Mission #{missionId}
        </Box>
        <Box sx={{ mt: "5px", fontSize: 15, fontWeight: 600, color: "rgba(255,255,255,.92)" }}>
          {siteName}
        </Box>
        {personLine && (
          <Box sx={{ mt: "2px", fontSize: 13.5, color: "rgba(255,255,255,.7)" }}>
            {personLine}
          </Box>
        )}
        <Box sx={{ display: "flex", gap: "8px", mt: "13px", flexWrap: "wrap" }}>
          <Box sx={{ display: "inline-flex", alignItems: "center", gap: "7px", height: 28, px: "12px", borderRadius: "999px", background: "rgba(255,255,255,.14)", color: "#fff", fontSize: 12.5, fontWeight: 600 }}>
            <CalendarIcon />
            {dateLabel}
          </Box>
          <Box sx={{ display: "inline-flex", alignItems: "center", height: 28, px: "12px", borderRadius: "999px", background: "rgba(255,255,255,.14)", color: "#fff", fontSize: 12.5, fontWeight: 600 }}>
            {typeLabel}
          </Box>
        </Box>
      </Box>
    </Box>
  );
}
