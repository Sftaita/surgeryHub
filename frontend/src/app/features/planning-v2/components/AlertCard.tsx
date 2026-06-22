import { Box, Button, Stack, Typography } from "@mui/material";
import LightbulbOutlinedIcon from "@mui/icons-material/LightbulbOutlined";
import PlaceOutlinedIcon from "@mui/icons-material/PlaceOutlined";
import SwapHorizOutlinedIcon from "@mui/icons-material/SwapHorizOutlined";

import type { PlanningAlertV2, PlanningAlertType, PlanningAlertStatus } from "../api/planningV2.types";
import { alertSeverityTokens, planningV2Colors, planningV2Radii, planningV2Shadows } from "../theme/tokens";

const TYPE_LABELS: Record<PlanningAlertType, string> = {
  SURGEON_ABSENCE: "Absence chirurgien",
  INSTRUMENTIST_ABSENCE: "Absence instrumentiste",
  SURGEON_CONFLICT: "Conflit chirurgien",
  INSTRUMENTIST_CONFLICT: "Conflit instrumentiste",
  REASSIGNMENT_REQUIRED: "Réassignation nécessaire",
  OCCURRENCE_CANCELLED: "Mission annulée",
};

type Severity = "crit" | "warn" | "info";

const TYPE_SEVERITY: Record<PlanningAlertType, Severity> = {
  REASSIGNMENT_REQUIRED: "crit",
  SURGEON_CONFLICT: "crit",
  INSTRUMENTIST_CONFLICT: "crit",
  SURGEON_ABSENCE: "warn",
  INSTRUMENTIST_ABSENCE: "warn",
  OCCURRENCE_CANCELLED: "warn",
};

const STATUS_LABELS: Record<PlanningAlertStatus, string> = {
  OPEN: "À traiter",
  ACKNOWLEDGED: "En cours",
  RESOLVED: "Résolu",
  IGNORED: "Ignoré",
};

const STATUS_SEVERITY: Record<PlanningAlertStatus, Severity | "ok"> = {
  OPEN: "crit",
  ACKNOWLEDGED: "warn",
  RESOLVED: "ok",
  IGNORED: "info",
};

interface Props {
  alert: PlanningAlertV2;
  onAcknowledge: (alert: PlanningAlertV2) => void;
  onResolve: (alert: PlanningAlertV2) => void;
  onIgnore: (alert: PlanningAlertV2) => void;
  onReassign: (alert: PlanningAlertV2) => void;
  onOpenAsAvailable: (alert: PlanningAlertV2) => void;
  busy?: boolean;
}

function buildProbleme(alert: PlanningAlertV2): string {
  const date = alert.mission.startAt?.slice(0, 10);
  const surgeon = alert.mission.surgeon?.name ?? alert.mission.surgeon?.email ?? "—";
  switch (alert.type) {
    case "SURGEON_ABSENCE": return `${surgeon} absent le ${formatFr(date)} — poste à couvrir`;
    case "INSTRUMENTIST_ABSENCE": return `Instrumentiste absente le ${formatFr(date)}`;
    case "REASSIGNMENT_REQUIRED": return `Instrumentiste absente le ${formatFr(date)} — réassignation nécessaire`;
    case "OCCURRENCE_CANCELLED": return `Occurrence annulée le ${formatFr(date)}`;
    case "SURGEON_CONFLICT": return `Conflit de planning pour ${surgeon} le ${formatFr(date)}`;
    case "INSTRUMENTIST_CONFLICT": return `Conflit d'instrumentiste le ${formatFr(date)}`;
  }
}

function buildImpact(alert: PlanningAlertV2): string {
  const type = alert.mission.status === "ASSIGNED" || alert.mission.status === "OPEN" ? "mission" : "poste";
  return `1 ${type} de bloc sans instrumentiste confirmée le ${formatFr(alert.mission.startAt?.slice(0, 10))}`;
}

function buildSuggestion(alert: PlanningAlertV2): string {
  switch (alert.actions.recommendedAction) {
    case "REASSIGN": return "Réassignez ce créneau à une instrumentiste disponible pour ne pas laisser le poste sans couverture.";
    case "REVIEW": return "Vérifiez ce créneau et décidez de l'action la plus adaptée (réassigner, ouvrir comme mission disponible, ou ignorer).";
    case "NONE": return "Aucune action n'est requise pour le moment.";
  }
}

function formatFr(iso?: string): string {
  if (!iso) return "—";
  return new Date(iso + "T00:00:00").toLocaleDateString("fr-FR", { day: "numeric", month: "long" });
}

export function AlertCard({ alert, onAcknowledge, onResolve, onIgnore, onReassign, onOpenAsAvailable, busy }: Props) {
  const { mission, actions } = alert;
  const date = mission.startAt?.slice(0, 10);
  const time = mission.startAt && mission.endAt ? `${mission.startAt.slice(11, 16)}–${mission.endAt.slice(11, 16)}` : "";
  const sev = alertSeverityTokens[TYPE_SEVERITY[alert.type]];
  const statusSev = alertSeverityTokens[STATUS_SEVERITY[alert.status]];

  return (
    <Stack direction="row" sx={{ bgcolor: "#fff", border: `1px solid ${planningV2Colors.cardBorder}`, borderRadius: planningV2Radii.cardLg, boxShadow: planningV2Shadows.card, overflow: "hidden" }}>
      <Box sx={{ width: 4, alignSelf: "stretch", bgcolor: sev.dot, flex: "none" }} />
      <Box sx={{ flex: 1, minWidth: 0, p: 2.25 }}>
        <Stack direction="row" alignItems="center" spacing={1.25} flexWrap="wrap" useFlexGap sx={{ mb: 1 }}>
          <Box sx={{ display: "inline-flex", alignItems: "center", gap: 0.7, fontSize: 11.5, fontWeight: 700, color: sev.fg, bgcolor: sev.bg, px: 1.1, py: 0.4, borderRadius: planningV2Radii.pill }}>
            <Box sx={{ width: 6, height: 6, borderRadius: "999px", bgcolor: sev.dot }} />
            {TYPE_LABELS[alert.type]}
          </Box>
          <Typography sx={{ fontSize: 12, color: planningV2Colors.textSecondary, fontVariantNumeric: "tabular-nums" }}>{formatFr(date)} · {time}</Typography>
          <Typography sx={{ fontSize: 12, color: planningV2Colors.textMuted }}>{mission.site?.name ?? "—"}</Typography>
          <Box sx={{ flex: 1 }} />
          <Box sx={{ fontSize: 11.5, fontWeight: 700, color: statusSev.fg, bgcolor: statusSev.bg, px: 1.1, py: 0.4, borderRadius: planningV2Radii.pill }}>
            {STATUS_LABELS[alert.status]}
          </Box>
        </Stack>

        <Typography sx={{ fontSize: 14.5, fontWeight: 700, color: planningV2Colors.textTitle, mb: 0.75 }}>
          {buildProbleme(alert)}
        </Typography>

        <Stack direction="row" alignItems="center" spacing={0.9} sx={{ fontSize: 12.5, color: planningV2Colors.textMuted, mb: 1.5 }}>
          <PlaceOutlinedIcon sx={{ fontSize: 14, color: planningV2Colors.textSecondary }} />
          <Typography component="span" sx={{ fontSize: 12.5 }}>
            <Box component="span" sx={{ color: planningV2Colors.textSecondary }}>Impact —</Box> {buildImpact(alert)}
          </Typography>
          <Typography component="span" sx={{ color: "#C2C9D1" }}>·</Typography>
          <Typography component="span" sx={{ fontSize: 12.5, color: planningV2Colors.textSecondary }}>
            {mission.surgeon?.name ?? mission.surgeon?.email ?? "—"} · {mission.instrumentist ? (mission.instrumentist.name ?? mission.instrumentist.email) : "Aucun"}
          </Typography>
        </Stack>

        <Stack direction="row" alignItems="flex-start" spacing={1.1} sx={{ bgcolor: "#F6FAFF", border: "1px solid #E3EEFD", borderRadius: planningV2Radii.card, p: 1.5, mb: 1.75 }}>
          <LightbulbOutlinedIcon sx={{ fontSize: 16, color: planningV2Colors.brand, mt: 0.1, flex: "none" }} />
          <Typography sx={{ fontSize: 12.5, color: planningV2Colors.textStrong, lineHeight: 1.5 }}>
            <Box component="span" sx={{ fontWeight: 700, color: planningV2Colors.brand }}>Action suggérée</Box> — {buildSuggestion(alert)}
          </Typography>
        </Stack>

        {alert.resolutionNote && (
          <Typography sx={{ fontSize: 12, color: planningV2Colors.textSecondary, fontStyle: "italic", mb: 1.5 }}>
            {alert.resolutionNote}
          </Typography>
        )}

        <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
          {actions.canReassign && (
            <Button
              size="small" disabled={busy} onClick={() => onReassign(alert)} startIcon={<SwapHorizOutlinedIcon sx={{ fontSize: 15 }} />}
              sx={{ height: 34, px: 1.75, borderRadius: "9px", textTransform: "none", fontWeight: 600, fontSize: 12.5, bgcolor: planningV2Colors.brand, color: "#fff", "&:hover": { bgcolor: planningV2Colors.brandHover } }}
            >
              Réassigner
            </Button>
          )}
          {actions.canOpenAsAvailable && (
            <Button
              size="small" disabled={busy} onClick={() => onOpenAsAvailable(alert)}
              sx={{ height: 34, px: 1.75, borderRadius: "9px", textTransform: "none", fontWeight: 600, fontSize: 12.5, border: `1px solid ${planningV2Colors.brand}`, color: planningV2Colors.brand, "&:hover": { bgcolor: planningV2Colors.infoBg } }}
            >
              Ouvrir comme mission
            </Button>
          )}
          {actions.canResolve && (
            <Button
              size="small" disabled={busy} onClick={() => onResolve(alert)}
              sx={{ height: 34, px: 1.6, borderRadius: "9px", textTransform: "none", fontWeight: 600, fontSize: 12.5, border: "1px solid #DDE2E8", color: planningV2Colors.textStrong, "&:hover": { bgcolor: "#F1F4F7" } }}
            >
              Résoudre
            </Button>
          )}
          {actions.canAcknowledge && (
            <Button
              size="small" disabled={busy} onClick={() => onAcknowledge(alert)}
              sx={{ height: 34, px: 1.6, borderRadius: "9px", textTransform: "none", fontWeight: 600, fontSize: 12.5, color: planningV2Colors.textMuted, "&:hover": { bgcolor: "#F1F4F7" } }}
            >
              Reconnaître
            </Button>
          )}
          {actions.canIgnore && (
            <Button
              size="small" disabled={busy} onClick={() => onIgnore(alert)}
              sx={{ height: 34, px: 1.6, borderRadius: "9px", textTransform: "none", fontWeight: 600, fontSize: 12.5, color: planningV2Colors.textSecondary, "&:hover": { bgcolor: "#F1F4F7" } }}
            >
              Ignorer
            </Button>
          )}
        </Stack>
      </Box>
    </Stack>
  );
}
