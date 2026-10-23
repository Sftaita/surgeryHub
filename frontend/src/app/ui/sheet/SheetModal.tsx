import * as React from "react";
import { Box, Modal } from "@mui/material";
import { CloseButton } from "./CloseButton";
import { HelpButton } from "../../features/help/HelpButton";
import { dvh } from "../dvh";

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
  /**
   * Clé du topic d'aide (features/help/content/registry.ts) — affiche un bouton "?"
   * dans l'en-tête, à gauche du bouton de fermeture, pour qu'un utilisateur perdu dans
   * cette sheet retrouve immédiatement le mode d'emploi sans devoir la fermer d'abord.
   * Omis par défaut : ce composant est partagé par des contextes hors encodage (ex.
   * suppression d'un chirurgien) qui n'ont pas forcément de topic dédié.
   */
  helpTopicId?: string;
};

/**
 * docs/design/components/sheet-modal.md — patron partagé par tous les sheets de
 * l'encodage (heures, nouvelle intervention, wizard matériel, récapitulatif) :
 * bottom sheet plein-largeur sur mobile (< 900px), dialogue centré 480px au-delà.
 */
export function SheetModal({ open, title, onClose, closeDisabled, children, steps, closeVariant, mobileMaxHeight = "80vh", helpTopicId }: Props) {
  // Fermeture animée : la Modal reste montée jusqu'à la fin de l'animation (SheetFrame).
  const closing = !open;

  // D-166 — la fenêtre est une Modal MUI (portail vers document.body, comme avant) et non
  // plus un portail maison à z-index 800/810. Raison : un sheet ouvert DEPUIS un Dialog MUI
  // (ex. « Corriger l'encodage » → « + Intervention » dans Suivi des encodages) passait
  // derrière ce Dialog (z-index 1300) et son piège à focus le rendait inutilisable. En
  // passant par Modal, le sheet est enregistré dans le ModalManager MUI : la dernière
  // fenêtre ouverte est au-dessus (même niveau zIndex.modal, ordre d'ouverture), elle seule
  // garde le focus, reçoit Échap et le défilement de la page est bloqué ; à la fermeture le
  // focus revient sur le bouton qui l'a ouverte.
  //
  // Le portail sort toujours le sheet de la sous-arborescence de MobileLayout, qui se
  // remonte (key={pathname}) avec une anim `transform` : un ancestor transformé deviendrait
  // le containing block des descendants `position:fixed` (géométrie qui saute).
  return (
    <Modal
      open={open}
      hideBackdrop
      onClose={(_event, reason) => {
        if (reason === "escapeKeyDown" && !closeDisabled) onClose();
      }}
    >
      {/* Les zIndex 0/1 ci-dessous sont relatifs au contexte d'empilement de la Modal. */}
      <SheetFrame in={open} title={title}>
        <Box
          onClick={closeDisabled ? undefined : onClose}
          sx={{
            position: "fixed", inset: 0, zIndex: 0, background: "rgba(11,19,32,.52)",
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
            position: "fixed", inset: 0, zIndex: 1, display: "flex",
            alignItems: "flex-end",
            "@media (min-width:900px)": { alignItems: "center", justifyContent: "center", padding: "24px" },
          }}
        >
          <Box
            sx={{
              background: "#fff", boxShadow: SHADOW_XL, ...dvh("maxHeight", mobileMaxHeight), overflowY: "auto",
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
              {helpTopicId && <HelpButton topicId={helpTopicId} />}
              <CloseButton onClick={onClose} disabled={closeDisabled} variant={closeVariant} />
            </Box>
            {steps}
            {children}
          </Box>
        </Box>
      </SheetFrame>
    </Modal>
  );
}

type SheetFrameProps = {
  in: boolean;
  title: string;
  onEnter?: () => void;
  onExited?: () => void;
  children: React.ReactNode;
};

/**
 * D-166 — enfant « transition » de la Modal (prop `in`) : dès que `open` passe à false, la
 * Modal sort la fenêtre du ModalManager (focus rendu tout de suite à l'élément d'origine,
 * Échap et piège à focus rendus à la fenêtre du dessous, défilement débloqué) mais la garde
 * montée jusqu'à `onExited`, appelé à la fin de l'animation de fermeture. Sans cela, un sheet
 * qui se ferme en ouvrant le suivant (ex. wizard matériel → « Je ne trouve pas le matériel »)
 * rendrait le focus au bouton d'origine 250 ms plus tard, en pleine saisie dans le suivant.
 */
const SheetFrame = React.forwardRef<HTMLDivElement, SheetFrameProps>(function SheetFrame(
  { in: inProp, title, onEnter, onExited, children, ...other },
  ref,
) {
  React.useEffect(() => {
    if (inProp) {
      onEnter?.();
      return;
    }
    const t = setTimeout(() => onExited?.(), CLOSE_DURATION_MS);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [inProp]);

  return (
    // Porte la sémantique de la fenêtre : c'est l'élément qui reçoit le focus à l'ouverture
    // (FocusTrap de la Modal), le lecteur d'écran annonce donc directement son titre.
    <Box ref={ref} {...other} role="dialog" aria-modal="true" aria-label={title} sx={{ outline: "none" }}>
      {children}
    </Box>
  );
});
