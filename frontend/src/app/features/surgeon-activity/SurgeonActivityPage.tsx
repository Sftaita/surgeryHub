import * as React from "react";
import { useSearchParams } from "react-router-dom";
import { Alert, Box, CircularProgress, IconButton, Stack, Typography } from "@mui/material";
import ChevronLeftIcon from "@mui/icons-material/ChevronLeft";
import ChevronRightIcon from "@mui/icons-material/ChevronRight";

import { useSurgeonActivity } from "./useSurgeonActivity";
import { formatActivityPeriodLabel, shiftActivityPeriod, todayYmd, type PeriodMode } from "./period";
import { PodiumRows } from "./components/PodiumRows";

const GREEN_700 = "#2C7D5F";
const GRAY_500 = "#727E8C";
const GRAY_600 = "#566270";
const GRAY_900 = "#16202B";
const SHADOW_XS = "0 1px 2px rgba(22,32,43,.05)";
const SHADOW_SM = "0 1px 2px rgba(22,32,43,.05), 0 2px 6px rgba(22,32,43,.06)";

/**
 * Toggle Mois/Année (§8) — même recette visuelle que ScopeControl (self-absences) et
 * CoverageFilterControl (SurgeonPlanningPage), gardé local plutôt que généralisé : chaque
 * page de ce type a jusqu'ici son propre contrôle à 2-3 options, jamais un composant partagé.
 */
function PeriodModeControl({ value, onChange }: { value: PeriodMode; onChange: (v: PeriodMode) => void }) {
  const seg = (key: PeriodMode, label: string) => (
    <Box
      component="button" type="button" onClick={() => onChange(key)}
      sx={{
        height: 36, px: "16px", borderRadius: "10px", border: "none", fontFamily: "inherit",
        fontSize: 13.5, fontWeight: value === key ? 700 : 600, cursor: "pointer",
        background: value === key ? "#F1F4F7" : "transparent",
        color: value === key ? GRAY_900 : GRAY_600,
      }}
    >
      {label}
    </Box>
  );
  return (
    <Box sx={{ display: "flex", gap: "4px", background: "#fff", borderRadius: "13px", padding: "4px", boxShadow: SHADOW_SM, width: "max-content" }}>
      {seg("month", "Mois")}
      {seg("year", "Année")}
    </Box>
  );
}

function SummaryRow({ interventionCount, missionCount }: { interventionCount: number; missionCount: number }) {
  return (
    <Stack direction="row" spacing={3.5}>
      <Box>
        <Typography sx={{ fontSize: 26, fontWeight: 800, color: GRAY_900, lineHeight: 1 }}>{interventionCount}</Typography>
        <Typography sx={{ fontSize: 12.5, color: GRAY_500, mt: 0.375 }}>
          intervention{interventionCount > 1 ? "s" : ""}
        </Typography>
      </Box>
      <Box>
        <Typography sx={{ fontSize: 26, fontWeight: 800, color: GRAY_900, lineHeight: 1 }}>{missionCount}</Typography>
        <Typography sx={{ fontSize: 12.5, color: GRAY_500, mt: 0.375 }}>
          mission{missionCount > 1 ? "s" : ""}
        </Typography>
      </Box>
    </Stack>
  );
}

function EmptyState({ isPast }: { isPast: boolean }) {
  return (
    <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, px: 2, py: 3, textAlign: "center" }}>
      <Typography sx={{ fontSize: 14, fontWeight: 700, color: GRAY_900 }}>
        Aucune intervention validée pour cette période
      </Typography>
      {!isPast && (
        <Typography sx={{ fontSize: 13, color: GRAY_500, mt: 0.5 }}>
          Les interventions apparaîtront ici après validation.
        </Typography>
      )}
    </Box>
  );
}

export default function SurgeonActivityPage() {
  const [searchParams, setSearchParams] = useSearchParams();

  const modeParam = searchParams.get("mode");
  const mode: PeriodMode = modeParam === "month" ? "month" : "year";
  const dateParam = searchParams.get("date");
  const date = dateParam && /^\d{4}-\d{2}-\d{2}$/.test(dateParam) ? dateParam : todayYmd();

  const updateParams = React.useCallback(
    (patch: Partial<{ mode: PeriodMode; date: string }>) => {
      const next = new URLSearchParams(searchParams);
      next.set("mode", patch.mode ?? mode);
      next.set("date", patch.date ?? date);
      setSearchParams(next);
    },
    [searchParams, mode, date, setSearchParams],
  );

  const activityQuery = useSurgeonActivity(mode, date);
  const data = activityQuery.data;
  const isCurrentPeriod = date === todayYmd() || formatActivityPeriodLabel(date, mode) === formatActivityPeriodLabel(todayYmd(), mode);

  return (
    <Stack spacing={2}>
      <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GREEN_700 }}>
        ACTIVITÉ
      </Typography>

      <PeriodModeControl value={mode} onChange={(v) => updateParams({ mode: v })} />

      <Stack direction="row" alignItems="center" justifyContent="center" spacing={0.5}>
        <IconButton size="small" onClick={() => updateParams({ date: shiftActivityPeriod(date, mode, -1) })} aria-label="Période précédente">
          <ChevronLeftIcon fontSize="small" />
        </IconButton>
        <Typography sx={{ fontSize: 15, fontWeight: 700, color: GRAY_900, minWidth: 130, textAlign: "center" }} noWrap>
          {formatActivityPeriodLabel(date, mode)}
        </Typography>
        <IconButton size="small" onClick={() => updateParams({ date: shiftActivityPeriod(date, mode, 1) })} aria-label="Période suivante">
          <ChevronRightIcon fontSize="small" />
        </IconButton>
      </Stack>

      {activityQuery.isLoading ? (
        <Box sx={{ display: "flex", justifyContent: "center", py: 6 }}>
          <CircularProgress size={28} />
        </Box>
      ) : activityQuery.isError ? (
        <Alert
          severity="error"
          action={
            <Box
              component="button" type="button" onClick={() => activityQuery.refetch()}
              sx={{ border: "none", background: "none", cursor: "pointer", fontWeight: 700, fontFamily: "inherit", color: "inherit" }}
            >
              Réessayer
            </Box>
          }
        >
          Impossible de charger votre activité.
        </Alert>
      ) : data && data.interventions.length === 0 ? (
        <EmptyState isPast={!isCurrentPeriod} />
      ) : data ? (
        <>
          <SummaryRow interventionCount={data.interventionCount} missionCount={data.missionCount} />

          <PodiumRows interventions={data.interventions} />

          <Stack spacing={1.25}>
            <Typography sx={{ fontSize: 12, fontWeight: 800, letterSpacing: "0.07em", color: GRAY_500 }}>
              TOUTES LES INTERVENTIONS
            </Typography>
            <Box sx={{ background: "#fff", borderRadius: "18px", boxShadow: SHADOW_XS, overflow: "hidden" }}>
              {data.interventions.map((it, idx) => (
                <Stack
                  key={it.interventionTypeId ?? `legacy:${it.label}`}
                  direction="row" alignItems="center" justifyContent="space-between"
                  sx={{ px: 2, py: 1.5, borderTop: idx > 0 ? "1px solid" : "none", borderColor: "grey.100" }}
                >
                  <Typography sx={{ fontSize: 14, color: GRAY_900, pr: 1.5, minWidth: 0 }} noWrap title={it.label}>
                    {it.label}
                  </Typography>
                  <Typography sx={{ fontSize: 14, fontWeight: 700, color: GRAY_900, flexShrink: 0 }}>
                    {it.count}
                  </Typography>
                </Stack>
              ))}
            </Box>
          </Stack>
        </>
      ) : null}
    </Stack>
  );
}
