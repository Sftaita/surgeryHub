import * as React from "react";
import { useNavigate } from "react-router-dom";
import {
  Box,
  Button,
  CircularProgress,
  List,
  ListItemButton,
  Paper,
  Stack,
  Typography,
} from "@mui/material";
import NotificationsActiveOutlinedIcon from "@mui/icons-material/NotificationsActiveOutlined";
import FiberManualRecordIcon from "@mui/icons-material/FiberManualRecord";

import dayjs from "dayjs";
import "dayjs/locale/fr";
import relativeTime from "dayjs/plugin/relativeTime";

import { useNotificationsFeed } from "../../features/notifications/useNotificationsFeed";
import { formatNotificationBody, formatNotificationTitle } from "../../features/notifications/notificationFormat";
import type { NotificationItem } from "../../features/notifications/api/notifications.api";
import { PageHeader } from "../../ui/PageHeader";
import { EmptyState } from "../../ui/EmptyState";

dayjs.locale("fr");
dayjs.extend(relativeTime);

/**
 * Historique des notifications internes pour manager/admin — absent avant
 * (audit PWA/mobile/admin 2026-07-29, Lot 3) : seul l'instrumentiste avait
 * une cloche + un historique. Backé par GET /api/notifications (serveur,
 * pas un cache local), donc cohérent entre appareils.
 */
export default function ManagerNotificationsPage() {
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
    if (n.missionId) navigate(`/app/m/missions/${n.missionId}`);
  };

  return (
    <Box sx={{ maxWidth: 720, mx: "auto" }}>
      <PageHeader
        icon={NotificationsActiveOutlinedIcon}
        title="Notifications"
        subtitle="Alertes concernant vos missions et votre planning."
        actions={
          unreadCount > 0 ? (
            <Button size="small" variant="text" onClick={() => void markAllSeen()}>
              Tout marquer comme lu
            </Button>
          ) : undefined
        }
      />

      {isLoading && (
        <Box sx={{ display: "flex", justifyContent: "center", pt: 4 }}>
          <CircularProgress size={28} />
        </Box>
      )}

      {!isLoading && items.length === 0 && (
        <EmptyState
          title="Aucune notification"
          description="Vous verrez ici les alertes concernant vos missions et votre planning."
        />
      )}

      {!isLoading && items.length > 0 && (
        <Paper variant="outlined" sx={{ borderRadius: 3, overflow: "hidden" }}>
          <List sx={{ p: 0 }}>
            {items.map((n) => {
              const isUnread = !n.seenAt;
              const body = formatNotificationBody(n);
              return (
                <ListItemButton
                  key={n.id}
                  onClick={() => handleClick(n)}
                  sx={{
                    py: 1.5,
                    px: 2,
                    borderLeft: "3px solid",
                    borderLeftColor: isUnread ? "primary.main" : "transparent",
                  }}
                >
                  <Stack direction="row" spacing={1.5} alignItems="flex-start" sx={{ width: "100%" }}>
                    <Box sx={{ pt: 0.6, flexShrink: 0, width: 8 }}>
                      {isUnread && <FiberManualRecordIcon sx={{ fontSize: 8, color: "primary.main" }} />}
                    </Box>
                    <Box sx={{ flex: 1, minWidth: 0 }}>
                      <Typography variant="body2" fontWeight={isUnread ? 700 : 500}>
                        {formatNotificationTitle(n)}
                      </Typography>
                      {body && (
                        <Typography variant="caption" color="text.secondary" display="block">
                          {body}
                        </Typography>
                      )}
                      <Typography variant="caption" color="text.disabled">
                        {n.sentAt ? dayjs(n.sentAt).fromNow() : ""}
                      </Typography>
                    </Box>
                  </Stack>
                </ListItemButton>
              );
            })}
          </List>
        </Paper>
      )}
    </Box>
  );
}
