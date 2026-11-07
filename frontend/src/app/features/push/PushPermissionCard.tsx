import { Button, Paper, Stack, Typography } from "@mui/material";
import { usePushNotifications } from "./usePushNotifications";
import type { PushHealth } from "./pushReconcile";
import { detectPlatform } from "../pwa-install/pwaInstallDetection";

const dateTime = new Intl.DateTimeFormat("fr-BE", { dateStyle: "short", timeStyle: "short" });

function formatDateTime(iso: string | null | undefined): string | null {
  if (!iso) return null;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : dateTime.format(d);
}

/**
 * D-175 — état réel de l'appareil courant tel que le serveur le connaît (POST /api/push/sync).
 * Une réparation n'est proposée que lorsqu'elle sert : abonnement déclaré mort par le service de
 * notifications et non réparé automatiquement, ou envois non confirmés par cet appareil.
 */
function DeviceHealth({ health, onRepair }: { health: PushHealth; onRepair: () => void }) {
  const device = health.device;
  const lastReceipt = formatDateTime(device?.lastReceiptAt);
  const invalidated = health.state === "RENEW_REQUIRED" && health.reason === "INVALIDATED";
  const needsRepair = invalidated || health.renewalPending !== null || device?.suspected === true;

  return (
    <Stack spacing={0.5} mt={1}>
      {health.renewed && (
        <Typography variant="body2" color="success.main">
          L'abonnement de cet appareil a été renouvelé automatiquement.
        </Typography>
      )}
      {invalidated && !health.renewed && (
        <Typography variant="body2" color="warning.main">
          Le service de notifications a signalé que l'abonnement de cet appareil n'est plus valide.
        </Typography>
      )}
      {!invalidated && device?.suspected && (
        <Typography variant="body2" color="warning.main">
          Les {device.unacknowledged} dernières notifications envoyées à cet appareil n'ont pas été confirmées par le navigateur.
        </Typography>
      )}
      {health.renewalPending === "cooldown" && (
        <Typography variant="caption" color="text.secondary">
          Une réparation automatique a déjà été tentée récemment sur cet appareil.
        </Typography>
      )}
      {health.renewalPending === "failed" && (
        <Typography variant="caption" color="error">
          La réparation de l'abonnement n'a pas abouti.
        </Typography>
      )}
      {needsRepair && (
        <Stack direction="row" justifyContent="flex-end">
          <Button variant="outlined" size="small" onClick={onRepair}>
            Réparer
          </Button>
        </Stack>
      )}
      {lastReceipt && (
        <Typography variant="caption" color="text.disabled">
          Dernière notification confirmée par le navigateur : {lastReceipt}
        </Typography>
      )}
      {device && device.otherDevices > 0 && (
        <Typography variant="caption" color="text.disabled">
          Notifications aussi actives sur {device.otherDevices} autre{device.otherDevices > 1 ? "s" : ""} appareil{device.otherDevices > 1 ? "s" : ""}.
        </Typography>
      )}
    </Stack>
  );
}

/**
 * Bloc "Notifications" unique pour les 4 états de permission — factorisé depuis
 * manager/ProfilePage.tsx (qui les distinguait déjà tous les 4) pour être aussi
 * utilisé côté instrumentiste, qui n'en affichait aucun avant ce lot (audit
 * PWA/mobile/admin 2026-07-29, Lot 3). Un site web ne peut jamais rouvrir la
 * demande native après un refus permanent — le cas `permission-denied` explique
 * donc où aller manuellement, sans bouton qui prétendrait réactiver seul.
 */
export function PushPermissionCard() {
  const { status, subscribe, unsubscribe, health, repair } = usePushNotifications();
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
        <>
          <Stack direction="row" alignItems="center" justifyContent="space-between" gap={1}>
            <Typography variant="body2" color="text.secondary">
              Notifications activées sur cet appareil.
            </Typography>
            <Button variant="text" size="small" color="inherit" onClick={() => void unsubscribe()}>
              Désactiver
            </Button>
          </Stack>
          {health && <DeviceHealth health={health} onRepair={() => void repair()} />}
        </>
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
        <Stack direction="row" alignItems="center" justifyContent="space-between" gap={1}>
          <Typography variant="body2" color="error">
            Une erreur est survenue lors de l'activation des notifications. Réessayez plus tard.
          </Typography>
          <Button variant="outlined" size="small" onClick={() => void repair()}>
            Réessayer
          </Button>
        </Stack>
      )}
    </Paper>
  );
}
