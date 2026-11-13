import * as React from "react";
import { Box, IconButton, Tooltip, Typography } from "@mui/material";
import ArrowDownwardRoundedIcon from "@mui/icons-material/ArrowDownwardRounded";
import ArrowUpwardRoundedIcon from "@mui/icons-material/ArrowUpwardRounded";
import ChevronLeftIcon from "@mui/icons-material/ChevronLeft";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";
import NorthEastIcon from "@mui/icons-material/NorthEast";
import SouthEastIcon from "@mui/icons-material/SouthEast";
import { monthLabel } from "../../hours-shared/format";
import { resolveApiAssetUrl } from "../../../api/apiAssetUrl";
import type { InstrumentistRef } from "../api/instrumentistHours.types";
import { deltaAria, initials, pctLabel } from "../format";

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
  red50: "#FDEEEE",
  red700: "#C62F36",
} as const;

/**
 * Évolution : flèche + valeur signée. « — » quand le serveur la déclare indisponible, raison en
 * infobulle. `tone="neutral"` (carte Top 5, D-180) : sans couleur de jugement. `tone="signed"`
 * (page et tiroir, D-189) : pastille verte ↑ en hausse, rouge ↓ en baisse, grise à 0 % ; jamais de
 * couleur sans historique.
 */
export function Delta({ pct, reference, reason, size = 13, tone = "neutral" }: { pct: number | null; reference: string; reason: string | null; size?: number; tone?: "neutral" | "signed" }) {
  const label = deltaAria(pct, reference, reason);
  const signed = tone === "signed" && pct !== null;
  const palette = !signed ? null
    : pct > 0 ? { fg: HOURS_COLOR.green800, bg: HOURS_COLOR.green100 }
    : pct < 0 ? { fg: HOURS_COLOR.red700, bg: HOURS_COLOR.red50 }
    : { fg: HOURS_COLOR.gray600, bg: HOURS_COLOR.gray75 };
  const UpIcon = signed ? ArrowUpwardRoundedIcon : NorthEastIcon;
  const DownIcon = signed ? ArrowDownwardRoundedIcon : SouthEastIcon;
  const content = (
    <Box
      component="span"
      aria-label={label}
      role="img"
      data-tone={!signed ? "none" : pct > 0 ? "up" : pct < 0 ? "down" : "flat"}
      sx={{
        display: "inline-flex", alignItems: "center", justifyContent: "flex-end", gap: "3px", whiteSpace: "nowrap",
        fontSize: size, fontWeight: pct === null ? 600 : 700, color: palette?.fg ?? (pct === null ? HOURS_COLOR.gray600 : HOURS_COLOR.gray800),
        fontVariantNumeric: "tabular-nums",
        ...(palette ? { bgcolor: palette.bg, borderRadius: "999px", px: "6px", py: "2px", gap: "2px", lineHeight: 1.3 } : {}),
      }}
    >
      {pct !== null && pct > 0 && <UpIcon aria-hidden sx={{ fontSize: size, color: palette?.fg ?? HOURS_COLOR.gray600 }} />}
      {pct !== null && pct < 0 && <DownIcon aria-hidden sx={{ fontSize: size, color: palette?.fg ?? HOURS_COLOR.gray600 }} />}
      {pctLabel(pct)}
    </Box>
  );
  return pct === null && reason ? <Tooltip title={reason}>{content}</Tooltip> : content;
}

/** D-189 — photo de profil existante, sinon initiales dans le cercle vert clair (même taille). */
export function HoursAvatar({ person, size = 32 }: { person: InstrumentistRef; size?: number }) {
  const [broken, setBroken] = React.useState(false);
  const src = broken ? undefined : resolveApiAssetUrl(person.profilePicturePath);
  if (src) {
    return (
      <Box component="img" src={src} alt="" aria-hidden onError={() => setBroken(true)} data-testid="hours-avatar-photo"
        sx={{ width: size, height: size, borderRadius: "999px", objectFit: "cover", flex: "none", display: "block" }} />
    );
  }
  return (
    <Box aria-hidden data-testid="hours-avatar-initials" sx={{ width: size, height: size, borderRadius: "999px", bgcolor: HOURS_COLOR.green100, color: HOURS_COLOR.green900, display: "flex", alignItems: "center", justifyContent: "center", fontSize: Math.round(size * 0.375), fontWeight: 700, flex: "none" }}>
      {initials(person)}
    </Box>
  );
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
