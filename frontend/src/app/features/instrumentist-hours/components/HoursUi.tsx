import { Box, IconButton, Tooltip, Typography } from "@mui/material";
import ChevronLeftIcon from "@mui/icons-material/ChevronLeft";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";
import NorthEastIcon from "@mui/icons-material/NorthEast";
import SouthEastIcon from "@mui/icons-material/SouthEast";
import { monthLabel } from "../../hours-shared/format";
import { deltaAria, pctLabel } from "../format";

// D-180 — petits éléments communs à la carte, à la page et au tiroir (maquettes 1a–3b).
export const HOURS_COLOR = {
  gray50: "#F5F7FA",
  gray75: "#F1F4F7",
  gray100: "#EFF2F5",
  gray150: "#E7EBEF",
  gray200: "#DDE2E8",
  gray300: "#C2C9D1",
  gray600: "#566270",
  gray700: "#3A4754",
  gray800: "#243240",
  gray900: "#16202B",
  green50: "#EFFAF5",
  green100: "#DDF4EA",
  green500: "#42A882",
  green700: "#2C7D5F",
  green800: "#1F6B4F",
  green900: "#144D38",
  amber50: "#FEF6E7",
  amber100: "#FBEACB",
  amber500: "#F0A91B",
  amber800: "#8A5A12",
  slate50: "#EFF4FB",
  slate700: "#3B6296",
} as const;

/**
 * Évolution neutre : flèche + valeur signée, sans couleur de jugement (plus d'heures n'est ni
 * bien ni mal). « — » quand le serveur la déclare indisponible, raison en infobulle.
 */
export function Delta({ pct, reference, reason, size = 13 }: { pct: number | null; reference: string; reason: string | null; size?: number }) {
  const label = deltaAria(pct, reference, reason);
  const content = (
    <Box
      component="span"
      aria-label={label}
      role="img"
      sx={{
        display: "inline-flex", alignItems: "center", justifyContent: "flex-end", gap: "3px", whiteSpace: "nowrap",
        fontSize: size, fontWeight: pct === null ? 600 : 700, color: pct === null ? HOURS_COLOR.gray600 : HOURS_COLOR.gray800,
        fontVariantNumeric: "tabular-nums",
      }}
    >
      {pct !== null && pct > 0 && <NorthEastIcon aria-hidden sx={{ fontSize: size, color: HOURS_COLOR.gray600 }} />}
      {pct !== null && pct < 0 && <SouthEastIcon aria-hidden sx={{ fontSize: size, color: HOURS_COLOR.gray600 }} />}
      {pctLabel(pct)}
    </Box>
  );
  return pct === null && reason ? <Tooltip title={reason}>{content}</Tooltip> : content;
}

/** ‹ Mois AAAA › — mois courant par défaut, jamais de mois futur. */
export function HoursMonthNav({ month, isCurrentMonth, onPrev, onNext, size = "small" }: {
  month: string;
  isCurrentMonth: boolean;
  onPrev: () => void;
  onNext: () => void;
  size?: "small" | "medium" | "large";
}) {
  const box = size === "large" ? 44 : size === "medium" ? 40 : 32;
  const btnSx = { width: box, height: box, borderRadius: "10px", border: `1px solid ${HOURS_COLOR.gray200}`, color: HOURS_COLOR.gray800 };
  return (
    <Box role="group" aria-label="Mois affiché" sx={{ display: "inline-flex", alignItems: "center", gap: 0.5 }}>
      <IconButton aria-label="Mois précédent" onClick={onPrev} sx={btnSx}><ChevronLeftIcon fontSize="small" /></IconButton>
      <Typography aria-live="polite" sx={{ minWidth: size === "small" ? 116 : 150, textAlign: "center", fontSize: size === "small" ? 13.5 : 15, fontWeight: 700, color: HOURS_COLOR.gray900 }}>
        {monthLabel(month)}
      </Typography>
      <IconButton aria-label="Mois suivant" onClick={onNext} disabled={isCurrentMonth} sx={btnSx}><ChevronRightIcon fontSize="small" /></IconButton>
    </Box>
  );
}

/** Puce « Provisoire · au J mois » (mois en cours, comparaisons à date équivalente). */
export function ProvisionalChip({ asOfDay, monthShortLabel }: { asOfDay: number; monthShortLabel: string }) {
  return (
    <Box component="span" sx={{
      display: "inline-flex", alignItems: "center", gap: "6px", height: 24, px: 1.25, borderRadius: 999, whiteSpace: "nowrap",
      fontSize: 12, fontWeight: 600, color: HOURS_COLOR.amber800, bgcolor: HOURS_COLOR.amber50, border: `1px solid ${HOURS_COLOR.amber100}`,
    }}>
      <Box component="span" sx={{ width: 7, height: 7, borderRadius: 999, bgcolor: HOURS_COLOR.amber500 }} />
      Provisoire · au {asOfDay} {monthShortLabel}
    </Box>
  );
}

/** « Tarif manquant » : ambre texte AA (--amber-800), jamais rouge. */
export function MissingRate({ text = "Tarif manquant" }: { text?: string }) {
  return (
    <Box component="span" sx={{ display: "inline-flex", alignItems: "center", gap: "6px", fontSize: 12.5, fontWeight: 600, color: HOURS_COLOR.amber800, whiteSpace: "nowrap" }}>
      <Box component="span" sx={{ width: 7, height: 7, borderRadius: 999, bgcolor: HOURS_COLOR.amber500 }} />
      {text}
    </Box>
  );
}
