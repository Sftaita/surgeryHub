import * as React from "react";
import { createPortal } from "react-dom";
import { Box } from "@mui/material";
import { CloseButton } from "./CloseButton";

const SHADOW_XL = "0 10px 24px rgba(22,32,43,.10), 0 28px 60px rgba(22,32,43,.16)";
// docs/design/animations/animations.md — "Fermetures" : le prototype ferme sans
// animation de sortie, la doc demande explicitement de jouer l'inverse en production.
const CLOSE_DURATION_MS = 250;

type Props = {
  open: boolean;
  title: string;
  onClose: () => void;
  closeDisabled?: boolean;
  children: React.ReactNode;
  /** Rangée d'étapes du wizard (docs/design/components/sheet-modal.md — variante à étapes). */
  steps?: React.ReactNode;
  /** Icône du bouton de fermeture — voir CloseButton. Par défaut "close" (croix). */
  closeVariant?: "close" | "back";
  /**
   * docs/design/components/sheet-modal.md prescrit "max-height 80vh scroll interne"
   * — c'est le défaut. Certains formulaires courts (ex. Déclarer une mission) tiennent
   * entièrement sur un écran mobile standard : ce prop permet d'aller plus haut pour
   * éviter un scroll interne inutile, sans changer le comportement des autres sheets.
   */
  mobileMaxHeight?: string;
};

/**
 * docs/design/components/sheet-modal.md — patron partagé par tous les sheets de
 * l'encodage (heures, nouvelle intervention, wizard matériel, récapitulatif) :
 * bottom sheet plein-largeur sur mobile (< 900px), dialogue centré 480px au-delà.
 */
export function SheetModal({ open, title, onClose, closeDisabled, children, steps, closeVariant, mobileMaxHeight = "80vh" }: Props) {
  const [mounted, setMounted] = React.useState(open);
  const [closing, setClosing] = React.useState(false);

  React.useEffect(() => {
    if (open) {
      setMounted(true);
      setClosing(false);
      return;
    }
    if (!mounted) return;
    setClosing(true);
    const t = setTimeout(() => {
      setMounted(false);
      setClosing(false);
    }, CLOSE_DURATION_MS);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  if (!mounted) return null;

  // Portail vers document.body : MobileLayout enveloppe <Outlet/> dans un Box qui se
  // remonte (key={pathname}) et joue une anim `transform` à chaque changement de route
  // (shFade). Un ancestor avec un `transform` actif devient le containing block des
  // descendants `position:fixed` (spec CSS) — sans portail, le backdrop/panneau du
  // sheet se retrouvaient positionnés par rapport à CET ancestor pendant ces 250ms au
  // lieu du viewport, produisant une géométrie qui saute et donnant l'impression que
  // deux animations se superposent. Le portail sort le sheet de cette sous-arborescence.
  return createPortal(
    <>
      <Box
        onClick={closeDisabled ? undefined : onClose}
        sx={{
          position: "fixed", inset: 0, zIndex: 800, background: "rgba(11,19,32,.52)",
          backdropFilter: "blur(3px)",
          animation: closing
            ? "shSheetOverlayOut 150ms ease-out both"
            : "shSheetOverlay 200ms ease-out",
          "@keyframes shSheetOverlay": { from: { opacity: 0 }, to: { opacity: 1 } },
          "@keyframes shSheetOverlayOut": { from: { opacity: 1 }, to: { opacity: 0 } },
        }}
      />
      <Box
        sx={{
          position: "fixed", inset: 0, zIndex: 810, display: "flex",
          alignItems: "flex-end",
          "@media (min-width:900px)": { alignItems: "center", justifyContent: "center", padding: "24px" },
        }}
      >
        <Box
          role="dialog"
          aria-modal="true"
          aria-label={title}
          sx={{
            background: "#fff", boxShadow: SHADOW_XL, maxHeight: mobileMaxHeight, overflowY: "auto",
            width: "100%", borderRadius: "26px 26px 0 0",
            padding: "20px 20px calc(24px + env(safe-area-inset-bottom))",
            animation: closing
              ? "shSheetDown 250ms cubic-bezier(.22,1,.36,1) both"
              : "shSheetUp 300ms cubic-bezier(.22,1,.36,1)",
            // translateY(100%) est relatif à la hauteur PROPRE de l'élément — quand
            // celle-ci dépend du contenu (mobileMaxHeight tendu, cf. Déclarer une
            // mission) plutôt que d'être plafonnée par une valeur fixe, elle met
            // quelques passes de layout à se stabiliser juste au moment où l'anim
            // démarre, et la base du transform bouge sous l'animation en cours —
            // visible comme "deux animations superposées". translateY(100vh) est
            // purement relatif au viewport, jamais au layout du panneau lui-même.
            "@keyframes shSheetUp": { from: { transform: "translateY(100vh)" }, to: { transform: "translateY(0)" } },
            "@keyframes shSheetDown": { from: { transform: "translateY(0)" }, to: { transform: "translateY(100vh)" } },
            "@media (min-width:900px)": {
              width: "min(480px, 100%)", borderRadius: "22px", padding: "24px", maxHeight: "85vh",
              animation: closing
                ? "shSheetPopOut 150ms cubic-bezier(.22,1,.36,1) both"
                : "shSheetPop 220ms cubic-bezier(.22,1,.36,1)",
              "@keyframes shSheetPop": {
                from: { transform: "translateY(10px) scale(.98)", opacity: 0 },
                to: { transform: "none", opacity: 1 },
              },
              "@keyframes shSheetPopOut": {
                from: { transform: "none", opacity: 1 },
                to: { transform: "translateY(10px) scale(.98)", opacity: 0 },
              },
            },
          }}
        >
          <Box sx={{ display: "flex", alignItems: "center", gap: "12px" }}>
            <Box component="h2" sx={{ m: 0, flex: 1, fontSize: 20, fontWeight: 800, letterSpacing: "-0.02em" }}>
              {title}
            </Box>
            <CloseButton onClick={onClose} disabled={closeDisabled} variant={closeVariant} />
          </Box>
          {steps}
          {children}
        </Box>
      </Box>
    </>,
    document.body,
  );
}
