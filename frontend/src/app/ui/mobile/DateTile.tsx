import { Box } from "@mui/material";

// design-tokens.json → semantic.status (+ "onPhoto", not a mission status but a
// documented DateTile treatment for the Today hero, see components/date-tile.md).
export type DateTileVariant =
  | "proposee"
  | "confirmee"
  | "aEncoder"
  | "aVenir"
  | "refusee"
  | "onPhoto";

const VARIANT_COLORS: Record<DateTileVariant, { bg: string; fg: string }> = {
  proposee: { bg: "#DDF4EA", fg: "#144D38" }, // green-100 / green-900
  confirmee: { bg: "#EFFAF5", fg: "#1F6B4F" }, // green-50 / green-800
  aEncoder: { bg: "#FEF6E7", fg: "#B7791F" }, // amber-50 / amber-700
  aVenir: { bg: "#EDF4FF", fg: "#1B5FD0" }, // blue-50 / blue-700 (aussi "Attribuée")
  refusee: { bg: "#EFF2F5", fg: "#727E8C" }, // gray-100 / gray-500
  onPhoto: { bg: "rgba(255,255,255,.95)", fg: "#144D38" }, // blanc / green-900
};

// components/date-tile.md — les 3 gabarits documentés (hors "hero sur photo",
// dimensionné au cas par cas là où il est posé directement sur le hero).
const PRESETS = {
  offer: { width: 54, height: 58, radius: 14, dayFont: 19, monthFont: 10 },
  list: { width: 50, height: 54, radius: 12, dayFont: 18, monthFont: 10 },
  rail: { width: 46, height: 50, radius: 12, dayFont: 17, monthFont: 9.5 },
  hero: { width: 48, height: 52, radius: 13, dayFont: 17, monthFont: 9.5 },
} as const;

interface DateTileProps {
  day: string;
  month: string;
  variant: DateTileVariant;
  preset?: keyof typeof PRESETS;
}

/** Tuile date — élément identitaire des cards mission/offre (components/date-tile.md). Toujours à gauche de la card, flex:none. */
export function DateTile({ day, month, variant, preset = "list" }: DateTileProps) {
  const { width, height, radius, dayFont, monthFont } = PRESETS[preset];
  const { bg, fg } = VARIANT_COLORS[variant];

  return (
    <Box
      sx={{
        width, height, borderRadius: `${radius}px`, flex: "none",
        bgcolor: bg, color: fg,
        display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: "1px",
        ...(variant === "onPhoto" && { boxShadow: "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)" }),
      }}
    >
      <Box sx={{ fontSize: dayFont, fontWeight: 800, lineHeight: 1, fontVariantNumeric: "tabular-nums" }}>{day}</Box>
      <Box sx={{ fontSize: monthFont, fontWeight: 700, letterSpacing: "0.08em" }}>{month}</Box>
    </Box>
  );
}
