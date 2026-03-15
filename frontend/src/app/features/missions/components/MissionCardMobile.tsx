import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import "dayjs/locale/fr";
import {
  Card,
  CardActions,
  CardContent,
  Button,
  Stack,
  Typography,
  Chip,
  Box,
} from "@mui/material";

import type { Mission, UserRef } from "../api/missions.types";

dayjs.extend(utc);
dayjs.extend(timezone);
dayjs.locale("fr");

type Action = {
  label: string;
  action: () => void | Promise<void>;
  visible: boolean;
  loading?: boolean;
  disabled?: boolean;
};

type Props = {
  mission: Mission;
  primaryAction?: Action;
  secondaryAction?: Action;
};

type ChipColor =
  | "default"
  | "info"
  | "primary"
  | "warning"
  | "error"
  | "success";

function getStatusChip(status: string): { label: string; color: ChipColor } {
  switch (status) {
    case "DRAFT":
      return { label: "Brouillon", color: "default" };
    case "OPEN":
      return { label: "Disponible", color: "info" };
    case "ASSIGNED":
      return { label: "En cours", color: "primary" };
    case "DECLARED":
      return { label: "À valider", color: "warning" };
    case "REJECTED":
      return { label: "Rejetée", color: "error" };
    case "SUBMITTED":
      return { label: "Soumis", color: "success" };
    case "VALIDATED":
      return { label: "Validée", color: "success" };
    case "CLOSED":
      return { label: "Clôturée", color: "default" };
    default:
      return { label: status, color: "default" };
  }
}

function formatMissionDateRange(startAt?: string, endAt?: string): string {
  if (!startAt || !endAt) return "—";
  try {
    const s = dayjs(startAt).tz("Europe/Brussels").locale("fr");
    const e = dayjs(endAt).tz("Europe/Brussels").locale("fr");
    const startTime = s.format("HH[h]mm");
    const endTime = e.format("HH[h]mm");

    if (s.isSame(e, "day")) {
      const dayLabel =
        s.format("ddd").charAt(0).toUpperCase() + s.format("ddd").slice(1);
      const dayNum = s.format("D");
      const month = s.format("MMMM");
      return `${dayLabel} ${dayNum} ${month} · ${startTime} → ${endTime}`;
    } else {
      const startDay = s.format("D");
      const endDay = e.format("D");
      const startMonth = s.format("MMMM");
      const endMonth = e.format("MMMM");
      const monthLabel =
        startMonth === endMonth ? endMonth : `${startMonth} → ${endMonth}`;
      return `${startDay} → ${endDay} ${monthLabel} · ${startTime} → ${endTime}`;
    }
  } catch {
    return `${startAt} → ${endAt}`;
  }
}

function userLabel(u?: UserRef | null): string {
  if (!u) return "—";
  const dn = (u as any).displayName?.toString?.().trim?.() ?? "";
  if (dn) return dn;

  const fn = (u.firstname ?? "").toString().trim();
  const ln = (u.lastname ?? "").toString().trim();
  const full = `${fn} ${ln}`.trim();
  return full || u.email || "—";
}

function InfoRow({
  label,
  value,
}: {
  label: string;
  value: React.ReactNode;
}) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary" display="block">
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={600}>
        {value}
      </Typography>
    </Box>
  );
}

export default function MissionCardMobile({
  mission,
  primaryAction,
  secondaryAction,
}: Props) {
  const siteName =
    (mission.site?.name as string | undefined) ??
    (mission.siteId ? `Site #${mission.siteId}` : "—");

  const dateRange = formatMissionDateRange(mission.startAt, mission.endAt);
  const status = String(mission.status ?? "");
  const { label: chipLabel, color: chipColor } = getStatusChip(status);
  const surgeon = userLabel(mission.surgeon);

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={1.5}>
          <Stack
            direction="row"
            alignItems="center"
            justifyContent="space-between"
          >
            <Typography variant="subtitle1" fontWeight={700}>
              Mission #{mission.id}
            </Typography>
            <Chip label={chipLabel} color={chipColor} size="small" />
          </Stack>

          <Stack spacing={1}>
            <InfoRow label="Site" value={siteName} />
            <InfoRow label="Horaire" value={dateRange} />
            <InfoRow label="Chirurgien" value={surgeon} />
          </Stack>
        </Stack>
      </CardContent>

      {(primaryAction?.visible || secondaryAction?.visible) && (
        <CardActions sx={{ justifyContent: "flex-end", gap: 1 }}>
          {secondaryAction?.visible && (
            <Button
              variant="outlined"
              size="small"
              onClick={secondaryAction.action}
              disabled={secondaryAction.disabled || secondaryAction.loading}
            >
              {secondaryAction.loading ? "..." : secondaryAction.label}
            </Button>
          )}

          {primaryAction?.visible && (
            <Button
              variant="contained"
              size="small"
              onClick={primaryAction.action}
              disabled={primaryAction.disabled || primaryAction.loading}
            >
              {primaryAction.loading ? "..." : primaryAction.label}
            </Button>
          )}
        </CardActions>
      )}
    </Card>
  );
}
