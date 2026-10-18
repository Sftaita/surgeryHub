import { Box, Chip, Paper, Stack, Typography } from "@mui/material";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";
import PersonOutlineIcon from "@mui/icons-material/PersonOutline";
import BadgeOutlinedIcon from "@mui/icons-material/BadgeOutlined";

import type { Mission } from "../api/missions.types";
import { formatMissionStatus, formatMissionType, formatPersonLabel } from "../utils/missions.format";
import { formatBrusselsTime, formatDayLabel, groupByDay, relativeDayLabel } from "../utils/dayGroups";

type ChipColor = "default" | "primary" | "secondary" | "error" | "info" | "success" | "warning";

export function statusChipColor(status: string): ChipColor {
  switch (status) {
    case "DRAFT": return "default";
    case "OPEN": return "info";
    case "ASSIGNED": return "primary";
    case "SUBMITTED": return "warning";
    case "DECLARED": return "warning";
    case "VALIDATED": return "success";
    case "CLOSED": return "default";
    case "REJECTED": return "error";
    case "CANCELLED": return "error";
    default: return "default";
  }
}

export function MissionStatusChip({ status }: { status?: string | null }) {
  const s = String(status ?? "");
  return (
    <Chip
      label={formatMissionStatus(s)}
      color={statusChipColor(s)}
      size="small"
      variant={s === "DRAFT" || s === "CLOSED" || s === "CANCELLED" ? "outlined" : "filled"}
    />
  );
}

function MissionRow({ mission, onOpen }: { mission: Mission; onOpen: (m: Mission) => void }) {
  const instrumentist = mission.instrumentist ? formatPersonLabel(mission.instrumentist) : null;

  return (
    <Box
      role="button"
      tabIndex={0}
      data-testid="mission-row"
      onClick={() => onOpen(mission)}
      onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onOpen(mission); } }}
      sx={{
        display: "grid",
        gridTemplateColumns: { xs: "1fr auto", md: "120px minmax(180px, 1.4fr) minmax(140px, 1fr) minmax(140px, 1fr) 130px 24px" },
        alignItems: "center",
        columnGap: 2,
        rowGap: 0.5,
        px: 2,
        py: 1.25,
        cursor: "pointer",
        "&:hover": { bgcolor: "action.hover" },
        "&:focus-visible": { outline: 2, outlineColor: "primary.main", outlineOffset: -2 },
      }}
    >
      <Typography variant="body2" fontWeight={700} sx={{ fontVariantNumeric: "tabular-nums" }}>
        {formatBrusselsTime(mission.startAt)} – {formatBrusselsTime(mission.endAt)}
      </Typography>

      <Box sx={{ minWidth: 0, gridColumn: { xs: "1 / 2", md: "auto" } }}>
        <Typography variant="body2" fontWeight={600} noWrap>{mission.site?.name ?? "—"}{mission.roomLabel ? ` · ${mission.roomLabel}` : ""}</Typography>
        <Typography variant="caption" color="text.secondary">
          {formatMissionType(mission.type)} · #{mission.id}
        </Typography>
      </Box>

      <Stack direction="row" spacing={0.75} alignItems="center" sx={{ minWidth: 0 }}>
        <PersonOutlineIcon sx={{ fontSize: 16, color: "text.disabled" }} />
        <Typography variant="body2" noWrap>{mission.surgeon ? `Dr ${formatPersonLabel(mission.surgeon)}` : "—"}</Typography>
      </Stack>

      <Stack direction="row" spacing={0.75} alignItems="center" sx={{ minWidth: 0 }}>
        <BadgeOutlinedIcon sx={{ fontSize: 16, color: "text.disabled" }} />
        <Typography variant="body2" noWrap color={instrumentist ? "text.primary" : "text.disabled"}>
          {instrumentist ?? "Aucune instrumentiste"}
        </Typography>
      </Stack>

      <Box sx={{ gridRow: { xs: "1 / 2", md: "auto" }, gridColumn: { xs: "2 / 3", md: "auto" }, justifySelf: { xs: "end", md: "start" } }}>
        <MissionStatusChip status={mission.status} />
      </Box>

      <ChevronRightIcon sx={{ color: "text.disabled", display: { xs: "none", md: "block" } }} />
    </Box>
  );
}

/**
 * D-147 — missions groupées par jour (Europe/Brussels), dans l'ordre renvoyé par le
 * serveur (`order`). Statuts et libellés viennent du serveur, rien n'est recalculé.
 */
export function MissionsDayList({ missions, onOpen }: { missions: Mission[]; onOpen: (m: Mission) => void }) {
  const groups = groupByDay(missions, (m) => m.startAt);

  return (
    <Stack spacing={2.5}>
      {groups.map((group) => {
        const relative = relativeDayLabel(group.key);
        return (
          <Box key={group.key} component="section" aria-label={formatDayLabel(group.key)}>
            <Stack
              direction="row"
              spacing={1}
              alignItems="center"
              sx={{ position: "sticky", top: 0, zIndex: 1, bgcolor: "background.default", py: 0.75 }}
            >
              <Typography variant="subtitle1" fontWeight={700}>{formatDayLabel(group.key)}</Typography>
              {relative && <Chip size="small" label={relative} color="primary" />}
              <Typography variant="body2" color="text.secondary">
                {group.items.length} mission{group.items.length > 1 ? "s" : ""}
              </Typography>
            </Stack>
            <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden", "& > *:not(:last-child)": { borderBottom: 1, borderColor: "divider" } }}>
              {group.items.map((m) => <MissionRow key={m.id} mission={m} onOpen={onOpen} />)}
            </Paper>
          </Box>
        );
      })}
    </Stack>
  );
}

export default MissionsDayList;
