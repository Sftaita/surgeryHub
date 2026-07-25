import * as React from "react";
import { Box } from "@mui/material";

// Palette et easing repris tels quels du reste du projet (MobileLayout.tsx,
// DesktopLayout.tsx) — aucune valeur inventée hors de la charte existante, comme demandé
// par le handoff design (handoff-install-guide/README.md).
const GREEN_50  = "#EFFAF5";
const GREEN_300 = "#8FDABF";
const GREEN_500 = "#42A882";
const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_75   = "#F1F4F7";
const GRAY_100  = "#EFF2F5";
const GRAY_400  = "#98A2AE";
const GRAY_500  = "#727E8C";
const BORDER_SUBTLE = "#E7EBEF";
const EASE_OUT = "cubic-bezier(0.22, 1, 0.36, 1)";

// Noms et timings repris exactement du handoff (README.md "Animation — timing exact").
const KEYFRAMES = {
  iigFade:    { from: { opacity: 0, transform: "translateY(4px)" }, to: { opacity: 1, transform: "none" } },
  iigHalo:    { "0%,100%": { boxShadow: "0 0 0 0 rgba(66,168,130,.45)" }, "50%": { boxShadow: "0 0 0 8px rgba(66,168,130,0)" } },
  iigTap:     { "0%,100%": { transform: "translateY(0)" }, "50%": { transform: "translateY(-5px)" } },
  iigGlow:    { "0%,100%": { boxShadow: "0 0 0 0 rgba(66,168,130,.4)" }, "50%": { boxShadow: "0 0 0 5px rgba(66,168,130,0)" } },
  iigPop:     { "0%": { opacity: 0, transform: "scale(.5)" }, "70%": { opacity: 1, transform: "scale(1.08)" }, "100%": { opacity: 1, transform: "scale(1)" } },
} as const;

const STEP_DURATION_MS = 2400; // README — "Cycle de 2.4s par étape"

function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = React.useState(
    () => typeof window !== "undefined" && window.matchMedia?.("(prefers-reduced-motion: reduce)").matches === true,
  );
  React.useEffect(() => {
    const mq = window.matchMedia?.("(prefers-reduced-motion: reduce)");
    if (!mq) return;
    const onChange = () => setReduced(mq.matches);
    mq.addEventListener("change", onChange);
    return () => mq.removeEventListener("change", onChange);
  }, []);
  return reduced;
}

/**
 * Aperçu animé "Partager → Sur l'écran d'accueil → Ajouter → Installé", recréé en React
 * à partir du handoff (handoff-install-guide/README.md + iOS Install Guide.dc.html) —
 * jamais copié tel quel (le fichier .dc.html n'est qu'une référence de conception,
 * cf. son propre README). Purement décoratif : les 3 étapes textuelles fixes du modal
 * restent seules porteuses d'information (voir IosInstallGuideModal), donc
 * compréhensibles sans cette animation (§10 accessibilité).
 */
export function IosStepAnimation(): React.ReactElement {
  const reducedMotion = usePrefersReducedMotion();
  const [step, setStep] = React.useState(0);

  React.useEffect(() => {
    // §5.4 / README : mouvement réduit → pas de cycle automatique, on reste sur la
    // première frame (garder seulement une éventuelle transition d'apparition, pas de
    // boucle).
    if (reducedMotion) return;
    const id = setInterval(() => setStep((s) => (s + 1) % 4), STEP_DURATION_MS);
    return () => clearInterval(id);
  }, [reducedMotion]);

  const displayedStep = reducedMotion ? 0 : step;

  return (
    <Box
      role="img"
      aria-hidden="true"
      sx={{
        position: "relative",
        height: 140,
        borderRadius: "12px",
        background: GRAY_75,
        border: `1px solid ${BORDER_SUBTLE}`,
        overflow: "hidden",
      }}
    >
      {displayedStep === 0 && <StepShare reducedMotion={reducedMotion} />}
      {displayedStep === 1 && <StepAddToHome reducedMotion={reducedMotion} />}
      {displayedStep === 2 && <StepConfirm reducedMotion={reducedMotion} />}
      {displayedStep === 3 && <StepInstalled reducedMotion={reducedMotion} />}
    </Box>
  );
}

function StepShare({ reducedMotion }: { reducedMotion: boolean }) {
  return (
    <Box sx={{ position: "relative", width: "100%", height: "100%", background: "#fff", animation: `iigFade .3s ${EASE_OUT}`, "@keyframes iigFade": KEYFRAMES.iigFade }}>
      <Box sx={{ display: "flex", alignItems: "center", gap: "5px", height: 20, px: "8px", background: GRAY_75, borderBottom: `1px solid ${GRAY_100}` }}>
        <Box component="span" sx={{ fontSize: 8, color: GRAY_400 }}>🔒</Box>
        <Box component="span" sx={{ fontSize: 8, fontWeight: 700, color: GRAY_500 }}>surgicalhub.be</Box>
      </Box>
      <Box sx={{ p: "8px 10px" }}>
        <Box sx={{ height: 8, width: "55%", borderRadius: "4px", background: GREEN_50 }} />
        <Box sx={{ mt: "6px", height: 8, width: "75%", borderRadius: "4px", background: GRAY_100 }} />
      </Box>
      <Box sx={{ position: "absolute", left: 0, right: 0, bottom: 0, height: 26, background: GRAY_75, borderTop: `1px solid ${GRAY_100}`, display: "flex", alignItems: "center", justifyContent: "space-around" }}>
        <Box component="span" sx={{ fontSize: 10, color: GRAY_400 }}>‹</Box>
        <Box component="span" sx={{ fontSize: 10, color: GRAY_400 }}>›</Box>
        <Box
          component="span"
          sx={{
            width: 18, height: 18, borderRadius: "999px", background: GREEN_500, color: "#fff",
            fontSize: 9, display: "flex", alignItems: "center", justifyContent: "center",
            ...(reducedMotion ? {} : { animation: "iigHalo 1.6s ease-in-out infinite" }),
            "@keyframes iigHalo": KEYFRAMES.iigHalo,
          }}
        >↑</Box>
        <Box component="span" sx={{ fontSize: 10, color: GRAY_400 }}>☆</Box>
      </Box>
    </Box>
  );
}

function StepAddToHome({ reducedMotion }: { reducedMotion: boolean }) {
  return (
    <Box sx={{ position: "relative", width: "100%", height: "100%", background: GRAY_75 }}>
      <Box
        sx={{
          position: "absolute", left: 0, right: 0, bottom: 0, background: "#fff",
          borderRadius: "14px 14px 0 0", boxShadow: "0 -6px 16px rgba(22,32,43,.12)", p: "8px 10px",
          ...(reducedMotion ? {} : { animation: `iigSheetUp .35s ${EASE_OUT}` }),
          "@keyframes iigSheetUp": { from: { transform: "translateY(100%)" }, to: { transform: "translateY(0)" } },
        }}
      >
        <Box sx={{ width: 26, height: 3, borderRadius: "99px", background: GRAY_400, opacity: 0.4, mx: "auto", mb: "6px" }} />
        <Box
          sx={{
            display: "flex", alignItems: "center", gap: "5px", fontSize: 8.5, fontWeight: 800,
            color: GREEN_800, background: GREEN_50, borderRadius: "7px", p: "4px 6px",
            ...(reducedMotion ? {} : { animation: "iigGlow 1.4s ease-in-out infinite" }),
            "@keyframes iigGlow": KEYFRAMES.iigGlow,
          }}
        >
          <span>⊕</span>Sur l&apos;écran d&apos;accueil
        </Box>
      </Box>
    </Box>
  );
}

function StepConfirm({ reducedMotion }: { reducedMotion: boolean }) {
  return (
    <Box sx={{ width: "100%", height: "100%", background: GRAY_75, display: "flex", alignItems: "center", justifyContent: "center" }}>
      <Box sx={{ width: "78%", background: "#fff", borderRadius: "12px", boxShadow: "0 4px 16px rgba(22,32,43,.12)", p: "10px", textAlign: "center", animation: `iigFade .25s ${EASE_OUT}`, "@keyframes iigFade": KEYFRAMES.iigFade }}>
        <Box sx={{ display: "inline-flex", width: 26, height: 26, borderRadius: "8px", background: `linear-gradient(140deg, ${GREEN_700}, ${GREEN_500})`, color: "#fff", fontSize: 9, fontWeight: 800, alignItems: "center", justifyContent: "center" }}>SH</Box>
        <Box sx={{ mt: "5px", fontSize: 9.5, fontWeight: 800 }}>SurgicalHub</Box>
        <Box sx={{ display: "flex", gap: "6px", mt: "8px", borderTop: `1px solid ${GRAY_100}`, pt: "6px" }}>
          <Box component="span" sx={{ flex: 1, fontSize: 9, color: GRAY_400, fontWeight: 700 }}>Annuler</Box>
          <Box
            component="span"
            sx={{
              flex: 1, fontSize: 9, color: GREEN_700, fontWeight: 800,
              ...(reducedMotion ? {} : { animation: "iigTap 1.4s ease-in-out infinite" }),
              "@keyframes iigTap": KEYFRAMES.iigTap,
            }}
          >Ajouter</Box>
        </Box>
      </Box>
    </Box>
  );
}

function StepInstalled({ reducedMotion }: { reducedMotion: boolean }) {
  const tiles = new Array(8).fill(null);
  return (
    <Box sx={{ width: "100%", height: "100%", background: `linear-gradient(160deg, ${GREEN_800}, ${GREEN_700})`, display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: "8px", p: "16px 14px", alignContent: "start" }}>
      {tiles.map((_, i) => {
        if (i === 4) {
          return (
            <Box key={i} sx={{ display: "flex", flexDirection: "column", alignItems: "center", gap: "2px" }}>
              <Box
                sx={{
                  width: 22, height: 22, borderRadius: "7px", background: `linear-gradient(140deg, ${GREEN_300}, ${GREEN_50})`,
                  color: GREEN_800, fontSize: 8, fontWeight: 800, display: "flex", alignItems: "center", justifyContent: "center",
                  ...(reducedMotion ? {} : { animation: "iigPop .5s ease-out both, iigGlow 1.8s ease-in-out .5s infinite" }),
                  "@keyframes iigPop": KEYFRAMES.iigPop,
                  "@keyframes iigGlow": KEYFRAMES.iigGlow,
                }}
              >SH</Box>
              <Box component="span" sx={{ fontSize: 6, color: "#fff", opacity: 0.85 }}>SurgicalHub</Box>
            </Box>
          );
        }
        return <Box key={i} sx={{ width: 22, height: 22, borderRadius: "7px", background: "rgba(255,255,255,.12)" }} />;
      })}
    </Box>
  );
}
