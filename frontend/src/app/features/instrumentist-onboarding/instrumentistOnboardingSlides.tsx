import * as React from "react";
import { Box } from "@mui/material";
import { WelcomeIllustration, InstallIllustration, MissionCardsPreview, EncodingProgress } from "./illustrations";
import { GREEN_50, GREEN_700, GREEN_800, AMBER_700, GRAY_600 } from "./onboardingTokens";

export type OnboardingSlide = {
  id: string;
  title: string;
  illustration: React.ReactNode;
  body: React.ReactNode;
  note?: React.ReactNode;
};

/**
 * Les 4 écrans exactement comme spécifiés : Bienvenue / Installer / Missions / Encodage.
 * Texte final repris de handoff-onboarding-react/src/InstrumentisteOnboarding.tsx — la
 * phrase métier de l'écran "encoding" (marquée ci-dessous) ne doit pas être raccourcie.
 * Le handoff utilisait "SurgeryHub" ; corrigé en "SurgicalHub" ici (nom de marque officiel
 * — docs/decisions.md, docs/architecture.md, docs/api.md ; convention déjà appliquée sur
 * cette même surface instrumentiste par PwaInstallBanner.tsx "Installez SurgicalHub").
 * "SurgeryHub" persiste par ailleurs dans MobileLayout.tsx/LoginPage.tsx/LandingPage.tsx —
 * incohérence préexistante hors périmètre de ce lot, signalée séparément.
 */
export const instrumentistOnboardingSlides: OnboardingSlide[] = [
  {
    id: "welcome",
    title: "Bienvenue dans SurgicalHub",
    illustration: <WelcomeIllustration />,
    body: (
      <>
        SurgicalHub centralise vos missions, vos horaires et le matériel utilisé au bloc.
        <br />
        Vous retrouvez ici tout ce qui est nécessaire pour suivre votre activité au quotidien.
      </>
    ),
  },
  {
    id: "install",
    title: "Installez SurgicalHub sur votre téléphone",
    illustration: <InstallIllustration />,
    body: <>SurgicalHub s&apos;installe sur Android et iPhone directement depuis votre navigateur, sans passer par un store.</>,
    note: (
      <Box sx={{ display: "flex", alignItems: "center", gap: "9px", background: GREEN_50, borderRadius: "12px", padding: "11px 13px", fontSize: 12.5, color: GREEN_800, lineHeight: 1.4 }}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke={GREEN_700} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0 }}>
          <circle cx="12" cy="12" r="9" /><path d="M12 8h.01M12 11v5" />
        </svg>
        <span>
          Retrouvez ces instructions à tout moment dans <strong>Paramètres → Installer l&apos;application</strong>.
        </span>
      </Box>
    ),
  },
  {
    id: "missions",
    title: "Vos missions, au même endroit",
    illustration: <MissionCardsPreview />,
    body: (
      <>
        Une mission = une période de travail avec un établissement, un chirurgien et des horaires définis.{" "}
        <strong>Mes missions</strong> sont déjà attribuées ; les <strong>Offres</strong> restent à prendre.
        <Box sx={{ mt: "9px", color: GREEN_700, fontWeight: 600, fontSize: 12.5 }}>
          Ouvrez toujours une mission pour voir ses informations complètes et les actions disponibles.
        </Box>
      </>
    ),
  },
  {
    id: "encoding",
    title: "Un encodage complet est indispensable",
    illustration: <EncodingProgress />,
    body: <>Après votre mission, encodez vos heures réellement prestées, les interventions réalisées et le matériel utilisé.</>,
    note: (
      <>
        {/* IMPORTANT — ne pas raccourcir : message métier central de l'écran 4 */}
        <Box sx={{ background: GREEN_50, borderRadius: "12px", padding: "11px 13px", fontSize: 12.5, color: GREEN_800, lineHeight: 1.5, fontWeight: 600 }}>
          Seule une mission entièrement encodée et terminée peut être prise en compte correctement pour la facturation.
        </Box>
        <Box sx={{ display: "flex", alignItems: "flex-start", gap: "8px", fontSize: 12, lineHeight: 1.5, color: GRAY_600, mt: "8px" }}>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke={AMBER_700} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" style={{ flexShrink: 0, marginTop: 1 }}>
            <path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3Z" /><path d="M12 9v4M12 17h.01" />
          </svg>
          <span>
            Une mission simplement ouverte ou partiellement encodée n&apos;est pas considérée comme terminée.
            Sans matériel utilisé, vous pouvez le confirmer explicitement — une mission de consultation n&apos;a jamais de matériel.
          </span>
        </Box>
      </>
    ),
  },
];

/** Index (0-based) de l'écran "Installer" — footer à deux boutons, jamais de bouton "Installer" auto. */
export const INSTALL_SLIDE_INDEX = 1;
