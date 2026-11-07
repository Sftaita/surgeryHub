import type { InputHTMLAttributes } from "react";
import { Alert, Box, Button, Paper, Stack, Switch, Typography } from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  fetchNotificationPreferences,
  updateNotificationPreference,
  type NotificationPreferencePatch,
} from "./api/notifications.api";
import { usePushNotifications } from "../push/usePushNotifications";
import type { PushNotificationStatus } from "../push/usePushNotifications";
import { detectPlatform } from "../pwa-install/pwaInstallDetection";

const TYPE = "ROOM_SLOT_AVAILABLE";

/** État réel du push sur CET appareil — jamais confondu avec la préférence applicative. */
function deviceStatusText(status: PushNotificationStatus): string {
  switch (status) {
    case "subscribed":
      return "Autorisées sur cet appareil.";
    case "subscribing":
      return "Activation en cours…";
    case "permission-default":
      return "Pas encore autorisées sur cet appareil : vous ne recevez aucune notification push ici.";
    case "permission-denied":
      return "Bloquées par le navigateur : vous ne recevez aucune notification push sur cet appareil.";
    case "unsupported":
      return "Ce navigateur ne prend pas en charge les notifications push.";
    case "error":
      return "L'activation des notifications a échoué. Réessayez plus tard.";
  }
}

function deniedHelp(): string {
  const platform = detectPlatform();
  if (platform === "ios") return "Ouvrez Réglages iOS → Notifications → SurgicalHub, puis autorisez les notifications.";
  if (platform === "android") return "Ouvrez les paramètres de votre navigateur (ou de l'application installée) → Notifications, puis autorisez SurgicalHub.";
  return "Ouvrez les paramètres du site dans votre navigateur (icône à côté de l'adresse) pour autoriser les notifications.";
}

/**
 * D-161 — « Notifications — Salles opératoires disponibles » (chirurgien). Deux canaux
 * indépendants, persistés côté serveur (`NotificationPreference` ROOM_SLOT_AVAILABLE) :
 * push activé par défaut, e-mail désactivé par défaut. La préférence push n'est qu'une
 * intention : la réception dépend de l'autorisation du navigateur et d'un abonnement valide,
 * affichés séparément. L'e-mail n'est jamais activé automatiquement, même sans push.
 */
export function RoomAvailabilityNotificationsCard() {
  const qc = useQueryClient();
  const { status, subscribe } = usePushNotifications();
  const query = useQuery({ queryKey: ["notifications", "preferences"], queryFn: fetchNotificationPreferences });

  const mutation = useMutation({
    mutationFn: (patch: NotificationPreferencePatch) => updateNotificationPreference(TYPE, patch),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["notifications", "preferences"] }),
  });

  const pref = query.data?.find((p) => p.type === TYPE);
  if (!pref) {
    return null;
  }

  const pushReachesThisDevice = pref.pushEnabled && status === "subscribed";

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
      <Typography variant="subtitle1" fontWeight={700}>
        Notifications — Salles opératoires disponibles
      </Typography>
      <Typography variant="body2" color="text.secondary" mb={1.5}>
        Quand une salle se libère sur l'un de vos sites et peut être reprise.
      </Typography>

      <Stack spacing={1.75}>
        <Box>
          <Stack direction="row" alignItems="center" justifyContent="space-between" gap={1}>
            <Typography variant="body2" fontWeight={600}>Notifications push</Typography>
            <Switch
              size="small"
              checked={pref.pushEnabled}
              disabled={mutation.isPending}
              onChange={(e) => mutation.mutate({ pushEnabled: e.target.checked })}
              slotProps={{
                input: { role: "switch", "aria-label": "Notifications push — Salles opératoires disponibles" } as InputHTMLAttributes<HTMLInputElement>,
              }}
            />
          </Stack>
          {pref.pushEnabled ? (
            <Stack spacing={0.75} mt={0.25}>
              <Typography variant="caption" color={status === "error" ? "error" : "text.secondary"}>
                {deviceStatusText(status)}
              </Typography>
              {status === "permission-default" && (
                <Box>
                  <Button variant="outlined" size="small" onClick={() => void subscribe()}>
                    Autoriser les notifications sur cet appareil
                  </Button>
                </Box>
              )}
              {status === "permission-denied" && (
                <Typography variant="caption" color="text.disabled">{deniedHelp()}</Typography>
              )}
            </Stack>
          ) : (
            <Typography variant="caption" color="text.secondary">
              Désactivées pour les salles disponibles.
            </Typography>
          )}
        </Box>

        <Box>
          <Stack direction="row" alignItems="center" justifyContent="space-between" gap={1}>
            <Typography variant="body2" fontWeight={600}>Notifications par e-mail</Typography>
            <Switch
              size="small"
              checked={pref.emailEnabled}
              disabled={mutation.isPending}
              onChange={(e) => mutation.mutate({ emailEnabled: e.target.checked })}
              slotProps={{
                input: { role: "switch", "aria-label": "Notifications par e-mail — Salles opératoires disponibles" } as InputHTMLAttributes<HTMLInputElement>,
              }}
            />
          </Stack>
          <Typography variant="caption" color="text.secondary">
            {pref.emailEnabled
              ? "Vous recevez aussi un e-mail pour chaque nouvelle salle disponible."
              : "Désactivées par défaut. Activez-les pour recevoir aussi un e-mail."}
          </Typography>
        </Box>

        {!pushReachesThisDevice && !pref.emailEnabled && (
          <Alert severity="info" variant="outlined" sx={{ py: 0 }}>
            Sur cet appareil, les salles disponibles n'apparaissent que dans le centre de notifications de SurgicalHub.
          </Alert>
        )}

        {mutation.isError && (
          <Typography variant="caption" color="error">
            La préférence n'a pas pu être enregistrée. Réessayez.
          </Typography>
        )}
      </Stack>
    </Paper>
  );
}
