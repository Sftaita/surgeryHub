import { Box } from "@mui/material";

const GRAY_75 = "#F1F4F7";
const GRAY_100 = "#EFF2F5";
const GRAY_600 = "#566270";

type Props = {
  onClick: () => void;
  disabled?: boolean;
  /**
   * "close" (par défaut) partout — croix, docs/design/components/close-button.md.
   * "back" pour les sheets atteints par une navigation "push" (ex. Déclarer une
   * mission) : flèche retour sur smartphone (bottom sheet, < 900px) — plus
   * intuitive qu'une croix "annuler" quand on est arrivé par un "push" — et croix
   * de fermeture sur PC (dialogue centré, ≥ 900px), où le geste standard reste "fermer".
   * Les deux icônes sont dans le DOM, le CSS bascule au même breakpoint que SheetModal.
   */
  variant?: "close" | "back";
};

/** docs/design/components/close-button.md — toujours ce composant, jamais un X/flèche nu. */
export function CloseButton({ onClick, disabled, variant = "close" }: Props) {
  return (
    <Box
      component="button"
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={variant === "back" ? "Retour" : "Fermer"}
      sx={{
        width: 40, height: 40, border: "none", borderRadius: "12px", cursor: "pointer", flexShrink: 0,
        background: GRAY_75, color: GRAY_600, display: "flex", alignItems: "center", justifyContent: "center",
        transition: "background 150ms", "&:hover": { background: GRAY_100 }, "&:active": { transform: "scale(.96)" },
      }}
    >
      {variant === "back" ? (
        <>
          <Box component="svg" sx={{ display: "flex", "@media (min-width:900px)": { display: "none" } }} width={18} height={18} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round">
            <path d="m15 18-6-6 6-6" />
          </Box>
          <Box component="svg" sx={{ display: "none", "@media (min-width:900px)": { display: "flex" } }} width={17} height={17} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round">
            <path d="M6 6l12 12M18 6 6 18" />
          </Box>
        </>
      ) : (
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round">
          <path d="M6 6l12 12M18 6 6 18" />
        </svg>
      )}
    </Box>
  );
}
