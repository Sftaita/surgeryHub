import { useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import {
  Box, Chip, CircularProgress, IconButton, List, ListItemButton, Paper, Stack, Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";

import { fetchNotification } from "./api/notifications.api";

/**
 * Centre de notifications — les missions concernées par une notification (impact d'absence),
 * telles que le serveur les décrit, chacune ouvrant son détail (tiroir mission manager). Le
 * manager n'a jamais à rechercher lui-même les missions : la liste vient de la notification,
 * leur statut actuel de la mission (« Sans instrumentiste », « Couverte — … », « Annulée »).
 */
export function NotificationMissionsPanel({ notificationId, onClose }: { notificationId: number; onClose: () => void }) {
  const navigate = useNavigate();
  const query = useQuery({
    queryKey: ["notifications", "detail", notificationId],
    queryFn: () => fetchNotification(notificationId),
  });

  const n = query.data;
  const missions = n?.missions ?? [];

  return (
    <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden", borderColor: "primary.light" }} aria-label="Missions concernées">
      <Stack direction="row" alignItems="flex-start" spacing={1} sx={{ px: 2, pt: 1.5, pb: 1 }}>
        <Box sx={{ flex: 1, minWidth: 0 }}>
          {query.isLoading && <CircularProgress size={20} />}
          {query.isError && <Typography variant="body2" color="error">La notification n'a pas pu être chargée.</Typography>}
          {n && (
            <>
              <Typography variant="subtitle2" fontWeight={700}>{n.title}</Typography>
              <Typography variant="body2" color="text.secondary">{n.body}</Typography>
            </>
          )}
        </Box>
        <IconButton size="small" aria-label="Fermer" onClick={onClose}>
          <CloseIcon fontSize="small" />
        </IconButton>
      </Stack>

      {n && missions.length === 0 && (
        <Typography variant="body2" color="text.secondary" sx={{ px: 2, pb: 1.5 }}>
          La liste des missions n'est pas disponible pour cette notification.
        </Typography>
      )}

      {missions.length > 0 && (
        <List dense sx={{ py: 0, borderTop: 1, borderColor: "divider" }}>
          {missions.map((m) => (
            <ListItemButton key={m.missionId} divider onClick={() => navigate(m.targetUrl)} sx={{ px: 2 }}>
              <Stack direction={{ xs: "column", sm: "row" }} spacing={{ xs: 0.5, sm: 1.5 }} alignItems={{ sm: "center" }} sx={{ width: "100%", minWidth: 0 }}>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography variant="body2" fontWeight={600}>
                    {[m.dateLabel, m.timeLabel].filter(Boolean).join(" ")}
                    {m.siteName ? ` — ${m.siteName}` : ""}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {[m.surgeonName ? `Dr ${m.surgeonName}` : null, m.impactLabel].filter(Boolean).join(" · ")}
                  </Typography>
                </Box>
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <Chip
                    size="small"
                    variant="outlined"
                    color={m.covered ? "default" : "warning"}
                    label={m.statusLabel}
                    sx={{ height: 22, fontSize: 12 }}
                  />
                  <ChevronRightIcon fontSize="small" sx={{ color: "text.disabled" }} />
                </Stack>
              </Stack>
            </ListItemButton>
          ))}
        </List>
      )}
    </Paper>
  );
}
