import { alpha, Avatar, Box, Button, Chip, CircularProgress, Link, Paper, Stack, Typography } from "@mui/material";
import AssignmentOutlinedIcon from "@mui/icons-material/AssignmentOutlined";
import MoveToInboxOutlinedIcon from "@mui/icons-material/MoveToInboxOutlined";
import CalendarMonthOutlinedIcon from "@mui/icons-material/CalendarMonthOutlined";
import EditNoteOutlinedIcon from "@mui/icons-material/EditNoteOutlined";
import EuroOutlinedIcon from "@mui/icons-material/EuroOutlined";
import Inventory2OutlinedIcon from "@mui/icons-material/Inventory2Outlined";
import CheckCircleOutlineIcon from "@mui/icons-material/CheckCircleOutline";
import HighlightOffIcon from "@mui/icons-material/HighlightOff";
import { Link as RouterLink } from "react-router-dom";

import type { AuditJournalEntry } from "../api/auditJournal.api";
import { formatAuditEventType } from "../auditEventLabels";
import { formatBrusselsTime, formatDayLabel, groupByDay, relativeDayLabel } from "../../missions/utils/dayGroups";
import { formatBrusselsRange, formatMissionType } from "../../missions/utils/missions.format";

type PaletteKey = "primary" | "warning" | "info" | "secondary" | "success" | "error";
type Tone = { icon: typeof AssignmentOutlinedIcon; palette: PaletteKey | null };

const CATEGORY_TONE: Record<string, Tone> = {
  MISSION: { icon: AssignmentOutlinedIcon, palette: "primary" },
  SURGEON_REQUEST: { icon: MoveToInboxOutlinedIcon, palette: "warning" },
  PLANNING: { icon: CalendarMonthOutlinedIcon, palette: "info" },
  ENCODING: { icon: EditNoteOutlinedIcon, palette: "secondary" },
  FINANCE: { icon: EuroOutlinedIcon, palette: "success" },
  CATALOGUE: { icon: Inventory2OutlinedIcon, palette: null },
};

// Décisions explicites : couleur de la décision plutôt que celle de la catégorie.
const EVENT_TONE: Record<string, Tone> = {
  SURGEON_MISSION_REQUEST_ACCEPTED: { icon: CheckCircleOutlineIcon, palette: "success" },
  SURGEON_MISSION_REQUEST_REJECTED: { icon: HighlightOffIcon, palette: "error" },
};

function toneOf(entry: AuditJournalEntry): Tone {
  return EVENT_TONE[entry.eventType] ?? CATEGORY_TONE[entry.category] ?? CATEGORY_TONE.MISSION;
}

function str(v: unknown): string | null {
  return typeof v === "string" && v.trim() !== "" ? v : null;
}

function EntryContext({ entry }: { entry: AuditJournalEntry }) {
  const { mission, surgeonMissionRequest: request } = entry;

  if (mission) {
    const parts = [
      mission.site?.name,
      mission.startAt && mission.endAt ? formatBrusselsRange(mission.startAt, mission.endAt) : null,
      mission.surgeon?.displayName ? `Dr ${mission.surgeon.displayName}` : null,
      mission.instrumentist?.displayName || null,
    ].filter(Boolean);
    return (
      <Typography variant="body2" color="text.secondary">
        <Link component={RouterLink} to={`/app/m/missions/${mission.id}`} underline="hover" fontWeight={600}>
          Mission #{mission.id}
        </Link>
        {parts.length > 0 && <> · {parts.join(" · ")}</>}
      </Typography>
    );
  }

  if (request) {
    const parts = [
      request.surgeon?.displayName ? `Dr ${request.surgeon.displayName}` : null,
      request.site?.name,
      request.startAt && request.endAt ? formatBrusselsRange(request.startAt, request.endAt) : null,
      str(entry.payload.type) ? formatMissionType(entry.payload.type as string) : null,
    ].filter(Boolean);
    return (
      <Typography variant="body2" color="text.secondary">
        Demande #{request.id}{parts.length > 0 && <> · {parts.join(" · ")}</>}
      </Typography>
    );
  }

  const name = str(entry.payload.siteName) ?? str(entry.payload.instrumentistName) ?? str(entry.payload.firmName);
  return name ? <Typography variant="body2" color="text.secondary">{name}</Typography> : null;
}

function EntryRow({ entry }: { entry: AuditJournalEntry }) {
  const tone = toneOf(entry);
  const Icon = tone.icon;
  const comment = str(entry.payload.reviewComment) ?? str(entry.payload.comment) ?? str(entry.payload.reason);

  return (
    <Stack direction="row" spacing={1.5} sx={{ py: 1.25, px: 1.5 }} data-testid="audit-entry">
      <Avatar
        sx={(t) => ({
          width: 32,
          height: 32,
          color: tone.palette ? t.palette[tone.palette].main : t.palette.text.secondary,
          bgcolor: tone.palette ? alpha(t.palette[tone.palette].main, 0.12) : t.palette.action.hover,
        })}
      >
        <Icon sx={{ fontSize: 18 }} />
      </Avatar>
      <Box sx={{ minWidth: 0, flex: 1 }}>
        <Stack direction="row" spacing={1} alignItems="baseline" justifyContent="space-between">
          <Typography variant="body2" fontWeight={600}>
            {formatAuditEventType(entry.eventType)}
            {entry.actor?.displayName && (
              <Typography component="span" variant="body2" color="text.secondary" fontWeight={400}>
                {" "}— par {entry.actor.displayName}
              </Typography>
            )}
          </Typography>
          <Typography variant="caption" color="text.secondary" sx={{ flexShrink: 0 }}>
            {formatBrusselsTime(entry.occurredAt)}
          </Typography>
        </Stack>
        <EntryContext entry={entry} />
        {comment && (
          <Typography variant="body2" sx={{ mt: 0.5, fontStyle: "italic" }} color="text.secondary">
            « {comment} »
          </Typography>
        )}
      </Box>
    </Stack>
  );
}

/**
 * D-147 — fil d'actions groupé par jour (journal d'activité, historique des demandes).
 * L'ordre (plus récent d'abord) est celui du serveur.
 */
export function AuditTimeline({
  entries,
  total,
  hasMore,
  loadingMore,
  onLoadMore,
}: {
  entries: AuditJournalEntry[];
  total: number;
  hasMore: boolean;
  loadingMore: boolean;
  onLoadMore: () => void;
}) {
  const groups = groupByDay(entries, (e) => e.occurredAt);

  return (
    <Stack spacing={2}>
      {groups.map((group) => {
        const relative = relativeDayLabel(group.key);
        return (
          <Box key={group.key}>
            <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.75 }}>
              <Typography variant="subtitle2" fontWeight={700}>{formatDayLabel(group.key)}</Typography>
              {relative && <Chip size="small" label={relative} color="primary" variant="outlined" />}
              <Typography variant="caption" color="text.secondary">
                {group.items.length} action{group.items.length > 1 ? "s" : ""}
              </Typography>
            </Stack>
            <Paper variant="outlined" sx={{ borderRadius: 2, "& > *:not(:last-child)": { borderBottom: 1, borderColor: "divider" } }}>
              {group.items.map((entry) => <EntryRow key={entry.id} entry={entry} />)}
            </Paper>
          </Box>
        );
      })}

      <Stack direction="row" alignItems="center" justifyContent="center" spacing={2} sx={{ py: 1 }}>
        <Typography variant="caption" color="text.secondary">
          {entries.length} / {total} action{total > 1 ? "s" : ""}
        </Typography>
        {hasMore && (
          <Button size="small" variant="outlined" onClick={onLoadMore} disabled={loadingMore}>
            {loadingMore ? <CircularProgress size={16} /> : "Charger plus"}
          </Button>
        )}
      </Stack>
    </Stack>
  );
}

export default AuditTimeline;
