import * as React from "react";
import { createPortal } from "react-dom";
import { Box } from "@mui/material";
import { IosStepAnimation } from "./IosStepAnimation";
import { useFocusTrap } from "./useFocusTrap";

const GREEN_700 = "#2C7D5F";
const GRAY_75   = "#F1F4F7";
const GRAY_500  = "#727E8C";
const GRAY_600  = "#566270";
const GRAY_900  = "#16202B";
const BORDER_SUBTLE = "#E7EBEF";
const SHADOW_XL = "0 10px 24px rgba(22,32,43,.10), 0 28px 60px rgba(22,32,43,.16)";
const EASE_OUT = "cubic-bezier(0.22, 1, 0.36, 1)";

const TITLE_ID = "pwa-ios-guide-title";
const DESC_ID = "pwa-ios-guide-desc";

const STEPS = [
  "Touchez Partager dans Safari",
  "Choisissez « Sur l'écran d'accueil »",
  "Appuyez sur « Ajouter »",
];

type Props = {
  open: boolean;
  onClose: (outcome: "later" | "understood") => void;
};

/**
 * Guide manuel d'installation iOS — Variante B du handoff (bottom sheet, recommandée ;
 * voir handoff-install-guide/README.md), reprise responsive vers un dialogue centré
 * ≥ 900px comme le fait déjà SheetModal.tsx pour le reste de l'app. Composant dédié
 * (pas une réutilisation de SheetModal) pour garder la maîtrise du focus trap — modifier
 * SheetModal, partagé par tout le reste de l'app, pour un seul lot était jugé hors
 * périmètre (D-082).
 *
 * Ne propose jamais de bouton "Installer" (iOS ne permet pas de déclencher
 * l'installation par script) et ne demande jamais la permission de notifications
 * push — parcours strictement distinct de PushProvider (D-081).
 */
export function IosInstallGuideModal({ open, onClose }: Props) {
  const containerRef = React.useRef<HTMLDivElement>(null);

  useFocusTrap(containerRef, open, () => onClose("later"));

  if (!open) return null;

  return createPortal(
    <>
      <Box
        data-testid="pwa-ios-guide-backdrop"
        onClick={() => onClose("later")}
        sx={{ position: "fixed", inset: 0, zIndex: 800, background: "rgba(11,19,32,.52)", backdropFilter: "blur(3px)" }}
      />
      <Box
        sx={{
          position: "fixed", inset: 0, zIndex: 810, display: "flex", alignItems: "flex-end",
          "@media (min-width:900px)": { alignItems: "center", justifyContent: "center", padding: "24px" },
        }}
      >
        <Box
          ref={containerRef}
          role="dialog"
          aria-modal="true"
          aria-labelledby={TITLE_ID}
          aria-describedby={DESC_ID}
          sx={{
            background: "#fff", boxShadow: SHADOW_XL, width: "100%", maxHeight: "85vh", overflowY: "auto",
            borderRadius: "22px 22px 0 0", padding: "16px 20px calc(20px + env(safe-area-inset-bottom))",
            animation: `pwaSheetUp .3s ${EASE_OUT}`,
            "@keyframes pwaSheetUp": { from: { transform: "translateY(100%)" }, to: { transform: "translateY(0)" } },
            "@media (min-width:900px)": {
              width: "min(420px, 100%)", borderRadius: "22px", padding: "24px", maxHeight: "85vh",
              animation: `pwaPop .22s ${EASE_OUT}`,
              "@keyframes pwaPop": { from: { transform: "translateY(10px) scale(.98)", opacity: 0 }, to: { transform: "none", opacity: 1 } },
            },
          }}
        >
          <Box sx={{ width: 34, height: 4, borderRadius: "99px", background: BORDER_SUBTLE, mx: "auto", mb: "10px", "@media (min-width:900px)": { display: "none" } }} />

          <Box component="h2" id={TITLE_ID} sx={{ m: 0, fontSize: 18, fontWeight: 800, letterSpacing: "-0.01em", color: GRAY_900 }}>
            Installez SurgicalHub
          </Box>
          <Box component="p" id={DESC_ID} sx={{ m: "4px 0 0", fontSize: 13, color: GRAY_500, lineHeight: 1.45 }}>
            Accédez plus rapidement à votre planning et recevez vos notifications importantes.
          </Box>

          <Box sx={{ mt: "14px" }}>
            <IosStepAnimation />
          </Box>

          {/* Étapes fixes, toujours visibles en texte — jamais dépendantes de
              l'animation ci-dessus (§10 : compréhensibles sans l'animation). */}
          <Box component="ol" sx={{ m: "14px 0 0", p: 0, listStyle: "none", display: "flex", flexDirection: "column", gap: "8px" }}>
            {STEPS.map((label, i) => (
              <Box key={label} component="li" sx={{ display: "flex", alignItems: "center", gap: "8px" }}>
                <Box sx={{
                  width: 18, height: 18, borderRadius: "999px", flexShrink: 0, background: GRAY_75, color: GRAY_600,
                  fontSize: 10, fontWeight: 800, display: "flex", alignItems: "center", justifyContent: "center",
                }}>{i + 1}</Box>
                <Box component="span" sx={{ fontSize: 13, color: GRAY_600 }}>{label}</Box>
              </Box>
            ))}
          </Box>

          <Box sx={{ display: "flex", gap: "8px", mt: "18px" }}>
            <Box
              component="button"
              type="button"
              onClick={() => onClose("later")}
              sx={{ flex: 1, height: 42, border: "none", background: "transparent", color: GRAY_500, fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer", borderRadius: "10px", "&:hover": { background: GRAY_75 } }}
            >
              Plus tard
            </Box>
            <Box
              component="button"
              type="button"
              onClick={() => onClose("understood")}
              sx={{ flex: 1.4, height: 42, border: "none", borderRadius: "10px", background: GREEN_700, color: "#fff", fontFamily: "inherit", fontSize: 13.5, fontWeight: 700, cursor: "pointer" }}
            >
              J&apos;ai compris
            </Box>
          </Box>
        </Box>
      </Box>
    </>,
    document.body,
  );
}
