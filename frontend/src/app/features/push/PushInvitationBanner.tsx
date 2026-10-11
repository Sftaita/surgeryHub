import * as React from "react";
import { Box } from "@mui/material";
import { usePushNotifications } from "./usePushNotifications";
import { recordPushInvitationDismissal, shouldShowPushInvitation } from "./pushInvitationStorage";
import { detectPlatform } from "../pwa-install/pwaInstallDetection";

const GREEN_700 = "#2C7D5F";
const GREEN_800 = "#1F6B4F";
const GRAY_500 = "#727E8C";
const GRAY_900 = "#16202B";

/**
 * D-143 — invitation à activer les notifications push, volontaire et jamais insistante :
 *  - la demande native du navigateur n'est déclenchée QUE par le clic « Activer les
 *    notifications » (jamais au chargement) ;
 *  - rien n'est affiché si le push est déjà actif sur cet appareil, non pris en charge
 *    (ex. iOS hors application installée) ou refusé — un refus n'est jamais re-sollicité
 *    ici ; seul un court message explique comment réautoriser, juste après le refus ;
 *  - « Plus tard » reporte l'invitation (7 j, puis 14 j, puis plus jamais) ; la carte
 *    « Notifications » du profil reste l'entrée permanente.
 * Le centre de notifications et les e-mails de secours fonctionnent sans push.
 */
export function PushInvitationBanner() {
  const { status, subscribe } = usePushNotifications();
  const [visible, setVisible] = React.useState(() => shouldShowPushInvitation());
  const [justDenied, setJustDenied] = React.useState(false);
  const [requested, setRequested] = React.useState(false);

  React.useEffect(() => {
    if (requested && status === "permission-denied") setJustDenied(true);
  }, [requested, status]);

  if (justDenied) {
    const platform = detectPlatform();
    return (
      <Box role="status" data-testid="push-invitation-denied" sx={bannerSx}>
        <Box sx={{ flex: 1, minWidth: 0, fontSize: 12.5, color: GRAY_500, lineHeight: 1.4 }}>
          Notifications refusées — vos rappels vous parviendront par e-mail.{" "}
          {platform === "ios"
            ? "Pour les réactiver : Réglages iOS → Notifications → SurgicalHub."
            : "Pour les réactiver : paramètres du site dans votre navigateur (icône à côté de l'adresse) → Notifications."}
        </Box>
        <Box component="button" type="button" onClick={() => setJustDenied(false)} sx={linkSx} aria-label="Fermer">
          OK
        </Box>
      </Box>
    );
  }

  if (!visible || status !== "permission-default") return null;

  function later() {
    recordPushInvitationDismissal();
    setVisible(false);
  }

  return (
    <Box data-testid="push-invitation" sx={bannerSx}>
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Box sx={{ fontSize: 13, fontWeight: 800, color: GRAY_900 }}>Ne manquez aucune information importante</Box>
        <Box sx={{ fontSize: 11.5, color: GRAY_500, lineHeight: 1.35, mt: "2px" }}>
          Activez les notifications pour recevoir vos rappels de mission, les modifications de planning et les informations qui nécessitent votre attention.
        </Box>
        <Box sx={{ display: "flex", gap: "14px", alignItems: "center", mt: "8px" }}>
          <Box
            component="button"
            type="button"
            onClick={() => { setRequested(true); void subscribe(); }}
            sx={{ border: "none", borderRadius: "9px", background: GREEN_700, color: "#fff", fontFamily: "inherit", fontSize: 12.5, fontWeight: 700, padding: "7px 12px", cursor: "pointer", "&:hover": { background: GREEN_800 } }}
          >
            Activer les notifications
          </Box>
          <Box component="button" type="button" onClick={later} sx={linkSx}>
            Plus tard
          </Box>
        </Box>
      </Box>
    </Box>
  );
}

const bannerSx = {
  display: "flex",
  alignItems: "flex-start",
  gap: "12px",
  mb: "14px",
  px: "14px",
  py: "12px",
  borderRadius: "14px",
  background: "#fff",
  boxShadow: "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)",
} as const;

const linkSx = {
  border: "none",
  background: "transparent",
  color: GRAY_500,
  fontFamily: "inherit",
  fontSize: 12.5,
  fontWeight: 700,
  cursor: "pointer",
  p: 0,
} as const;
