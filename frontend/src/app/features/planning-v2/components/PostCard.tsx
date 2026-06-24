import * as React from "react";
import { Box, Chip, IconButton, Menu, MenuItem, Stack, Tooltip, Typography } from "@mui/material";
import MoreHorizIcon from "@mui/icons-material/MoreHoriz";
import AccessTimeOutlinedIcon from "@mui/icons-material/AccessTimeOutlined";
import RepeatOutlinedIcon from "@mui/icons-material/RepeatOutlined";
import CalendarTodayOutlinedIcon from "@mui/icons-material/CalendarTodayOutlined";
import EventBusyOutlinedIcon from "@mui/icons-material/EventBusyOutlined";

import type { SurgeonSchedulePostV2 } from "../api/planningV2.types";
import { summarizeRecurrence } from "../api/planningV2.types";
import { isEndingSoon, formatEndingSoonLabel } from "../api/endingSoon";
import { planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";
import { Avatar } from "../../../ui/avatar/Avatar";

const PERIOD_LABELS: Record<string, string> = { MATIN: "Matin", APRES_MIDI: "Après-midi", JOURNEE: "Journée" };
const PERIOD_HOURS: Record<string, string> = { MATIN: "08h–13h", APRES_MIDI: "13h–18h", JOURNEE: "08h–18h" };
const WEEKDAY_LABELS: Record<number, string> = { 1: "Lun", 2: "Mar", 3: "Mer", 4: "Jeu", 5: "Ven", 6: "Sam", 7: "Dim" };

interface Props {
  post: SurgeonSchedulePostV2;
  variant?: "card" | "split";
  onEdit: (post: SurgeonSchedulePostV2) => void;
  onToggleActive: (post: SurgeonSchedulePostV2) => void;
  onManageExceptions: (post: SurgeonSchedulePostV2) => void;
  exceptionCount?: number;
}

export function PostCard({ post, variant = "card", onEdit, onToggleActive, onManageExceptions, exceptionCount = 0 }: Props) {
  const [menuAnchor, setMenuAnchor] = React.useState<HTMLElement | null>(null);
  const endingSoon = isEndingSoon(post.endDate);
  const days = post.recurrence.weekdays.map((d) => WEEKDAY_LABELS[d]).join(", ") || "—";

  return (
    <Box
      sx={{
        background: "#fff",
        border: `1px solid ${planningV2Colors.cardBorder}`,
        borderRadius: planningV2Radii.card,
        boxShadow: planningV2Shadows.card,
        p: 2,
        opacity: post.active ? 1 : 0.55,
        transition: "box-shadow .15s, transform .15s",
        "&:hover": { boxShadow: planningV2Shadows.cardHover },
      }}
    >
      <Stack direction="row" justifyContent="space-between" alignItems="flex-start" sx={{ mb: 1.5 }}>
        <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
          <Typography sx={{ fontSize: variant === "split" ? 14 : 13.5, fontWeight: 700, color: planningV2Colors.textTitle }}>
            {post.site.name}
          </Typography>
          <Chip
            size="small"
            label={post.type === "BLOCK" ? "Bloc" : "Consultation"}
            sx={{
              height: 22, fontSize: 11.5, fontWeight: 700,
              bgcolor: post.type === "BLOCK" ? planningV2Colors.infoBg : "#F3EBFD",
              color: post.type === "BLOCK" ? planningV2Colors.infoFg : "#7C4FCC",
            }}
          />
          {!post.active && (
            <Chip size="small" label="Désactivé" sx={{ height: 22, fontSize: 11.5, fontWeight: 700, bgcolor: "#F1F4F7", color: planningV2Colors.textMuted }} />
          )}
          {endingSoon && post.endDate && (
            <Chip
              size="small"
              icon={<EventBusyOutlinedIcon sx={{ fontSize: 13 }} />}
              label={formatEndingSoonLabel(post.endDate)}
              sx={{ height: 22, fontSize: 11, fontWeight: 700, bgcolor: planningV2Colors.warnBg, color: planningV2Colors.warnFg }}
            />
          )}
        </Stack>
        <IconButton size="small" onClick={(e) => setMenuAnchor(e.currentTarget)} sx={{ color: planningV2Colors.textSecondary }}>
          <MoreHorizIcon fontSize="small" />
        </IconButton>
        <Menu anchorEl={menuAnchor} open={!!menuAnchor} onClose={() => setMenuAnchor(null)}>
          <MenuItem onClick={() => { setMenuAnchor(null); onEdit(post); }}>Modifier</MenuItem>
          <MenuItem onClick={() => { setMenuAnchor(null); onToggleActive(post); }}>
            {post.active ? "Désactiver" : "Réactiver"}
          </MenuItem>
        </Menu>
      </Stack>

      <Box
        sx={{
          display: "grid",
          gridTemplateColumns: variant === "split" ? "1fr 1fr" : "1fr",
          gap: variant === "split" ? "10px 22px" : "9px",
          mb: 1.6,
        }}
      >
        <Stack direction="row" spacing={1.1} alignItems="center" sx={{ color: planningV2Colors.textBody, fontSize: 12.5 }}>
          <AccessTimeOutlinedIcon sx={{ fontSize: 14, color: planningV2Colors.textSecondary }} />
          <Typography component="span" sx={{ fontWeight: 600, color: planningV2Colors.textStrong, fontSize: 12.5 }}>
            {PERIOD_LABELS[post.period]}
          </Typography>
          <Typography component="span" sx={{ fontVariantNumeric: "tabular-nums", fontSize: 12.5, color: planningV2Colors.textBody }}>
            {PERIOD_HOURS[post.period]}
          </Typography>
        </Stack>
        <Stack direction="row" spacing={1.1} alignItems="center" sx={{ color: planningV2Colors.textBody, fontSize: 12.5 }}>
          <RepeatOutlinedIcon sx={{ fontSize: 14, color: planningV2Colors.textSecondary }} />
          <span>{summarizeRecurrence(post.recurrence)}</span>
        </Stack>
        {post.recurrence.frequency === "WEEKLY" && (
          <Stack direction="row" spacing={1.1} alignItems="center" sx={{ color: planningV2Colors.textBody, fontSize: 12.5 }}>
            <CalendarTodayOutlinedIcon sx={{ fontSize: 13, color: planningV2Colors.textSecondary }} />
            <span style={{ fontVariantNumeric: "tabular-nums" }}>{days}</span>
          </Stack>
        )}
        {variant === "split" && (
          <>
            <Stack direction="row" spacing={1.1} alignItems="center" sx={{ color: planningV2Colors.textBody, fontSize: 12.5 }}>
              <CalendarTodayOutlinedIcon sx={{ fontSize: 13, color: planningV2Colors.textSecondary }} />
              <span style={{ fontVariantNumeric: "tabular-nums" }}>Du {formatFr(post.startDate)}</span>
            </Stack>
            <Stack direction="row" spacing={1.1} alignItems="center" sx={{ color: planningV2Colors.textBody, fontSize: 12.5 }}>
              <CalendarTodayOutlinedIcon sx={{ fontSize: 13, color: planningV2Colors.textSecondary }} />
              <span style={{ fontVariantNumeric: "tabular-nums" }}>
                {post.endDate ? `Jusqu'au ${formatFr(post.endDate)}` : "Sans date de fin"}
              </span>
            </Stack>
          </>
        )}
      </Box>

      <Box sx={{ height: "1px", background: planningV2Colors.divider, mb: 1.4 }} />

      <Stack direction="row" alignItems="center" justifyContent="space-between" spacing={1}>
        <Stack direction="row" alignItems="center" spacing={1} sx={{ minWidth: 0 }}>
          {post.instrumentist ? (
            <>
              <Avatar name={post.instrumentist.name ?? post.instrumentist.email} />
              <Typography noWrap sx={{ fontSize: 12.5, fontWeight: 600, color: planningV2Colors.textStrong }}>
                {post.instrumentist.name ?? post.instrumentist.email}
              </Typography>
            </>
          ) : (
            <Chip
              size="small"
              variant="outlined"
              label="À assigner"
              sx={{
                height: 22, fontSize: 11, fontWeight: 700, borderStyle: "dashed",
                borderColor: planningV2Colors.warnDot, color: planningV2Colors.warnFg, bgcolor: planningV2Colors.warnBg,
              }}
            />
          )}
        </Stack>
        <Tooltip title="Voir les exceptions de ce poste">
          <Box
            component="button"
            onClick={() => onManageExceptions(post)}
            sx={{
              border: "none", background: "transparent", cursor: "pointer", fontFamily: "inherit",
              fontSize: 12, fontWeight: 700, color: exceptionCount > 0 ? planningV2Colors.warnFg : planningV2Colors.textSecondary,
              px: 1, py: 0.4, borderRadius: "8px",
              "&:hover": { bgcolor: "#F1F4F7" },
            }}
          >
            Exceptions{exceptionCount > 0 ? ` (${exceptionCount})` : ""}
          </Box>
        </Tooltip>
      </Stack>
    </Box>
  );
}

function formatFr(iso: string): string {
  return new Date(iso + "T00:00:00").toLocaleDateString("fr-FR", { day: "numeric", month: "short", year: "numeric" });
}
