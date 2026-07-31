import { Button, Paper, Stack, Typography } from "@mui/material";
import { usePushNotifications } from "./usePushNotifications";
import { detectPlatform } from "../pwa-install/pwaInstallDetection";

/**
 * Bloc "Notifications" unique pour les 4 états de permission — factorisé depuis
 * manager/ProfilePage.tsx (qui les distinguait déjà tous les 4) pour être aussi
 * utilisé côté instrumentiste, qui n'en affichait aucun avant ce lot (audit
 * PWA/mobile/admin 2026-07-29, Lot 3). Un site web ne peut jamais rouvrir la
 * demande native après un refus permanent — le cas `permission-denied` explique
 * donc où aller manuellement, sans bouton qui prétendrait réactiver seul.
 */
export function PushPermissionCard() {
  const { status, subscribe, unsubscribe } = usePushNotifications();
  const platform = detectPlatform();

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
      <Typography variant="subtitle1" fontWeight={700} mb={1}>
        Notifications
      </Typography>

      {status === "permission-default" && (
        <Stack direction="row" alignItems="center" justifyContent="space-between" gap={1}>
          <Typography variant="body2" color="text.secondary">
            Activez les notifications pour rester informé des nouvelles demandes.
          </Typography>
          <Button variant="outlined" size="small" onClick={() => void subscribe()}>
            Activer
          </Button>
        </Stack>
      )}

      {status === "subscribing" && (
        <Typography variant="body2" color="text.secondary">
          Activation en cours…
        </Typography>
      )}

      {status === "subscribed" && (
        <Stack direction="row" alignItems="center" justifyContent="space-between" gap={1}>
          <Typography variant="body2" color="text.secondary">
            Notifications activées sur cet appareil.
          </Typography>
          <Button variant="text" size="small" color="inherit" onClick={() => void unsubscribe()}>
            Désactiver
          </Button>
        </Stack>
      )}

      {status === "permission-denied" && (
        <Stack spacing={1}>
          <Typography variant="body2" color="text.secondary">
            Notifications bloquées par le navigateur.
          </Typography>
          {platform === "ios" && (
            <Typography variant="caption" color="text.disabled">
              Ouvrez Réglages iOS → Notifications → SurgicalHub, puis autorisez les notifications.
            </Typography>
          )}
          {platform === "android" && (
            <Typography variant="caption" color="text.disabled">
              Ouvrez les paramètres de votre navigateur (ou de l'application installée) → Notifications, puis autorisez SurgicalHub.
            </Typography>
          )}
          {platform === "other" && (
            <Typography variant="caption" color="text.disabled">
              Ouvrez les paramètres du site dans votre navigateur (icône à côté de l'adresse) pour autoriser les notifications.
            </Typography>
          )}
        </Stack>
      )}

      {status === "unsupported" && (
        <Typography variant="body2" color="text.secondary">
          Les notifications ne sont pas prises en charge par ce navigateur.
        </Typography>
      )}

      {status === "error" && (
        <Typography variant="body2" color="error">
          Une erreur est survenue lors de l'activation des notifications. Réessayez plus tard.
        </Typography>
      )}
    </Paper>
  );
}
