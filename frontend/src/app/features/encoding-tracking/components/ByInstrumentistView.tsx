import * as React from "react";
import { Alert, Box, CircularProgress, Paper, Stack, Tooltip, Typography } from "@mui/material";
import type { EncodingTrackingItem } from "../api/encodingTracking.api";
import { EncodingTrackingTable } from "./EncodingTrackingTable";
import { formatMinutes } from "../encodingStateMeta";
import { parseDateOnly } from "../period";
import { EmptyState } from "../../../ui/EmptyState";

interface Props {
  items: EncodingTrackingItem[];
  isLoading: boolean;
  isError: boolean;
  isCapped: boolean;
  cappedTotal: number;
}

type DayIndicator = "complete" | "partial" | "missing";

interface InstrumentistGroup {
  id: number;
  name: string;
  items: EncodingTrackingItem[];
}

/**
 * Suivi des encodages (D-118), Étape 9 — vue "Par instrumentiste".
 *
 * Regroupe des items déjà entièrement résolus par le backend : aucun nouveau calcul
 * d'état ou de compensation, uniquement un groupby + des sommes arithmétiques sur des
 * champs déjà fournis (compter des encodingState, sommer des effectiveMinutes).
 *
 * Le repérage par jour est un simple indicateur d'affichage (complet / partiel / manque),
 * pas une nouvelle définition métier de "encodage correct" — volontairement pas un
 * calendrier complet.
 */
export function ByInstrumentistView({ items, isLoading, isError, isCapped, cappedTotal }: Props) {
  const [expanded, setExpanded] = React.useState<string | null>(null);

  if (isLoading) {
    return (
      <Box sx={{ p: 4, textAlign: "center" }}>
        <CircularProgress size={24} />
      </Box>
    );
  }

  if (isError) {
    return (
      <EmptyState
        title="Impossible de charger la vue « Par instrumentiste »"
        description="Une erreur est survenue lors de la récupération des données. Réessayez dans un instant."
      />
    );
  }

  const groups = groupByInstrumentist(items);

  if (groups.length === 0) {
    return <EmptyState title="Aucune mission assignée à un instrumentiste sur cette période" />;
  }

  return (
    <Stack spacing={2}>
      {isCapped && (
        <Alert severity="info">
          Cette vue est calculée sur les {items.length} premières missions de la période
          (sur {cappedTotal} au total) — affinez les filtres ou réduisez la période pour une vue exhaustive.
        </Alert>
      )}

      {groups.map((group) => {
        const submitted = group.items.filter((i) => i.encodingState === "SUBMITTED").length;
        const validatedOrLocked = group.items.filter((i) => i.encodingState === "VALIDATED" || i.encodingState === "LOCKED").length;
        const inProgress = group.items.filter((i) => i.encodingState === "IN_PROGRESS").length;
        const toEncode = group.items.filter((i) => i.encodingState === "TO_ENCODE").length;
        const totalEffectiveMinutes = group.items
          .filter((i) => i.hours.hasRealHours)
          .reduce((sum, i) => sum + i.hours.effectiveMinutes, 0);
        const days = groupByDay(group.items);
        const key = String(group.id);

        return (
          <Paper key={group.id} variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
            <Typography variant="subtitle1" fontWeight={700}>{group.name}</Typography>
            <Stack direction="row" spacing={3} flexWrap="wrap" useFlexGap sx={{ mt: 1 }}>
              <Metric label="Missions" value={group.items.length} />
              <Metric label="Soumises/Validées" value={submitted + validatedOrLocked} />
              <Metric label="En cours" value={inProgress} />
              <Metric label="À encoder" value={toEncode} />
              <Metric label="Temps effectif" value={formatMinutes(totalEffectiveMinutes)} />
            </Stack>

            <Stack direction="row" spacing={0.5} sx={{ mt: 1.5, flexWrap: "wrap" }} useFlexGap>
              {days.map((day) => (
                <Tooltip key={day.date} title={`${day.label} — ${day.items.length} mission(s), ${dayIndicatorLabel(day.indicator)}`}>
                  <Box
                    component="button"
                    type="button"
                    onClick={() => setExpanded(expanded === `${key}-${day.date}` ? null : `${key}-${day.date}`)}
                    sx={{
                      width: 28, height: 28, borderRadius: 1, border: "1px solid",
                      borderColor: "divider", cursor: "pointer", fontSize: 11, fontFamily: "inherit",
                      bgcolor: dayIndicatorColor(day.indicator), color: day.indicator ? "common.white" : "text.secondary",
                    }}
                  >
                    {day.dayNumber}
                  </Box>
                </Tooltip>
              ))}
            </Stack>

            {days.map((day) => (
              expanded === `${key}-${day.date}` && (
                <Box key={day.date} sx={{ mt: 1.5 }}>
                  <EncodingTrackingTable
                    items={day.items}
                    total={day.items.length}
                    page={1}
                    limit={day.items.length}
                    isLoading={false}
                    isError={false}
                    onPageChange={() => {}}
                    emptyTitle="Aucune mission ce jour-là"
                  />
                </Box>
              )
            ))}
          </Paper>
        );
      })}
    </Stack>
  );
}

function Metric({ label, value }: { label: string; value: number | string }) {
  return (
    <Box>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Typography variant="body1" fontWeight={600}>{value}</Typography>
    </Box>
  );
}

function groupByInstrumentist(items: EncodingTrackingItem[]): InstrumentistGroup[] {
  const map = new Map<number, InstrumentistGroup>();
  for (const item of items) {
    if (!item.instrumentist) continue;
    const existing = map.get(item.instrumentist.id);
    if (existing) {
      existing.items.push(item);
    } else {
      map.set(item.instrumentist.id, { id: item.instrumentist.id, name: item.instrumentist.name ?? "—", items: [item] });
    }
  }
  return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
}

interface DayGroup {
  date: string;
  dayNumber: string;
  label: string;
  items: EncodingTrackingItem[];
  indicator: DayIndicator | null;
}

function groupByDay(items: EncodingTrackingItem[]): DayGroup[] {
  const map = new Map<string, EncodingTrackingItem[]>();
  for (const item of items) {
    if (!item.startAt) continue;
    const date = item.startAt.slice(0, 10);
    const list = map.get(date);
    if (list) list.push(item); else map.set(date, [item]);
  }

  return Array.from(map.entries())
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([date, dayItems]) => {
      const hasMissing = dayItems.some((i) => i.encodingState === "TO_ENCODE" || i.encoding.isStale);
      const allEncoded = dayItems.every((i) => ["SUBMITTED", "VALIDATED", "LOCKED"].includes(i.encodingState));
      const indicator: DayIndicator = hasMissing ? "missing" : allEncoded ? "complete" : "partial";
      const d = parseDateOnly(date);
      return {
        date,
        dayNumber: String(d.getDate()),
        label: d.toLocaleDateString("fr-BE", { day: "numeric", month: "short" }),
        items: dayItems,
        indicator,
      };
    });
}

function dayIndicatorColor(indicator: DayIndicator | null): string {
  switch (indicator) {
    case "complete": return "success.main";
    case "partial": return "warning.main";
    case "missing": return "error.main";
    default: return "transparent";
  }
}

function dayIndicatorLabel(indicator: DayIndicator | null): string {
  switch (indicator) {
    case "complete": return "complet";
    case "partial": return "partiel";
    case "missing": return "manque";
    default: return "—";
  }
}

export default ByInstrumentistView;
