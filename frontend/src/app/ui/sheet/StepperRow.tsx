import { Box } from "@mui/material";

const GRAY_200 = "#DDE2E8";
const GRAY_700 = "#3A4754";
const GRAY_900 = "#16202B";
const GREEN_500 = "#42A882";

type Props = {
  /** Omis pour la variante quantité (docs/design: `.qty-row`, pas de colonne label). */
  label?: string;
  value: string;
  onMinus: () => void;
  onPlus: () => void;
  minusDisabled?: boolean;
  plusDisabled?: boolean;
  minusAriaLabel: string;
  plusAriaLabel: string;
};

/**
 * docs/design/components/stepper-row.md — le composant signature de saisie sans
 * clavier (heures, pauses, quantités). Boutons 46×46 (48×48 pour la variante
 * quantité sans label, cf. wizard étape 3), bornes atteintes → bouton disabled.
 */
export function StepperRow({ label, value, onMinus, onPlus, minusDisabled, plusDisabled, minusAriaLabel, plusAriaLabel }: Props) {
  const size = label ? 46 : 48;
  const btnSx = {
    width: size, height: size, border: "1.5px solid", borderColor: GRAY_200, borderRadius: "12px",
    background: "#fff", color: GRAY_700, fontSize: label ? 19 : 20, fontWeight: 700, flexShrink: 0, cursor: "pointer",
    "&:hover": { borderColor: GREEN_500, color: "#2C7D5F" },
    "&:active": { transform: "scale(.96)" },
    "&:disabled": { opacity: 0.4, cursor: "default", "&:hover": { borderColor: GRAY_200, color: GRAY_700 } },
  };

  return (
    <Box sx={{ display: "flex", alignItems: "center", gap: "12px" }}>
      {label && (
        <Box sx={{ width: 74, flexShrink: 0, fontSize: 13, fontWeight: 700, color: GRAY_700 }}>{label}</Box>
      )}
      <Box component="button" type="button" onClick={onMinus} disabled={minusDisabled} aria-label={minusAriaLabel} sx={btnSx}>−</Box>
      <Box sx={{ flex: 1, textAlign: "center", fontSize: 19, fontWeight: 800, color: GRAY_900, fontVariantNumeric: "tabular-nums" }}>
        {value}
      </Box>
      <Box component="button" type="button" onClick={onPlus} disabled={plusDisabled} aria-label={plusAriaLabel} sx={btnSx}>+</Box>
    </Box>
  );
}
