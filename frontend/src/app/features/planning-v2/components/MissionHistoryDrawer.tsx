import {
  Box, Divider, Drawer, IconButton,
  Skeleton, Stack, Typography,
} from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import HistoryIcon from "@mui/icons-material/History";
import { useQuery } from "@tanstack/react-query";
import { fetchMissionAudit } from "../api/planningV2.api";
import type { MissionAuditEvent } from "../api/planningV2.types";

const EVENT_LABELS: Record<string, string> = {
  MISSION_CLAIMED_FROM_POOL:       "Prise en charge",
  MISSION_RELEASED_TO_POOL:        "Remise au pool",
  MISSION_CANCELLED_POST_DEPLOY:   "Annulation",
  MISSION_REASSIGNED_POST_DEPLOY:  "Réassignation",
  MISSION_TIME_CHANGED_POST_DEPLOY:"Horaire modifié",
  MISSION_ADDED_POST_DEPLOY:       "Ajout post-déploiement",
};

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString("fr-BE", {
    day: "2-digit", month: "2-digit", year: "numeric",
    hour: "2-digit", minute: "2-digit",
  });
}

function AuditEventCard({ event }: { event: MissionAuditEvent }) {
  const label = EVENT_LABELS[event.eventType] ?? event.eventType;
  return (
    <Box sx={{ py: 1 }}>
      <Stack direction="row" justifyContent="space-between" alignItems="baseline">
        <Typography variant="body2" fontWeight={600}>{label}</Typography>
        <Typography variant="caption" color="text.secondary">
          {formatDateTime(event.occurredAt)}
        </Typography>
      </Stack>
      {event.actorName && (
        <Typography variant="caption" color="text.secondary">
          par {event.actorName}
        </Typography>
      )}
    </Box>
  );
}

interface MissionHistoryDrawerProps {
  missionId: number | null;
  open: boolean;
  onClose: () => void;
}

export function MissionHistoryDrawer({ missionId, open, onClose }: MissionHistoryDrawerProps) {
  const { data, isLoading } = useQuery({
    queryKey: ["mission-audit", missionId],
    queryFn: () => fetchMissionAudit(missionId!),
    enabled: open && missionId !== null,
    staleTime: 0,
  });

  return (
    <Drawer
      anchor="right"
      open={open}
      onClose={onClose}
      PaperProps={{ sx: { width: 400, p: 0 } }}
    >
      <Stack sx={{ height: "100%" }}>
        {/* Header */}
        <Stack
          direction="row"
          alignItems="center"
          justifyContent="space-between"
          sx={{ px: 2.5, py: 2, borderBottom: "1px solid", borderColor: "divider" }}
        >
          <Stack direction="row" alignItems="center" spacing={1}>
            <HistoryIcon fontSize="small" color="action" />
            <Typography variant="subtitle1" fontWeight={700}>Historique de la mission</Typography>
          </Stack>
          <IconButton onClick={onClose} size="small" aria-label="Fermer l'historique">
            <CloseIcon fontSize="small" />
          </IconButton>
        </Stack>

        {/* Body */}
        <Box sx={{ flex: 1, overflowY: "auto", px: 2.5, py: 2 }}>
          {isLoading && (
            <Stack spacing={1}>
              <Skeleton variant="text" width="60%" />
              <Skeleton variant="text" width="80%" />
              <Skeleton variant="text" width="50%" />
            </Stack>
          )}

          {!isLoading && (!data || data.length === 0) && (
            <Box sx={{ textAlign: "center", py: 6 }}>
              <HistoryIcon sx={{ fontSize: 40, color: "text.disabled", mb: 1 }} />
              <Typography variant="body2" color="text.secondary">
                Aucune modification enregistrée
              </Typography>
            </Box>
          )}

          {data && data.length > 0 && (
            <Stack divider={<Divider />}>
              {data.map((event, idx) => (
                <AuditEventCard key={idx} event={event} />
              ))}
            </Stack>
          )}
        </Box>
      </Stack>
    </Drawer>
  );
}
