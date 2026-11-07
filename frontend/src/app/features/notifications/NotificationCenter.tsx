import * as React from "react";
import { useNavigate } from "react-router-dom";
import {
  Box,
  Button,
  Chip,
  CircularProgress,
  List,
  ListItemButton,
  Paper,
  Stack,
  ToggleButton,
  ToggleButtonGroup,
  Tooltip,
  Typography,
  alpha,
} from "@mui/material";
import type { SvgIconComponent } from "@mui/icons-material";
import EventNoteOutlinedIcon from "@mui/icons-material/EventNoteOutlined";
import LocalOfferOutlinedIcon from "@mui/icons-material/LocalOfferOutlined";
import EventBusyOutlinedIcon from "@mui/icons-material/EventBusyOutlined";
import CalendarMonthOutlinedIcon from "@mui/icons-material/CalendarMonthOutlined";
import WarningAmberOutlinedIcon from "@mui/icons-material/WarningAmberOutlined";
import EditNoteOutlinedIcon from "@mui/icons-material/EditNoteOutlined";
import Inventory2OutlinedIcon from "@mui/icons-material/Inventory2Outlined";
import AssignmentOutlinedIcon from "@mui/icons-material/AssignmentOutlined";
import MeetingRoomOutlinedIcon from "@mui/icons-material/MeetingRoomOutlined";
import InfoOutlinedIcon from "@mui/icons-material/InfoOutlined";
import CheckCircleOutlineIcon from "@mui/icons-material/CheckCircleOutline";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";

import dayjs from "dayjs";
import "dayjs/locale/fr";
import relativeTime from "dayjs/plugin/relativeTime";

import { useNotificationsFeed } from "./useNotificationsFeed";
import type { NotificationCategory, NotificationFilter, NotificationItem } from "./api/notifications.api";
import { EmptyState } from "../../ui/EmptyState";

dayjs.locale("fr");
dayjs.extend(relativeTime);

const CATEGORY_ICONS: Record<NotificationCategory, SvgIconComponent> = {
  mission: EventNoteOutlinedIcon,
  offer: LocalOfferOutlinedIcon,
  absence: EventBusyOutlinedIcon,
  planning: CalendarMonthOutlinedIcon,
  alert: WarningAmberOutlinedIcon,
  encoding: EditNoteOutlinedIcon,
  catalogue: Inventory2OutlinedIcon,
  request: AssignmentOutlinedIcon,
  room: MeetingRoomOutlinedIcon,
  info: InfoOutlinedIcon,
};

const EMPTY_TEXT: Record<NotificationFilter, { title: string; description: string }> = {
  all: { title: "Aucune notification", description: "Vous verrez ici les événements concernant vos missions et votre planning." },
  unread: { title: "Tout est lu", description: "Aucune notification non lue." },
  action: { title: "Rien à traiter", description: "Aucune notification ne demande d'intervention de votre part." },
};

/**
 * Centre de notifications — liste partagée manager/admin (desktop) et instrumentiste/
 * chirurgien (mobile). N'affiche QUE ce que le serveur a rédigé (titre, phrase, catégorie,
 * état métier, cible) : aucune phrase n'est reconstruite ici à partir du payload.
 *
 * Le clic sur une ligne est l'interaction principale : il la marque lue et ouvre `targetUrl`.
 * L'état de lecture (point, gras) et l'état métier (« À traiter », « Traitée »…) sont deux
 * informations distinctes : ouvrir l'écran ne marque plus rien comme lu.
 */
export function NotificationCenter() {
  const navigate = useNavigate();
  const [filter, setFilter] = React.useState<NotificationFilter>("all");
  const {
    items, unreadCount, actionRequiredCount, hasMore, loadMore, isLoadingMore, isLoading, isError, markSeen, markAllSeen,
  } = useNotificationsFeed(50, filter);

  const open = (n: NotificationItem) => {
    if (!n.seenAt) void markSeen(n.id);
    if (n.targetUrl) navigate(n.targetUrl);
  };

  return (
    <Stack spacing={1.5}>
      <Stack direction="row" alignItems="center" justifyContent="space-between" flexWrap="wrap" gap={1}>
        <ToggleButtonGroup
          size="small"
          exclusive
          value={filter}
          onChange={(_, value: NotificationFilter | null) => value && setFilter(value)}
          aria-label="Filtrer les notifications"
          sx={{ "& .MuiToggleButton-root": { textTransform: "none", px: 1.5, py: 0.4, fontSize: 13 } }}
        >
          <ToggleButton value="all">Toutes</ToggleButton>
          <ToggleButton value="unread">Non lues{unreadCount > 0 ? ` (${unreadCount})` : ""}</ToggleButton>
          <ToggleButton value="action">À traiter{actionRequiredCount > 0 ? ` (${actionRequiredCount})` : ""}</ToggleButton>
        </ToggleButtonGroup>
        {unreadCount > 0 && (
          <Button size="small" variant="text" onClick={() => void markAllSeen()} sx={{ fontWeight: 500 }}>
            Tout marquer comme lu
          </Button>
        )}
      </Stack>

      {isLoading && (
        <Box sx={{ display: "flex", justifyContent: "center", pt: 4 }}>
          <CircularProgress size={28} />
        </Box>
      )}

      {!isLoading && isError && (
        <Typography variant="body2" color="error" sx={{ py: 2 }}>
          Les notifications n'ont pas pu être chargées.
        </Typography>
      )}

      {!isLoading && !isError && items.length === 0 && <EmptyState {...EMPTY_TEXT[filter]} />}

      {!isLoading && items.length > 0 && (
        <Paper variant="outlined" sx={{ borderRadius: 3, overflow: "hidden" }}>
          <List sx={{ p: 0 }} aria-label="Notifications">
            {items.map((n, index) => (
              <NotificationRow key={n.id} n={n} divider={index < items.length - 1} onOpen={() => open(n)} />
            ))}
          </List>
        </Paper>
      )}

      {!isLoading && hasMore && (
        <Box sx={{ display: "flex", justifyContent: "center" }}>
          <Button size="small" variant="outlined" onClick={loadMore} disabled={isLoadingMore}>
            {isLoadingMore ? "Chargement…" : "Afficher plus"}
          </Button>
        </Box>
      )}
    </Stack>
  );
}

function NotificationRow({ n, divider, onOpen }: { n: NotificationItem; divider: boolean; onOpen: () => void }) {
  const isUnread = !n.seenAt;
  const isActionable = !!n.targetUrl;
  const Icon = CATEGORY_ICONS[n.category] ?? InfoOutlinedIcon;
  const toDo = n.businessState === "ACTION_REQUIRED";

  return (
    <ListItemButton
      onClick={onOpen}
      divider={divider}
      disableRipple={!isActionable}
      data-unread={isUnread ? "true" : "false"}
      aria-label={`${n.title}${isUnread ? " (non lue)" : ""}`}
      sx={{
        py: 1.5,
        px: { xs: 1.5, sm: 2 },
        alignItems: "flex-start",
        bgcolor: isUnread ? "action.hover" : "transparent",
        cursor: isActionable ? "pointer" : "default",
        "&:hover": isActionable ? undefined : { bgcolor: isUnread ? "action.hover" : "transparent" },
      }}
    >
      <Stack direction="row" spacing={1.5} alignItems="flex-start" sx={{ width: "100%", minWidth: 0 }}>
        <Box
          sx={{
            mt: 0.25,
            width: 34,
            height: 34,
            flexShrink: 0,
            borderRadius: "50%",
            display: "grid",
            placeItems: "center",
            bgcolor: (t) => (toDo ? alpha(t.palette.warning.main, 0.12) : t.palette.grey[100]),
            color: toDo ? "warning.dark" : "text.secondary",
          }}
        >
          <Icon sx={{ fontSize: 19 }} />
        </Box>

        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Stack direction="row" spacing={1} alignItems="flex-start" justifyContent="space-between">
            <Typography variant="body2" fontWeight={isUnread ? 700 : 500} sx={{ minWidth: 0 }}>
              {n.title}
            </Typography>
            <Stack direction="row" spacing={0.75} alignItems="center" sx={{ flexShrink: 0 }}>
              <BusinessStateChip n={n} />
              {isUnread && (
                <Box
                  component="span"
                  aria-label="Non lue"
                  sx={{ width: 8, height: 8, borderRadius: "50%", bgcolor: "primary.main", display: "inline-block" }}
                />
              )}
            </Stack>
          </Stack>

          {n.body && (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.25, fontSize: 13.5, fontStyle: n.legacy ? "italic" : undefined }}>
              {n.body}
            </Typography>
          )}

          <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 0.5 }}>
            <Tooltip title={n.sentAt ? dayjs(n.sentAt).format("dddd D MMMM YYYY [à] HH:mm") : ""}>
              <Typography variant="caption" color="text.disabled">
                {n.sentAt ? dayjs(n.sentAt).fromNow() : ""}
              </Typography>
            </Tooltip>
            {isActionable && n.actionLabel && (
              <Typography
                variant="caption"
                color={toDo ? "primary.main" : "text.secondary"}
                sx={{ display: "inline-flex", alignItems: "center", fontWeight: toDo ? 600 : 500 }}
              >
                · {n.actionLabel}
                <ChevronRightIcon sx={{ fontSize: 15 }} />
              </Typography>
            )}
          </Stack>
        </Box>
      </Stack>
    </ListItemButton>
  );
}

function BusinessStateChip({ n }: { n: NotificationItem }) {
  if (n.businessState === "ACTION_REQUIRED") {
    return <Chip size="small" color="warning" variant="outlined" label={n.stateLabel ?? "À traiter"} sx={{ height: 22, fontSize: 12 }} />;
  }
  if (n.businessState === "DONE") {
    return (
      <Chip
        size="small"
        variant="outlined"
        icon={<CheckCircleOutlineIcon sx={{ fontSize: 15 }} />}
        label={n.stateLabel ?? "Traitée"}
        sx={{ height: 22, fontSize: 12, color: "text.secondary" }}
      />
    );
  }
  if (n.stateLabel) {
    return <Chip size="small" variant="outlined" label={n.stateLabel} sx={{ height: 22, fontSize: 12 }} />;
  }
  return null;
}
