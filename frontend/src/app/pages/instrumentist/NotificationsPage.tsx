import * as React from "react";
import { useNavigate } from "react-router-dom";
import { Box, Button, Stack, Typography } from "@mui/material";
import FiberManualRecordIcon from "@mui/icons-material/FiberManualRecord";
import NotificationsNoneIcon from "@mui/icons-material/NotificationsNone";

import dayjs from "dayjs";
import "dayjs/locale/fr";
import relativeTime from "dayjs/plugin/relativeTime";

import { useNotificationsFeed } from "../../features/notifications/useNotificationsFeed";
import { formatNotificationBody, formatNotificationTitle } from "../../features/notifications/notificationFormat";
import type { NotificationItem } from "../../features/notifications/api/notifications.api";
import { MobileCard } from "../../ui/mobile/MobileCard";

dayjs.locale("fr");
dayjs.extend(relativeTime);

/**
 * Backé par GET /api/notifications, comme ManagerNotificationsPage — plus un cache
 * localStorage séparé (audit PWA/mobile/admin 2026-07-29, revue post-rapport). Le
 * backend est l'unique source de vérité : historique, compteur, lu/non lu, cohérents
 * entre appareils. Le nudge temps réel à la réception d'un push reste assuré par
 * PushProvider (toast + invalidation immédiate de la requête), pas par un état local
 * persistant ici.
 */
export default function NotificationsPage() {
  const navigate = useNavigate();
  const { items, unreadCount, isLoading, markSeen, markAllSeen } = useNotificationsFeed();
  const markedAllRef = React.useRef(false);

  React.useEffect(() => {
    if (!isLoading && unreadCount > 0 && !markedAllRef.current) {
      markedAllRef.current = true;
      void markAllSeen();
    }
  }, [isLoading, unreadCount, markAllSeen]);

  const handleClick = (n: NotificationItem) => {
    if (!n.seenAt) void markSeen(n.id);
    if (n.missionId) navigate(`/app/i/missions/${n.missionId}`);
  };

  if (!isLoading && items.length === 0) {
    return (
      <Box sx={{ py: 8, textAlign: "center" }}>
        <NotificationsNoneIcon sx={{ fontSize: 48, color: "text.disabled", mb: 1 }} />
        <Typography variant="body1" fontWeight={600} color="text.secondary">
          Aucune notification
        </Typography>
        <Typography variant="caption" color="text.disabled">
          Vous verrez ici les alertes de nouvelles missions
        </Typography>
      </Box>
    );
  }

  return (
    <Stack spacing={1.5}>
      {unreadCount > 0 && (
        <Stack direction="row" alignItems="center" justifyContent="flex-end">
          <Button size="small" variant="text" onClick={() => void markAllSeen()} sx={{ fontWeight: 500 }}>
            Tout marquer comme lu
          </Button>
        </Stack>
      )}

      {items.map((n) => {
        const isUnread = !n.seenAt;
        const body = formatNotificationBody(n);
        const timeLabel = n.sentAt ? dayjs(n.sentAt).fromNow() : "";

        return (
          <MobileCard
            key={n.id}
            sx={{
              cursor: n.missionId ? "pointer" : "default",
              borderLeft: isUnread ? "3px solid" : "3px solid transparent",
              borderLeftColor: isUnread ? "primary.main" : "transparent",
            }}
            onClick={() => handleClick(n)}
          >
            <Stack direction="row" alignItems="flex-start" spacing={1.5} sx={{ p: 2 }}>
              <Box sx={{ pt: 0.5, flexShrink: 0, width: 8 }}>
                {isUnread && (
                  <FiberManualRecordIcon sx={{ fontSize: 8, color: "primary.main" }} />
                )}
              </Box>

              <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography variant="body2" fontWeight={isUnread ? 700 : 500} mb={0.25}>
                  {formatNotificationTitle(n)}
                </Typography>
                {body && (
                  <Typography variant="caption" color="text.secondary" display="block" mb={0.5}>
                    {body}
                  </Typography>
                )}
                <Typography variant="caption" color="text.disabled">
                  {timeLabel}
                </Typography>
              </Box>
            </Stack>
          </MobileCard>
        );
      })}
    </Stack>
  );
}
