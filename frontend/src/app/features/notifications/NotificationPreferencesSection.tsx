import type { InputHTMLAttributes } from "react";
import { Box, CircularProgress, Paper, Stack, Switch, Typography } from "@mui/material";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  fetchNotificationPreferences,
  updateNotificationPreference,
  type NotificationPreferencePatch,
} from "./api/notifications.api";
import { notificationTypeLabel } from "./notificationTypeLabels";

const CHANNEL_LABELS: { key: keyof NotificationPreferencePatch; label: string }[] = [
  { key: "inAppEnabled", label: "Dans l'app" },
  { key: "emailEnabled", label: "E-mail" },
];

/**
 * Toggles par catégorie de notification — `NotificationPreference` existait déjà côté
 * backend (Batch 15A) mais n'avait jamais d'écran (audit PWA/mobile/admin 2026-07-29,
 * Lot 3). Le canal push n'est pas proposé ici : il dépend d'un abonnement d'appareil
 * (voir PushPermissionCard), pas d'un simple bool par catégorie.
 */
export function NotificationPreferencesSection() {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ["notifications", "preferences"], queryFn: fetchNotificationPreferences });

  const mutation = useMutation({
    mutationFn: ({ type, patch }: { type: string; patch: NotificationPreferencePatch }) =>
      updateNotificationPreference(type, patch),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["notifications", "preferences"] }),
  });

  if (query.isLoading) {
    return (
      <Paper variant="outlined" sx={{ p: 2, borderRadius: 3, display: "flex", justifyContent: "center" }}>
        <CircularProgress size={20} />
      </Paper>
    );
  }

  if (query.isError || !query.data) {
    return null;
  }

  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
      <Typography variant="subtitle1" fontWeight={700} mb={1.5}>
        Catégories de notifications
      </Typography>
      <Stack spacing={1.5}>
        {query.data.map((pref) => (
          <Box key={pref.type}>
            <Typography variant="body2" fontWeight={600} mb={0.25}>
              {notificationTypeLabel(pref.type)}
            </Typography>
            <Stack direction="row" spacing={2.5}>
              {CHANNEL_LABELS.map(({ key, label }) => (
                <Stack key={key} direction="row" alignItems="center" spacing={0.5}>
                  <Switch
                    size="small"
                    checked={Boolean(pref[key])}
                    disabled={mutation.isPending}
                    onChange={(e) => mutation.mutate({ type: pref.type, patch: { [key]: e.target.checked } })}
                    // MUI Switch injects slotProps.input = { role: "switch" } internally and a
                    // caller-supplied slotProps.input object-replaces (not merges) that, so
                    // role must be repeated here or the accessible role is silently dropped.
                    slotProps={{
                      input: {
                        role: "switch",
                        "aria-label": `${label} — ${notificationTypeLabel(pref.type)}`,
                      } as InputHTMLAttributes<HTMLInputElement>,
                    }}
                  />
                  <Typography variant="caption" color="text.secondary">
                    {label}
                  </Typography>
                </Stack>
              ))}
            </Stack>
          </Box>
        ))}
      </Stack>
    </Paper>
  );
}
