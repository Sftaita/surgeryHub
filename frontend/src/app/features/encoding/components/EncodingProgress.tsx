import { Box } from "@mui/material";

const GREEN_800 = "#1F6B4F";
const GREEN_600 = "#338F6E";
const GRAY_150 = "#E7EBEF";
const GRAY_200 = "#DDE2E8";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#5A6675";
const GREEN_500 = "#42A882";
const AMBER_600 = "#D2901A";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";

function ClockIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={AMBER_600} strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 8v5l3 2" />
    </svg>
  );
}
function CheckCircleIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={GREEN_600} strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="m8.5 12.5 2.5 2.5 4.5-5" />
    </svg>
  );
}

type Props = {
  done: number;
  total: number;
};

/**
 * Bloc progression (zone 2) — DOCUMENTATION.md §3. Compteur "x / y", jauge segmentée,
 * phrase d'incitation. Une intervention (ou draft) compte comme encodée dès qu'elle a
 * au moins un matériel — supprimer le dernier matériel fait reculer la jauge, c'est
 * voulu (§3). Pas de pastille de points : aucun système de score n'existe dans l'app.
 */
export function EncodingProgress({ done, total }: Props) {
  const left = total - done;
  const allDone = left === 0;

  return (
    <Box
      role="progressbar"
      aria-valuemin={0}
      aria-valuemax={total}
      aria-valuenow={done}
      aria-label="Progression de l'encodage"
      sx={{
        background: "#fff", border: "1px solid", borderColor: GRAY_150, borderRadius: "16px", boxShadow: SHADOW_SM,
        padding: "14px 15px", display: "flex", flexDirection: "column", gap: "13px",
        "@media (min-width:600px)": { padding: "16px 18px" },
        "@media (max-height:480px) and (orientation:landscape)": { padding: "12px 15px", gap: "10px" },
      }}
    >
      <Box sx={{ display: "flex", alignItems: "baseline", gap: "7px" }}>
        <Box sx={{
          fontSize: 26, fontWeight: 800, letterSpacing: "-.02em", color: GREEN_800, fontVariantNumeric: "tabular-nums",
          "@media (min-width:600px)": { fontSize: 30 },
          "@media (max-height:480px) and (orientation:landscape)": { fontSize: 24 },
        }}>
          {done} / {total}
        </Box>
        <Box sx={{ fontSize: 13, fontWeight: 700, color: GRAY_500 }}>
          {allDone ? "Mission complète" : "interventions encodées"}
        </Box>
      </Box>

      <Box sx={{ display: "flex", gap: "6px" }}>
        {Array.from({ length: total }).map((_, i) => (
          <Box
            key={i}
            sx={{ flex: 1, height: 8, borderRadius: "99px", background: i < done ? GREEN_500 : GRAY_200, transition: "background 200ms" }}
          />
        ))}
      </Box>

      <Box sx={{ display: "flex", alignItems: "center", gap: "8px", fontSize: 13, fontWeight: 600, color: GRAY_600 }}>
        {allDone ? <CheckCircleIcon /> : <ClockIcon />}
        {allDone
          ? "Tout est encodé. Vous pouvez valider la mission."
          : `Encore ${left > 1 ? `${left} interventions` : "1 intervention"} et la mission est complète.`}
      </Box>
    </Box>
  );
}

export default EncodingProgress;
