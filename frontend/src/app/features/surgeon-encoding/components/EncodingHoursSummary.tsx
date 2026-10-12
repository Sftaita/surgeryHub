import { Box, Stack, Typography } from "@mui/material";
import AccessTimeIcon from "@mui/icons-material/AccessTime";
import dayjs from "dayjs";
import type { MissionExecutionInfo } from "../../missions/api/missions.api";
import { executionDurationMinutes } from "../../missions/utils/missions.format";

const GRAY_500 = "#727E8C";
const GRAY_700 = "#3A4754";
const GRAY_900 = "#16202B";
const GRAY_150 = "#E7EBEF";
const GREEN_700 = "#2C7D5F";
const AMBER_700 = "#9A5B00";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";

function hm(iso: string): string {
  return dayjs(iso).format("HH[h]mm");
}

function duration(minutes: number): string {
  return `${Math.floor(minutes / 60)}h${String(minutes % 60).padStart(2, "0")}`;
}

/** Écart signé réel − planifié, "+0h45" / "−1h15" / "conforme au planning". */
export function formatGap(actualMinutes: number, plannedMinutes: number): string {
  const gap = actualMinutes - plannedMinutes;
  if (gap === 0) return "Conforme au planning";
  const abs = Math.abs(gap);
  return `${gap > 0 ? "+" : "−"}${duration(abs)} par rapport au planning`;
}

function Row({ label, value, tone }: { label: string; value: string; tone?: string }) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="baseline" spacing={1} sx={{ py: "6px", borderTop: "1px dashed", borderColor: GRAY_150, "&:first-of-type": { borderTop: "none" } }}>
      <Typography sx={{ fontSize: 13, color: GRAY_500 }}>{label}</Typography>
      <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: tone ?? GRAY_900, fontVariantNumeric: "tabular-nums", textAlign: "right" }}>{value}</Typography>
    </Stack>
  );
}

type Props = {
  execution: MissionExecutionInfo | undefined;
  plannedStartAt: string | null | undefined;
  plannedEndAt: string | null | undefined;
};

/**
 * Lot 6 (D-100), complété par D-143 — horaires en lecture seule pour le chirurgien :
 * planifié, réel déclaré (début → fin, pause), durée réelle et écart au planning. Aucun
 * contrôle de modification, aucune donnée tarifaire. Une durée seule (saisie antérieure à
 * D-143) est affichée telle quelle, sans inventer de plage horaire.
 */
export function EncodingHoursSummary({ execution, plannedStartAt, plannedEndAt }: Props) {
  const planned = plannedStartAt && plannedEndAt ? `${hm(plannedStartAt)} → ${hm(plannedEndAt)}` : "—";
  const plannedMinutes = plannedStartAt && plannedEndAt ? Math.max(0, dayjs(plannedEndAt).diff(dayjs(plannedStartAt), "minute")) : null;

  const start = execution?.actualStartAt ?? null;
  const end = execution?.actualEndAt ?? null;
  const actual = start && end
    ? `${hm(start)} → ${hm(end)}${execution?.breakMinutes ? ` · pause ${execution.breakMinutes} min` : ""}`
    : start
      ? `Début ${hm(start)} — fin non déclarée`
      : "Non déclarées";
  const actualMinutes = executionDurationMinutes(execution);

  return (
    <Box data-testid="surgeon-hours-summary" sx={{ background: "#fff", borderRadius: "16px", padding: "14px 16px", boxShadow: SHADOW_XS }}>
      <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 0.5 }}>
        <AccessTimeIcon sx={{ fontSize: 18, color: GREEN_700 }} />
        <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: GRAY_700 }}>Horaires</Typography>
      </Stack>
      <Row label="Heures planifiées" value={planned} />
      <Row label="Heures prestées" value={actual} tone={start && end ? undefined : AMBER_700} />
      <Row label="Durée prestée" value={actualMinutes != null ? duration(actualMinutes) : "—"} />
      {actualMinutes != null && plannedMinutes != null && (
        <Row label="Écart" value={formatGap(actualMinutes, plannedMinutes)} tone={actualMinutes === plannedMinutes ? GREEN_700 : GRAY_900} />
      )}
    </Box>
  );
}
