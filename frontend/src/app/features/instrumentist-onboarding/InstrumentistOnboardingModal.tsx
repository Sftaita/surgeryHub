import * as React from "react";
import { Box } from "@mui/material";
import { SheetModal } from "../../ui/sheet/SheetModal";
import { OnboardingProgressDots } from "./OnboardingProgressDots";
import { instrumentistOnboardingSlides, INSTALL_SLIDE_INDEX } from "./instrumentistOnboardingSlides";
import { GREEN_700, GREEN_800, GRAY_200, GRAY_600 } from "./onboardingTokens";

type Props = {
  open: boolean;
  /** Fermeture anticipée (X, ou "Plus tard" à l'écran 1) — ne complète jamais le tutoriel. */
  onDismiss: () => void;
  /** CTA final "Compris, commencer" — déclenche la mutation serveur. */
  onFinish: () => void;
  /** Désactive le CTA final pendant que la requête est en cours. */
  finishing?: boolean;
};

const total = instrumentistOnboardingSlides.length;
const last = total - 1;

/**
 * Carrousel d'onboarding instrumentiste, bâti sur la primitive de modale
 * accessible existante du projet (SheetModal + CloseButton) plutôt que sur le
 * shell CSS custom (.ob-*) du handoff — cf. rapport d'audit, point 5.
 */
export function InstrumentistOnboardingModal({ open, onDismiss, onFinish, finishing }: Props) {
  const [step, setStep] = React.useState(0);
  const titleRef = React.useRef<HTMLDivElement>(null);
  const previouslyFocusedRef = React.useRef<HTMLElement | null>(null);

  React.useEffect(() => {
    if (open) {
      setStep(0);
      previouslyFocusedRef.current = document.activeElement as HTMLElement | null;
    } else {
      previouslyFocusedRef.current?.focus?.();
    }
  }, [open]);

  React.useEffect(() => {
    if (open) titleRef.current?.focus();
  }, [open, step]);

  // Écouteur au niveau document plutôt que sur le contenu seul : le bouton de fermeture
  // et les points de progression sont rendus par SheetModal, hors de notre sous-arbre
  // `children` — un onKeyDown local ne verrait pas Échap si le focus est sur ces
  // éléments-là. Portée uniquement à l'écran ≥ 2, comme le clic sur le backdrop et la
  // croix (SheetModal applique déjà la même règle via `closeDisabled`).
  React.useEffect(() => {
    if (!open || step === 0) return;
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") onDismiss();
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, step, onDismiss]);

  if (!open) return null;

  const slide = instrumentistOnboardingSlides[step];
  const isInstallSlide = step === INSTALL_SLIDE_INDEX;
  const isLastSlide = step === last;

  function goNext() {
    if (isLastSlide) {
      onFinish();
      return;
    }
    setStep((s) => Math.min(last, s + 1));
  }
  function goBack() {
    setStep((s) => Math.max(0, s - 1));
  }

  const btnBase = {
    height: 48,
    border: "none",
    borderRadius: "13px",
    fontFamily: "inherit",
    fontSize: 14.5,
    fontWeight: 700,
    cursor: "pointer",
  } as const;

  return (
    <SheetModal
      open={open}
      title={slide.title}
      onClose={onDismiss}
      closeDisabled={step === 0}
      steps={<OnboardingProgressDots current={step} total={total} />}
    >
      <Box
        tabIndex={-1}
        ref={titleRef}
        sx={{ display: "flex", flexDirection: "column", gap: "18px", pt: "12px", pb: "8px", outline: "none" }}
      >
        <Box sx={{ display: "flex", justifyContent: "center" }}>{slide.illustration}</Box>
        <Box sx={{ fontSize: 14, lineHeight: 1.55, color: GRAY_600, "& strong": { color: "#28323D" } }}>{slide.body}</Box>
        {slide.note}

        <Box sx={{ display: "flex", gap: "10px", mt: "6px" }}>
          {isInstallSlide ? (
            <>
              <Box component="button" type="button" onClick={goNext} sx={{ ...btnBase, flex: "none", padding: "0 16px", background: "transparent", color: GRAY_600 }}>
                Plus tard
              </Box>
              <Box component="button" type="button" onClick={goNext} sx={{ ...btnBase, flex: 1, background: GREEN_700, color: "#fff", "&:hover": { background: GREEN_800 } }}>
                Continuer
              </Box>
            </>
          ) : (
            <>
              {step > 0 && (
                <Box
                  component="button"
                  type="button"
                  onClick={goBack}
                  aria-label="Retour"
                  sx={{ ...btnBase, flex: "none", width: 48, border: `1.5px solid ${GRAY_200}`, background: "#fff", color: GRAY_600, display: "flex", alignItems: "center", justifyContent: "center" }}
                >
                  <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
                    <path d="m15 18-6-6 6-6" />
                  </svg>
                </Box>
              )}
              <Box
                component="button"
                type="button"
                onClick={goNext}
                disabled={isLastSlide && finishing}
                sx={{ ...btnBase, flex: 1, background: GREEN_700, color: "#fff", "&:hover": { background: GREEN_800 }, "&:disabled": { opacity: 0.6, cursor: "default" } }}
              >
                {isLastSlide ? "Compris, commencer" : "Continuer"}
              </Box>
            </>
          )}
        </Box>
      </Box>
    </SheetModal>
  );
}
