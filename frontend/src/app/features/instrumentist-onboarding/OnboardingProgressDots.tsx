import { Box } from "@mui/material";
import { GREEN_300, GREEN_600, GRAY_150 } from "./onboardingTokens";

type Props = {
  /** Étape courante, 0-based. */
  current: number;
  total: number;
};

/**
 * Indicateur de progression — passé dans le slot `steps` de SheetModal
 * ("Rangée d'étapes du wizard", cf. app/ui/sheet/SheetModal.tsx).
 */
export function OnboardingProgressDots({ current, total }: Props) {
  return (
    <Box
      role="status"
      aria-label={`Étape ${current + 1} sur ${total}`}
      sx={{ display: "flex", gap: "6px", mt: "14px", mb: "4px" }}
    >
      {Array.from({ length: total }, (_, i) => (
        <Box
          key={i}
          aria-hidden
          sx={{
            height: 4,
            borderRadius: "999px",
            flex: 1,
            background: i === current ? GREEN_600 : i < current ? GREEN_300 : GRAY_150,
            transition: "background .2s",
          }}
        />
      ))}
    </Box>
  );
}
