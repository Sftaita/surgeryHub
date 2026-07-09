import { Box } from "@mui/material";

// design-tokens.json → semantic.status
export type StatusPillVariant =
  | "proposee"
  | "confirmee"
  | "enCours"
  | "aEncoder"
  | "enAttente"
  | "aVenir"
  | "refusee";

const VARIANT_COLORS: Record<StatusPillVariant, { bg: string; fg: string }> = {
  proposee: { bg: "#DDF4EA", fg: "#2C7D5F" }, // green-100 / green-700
  confirmee: { bg: "#DDF4EA", fg: "#2C7D5F" },
  enCours: { bg: "#DDF4EA", fg: "#2C7D5F" },
  aEncoder: { bg: "#FEF6E7", fg: "#B7791F" }, // amber-50 / amber-700
  enAttente: { bg: "#FEF6E7", fg: "#B7791F" },
  aVenir: { bg: "#D6E6FE", fg: "#1B5FD0" }, // blue-100 / blue-700
  refusee: { bg: "#EFF2F5", fg: "#727E8C" }, // gray-100 / gray-500
};

interface StatusPillProps {
  variant: StatusPillVariant;
  label: string;
  /** Point pulsant 7px — utilisé pour "En cours" (components/status-pill.md). */
  withDot?: boolean;
}

/** Pastille de statut — components/status-pill.md. Couleur + libellé toujours ensemble, jamais une pastille sans texte. */
export function StatusPill({ variant, label, withDot = false }: StatusPillProps) {
  const { bg, fg } = VARIANT_COLORS[variant];

  return (
    <Box
      sx={{
        display: "inline-flex", alignItems: "center", gap: "6px", flexShrink: 0,
        height: 24, px: "10px", borderRadius: "999px",
        fontSize: 12, fontWeight: 700,
        bgcolor: bg, color: fg,
      }}
    >
      {withDot && (
        <Box
          sx={{
            width: 7, height: 7, borderRadius: "999px", bgcolor: "#42A882",
            animation: "shPulse 1.6s ease-in-out infinite",
            "@keyframes shPulse": { "0%,100%": { opacity: 1 }, "50%": { opacity: 0.35 } },
          }}
        />
      )}
      {label}
    </Box>
  );
}
