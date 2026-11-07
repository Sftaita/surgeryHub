import * as React from "react";
import {
  Box, Button, FormControl, InputAdornment, InputLabel, MenuItem, Paper, Select, Skeleton, Stack, Table, TableBody, TableCell,
  TableContainer, TableFooter, TableHead, TableRow, TableSortLabel, TextField, Typography, useMediaQuery,
} from "@mui/material";
import ScheduleOutlinedIcon from "@mui/icons-material/ScheduleOutlined";
import SearchIcon from "@mui/icons-material/Search";
import { useQuery } from "@tanstack/react-query";
import { useSearchParams } from "react-router-dom";
import { PageHeader } from "../../../ui/PageHeader";
import { currentMonth, duration, monthLabel, monthName, monthShort, prestationCount, shiftMonth } from "../../../features/hours-shared/format";
import { fetchInstrumentistHours } from "../../../features/instrumentist-hours/api/instrumentistHours.api";
import type { HoursSort, InstrumentistHoursList, InstrumentistHoursRow, SortDirection } from "../../../features/instrumentist-hours/api/instrumentistHours.types";
import {
  approx, averagePeriodNote, averageReason, encodedCost, fullName, initials, previousMonthHeading, previousMonthReason, unratedNote, validatedCost,
} from "../../../features/instrumentist-hours/format";
import { Delta, HOURS_COLOR, HoursMonthNav, MissingRate } from "../../../features/instrumentist-hours/components/HoursUi";
import { InstrumentistHoursDrawer } from "../../../features/instrumentist-hours/InstrumentistHoursDrawer";

const MONTH_RE = /^\d{4}-(0[1-9]|1[0-2])$/;
const SORTS: HoursSort[] = ["name", "encodedMinutes", "validatedMinutes", "encodedAmount", "validatedAmount", "previousMonthPct", "personalAveragePct"];
const SORT_LABEL: Record<HoursSort, string> = {
  name: "Nom",
  encodedMinutes: "Heures encodées",
  validatedMinutes: "Heures validées",
  encodedAmount: "Coût estimé encodé",
  validatedAmount: "Coût estimé validé",
  previousMonthPct: "Évolution vs mois précédent",
  personalAveragePct: "Évolution vs moyenne personnelle",
};

/**
 * D-180 — Facturation › Heures instrumentistes (maquettes 2a–2c). Le serveur calcule tout pour
 * le périmètre demandé (mois, site, recherche, tri) : indicateurs, lignes, total et évolutions.
 * Mois, site, recherche, tri et tiroir ouvert sont dans l'URL (lien partageable, Retour ferme
 * le tiroir).
 */
export default function InstrumentistHoursPage() {
  const wide = useMediaQuery("(min-width:900px)");
  const [params, setParams] = useSearchParams();
  const thisMonth = React.useMemo(() => currentMonth(), []);

  const rawMonth = params.get("month") ?? "";
  const month = MONTH_RE.test(rawMonth) && rawMonth <= thisMonth ? rawMonth : thisMonth;
  const siteId = Number(params.get("siteId")) > 0 ? Number(params.get("siteId")) : null;
  const q = params.get("q") ?? "";
  const [sortKey, sortDir] = (params.get("sort") ?? "validatedMinutes:desc").split(":") as [HoursSort, SortDirection];
  const sort: HoursSort = SORTS.includes(sortKey) ? sortKey : "validatedMinutes";
  const direction: SortDirection = sortDir === "asc" ? "asc" : "desc";
  const instrumentistId = Number(params.get("instrumentist")) > 0 ? Number(params.get("instrumentist")) : null;
  const isCurrentMonth = month === thisMonth;

  const update = (patch: Record<string, string | null>, push = false) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) {
      if (v === null || v === "") next.delete(k);
      else next.set(k, v);
    }
    setParams(next, { replace: !push });
  };

  // Recherche : saisie immédiate, requête serveur après 300 ms.
  const [search, setSearch] = React.useState(q);
  React.useEffect(() => setSearch(q), [q]);
  React.useEffect(() => {
    if (search === q) return;
    const t = window.setTimeout(() => update({ q: search.trim() || null }), 300);
    return () => window.clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  // Aucune donnée de remplacement : une autre sélection (mois, site, recherche, tri) ne doit
  // jamais afficher les chiffres de la précédente. La réponse n'est en plus affichée que si
  // le périmètre renvoyé par le serveur est exactement celui demandé, et pas pendant la
  // saisie d'une recherche pas encore appliquée.
  const query = useQuery({
    queryKey: ["instrumentist-hours", "list", month, siteId, q, sort, direction],
    queryFn: () => fetchInstrumentistHours({ month, siteId, q: q || null, sort, direction }),
  });
  const searchPending = search.trim() !== q;
  const data = query.data && !searchPending && isSameScope(query.data, { month, siteId, q, sort, direction }) ? query.data : null;

  // Libellés des sites pour le sélecteur seulement (jamais pour des chiffres) : gardés le
  // temps que la réponse de la nouvelle sélection arrive.
  const knownSites = React.useRef<{ id: number; name: string }[]>([]);
  if (data) knownSites.current = data.sites;
  const sites = data?.sites ?? knownSites.current;
  const siteName = siteId === null ? null : sites.find((s) => s.id === siteId)?.name ?? null;
  const filtered = siteId !== null || q !== "";

  const changeMonth = (m: string) => update({ month: m === thisMonth ? null : m, instrumentist: null });
  const toggleSort = (key: HoursSort) => {
    const dir: SortDirection = sort === key ? (direction === "desc" ? "asc" : "desc") : key === "name" ? "asc" : "desc";
    update({ sort: key === "validatedMinutes" && dir === "desc" ? null : `${key}:${dir}` });
  };

  return (
    <Box sx={{ p: wide ? 3 : 2 }}>
      <PageHeader
        icon={ScheduleOutlinedIcon}
        title="Suivi des heures instrumentistes"
        subtitle="Heures encodées et validées par instrumentiste, coût estimé au tarif personnel de chaque prestation."
        helpTopicId="instrumentist-hours"
      />

      <Stack spacing={3}>
        <Paper variant="outlined" role="search" sx={{ borderRadius: 2, px: 2, py: 1.5, display: "flex", alignItems: "center", gap: 1.5, flexWrap: "wrap" }}>
          <HoursMonthNav month={month} isCurrentMonth={isCurrentMonth} size={wide ? "medium" : "large"}
            onPrev={() => changeMonth(shiftMonth(month, -1))} onNext={() => !isCurrentMonth && changeMonth(shiftMonth(month, 1))} />
          {!isCurrentMonth && <Button onClick={() => changeMonth(thisMonth)} sx={{ color: HOURS_COLOR.green800 }}>Mois en cours</Button>}
          <FormControl size="small" sx={{ minWidth: 220, flex: wide ? "0 0 auto" : "1 1 160px" }}>
            <InputLabel id="hours-site-label" shrink>Site</InputLabel>
            <Select labelId="hours-site-label" label="Site" notched value={siteId === null ? "" : String(siteId)} displayEmpty
              onChange={(e) => update({ siteId: e.target.value === "" ? null : String(e.target.value), instrumentist: null })}
              renderValue={(v) => (v === "" ? "Tous les sites" : sites.find((s) => String(s.id) === v)?.name ?? "Site")}>
              <MenuItem value="">Tous les sites</MenuItem>
              {sites.map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.name}</MenuItem>)}
            </Select>
          </FormControl>
          {!wide && (
            <FormControl size="small" sx={{ flex: "1 1 160px" }}>
              <InputLabel id="hours-sort-label">Trier par</InputLabel>
              <Select labelId="hours-sort-label" label="Trier par" value={`${sort}:${direction}`} onChange={(e) => update({ sort: String(e.target.value) })}>
                {SORTS.flatMap((k) => (["desc", "asc"] as const).map((d) => (
                  <MenuItem key={`${k}:${d}`} value={`${k}:${d}`}>{SORT_LABEL[k]} {d === "desc" ? "↓" : "↑"}</MenuItem>
                )))}
              </Select>
            </FormControl>
          )}
          <TextField size="small" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Rechercher un instrumentiste"
            inputProps={{ "aria-label": "Rechercher un instrumentiste", maxLength: 100 }}
            InputProps={{ startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment> }}
            sx={{ flex: "1 1 240px", minWidth: 200 }} />
          {filtered && <Button onClick={() => { setSearch(""); update({ siteId: null, q: null, instrumentist: null }); }} sx={{ color: HOURS_COLOR.green800 }}>Réinitialiser les filtres</Button>}
          {data && (
            <Typography sx={{ ml: { md: "auto" }, fontSize: 13, color: HOURS_COLOR.gray600 }}>
              {data.totals.instrumentistCount} instrumentiste{data.totals.instrumentistCount > 1 ? "s" : ""} · {siteName ?? "tous les sites"}{q ? ` · « ${q} »` : ""}
            </Typography>
          )}
        </Paper>

        {data?.provisional && data.asOfDay !== null && (
          <Stack direction="row" alignItems="center" gap={1.25} sx={{ px: 2, py: 1.5, borderRadius: 2, bgcolor: HOURS_COLOR.amber50, border: `1px solid ${HOURS_COLOR.amber100}` }}>
            <Box sx={{ width: 7, height: 7, borderRadius: 999, bgcolor: HOURS_COLOR.amber500, flex: "none" }} />
            <Typography sx={{ fontSize: 13.5, color: HOURS_COLOR.amber800 }}>
              <b>{monthLabel(month)} — provisoire au {data.asOfDay} {monthShort(month)}.</b> Évolutions comparées aux {data.asOfDay} premiers jours de {monthName(data.reference.previousMonth)} et des mois de référence.
            </Typography>
          </Stack>
        )}

        {query.isError && !searchPending ? (
          <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ p: 2, borderRadius: 2, bgcolor: "#FDEEEE" }}>
            <Typography sx={{ fontSize: 13.5, fontWeight: 600, color: "#C62F36" }}>Impossible de charger le suivi des heures.</Typography>
            <Button onClick={() => void query.refetch()} sx={{ color: "#C62F36" }}>Réessayer</Button>
          </Stack>
        ) : !data ? (
          <Stack spacing={2} aria-busy="true" data-testid="hours-page-loading">
            <Box sx={{ display: "grid", gridTemplateColumns: wide ? "repeat(4, minmax(0, 1fr))" : "repeat(2, minmax(0, 1fr))", gap: 2 }}>
              {[0, 1, 2, 3].map((i) => <Skeleton key={i} variant="rounded" height={104} />)}
            </Box>
            {[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} variant="rounded" height={52} />)}
          </Stack>
        ) : (
          <Box sx={{ display: "flex", flexDirection: "column", gap: 3 }} aria-busy={query.isFetching}>
            <Kpis data={data} wide={wide} />
            <Content data={data} wide={wide} month={month} siteName={siteName} q={q} isCurrentMonth={isCurrentMonth}
              sort={sort} direction={direction} onSort={toggleSort} onOpen={(id) => update({ instrumentist: String(id) }, true)}
              onReset={() => { setSearch(""); update({ siteId: null, q: null }); }} onCurrentMonth={() => changeMonth(thisMonth)} />
          </Box>
        )}
      </Stack>

      <InstrumentistHoursDrawer instrumentistId={instrumentistId} month={month} siteId={siteId} siteName={siteName} onClose={() => update({ instrumentist: null })} />
    </Box>
  );
}

/** La réponse correspond-elle exactement à la sélection affichée ? */
function isSameScope(data: InstrumentistHoursList, s: { month: string; siteId: number | null; q: string; sort: HoursSort; direction: SortDirection }): boolean {
  return data.month === s.month && data.siteId === s.siteId && (data.q ?? "") === s.q && data.sort === s.sort && data.direction === s.direction;
}

function Kpis({ data, wide }: { data: InstrumentistHoursList; wide: boolean }) {
  const t = data.totals;
  const empty = t.count === 0;
  return (
    <Box sx={{ display: "grid", gridTemplateColumns: wide ? "repeat(4, minmax(0, 1fr))" : "repeat(2, minmax(0, 1fr))", gap: wide ? 2 : 1.25 }}>
      <Kpi label="Heures encodées" value={duration(t.encodedMinutes)} hint={`${prestationCount(t.count)} · en attente + validées`} ghost={empty} />
      <Kpi label="Heures validées" value={duration(t.validatedMinutes)} hint={`${t.validatedPct} % des heures encodées`} ghost={empty} />
      <Kpi label="En attente de validation" value={duration(t.pendingMinutes)} hint={`${t.pendingCount} prestation${t.pendingCount > 1 ? "s soumises" : " soumise"}`} warn={!empty} ghost={empty} />
      <Kpi label="Coût estimé validé" money value={t.validatedAmount ? approx(t.validatedAmount) : "—"} ghost={empty}
        hint={<>{t.encodedAmount ? `encodé ${approx(t.encodedAmount)}` : "aucune prestation valorisée"}{t.unratedInstrumentistCount > 0 && (
          <> · <Box component="span" sx={{ color: HOURS_COLOR.amber800, fontWeight: 600 }}>{t.unratedInstrumentistCount} instrumentiste{t.unratedInstrumentistCount > 1 ? "s" : ""} sans tarif</Box></>
        )}</>} />
    </Box>
  );
}

/** Indicateur au style de StatCard ; un montant reste sur une ligne (« ≈ 20 975,00 € »). */
function Kpi({ label, value, hint, warn, money, ghost }: { label: string; value: string; hint: React.ReactNode; warn?: boolean; money?: boolean; ghost?: boolean }) {
  return (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2, minWidth: 0 }}>
      <Typography variant="caption" color="text.secondary" noWrap component="div">{label}</Typography>
      <Typography sx={{ mt: 0.25, fontSize: money ? { xs: 18, sm: 22, lg: 24 } : { xs: 22, sm: 28, lg: 30 }, lineHeight: money ? { xs: 1.7, sm: 1.45 } : 1.25, fontWeight: 700, whiteSpace: "nowrap", fontVariantNumeric: "tabular-nums",
        color: ghost ? HOURS_COLOR.gray300 : warn ? HOURS_COLOR.amber800 : HOURS_COLOR.gray900 }}>
        {value}
      </Typography>
      <Typography component="div" sx={{ fontSize: 12.5, color: HOURS_COLOR.gray600 }}>{hint}</Typography>
    </Paper>
  );
}

interface ContentProps {
  data: InstrumentistHoursList;
  wide: boolean;
  month: string;
  siteName: string | null;
  q: string;
  isCurrentMonth: boolean;
  sort: HoursSort;
  direction: SortDirection;
  onSort: (k: HoursSort) => void;
  onOpen: (id: number) => void;
  onReset: () => void;
  onCurrentMonth: () => void;
}

function Content(props: ContentProps) {
  const { data, q, siteName } = props;
  if (data.items.length === 0) {
    return (
      <Paper variant="outlined" sx={{ borderRadius: 2, py: 4, px: 2, textAlign: "center" }}>
        <Typography sx={{ fontSize: 14, fontWeight: 700, color: HOURS_COLOR.gray900 }}>
          {q ? `Aucun instrumentiste ne correspond à « ${q} »${siteName ? ` sur ${siteName}` : ""}` : "Aucun instrumentiste pour ce périmètre"}
        </Typography>
        <Button onClick={props.onReset} sx={{ mt: 1, color: HOURS_COLOR.green800 }}>Réinitialiser les filtres</Button>
      </Paper>
    );
  }
  const emptyMonth = data.totals.count === 0 && !q && !siteName;
  return (
    <>
      {emptyMonth && (
        <Paper variant="outlined" sx={{ borderRadius: 2, py: 3, px: 2, textAlign: "center" }}>
          <Typography sx={{ fontSize: 14, fontWeight: 700, color: HOURS_COLOR.gray900 }}>Aucune prestation encodée en {monthName(props.month)} {props.month.slice(0, 4)}</Typography>
          <Typography sx={{ fontSize: 13, color: HOURS_COLOR.gray600 }}>Les instrumentistes actives restent listées à 0h00.</Typography>
          {!props.isCurrentMonth && <Button onClick={props.onCurrentMonth} sx={{ mt: 1, color: HOURS_COLOR.green800 }}>Revenir au mois en cours</Button>}
        </Paper>
      )}
      {props.wide ? <HoursTable {...props} /> : <HoursList {...props} />}
      <Typography sx={{ fontSize: 12.5, color: HOURS_COLOR.gray600, lineHeight: 1.5 }}>
        Heures encodées = en attente de validation + validées · heures validées = missions validées ou clôturées · durée = fin − début réellement encodés (jamais l'horaire planifié).
        Coûts : estimation indicative au tarif personnel en vigueur à chaque prestation (horaire pour le bloc, forfait pour la consultation) — ni facture, ni paiement.
        Évolutions des heures validées sur le même site ; moyenne personnelle des six mois précédents, limitée à l'historique observable (première prestation connue).
      </Typography>
    </>
  );
}

function comparisons({ row, data, size }: { row: InstrumentistHoursRow; data: InstrumentistHoursList; size?: number }) {
  const prev = row.comparison.previousMonth;
  const avg = row.comparison.personalAverage;
  return {
    prev: <Delta pct={prev.pct} reference={monthName(data.reference.previousMonth)} reason={previousMonthReason(prev, data.reference.previousMonth)} size={size} />,
    avg: <Delta pct={avg.pct} reference="la moyenne personnelle" reason={averageReason(avg)} size={size} />,
    avgNote: averagePeriodNote(avg) ?? (avg.reason === "NO_REFERENCE" && avg.observableSince ? `historique depuis ${monthShort(avg.observableSince)}` : null),
  };
}

function HoursTable({ data, sort, direction, onSort, onOpen }: ContentProps) {
  const head = (key: HoursSort, label: string, align: "left" | "right" = "right") => (
    <TableCell align={align} sortDirection={sort === key ? direction : false} sx={{ fontSize: 12, fontWeight: 700, color: HOURS_COLOR.gray600, lineHeight: 1.3, verticalAlign: "bottom" }}>
      <TableSortLabel active={sort === key} direction={sort === key ? direction : key === "name" ? "asc" : "desc"} onClick={() => onSort(key)}>{label}</TableSortLabel>
    </TableCell>
  );
  const t = data.totals;
  return (
    <Paper variant="outlined" sx={{ borderRadius: 2, overflow: "hidden" }}>
      <TableContainer>
        <Table size="small" sx={{ minWidth: 860, "& td, & th": { px: 1.25, py: 1.5 } }}>
          <TableHead>
            <TableRow>
              {head("name", "Instrumentiste", "left")}
              {head("encodedMinutes", "Heures encodées")}
              {head("validatedMinutes", "Heures validées")}
              {head("encodedAmount", "Coût est. encodé")}
              {head("validatedAmount", "Coût est. validé")}
              {head("previousMonthPct", previousMonthHeading(data.reference.previousMonth, data.asOfDay))}
              {head("personalAveragePct", "vs moy. 6 mois")}
            </TableRow>
          </TableHead>
          <TableBody>
            {data.items.map((row) => {
              const c = comparisons({ row, data });
              const enc = encodedCost(row);
              const val = validatedCost(row);
              const note = unratedNote(row);
              return (
                <TableRow key={row.instrumentist.id} hover tabIndex={0} sx={{ cursor: "pointer" }} aria-label={`${fullName(row.instrumentist)}, ${duration(row.validatedMinutes)} validées — ouvrir le détail`}
                  onClick={() => onOpen(row.instrumentist.id)} onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onOpen(row.instrumentist.id); } }}>
                  <TableCell>
                    <Stack direction="row" alignItems="center" gap={1.25}>
                      <Avatar row={row} />
                      <Box sx={{ minWidth: 0 }}>
                        <Stack direction="row" alignItems="center" gap={1}>
                          <Typography sx={{ fontSize: 14, fontWeight: 600, color: HOURS_COLOR.gray900, whiteSpace: "nowrap" }}>{fullName(row.instrumentist)}</Typography>
                          {!row.instrumentist.active && <InactiveChip />}
                        </Stack>
                        <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600 }}>{row.count === 0 ? "aucune prestation" : prestationCount(row.count)}</Typography>
                      </Box>
                    </Stack>
                  </TableCell>
                  <TableCell align="right">
                    <Typography sx={{ fontSize: 14, fontVariantNumeric: "tabular-nums" }}>{duration(row.encodedMinutes)}</Typography>
                    {row.pendingMinutes > 0 && <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600, whiteSpace: "nowrap" }}>{duration(row.pendingMinutes)} en attente</Typography>}
                  </TableCell>
                  <TableCell align="right"><Typography sx={{ fontSize: 14, fontWeight: 800, color: HOURS_COLOR.gray900, fontVariantNumeric: "tabular-nums" }}>{duration(row.validatedMinutes)}</Typography></TableCell>
                  <TableCell align="right">{enc.missing ? <MissingRate /> : <Typography sx={{ fontSize: 14, fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap" }}>{enc.text}</Typography>}</TableCell>
                  <TableCell align="right">
                    {val.missing ? <MissingRate /> : <Typography sx={{ fontSize: 14, fontWeight: 700, color: HOURS_COLOR.gray900, fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap" }}>{val.text}</Typography>}
                    {row.estimate && row.estimate.consultationFeeCount > 0 && <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600 }}>dont {row.estimate.consultationFeeCount} forfait{row.estimate.consultationFeeCount > 1 ? "s" : ""} consultation</Typography>}
                    {note && <Typography sx={{ fontSize: 12, color: HOURS_COLOR.amber800, fontWeight: 600, whiteSpace: "nowrap" }}>{note}</Typography>}
                  </TableCell>
                  <TableCell align="right">{c.prev}</TableCell>
                  <TableCell align="right">
                    {c.avg}
                    {c.avgNote && <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600 }}>{c.avgNote}</Typography>}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
          <TableFooter>
            <TableRow sx={{ bgcolor: HOURS_COLOR.gray50, "& td": { fontSize: 14, fontWeight: 700, color: HOURS_COLOR.gray900, borderBottom: "none" } }}>
              <TableCell>Total · {t.instrumentistCount} instrumentiste{t.instrumentistCount > 1 ? "s" : ""} · {prestationCount(t.count)}</TableCell>
              <TableCell align="right">
                {duration(t.encodedMinutes)}
                {t.pendingMinutes > 0 && <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600, fontWeight: 500 }}>{duration(t.pendingMinutes)} en attente</Typography>}
              </TableCell>
              <TableCell align="right">{duration(t.validatedMinutes)}</TableCell>
              <TableCell align="right" sx={{ whiteSpace: "nowrap" }}>{t.encodedAmount ? approx(t.encodedAmount) : "—"}</TableCell>
              <TableCell align="right" sx={{ whiteSpace: "nowrap" }}>{t.validatedAmount ? approx(t.validatedAmount) : "—"}</TableCell>
              <TableCell />
              <TableCell />
            </TableRow>
          </TableFooter>
        </Table>
      </TableContainer>
    </Paper>
  );
}

function HoursList({ data, onOpen }: ContentProps) {
  const t = data.totals;
  return (
    <>
      <Paper variant="outlined" component="ul" sx={{ borderRadius: "20px", overflow: "hidden", m: 0, p: 0, listStyle: "none" }}>
        {data.items.map((row, i) => {
          const c = comparisons({ row, data, size: 12.5 });
          const val = validatedCost(row);
          return (
            <li key={row.instrumentist.id}>
              <Box component="button" type="button" onClick={() => onOpen(row.instrumentist.id)} aria-label={`${fullName(row.instrumentist)}, ${duration(row.validatedMinutes)} validées — ouvrir le détail`}
                sx={{ width: "100%", border: "none", borderTop: i === 0 ? "none" : `1px solid ${HOURS_COLOR.gray100}`, background: "#fff", font: "inherit", textAlign: "left", cursor: "pointer",
                  display: "grid", gridTemplateColumns: "36px minmax(0, 1fr) auto", columnGap: "10px", rowGap: "8px", alignItems: "center", px: 2, py: 1.75 }}>
                <Avatar row={row} />
                <Box sx={{ minWidth: 0 }}>
                  <Stack direction="row" alignItems="center" gap={0.75}>
                    <Typography noWrap sx={{ fontSize: 14, fontWeight: 600, color: HOURS_COLOR.gray900 }}>{fullName(row.instrumentist)}</Typography>
                    {!row.instrumentist.active && <InactiveChip />}
                  </Stack>
                  <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600 }}>
                    {duration(row.encodedMinutes)} encodées · {row.count === 0 ? "aucune prestation" : row.pendingMinutes > 0 ? `${duration(row.pendingMinutes)} en attente` : "tout validé"}
                  </Typography>
                </Box>
                <Box sx={{ textAlign: "right" }}>
                  <Typography sx={{ fontSize: 15, fontWeight: 800, color: HOURS_COLOR.gray900, fontVariantNumeric: "tabular-nums" }}>{duration(row.validatedMinutes)}</Typography>
                  {val.missing ? <MissingRate /> : <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600, whiteSpace: "nowrap" }}>{val.text}</Typography>}
                </Box>
                <Box sx={{ gridColumn: "2 / 4", display: "flex", flexWrap: "wrap", columnGap: 1.5, rowGap: 0.5, fontSize: 12.5, color: HOURS_COLOR.gray600 }}>
                  <Box component="span" sx={{ display: "inline-flex", alignItems: "center", gap: "5px", whiteSpace: "nowrap" }}>{c.prev}{previousMonthHeading(data.reference.previousMonth, data.asOfDay)}</Box>
                  <Box component="span" sx={{ display: "inline-flex", alignItems: "center", gap: "5px", whiteSpace: "nowrap" }}>{c.avg}{c.avgNote ? `vs moy. · ${c.avgNote}` : "vs moy. 6 m"}</Box>
                </Box>
              </Box>
            </li>
          );
        })}
      </Paper>
      <Paper variant="outlined" sx={{ borderRadius: "20px", px: 2, py: 1.75, bgcolor: HOURS_COLOR.gray75 }}>
        <Stack direction="row" justifyContent="space-between" sx={{ fontSize: 13.5, fontWeight: 700 }}>
          <span>Total · {t.instrumentistCount} instrumentiste{t.instrumentistCount > 1 ? "s" : ""}</span>
          <span>{duration(t.validatedMinutes)} validées</span>
        </Stack>
        <Stack direction="row" justifyContent="space-between" sx={{ fontSize: 12.5, color: HOURS_COLOR.gray600 }}>
          <span>{duration(t.encodedMinutes)} encodées · {duration(t.pendingMinutes)} en attente</span>
          <span>{t.validatedAmount ? approx(t.validatedAmount) : "—"}</span>
        </Stack>
      </Paper>
    </>
  );
}

function Avatar({ row }: { row: InstrumentistHoursRow }) {
  return (
    <Box aria-hidden sx={{ width: 32, height: 32, borderRadius: 999, bgcolor: HOURS_COLOR.green100, color: HOURS_COLOR.green900, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 12, fontWeight: 700, flex: "none" }}>
      {initials(row.instrumentist)}
    </Box>
  );
}

function InactiveChip() {
  return (
    <Box component="span" sx={{ display: "inline-flex", alignItems: "center", height: 20, px: 1, borderRadius: 999, bgcolor: HOURS_COLOR.gray75, color: HOURS_COLOR.gray700, fontSize: 11, fontWeight: 600 }}>
      Inactive
    </Box>
  );
}
