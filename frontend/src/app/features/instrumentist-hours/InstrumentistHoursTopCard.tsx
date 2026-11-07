import * as React from "react";
import { Box, Button, Paper, Skeleton, Stack, Typography, useMediaQuery } from "@mui/material";
import ArrowForwardIcon from "@mui/icons-material/ArrowForward";
import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import { currentMonth, duration, monthName, monthShort, shiftMonth } from "../hours-shared/format";
import { fetchInstrumentistHours } from "./api/instrumentistHours.api";
import type { InstrumentistHoursList, InstrumentistHoursRow } from "./api/instrumentistHours.types";
import { averagePeriodNote, averagePeriodRange, averageReason, fullName, previousMonthHeading, previousMonthReason, validatedCost } from "./format";
import { Delta, HOURS_COLOR, HoursMonthNav, MissingRate, ProvisionalChip } from "./components/HoursUi";

const DESKTOP_COLS = "22px minmax(110px, 160px) minmax(80px, 1fr) 96px 64px 84px";

/**
 * D-180 — carte « Top 5 des instrumentistes » (Dashboard › Classements, maquettes 1a–1c).
 * Classement par heures validées décroissantes, calculé par le serveur (limit=5, tous sites).
 * Seul « En savoir plus » navigue : la navigation mensuelle reste utilisable dans la carte.
 */
export function InstrumentistHoursTopCard() {
  const navigate = useNavigate();
  const wide = useMediaQuery("(min-width:900px)");
  const thisMonth = React.useMemo(() => currentMonth(), []);
  const [month, setMonth] = React.useState(thisMonth);
  const isCurrentMonth = month === thisMonth;

  const query = useQuery({
    queryKey: ["instrumentist-hours", "top5", month],
    queryFn: () => fetchInstrumentistHours({ month, limit: 5 }),
  });
  // Jamais le classement d'un autre mois : chargement explicite tant que la réponse ne
  // correspond pas au mois affiché.
  const data = query.data && query.data.month === month ? query.data : null;

  return (
    <Paper component="article" variant="outlined" aria-labelledby="hours-top5-title" sx={{ p: "18px 20px 14px", borderRadius: 2, display: "flex", flexDirection: "column", gap: 1.75, gridColumn: { md: "span 2" } }}>
      <Stack direction={wide ? "row" : "column"} justifyContent="space-between" alignItems={wide ? "flex-start" : "stretch"} gap={1.25}>
        <Box>
          <Stack direction="row" alignItems="center" gap={1.25} flexWrap="wrap">
            <Typography id="hours-top5-title" component="h3" sx={{ fontSize: 15, fontWeight: 700, color: HOURS_COLOR.gray900 }}>Top 5 des instrumentistes</Typography>
            {data?.provisional && data.asOfDay !== null && <ProvisionalChip asOfDay={data.asOfDay} monthShortLabel={monthShort(month)} />}
          </Stack>
          <Typography sx={{ fontSize: 12.5, color: HOURS_COLOR.gray600, mt: 0.25 }}>Heures validées · tous les sites</Typography>
        </Box>
        <Box sx={{ alignSelf: wide ? "auto" : "center" }}>
          <HoursMonthNav month={month} isCurrentMonth={isCurrentMonth} size={wide ? "small" : "large"}
            onPrev={() => setMonth((m) => shiftMonth(m, -1))} onNext={() => !isCurrentMonth && setMonth((m) => shiftMonth(m, 1))} />
        </Box>
      </Stack>

      {query.isError && !data ? (
        <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ p: 2, borderRadius: 2, bgcolor: "#FDEEEE" }}>
          <Typography sx={{ fontSize: 13.5, fontWeight: 600, color: "#C62F36" }}>Impossible de charger le classement.</Typography>
          <Button size="small" onClick={() => void query.refetch()} sx={{ color: "#C62F36" }}>Réessayer</Button>
        </Stack>
      ) : !data ? (
        <Stack spacing={1.25} aria-busy="true" data-testid="hours-top5-loading">
          {[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} variant="rounded" height={wide ? 28 : 56} />)}
        </Stack>
      ) : data.items.length === 0 ? (
        <EmptyRanking data={data} isCurrentMonth={isCurrentMonth} onCurrent={() => setMonth(thisMonth)} />
      ) : (
        <Box>
          {wide && (
            <Box aria-hidden sx={{ display: "grid", gridTemplateColumns: DESKTOP_COLS, columnGap: "14px", px: "2px", pb: 0.75, fontSize: 11, fontWeight: 700, letterSpacing: ".06em", textTransform: "uppercase", color: HOURS_COLOR.gray600, whiteSpace: "nowrap" }}>
              <span>#</span><span>Instrumentiste</span><span>Heures validées</span><span />
              <Box component="span" sx={{ textAlign: "right" }}>{previousMonthHeading(data.reference.previousMonth, data.asOfDay)}</Box>
              <Box component="span" sx={{ textAlign: "right" }}>vs moy. 6 m</Box>
            </Box>
          )}
          <Box component="ol" sx={{ m: 0, p: 0, listStyle: "none" }}>
            {data.items.map((row, i) => (
              <TopRow key={row.instrumentist.id} row={row} rank={i + 1} wide={wide} previousMonth={data.reference.previousMonth} asOfDay={data.asOfDay} />
            ))}
          </Box>
        </Box>
      )}

      <Stack direction={wide ? "row" : "column"} justifyContent="space-between" alignItems={wide ? "center" : "flex-start"} gap={wide ? 1.5 : 0.5} sx={{ borderTop: `1px solid ${HOURS_COLOR.gray100}`, pt: 1.25 }}>
        <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600 }}>
          Évolution des heures validées · moy. 6 m = moyenne personnelle des six mois précédents · ≈ coût estimé des prestations validées
        </Typography>
        <Button
          endIcon={<ArrowForwardIcon />}
          onClick={() => navigate(`/app/m/billing/hours?month=${month}`)}
          sx={{ color: HOURS_COLOR.green800, fontWeight: 700, whiteSpace: "nowrap", minHeight: wide ? undefined : 44, px: 0.5 }}
        >
          En savoir plus
        </Button>
      </Stack>
    </Paper>
  );
}

function TopRow({ row, rank, wide, previousMonth, asOfDay }: { row: InstrumentistHoursRow; rank: number; wide: boolean; previousMonth: string; asOfDay: number | null }) {
  const cost = validatedCost(row);
  const prev = row.comparison.previousMonth;
  const avg = row.comparison.personalAverage;
  const prevRef = asOfDay === null ? monthName(previousMonth) : `la même période de ${monthName(previousMonth)}`;
  const avgNote = averagePeriodNote(avg);
  const avgRef = `la moyenne personnelle${averagePeriodRange(avg) ? " (" + averagePeriodRange(avg) + ")" : ""}`;
  const name = fullName(row.instrumentist);

  const bar = (
    <Box aria-hidden sx={{ height: 8, borderRadius: 999, bgcolor: HOURS_COLOR.gray100, overflow: "hidden" }}>
      <Box sx={{ height: "100%", width: `${Math.round(row.ratio * 1000) / 10}%`, borderRadius: 999, bgcolor: HOURS_COLOR.green500 }} />
    </Box>
  );
  const numbers = (
    <Box sx={{ textAlign: "right", display: "flex", flexDirection: "column", alignItems: "flex-end" }}>
      <Typography sx={{ fontSize: 15, fontWeight: 800, color: HOURS_COLOR.gray900, fontVariantNumeric: "tabular-nums", lineHeight: 1.25 }}>{duration(row.validatedMinutes)}</Typography>
      {cost.missing ? <MissingRate /> : <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600, fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap" }}>{cost.text}</Typography>}
    </Box>
  );
  const prevDelta = <Delta pct={prev.pct} reference={prevRef} reason={previousMonthReason(prev, previousMonth)} size={wide ? 13 : 12.5} />;
  const avgDelta = <Delta pct={avg.pct} reference={avgRef} reason={averageReason(avg)} size={wide ? 13 : 12.5} />;

  if (wide) {
    return (
      <Box component="li" sx={{ display: "grid", gridTemplateColumns: DESKTOP_COLS, columnGap: "14px", alignItems: "center", py: "9px", px: "2px", borderTop: `1px solid ${HOURS_COLOR.gray100}` }}>
        <Typography sx={{ fontSize: 12.5, fontWeight: 700, color: HOURS_COLOR.gray600 }}>{rank}</Typography>
        <Typography noWrap sx={{ fontSize: 14, fontWeight: 600, color: HOURS_COLOR.gray900 }}>{name}</Typography>
        {bar}
        {numbers}
        <Box sx={{ textAlign: "right" }}>{prevDelta}</Box>
        <Box sx={{ textAlign: "right", display: "flex", flexDirection: "column", alignItems: "flex-end" }}>
          {avgDelta}
          {avgNote && <Typography sx={{ fontSize: 11, color: HOURS_COLOR.gray600 }}>{avgNote}</Typography>}
        </Box>
      </Box>
    );
  }
  return (
    <Box component="li" sx={{ display: "grid", gridTemplateColumns: "18px minmax(0, 1fr) auto", columnGap: "10px", rowGap: "6px", alignItems: "center", py: 1.25, borderTop: `1px solid ${HOURS_COLOR.gray100}` }}>
      <Typography sx={{ fontSize: 12.5, fontWeight: 700, color: HOURS_COLOR.gray600 }}>{rank}</Typography>
      <Typography noWrap sx={{ fontSize: 14, fontWeight: 600, color: HOURS_COLOR.gray900 }}>{name}</Typography>
      <Box sx={{ gridRow: "span 2" }}>{numbers}</Box>
      <span />
      {bar}
      <Box sx={{ gridColumn: "2 / 4", display: "flex", flexWrap: "wrap", columnGap: 1.75, rowGap: 0.5, fontSize: 12, color: HOURS_COLOR.gray600 }}>
        <Box component="span" sx={{ display: "inline-flex", alignItems: "center", gap: "5px", whiteSpace: "nowrap" }}>{prevDelta}{previousMonthHeading(previousMonth, asOfDay)}</Box>
        <Box component="span" sx={{ display: "inline-flex", alignItems: "center", gap: "5px", whiteSpace: "nowrap" }}>{avgDelta}{avgNote ? `vs moy. ${avg.monthsCounted} m` : "vs moy. 6 m"}</Box>
      </Box>
    </Box>
  );
}

function EmptyRanking({ data, isCurrentMonth, onCurrent }: { data: InstrumentistHoursList; isCurrentMonth: boolean; onCurrent: () => void }) {
  const pending = data.totals.pendingMinutes;
  return (
    <Stack alignItems="center" textAlign="center" spacing={0.75} sx={{ py: 4, px: 2, borderTop: `1px solid ${HOURS_COLOR.gray100}` }}>
      <Typography sx={{ fontSize: 14, fontWeight: 700, color: HOURS_COLOR.gray900 }}>Aucune heure validée en {monthName(data.month)} {data.month.slice(0, 4)}</Typography>
      <Typography sx={{ fontSize: 13, color: HOURS_COLOR.gray600 }}>
        {pending > 0 ? `${duration(pending)} encodées en attente de validation.` : "Le classement se remplit dès que des prestations du mois sont validées."}
      </Typography>
      {!isCurrentMonth && <Button onClick={onCurrent} sx={{ color: HOURS_COLOR.green800, fontWeight: 600 }}>Revenir au mois en cours</Button>}
    </Stack>
  );
}
