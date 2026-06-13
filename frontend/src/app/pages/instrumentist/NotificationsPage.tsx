import * as React from "react";
import { useNavigate } from "react-router-dom";
import { Box, Button, Stack, Typography } from "@mui/material";
import FiberManualRecordIcon from "@mui/icons-material/FiberManualRecord";
import NotificationsNoneIcon from "@mui/icons-material/NotificationsNone";

import dayjs from "dayjs";
import "dayjs/locale/fr";
import relativeTime from "dayjs/plugin/relativeTime";

import { useNotifications } from "../../features/push/useNotifications";
import type { AppNotification } from "../../features/push/notifications.store";
import { MobileCard } from "../../ui/mobile/MobileCard";

dayjs.locale("fr");
dayjs.extend(relativeTime);

function notificationTarget(n: AppNotification): string | null {
  const missionId = n.data?.missionId;
  if (missionId) return `/app/i/missions/${missionId}`;
  return null;
}

export default function NotificationsPage() {
  const navigate = useNavigate();
  const { notifications, unreadCount, markAllRead } = useNotifications();

  React.useEffect(() => {
    if (unreadCount > 0) markAllRead();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  if (notifications.length === 0) {
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
      <Stack direction="row" alignItems="center" justifyContent="flex-end">
        <Button size="small" variant="text" onClick={markAllRead} sx={{ fontWeight: 500 }}>
          Tout marquer comme lu
        </Button>
      </Stack>

      {notifications.map((n) => {
        const target = notificationTarget(n);
        const isUnread = !n.readAt;
        const timeLabel = dayjs(n.createdAt).fromNow();

        return (
          <MobileCard
            key={n.id}
            sx={{
              cursor: target ? "pointer" : "default",
              borderLeft: isUnread ? "3px solid" : "3px solid transparent",
              borderLeftColor: isUnread ? "primary.main" : "transparent",
            }}
            onClick={() => target && navigate(target)}
          >
            <Stack direction="row" alignItems="flex-start" spacing={1.5} sx={{ p: 2 }}>
              {/* Unread dot */}
              <Box sx={{ pt: 0.5, flexShrink: 0, width: 8 }}>
                {isUnread && (
                  <FiberManualRecordIcon sx={{ fontSize: 8, color: "primary.main" }} />
                )}
              </Box>

              {/* Content */}
              <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography
                  variant="body2"
                  fontWeight={isUnread ? 700 : 500}
                  mb={0.25}
                >
                  {n.title}
                </Typography>
                {n.body && (
                  <Typography variant="caption" color="text.secondary" display="block" mb={0.5}>
                    {n.body}
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
