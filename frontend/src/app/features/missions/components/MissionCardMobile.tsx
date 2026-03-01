import dayjs from "dayjs";
import utc from "dayjs/plugin/utc";
import timezone from "dayjs/plugin/timezone";
import {
  Card,
  CardActions,
  CardContent,
  Button,
  Stack,
  Typography,
  Box,
} from "@mui/material";

import type { Mission, UserRef } from "../api/missions.types";

dayjs.extend(utc);
dayjs.extend(timezone);

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

function formatMissionDateRange(startAt?: string, endAt?: string) {
  if (!startAt || !endAt) return "—";
  try {
    const s = dayjs(startAt).tz("Europe/Brussels");
    const e = dayjs(endAt).tz("Europe/Brussels");
    return `${s.format("DD/MM HH:mm")} → ${e.format("DD/MM HH:mm")}`;
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

function getStatusUi(status: string): {
  badgeText: string;
  badgeTone: "neutral" | "warning" | "error";
  message?: string;
} {
  const s = String(status ?? "—");

  if (s === "DECLARED") {
    return {
      badgeText: "DECLARED",
      badgeTone: "warning",
      message: "En attente de validation manager.",
    };
  }

  if (s === "REJECTED") {
    return {
      badgeText: "REJECTED",
      badgeTone: "error",
      message: "Rejetée — encodage supprimé.",
    };
  }

  return { badgeText: s, badgeTone: "neutral" };
}

function StatusBadge({
  text,
  tone,
}: {
  text: string;
  tone: "neutral" | "warning" | "error";
}) {
  const sx =
    tone === "warning"
      ? { bgcolor: "warning.light", color: "warning.contrastText" }
      : tone === "error"
        ? { bgcolor: "error.light", color: "error.contrastText" }
        : { bgcolor: "grey.200", color: "text.primary" };

  return (
    <Box
      component="span"
      sx={{
        display: "inline-flex",
        alignItems: "center",
        px: 1,
        py: 0.25,
        borderRadius: 1,
        fontSize: 12,
        fontWeight: 700,
        letterSpacing: 0.2,
        ...sx,
      }}
    >
      {text}
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
  const statusUi = getStatusUi((mission.status ?? "—").toString());

  return (
    <Card variant="outlined">
      <CardContent>
        <Stack spacing={0.5}>
          <Typography variant="subtitle1" fontWeight={700}>
            Mission #{mission.id}
          </Typography>

          <Typography variant="body2">Site : {siteName}</Typography>
          <Typography variant="body2">Horaire : {dateRange}</Typography>
          <Typography variant="body2">
            Chirurgien : {userLabel(mission.surgeon)}
          </Typography>

          <Stack direction="row" spacing={1} alignItems="center">
            <Typography variant="body2">Statut :</Typography>
            <StatusBadge text={statusUi.badgeText} tone={statusUi.badgeTone} />
          </Stack>

          {statusUi.message ? (
            <Typography variant="body2" color="text.secondary">
              {statusUi.message}
            </Typography>
          ) : null}
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
