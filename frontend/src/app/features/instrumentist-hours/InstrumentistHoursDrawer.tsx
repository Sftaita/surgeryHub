import * as React from "react";
import { Box, Button, Drawer, IconButton, Skeleton, Stack, Typography, useMediaQuery } from "@mui/material";
import CloseIcon from "@mui/icons-material/Close";
import ArrowBackIcon from "@mui/icons-material/ArrowBack";
import { useQuery } from "@tanstack/react-query";
import "../hours-shared/hours-shared.css";
import { dayOfMonth, dowShort, duration, euro, hourlyRate, monthLabel, monthName, monthShort, prestationCount, shiftMonth } from "../hours-shared/format";
import { isFlatFee, isPresenceShare, StatusLabel, Track, TrackHeads } from "../hours-shared/HoursTrack";
import type { MyHoursItem } from "../my-hours/api/myHours.types";
import { ManagerMissionDrawer } from "../missions/drawer/ManagerMissionDrawer";
import { fetchInstrumentistHoursDetail } from "./api/instrumentistHours.api";
import type { InstrumentistHoursDetail } from "./api/instrumentistHours.types";
import { approx, averagePeriodNote, averagePeriodRange, averageReason, fullName, initials, previousMonthReason } from "./format";
import { Delta, HOURS_COLOR, ProvisionalChip } from "./components/HoursUi";

interface Props {
  /** null = tiroir fermé. */
  instrumentistId: number | null;
  month: string;
  siteId: number | null;
  siteName: string | null;
  onClose: () => void;
}

/**
 * D-180 — tiroir de détail individuel (maquettes 3a / 3b). Le contenu est le relevé
 * « Mes heures » de l'instrumentiste (même endpoint de calcul, mêmes valeurs) + comparaisons ;
 * une prestation ouvre le tiroir mission manager existant (D-168). Plein écran sous 900 px.
 */
export function InstrumentistHoursDrawer({ instrumentistId, month, siteId, siteName, onClose }: Props) {
  const wide = useMediaQuery("(min-width:900px)");
  const [missionId, setMissionId] = React.useState<number | null>(null);
  const open = instrumentistId !== null;

  const query = useQuery({
    queryKey: ["instrumentist-hours", "detail", instrumentistId, month, siteId],
    queryFn: () => fetchInstrumentistHoursDetail(instrumentistId!, month, siteId),
    enabled: open,
  });
  // Jamais le relevé d'une autre instrumentiste, d'un autre mois ou d'un autre site.
  const data = query.data && query.data.instrumentist.id === instrumentistId && query.data.month === month && query.data.siteId === siteId
    ? query.data : undefined;

  return (
    <>
      <Drawer
        anchor="right"
        open={open}
        onClose={onClose}
        PaperProps={{ sx: { width: wide ? 720 : "100%", maxWidth: "100%" }, role: "dialog", "aria-labelledby": "hours-drawer-name" } as object}
      >
        <Stack direction="row" alignItems="flex-start" gap={1.75} sx={{ px: wide ? 3 : 1, py: wide ? 2.5 : 1, borderBottom: `1px solid ${HOURS_COLOR.gray150}` }}>
          {!wide && <IconButton aria-label="Retour à la liste" onClick={onClose} sx={{ width: 44, height: 44 }}><ArrowBackIcon /></IconButton>}
          {wide && data && (
            <Box aria-hidden sx={{ width: 44, height: 44, borderRadius: 999, bgcolor: HOURS_COLOR.green100, color: HOURS_COLOR.green900, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 15, fontWeight: 700, flex: "none" }}>
              {initials(data.instrumentist)}
            </Box>
          )}
          <Box sx={{ minWidth: 0, flex: 1, pt: wide ? 0 : 1 }}>
            <Typography id="hours-drawer-name" component="h2" noWrap sx={{ fontSize: wide ? 20 : 16, fontWeight: 800, color: HOURS_COLOR.gray900 }}>
              {data ? fullName(data.instrumentist) : "Détail des heures"}
            </Typography>
            <Typography sx={{ fontSize: 13, color: HOURS_COLOR.gray600 }}>
              {monthLabel(month)} · {siteName ?? "tous les sites"}{data ? ` · ${prestationCount(data.summary.count)}` : ""}
              {data && !data.instrumentist.active ? " · Inactive" : ""}
            </Typography>
          </Box>
          {wide && <IconButton aria-label="Fermer le détail" onClick={onClose}><CloseIcon /></IconButton>}
        </Stack>

        <Box sx={{ p: wide ? 3 : 2, display: "flex", flexDirection: "column", gap: 2.5, overflowY: "auto", flex: 1, "& > *": { flexShrink: 0 } }}>
          {query.isError ? (
            <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ p: 2, borderRadius: 2, bgcolor: "#FDEEEE" }}>
              <Typography sx={{ fontSize: 13.5, fontWeight: 600, color: "#C62F36" }}>Impossible de charger le détail.</Typography>
              <Button size="small" onClick={() => void query.refetch()} sx={{ color: "#C62F36" }}>Réessayer</Button>
            </Stack>
          ) : !data ? (
            <Stack spacing={1.5} aria-busy="true">{[0, 1, 2, 3].map((i) => <Skeleton key={i} variant="rounded" height={64} />)}</Stack>
          ) : (
            <DetailBody data={data} onOpenMission={setMissionId} wide={wide} />
          )}
        </Box>
      </Drawer>
      <ManagerMissionDrawer missionId={missionId} onClose={() => setMissionId(null)} />
    </>
  );
}

function DetailBody({ data, onOpenMission, wide }: { data: InstrumentistHoursDetail; onOpenMission: (id: number) => void; wide: boolean }) {
  const s = data.summary;
  const e = data.estimate;
  const previousMonth = shiftMonth(data.month, -1);
  const prev = data.comparison.previousMonth;
  const avg = data.comparison.personalAverage;
  const prevLabel = data.asOfDay === null ? `vs ${monthName(previousMonth)}` : `vs 1–${data.asOfDay} ${monthShort(previousMonth)}`;
  const avgRange = averagePeriodRange(avg);
  const avgNote = averagePeriodNote(avg);

  return (
    <>
      {data.provisional && data.asOfDay !== null && (
        <Box><ProvisionalChip asOfDay={data.asOfDay} monthShortLabel={monthShort(data.month)} /></Box>
      )}

      <Box sx={{ display: "grid", gridTemplateColumns: "repeat(2, minmax(0, 1fr))", gap: 1.5 }}>
        <Tile label="Encodées" value={duration(s.encodedMinutes)} hint={prestationCount(s.count)} />
        <Tile label="Validées" value={duration(s.validatedMinutes)} hint={`${s.validatedPct} % des encodées`} />
        <Tile label="En attente" value={duration(s.pendingMinutes)} warn />
        <Tile label="Coût estimé validé" value={e ? approx(e.validatedAmount) : "—"} hint={e ? `encodé ${approx(e.encodedAmount)}` : undefined} />
      </Box>

      <Box sx={{ display: "grid", gridTemplateColumns: wide ? "repeat(2, minmax(0, 1fr))" : "minmax(0, 1fr)", gap: 1.5 }}>
        <Comparison
          label={prevLabel}
          reference={prev.available ? `${duration(prev.validatedMinutes)} validées` : previousMonthReason(prev, previousMonth) ?? ""}
          delta={<Delta pct={prev.pct} reference={prevLabel.replace(/^vs /, "")} reason={previousMonthReason(prev, previousMonth)} size={15} />}
        />
        <Comparison
          label="vs moyenne personnelle"
          reference={avg.available
            ? `${duration(avg.validatedMinutes)} / mois${avgRange ? " · " + avgRange : ""}${avgNote ? " · " + avgNote : ""}`
            : averageReason(avg) ?? ""}
          delta={<Delta pct={avg.pct} reference="la moyenne personnelle" reason={averageReason(avg)} size={15} />}
        />
      </Box>
      {avg.observableSince && (
        <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600, mt: -1.5 }}>
          Historique observable depuis {monthName(avg.observableSince)} {avg.observableSince.slice(0, 4)} (première prestation connue dans SurgicalHub, pas une date d'entrée en activité).
        </Typography>
      )}

      {e && (
        <Box sx={{ border: `1px solid ${HOURS_COLOR.gray150}`, borderRadius: "16px", overflow: "hidden" }}>
          <Typography sx={{ px: 1.75, py: 1.25, fontSize: 11, fontWeight: 800, letterSpacing: ".08em", color: HOURS_COLOR.gray600, bgcolor: HOURS_COLOR.gray50 }}>
            ESTIMATION INDICATIVE · TARIFS PERSONNELS PAR SITE
          </Typography>
          {e.lines.map((l) => (
            <Stack key={l.siteName + l.rateType + l.unitAmount} direction="row" justifyContent="space-between" alignItems="center" gap={1.5} sx={{ px: 1.75, py: 1.25, borderTop: `1px solid ${HOURS_COLOR.gray100}` }}>
              <Box sx={{ minWidth: 0 }}>
                <Stack direction="row" alignItems="center" gap={1} flexWrap="wrap">
                  <Typography sx={{ fontSize: 13.5, fontWeight: 600, color: HOURS_COLOR.gray900 }}>{l.siteName}</Typography>
                  {l.rateType === "CONSULTATION_FEE" && <FeeTag>Forfait consultation</FeeTag>}
                </Stack>
                <Typography sx={{ fontSize: 12.5, color: HOURS_COLOR.gray600 }}>
                  {l.rateType === "HOURLY_RATE"
                    ? `${prestationCount(l.count)} · ${duration(l.minutes ?? 0)} × ${hourlyRate(l.unitAmount)}`
                    : `${l.count} consultation${l.count > 1 ? "s" : ""} × forfait ${euro(l.unitAmount)}`}
                </Typography>
              </Box>
              <Typography sx={{ fontSize: 13.5, fontWeight: 700, color: HOURS_COLOR.gray900, whiteSpace: "nowrap", fontVariantNumeric: "tabular-nums" }}>{approx(l.amount)}</Typography>
            </Stack>
          ))}
          <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1.5} sx={{ px: 1.75, py: 1.25, borderTop: `1px solid ${HOURS_COLOR.gray100}`, bgcolor: HOURS_COLOR.gray50 }}>
            <Typography sx={{ fontSize: 13.5, fontWeight: 700 }}>Total encodé · dont validé {approx(e.validatedAmount)}</Typography>
            <Typography sx={{ fontSize: 13.5, fontWeight: 700, whiteSpace: "nowrap", fontVariantNumeric: "tabular-nums" }}>{approx(e.encodedAmount)}</Typography>
          </Stack>
          {e.unratedCount > 0 && (
            <Typography sx={{ px: 1.75, pb: 1.25, fontSize: 12.5, color: HOURS_COLOR.amber800, fontWeight: 600 }}>
              {e.unratedCount === 1 ? "1 prestation sans tarif n'est pas comptée." : `${e.unratedCount} prestations sans tarif ne sont pas comptées.`}
            </Typography>
          )}
        </Box>
      )}

      <Box component="section" aria-labelledby="hours-drawer-prestations">
        <Stack direction="row" justifyContent="space-between" alignItems="baseline" sx={{ mb: 1 }}>
          <Typography id="hours-drawer-prestations" component="h3" sx={{ fontSize: 15, fontWeight: 700, color: HOURS_COLOR.gray900 }}>Prestations</Typography>
          <Typography sx={{ fontSize: 12.5, color: HOURS_COLOR.gray600 }}>{prestationCount(s.count)}</Typography>
        </Stack>
        {data.items.length === 0 ? (
          <Typography sx={{ fontSize: 13, color: HOURS_COLOR.gray600, py: 2 }}>Aucune prestation encodée ce mois-ci.</Typography>
        ) : (
          <Box className="hs" component="ul" sx={{ m: 0, p: 0, listStyle: "none" }}>
            <Box component="li" sx={rowGrid(wide, true)}><span /><span /><TrackHeads short={!wide} /><span /></Box>
            {data.items.map((p) => <PrestationRow key={p.missionId} p={p} wide={wide} onOpen={onOpenMission} />)}
          </Box>
        )}
        <Typography sx={{ mt: 1.5, fontSize: 12, color: HOURS_COLOR.gray600 }}>
          Estimation indicative au tarif personnel en vigueur à chaque prestation — ni facture, ni paiement.
        </Typography>
      </Box>
    </>
  );
}

function rowGrid(wide: boolean, heads = false) {
  return wide
    ? { display: "grid", gridTemplateColumns: "44px minmax(0, 1fr) 270px 92px", columnGap: "12px", alignItems: "center", px: 1, py: heads ? 0.75 : 1.25, borderTop: heads ? "none" : `1px solid ${HOURS_COLOR.gray100}` }
    : { display: "grid", gridTemplateColumns: "40px minmax(0, 1fr) auto", columnGap: "10px", rowGap: "10px", alignItems: "center", px: 0.5, py: heads ? 0.75 : 1.5, borderTop: heads ? "none" : `1px solid ${HOURS_COLOR.gray100}` };
}

function PrestationRow({ p, wide, onOpen }: { p: MyHoursItem; wide: boolean; onOpen: (id: number) => void }) {
  const amount = p.estimate ? (isFlatFee(p) ? euro(p.estimate.amount) : approx(p.estimate.amount)) : "—";
  const rate = p.estimate ? (isFlatFee(p) ? "Forfait consultation" : hourlyRate(p.estimate.unitAmount)) : "Sans tarif";
  const label = `${dowShort(p.date)} ${dayOfMonth(p.date)}, ${p.surgeonName ?? "chirurgien non renseigné"}, ${p.site.name}, ${p.minutes === null ? "heures non renseignées" : duration(p.minutes)}, ${p.status === "validated" ? "validé" : "en attente de validation"} — ouvrir la mission`;
  return (
    <Box component="li">
      <Box component="button" type="button" aria-label={label} onClick={() => onOpen(p.missionId)}
        sx={{ ...rowGrid(wide), width: "100%", border: "none", borderTop: `1px solid ${HOURS_COLOR.gray100}`, background: "transparent", font: "inherit", textAlign: "left", cursor: "pointer",
          "&:hover": { bgcolor: HOURS_COLOR.gray50 }, "&:focus-visible": { outline: "none", boxShadow: `inset 0 0 0 3px ${HOURS_COLOR.green500}55` } }}>
        <Box sx={{ width: 40, height: 46, borderRadius: "11px", bgcolor: HOURS_COLOR.gray50, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center" }}>
          <Typography component="span" sx={{ fontSize: 10.5, fontWeight: 700, color: HOURS_COLOR.gray600, textTransform: "uppercase" }}>{dowShort(p.date).replace(".", "")}</Typography>
          <Typography component="span" sx={{ fontSize: 17, fontWeight: 800, color: HOURS_COLOR.gray900, lineHeight: 1.1 }}>{dayOfMonth(p.date)}</Typography>
        </Box>
        <Box sx={{ minWidth: 0, display: "flex", flexDirection: "column", gap: "2px" }}>
          <Typography component="span" noWrap sx={{ fontSize: 13.5, fontWeight: 700, color: HOURS_COLOR.gray900 }}>{p.surgeonName ?? "Chirurgien non renseigné"}</Typography>
          <Typography component="span" noWrap sx={{ fontSize: 12.5, color: HOURS_COLOR.gray600 }}>{p.site.name}</Typography>
          <Stack direction="row" alignItems="center" gap={1} flexWrap="wrap">
            {isFlatFee(p) && <FeeTag>Forfait consultation</FeeTag>}
            {isPresenceShare(p) && <FeeTag>Multi-salles · part imputée</FeeTag>}
            <StatusLabel status={p.status} />
          </Stack>
        </Box>
        {wide && <Track p={p} />}
        <Box sx={{ textAlign: "right", display: "flex", flexDirection: "column", alignItems: "flex-end" }}>
          <Typography component="span" sx={{ fontSize: 13.5, fontWeight: 700, color: HOURS_COLOR.gray900, whiteSpace: "nowrap", fontVariantNumeric: "tabular-nums" }}>{amount}</Typography>
          <Typography component="span" sx={{ fontSize: 11.5, color: HOURS_COLOR.gray600, whiteSpace: "nowrap" }}>{rate}</Typography>
        </Box>
        {!wide && <Box sx={{ gridColumn: "2 / 4" }}><Track p={p} /></Box>}
      </Box>
    </Box>
  );
}

function Tile({ label, value, hint, warn }: { label: string; value: string; hint?: string; warn?: boolean }) {
  return (
    <Box sx={{ border: `1px solid ${HOURS_COLOR.gray150}`, borderRadius: "16px", px: 1.75, py: 1.5, display: "flex", flexDirection: "column", gap: "2px", minWidth: 0 }}>
      <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600 }}>{label}</Typography>
      <Typography sx={{ fontSize: 20, fontWeight: 800, color: warn ? HOURS_COLOR.amber800 : HOURS_COLOR.gray900, fontVariantNumeric: "tabular-nums", whiteSpace: "nowrap" }}>{value}</Typography>
      {hint && <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600 }}>{hint}</Typography>}
    </Box>
  );
}

function Comparison({ label, reference, delta }: { label: string; reference: string; delta: React.ReactNode }) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1} sx={{ bgcolor: HOURS_COLOR.gray50, borderRadius: "14px", px: 1.75, py: 1.25 }}>
      <Box sx={{ minWidth: 0 }}>
        <Typography sx={{ fontSize: 12.5, color: HOURS_COLOR.gray700 }}>{label}</Typography>
        <Typography sx={{ fontSize: 12, color: HOURS_COLOR.gray600 }}>{reference}</Typography>
      </Box>
      {delta}
    </Stack>
  );
}

function FeeTag({ children }: { children: React.ReactNode }) {
  return (
    <Box component="span" sx={{ display: "inline-flex", alignItems: "center", height: 20, px: 1, borderRadius: 999, bgcolor: HOURS_COLOR.slate50, color: HOURS_COLOR.slate700, fontSize: 11.5, fontWeight: 700, whiteSpace: "nowrap" }}>
      {children}
    </Box>
  );
}
