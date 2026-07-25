import { Box } from "@mui/material";

type Props = {
  onClick: () => void;
  disabled?: boolean;
};

/**
 * prototypes/encodage-react/src/components/StickyValidateFooter.tsx — le CTA de
 * clôture reste accessible en permanence (position: sticky) au lieu d'être en bout de
 * liste. Ouvre toujours le récapitulatif (SubmitDialog) — cf. screens/sheets-divers.md
 * "passage obligé avant la clôture, jamais un raccourci direct" : contrairement au
 * prototype générique, ce bouton ne clôture jamais la mission lui-même.
 *
 * `bottom` doit couvrir la hauteur RÉELLE de MobileBottomNav (layouts/MobileLayout.tsx :
 * padding 10px + boutons 58px + padding 10px + env(safe-area-inset-bottom), soit
 * 78px + safe-area), pas seulement son padding déclaré sans la safe area — sinon, sur un
 * appareil avec indicateur d'accueil (safe-area-inset-bottom > 0), le bas du bouton
 * s'enfonce sous la nav fixe. Les +10px ajoutent l'espacement minimum demandé au-dessus.
 */
export function StickyValidateFooter({ onClick, disabled }: Props) {
  return (
    <Box
      sx={{
        position: "sticky",
        bottom: "calc(78px + env(safe-area-inset-bottom) + 10px)",
        zIndex: 200,
        padding: "12px 20px 0",
        background: "linear-gradient(180deg, rgba(245,247,250,0), #F5F7FA 30%)",
        "@media (min-width:900px)": { bottom: 0, padding: "12px 0 0" },
      }}
    >
      <Box
        component="button"
        type="button"
        onClick={onClick}
        disabled={disabled}
        sx={{
          height: 54, width: "100%", border: "none", borderRadius: "13px",
          background: "#1F6B4F", color: "#fff", fontFamily: "inherit", fontSize: 15.5, fontWeight: 700,
          cursor: "pointer", boxShadow: "0 5px 14px rgba(20,77,56,.3)",
          "&:hover": { background: "#144D38" }, "&:active": { transform: "translateY(0.5px)" },
          "&:disabled": { opacity: 0.6, cursor: "default", boxShadow: "none" },
        }}
      >
        Terminer l'encodage
      </Box>
    </Box>
  );
}
