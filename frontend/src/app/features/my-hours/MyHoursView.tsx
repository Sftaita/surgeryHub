import React from "react";
import "./my-hours.css";
import type { MyHoursEstimate, MyHoursItem, MyHoursSite, MyHoursStatement, MyHoursSummary } from "./api/myHours.types";
import { clock, dateLong, dayOfMonth, dowShort, duration, euro, hourlyRate, monthLabel, monthName, prestationCount, rateRange } from "./format";
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ClockIcon, InfoIcon } from "./Icons";

// D-179 — écran « Mes heures », porté de handoff-mes-heures/src/MesHeures.tsx (révision 3
// figée). Composant de présentation : toutes les valeurs (durées, totaux, montants, filtre
// par site) viennent de GET /api/me/hours ; seuls les formats sont appliqués ici.

export type SiteFilter = "all" | number;

export interface MyHoursViewProps {
  month: string;
  /** null pendant le premier chargement. */
  statement: MyHoursStatement | null;
  loading: boolean;
  error: boolean;
  site: SiteFilter;
  onSite: (site: SiteFilter) => void;
  isCurrentMonth: boolean;
  isDesktop: boolean;
  /** Tableau à colonnes (≈ 925 px utiles) ; sinon liste, y compris sur un desktop étroit. */
  tableLayout: boolean;
  onPrevMonth: () => void;
  onNextMonth: () => void;
  onGoToCurrentMonth: () => void;
  onRetry: () => void;
  /** Ouvre le détail mission EXISTANT. */
  onOpenMission: (missionId: number) => void;
  /** Rangée logo / cloche / avatar du shell (MobileLayout), en tête du bandeau. */
  headerTop?: React.ReactNode;
}

export function MyHoursView(props: MyHoursViewProps) {
  const { month, statement, isDesktop, site } = props;
  const [ratesOpen, setRatesOpen] = React.useState(true);

  const summary = statement?.summary ?? null;
  const sites = statement?.sites ?? [];
  const estimate = statement?.estimate ?? null;
  const items = statement?.items ?? [];
  const monthTotal = sites.reduce((t, s) => t + s.count, 0);
  const empty = statement !== null && monthTotal === 0;
  const activeSite = site === "all" ? null : sites.find((s) => s.id === site) ?? null;
  const scope = summary ? (activeSite ? `${prestationCount(summary.count)} · ${activeSite.name}` : prestationCount(summary.count)) : "";
  const filterVisible = !empty && sites.length >= 2;

  return (
    <div className={`mh mh--${isDesktop ? "desktop" : "mobile"}`}>
      <div className="mh-band-wrap">
        <header className="mh-band">
          <Waves />
          {props.headerTop && <div className="mh-band__top">{props.headerTop}</div>}
          <div className="mh-band__row">
            <div className="mh-band__titles">
              <h1 className="mh-band__title">Mes heures</h1>
              {isDesktop && summary && !empty && (
                <p className="mh-band__sub">
                  {activeSite ? `${activeSite.name} · ${prestationCount(summary.count)}` : `Heures encodées et validées · ${prestationCount(summary.count)}`}
                </p>
              )}
            </div>
            {isDesktop && <MonthNav {...props} />}
          </div>
          {!isDesktop && <MonthNav {...props} />}
          {filterVisible && <SiteChips sites={sites} total={monthTotal} site={site} onSite={props.onSite} />}
        </header>
      </div>

      <div className="mh-body" aria-busy={props.loading}>
        {props.error && !statement ? (
          <section className="mh-card mh-empty" role="alert">
            <h2 className="mh-empty__title">Impossible de charger vos heures</h2>
            <p className="mh-empty__text">Vérifiez votre connexion puis réessayez.</p>
            <button type="button" className="mh-btn-primary" onClick={props.onRetry}>Réessayer</button>
          </section>
        ) : !summary ? (
          <section className="mh-card mh-empty"><p className="mh-empty__text">Chargement…</p></section>
        ) : (
          <>
            <StatCards summary={summary} empty={empty} isDesktop={isDesktop} estimate={isDesktop && !empty ? estimate : null} />

            {!isDesktop && !empty && estimate && (
              <EstimationCard estimate={estimate} encodedMinutes={summary.encodedMinutes} open={ratesOpen} onToggle={() => setRatesOpen((o) => !o)} />
            )}

            {empty ? (
              <EmptyMonth month={month} isCurrentMonth={props.isCurrentMonth} onGoToCurrentMonth={props.onGoToCurrentMonth} />
            ) : props.tableLayout ? (
              <PrestationTable items={items} summary={summary} estimate={estimate} scope={scope} onOpen={props.onOpenMission} />
            ) : (
              <section className="mh-list-section">
                <div className="mh-section-head">
                  <h2 className="mh-section-head__title">Prestations</h2>
                  <span className="mh-section-head__count">{scope}</span>
                </div>
                <PrestationList items={items} onOpen={props.onOpenMission} />
              </section>
            )}
          </>
        )}
      </div>
    </div>
  );
}

function Waves() {
  return (
    <svg className="mh-band__waves" viewBox="0 0 400 190" preserveAspectRatio="none" fill="none" aria-hidden>
      <path d="M0 112 C 80 70, 170 158, 268 112 S 382 66, 400 92 L400 190 L0 190 Z" fill="rgba(255,255,255,.06)" />
      <path d="M0 150 C 110 118, 220 176, 400 132 L400 190 L0 190 Z" fill="rgba(11,19,32,.12)" />
    </svg>
  );
}

function MonthNav({ month, isCurrentMonth, onPrevMonth, onNextMonth }: MyHoursViewProps) {
  return (
    <div className="mh-month">
      <button type="button" className="mh-month__btn" aria-label="Mois précédent" onClick={onPrevMonth}><ChevronLeft /></button>
      <div className="mh-month__label" aria-live="polite">
        <div className="mh-month__name">{monthLabel(month)}</div>
        <div className="mh-month__hint">{isCurrentMonth ? "Mois en cours" : "Mois passé"}</div>
      </div>
      <button type="button" className="mh-month__btn" aria-label="Mois suivant" onClick={onNextMonth} disabled={isCurrentMonth}><ChevronRight size={18} /></button>
    </div>
  );
}

/** Puces de site, dans le bandeau : elles pilotent compteurs, estimation et liste. Défilement horizontal sur mobile. */
function SiteChips({ sites, total, site, onSite }: { sites: MyHoursSite[]; total: number; site: SiteFilter; onSite: (s: SiteFilter) => void }) {
  const chips: { id: SiteFilter; label: string; count: number }[] = [
    { id: "all", label: "Tous les sites", count: total },
    ...sites.map((s) => ({ id: s.id as SiteFilter, label: s.name, count: s.count })),
  ];
  return (
    <div className="mh-chips" role="group" aria-label="Filtrer par site">
      {chips.map((c) => (
        <button key={String(c.id)} type="button" className={`mh-chip ${site === c.id ? "mh-chip--active" : ""}`} aria-pressed={site === c.id} onClick={() => onSite(c.id)}>
          {c.label}<span className="mh-chip__count">{c.count}</span>
        </button>
      ))}
    </div>
  );
}

function StatCards({ summary, empty, isDesktop, estimate }: { summary: MyHoursSummary; empty: boolean; isDesktop: boolean; estimate: MyHoursEstimate | null }) {
  return (
    <div className={`mh-stats ${estimate ? "mh-stats--3" : ""}`}>
      <div className="mh-card mh-stat">
        <div className="mh-stat__label">Heures encodées</div>
        <div className={`mh-stat__value ${empty ? "mh-stat__value--empty" : ""}`}>{duration(summary.encodedMinutes)}</div>
        {!empty && (isDesktop ? (
          <div className="mh-stat__foot"><strong className="mh-warn">{duration(summary.pendingMinutes)}</strong> en attente + <strong className="mh-ok">{duration(summary.validatedMinutes)}</strong> validées</div>
        ) : (
          <div className="mh-stat__foot">dont <strong className="mh-warn">{duration(summary.pendingMinutes)}</strong> en attente</div>
        ))}
      </div>
      <div className="mh-card mh-stat">
        <div className="mh-stat__label">Heures validées</div>
        <div className={`mh-stat__value mh-stat__value--ok ${empty ? "mh-stat__value--empty" : ""}`}>{duration(summary.validatedMinutes)}</div>
        {!empty && (
          <div className="mh-stat__progress">
            <div className="mh-bar" role="img" aria-label={`${summary.validatedPct} % des heures encodées sont validées`}><div className="mh-bar__fill" style={{ width: summary.validatedPct + "%" }} /></div>
            <span className="mh-stat__foot">{isDesktop ? summary.validatedPct + " %" : summary.validatedPct + " % des encodées"}</span>
          </div>
        )}
      </div>
      {estimate && (
        <div className="mh-card mh-stat">
          <Eyebrow />
          <div className="mh-est-row mh-est-row--compact"><span>Heures encodées</span><strong>≈ {euro(estimate.encodedAmount)}</strong></div>
          <div className="mh-est-row mh-est-row--compact"><span>Heures validées</span><strong className="mh-ok">≈ {euro(estimate.validatedAmount)}</strong></div>
          <div className="mh-disclaimer mh-disclaimer--compact">Brut, selon votre tarif personnel. Ni salaire net, ni facture, ni paiement.</div>
        </div>
      )}
    </div>
  );
}

function Eyebrow() {
  return <div className="mh-eyebrow"><InfoIcon /><span>ESTIMATION INDICATIVE</span></div>;
}

function ratesSummary(estimate: MyHoursEstimate): string {
  const hourly = estimate.hourlyRateMin !== null && estimate.hourlyRateMax !== null ? rateRange(estimate.hourlyRateMin, estimate.hourlyRateMax) : null;
  const hasFee = estimate.lines.some((l) => l.rateType === "CONSULTATION_FEE");
  return [hourly, hasFee ? "forfait consultation" : null].filter(Boolean).join(" · ");
}

function EstimationCard({ estimate, encodedMinutes, open, onToggle }: { estimate: MyHoursEstimate; encodedMinutes: number; open: boolean; onToggle: () => void }) {
  return (
    <section className="mh-card mh-est">
      <Eyebrow />
      <div className="mh-est__rows">
        <div className="mh-est-row mh-est-row--rule"><span>Sur les heures encodées</span><strong>≈ {euro(estimate.encodedAmount)}</strong></div>
        <div className="mh-est-row"><span>Sur les heures validées</span><strong className="mh-ok">≈ {euro(estimate.validatedAmount)}</strong></div>
      </div>
      <button type="button" className="mh-est__toggle" onClick={onToggle} aria-expanded={open}>
        <span className="mh-est__toggle-label">Tarifs utilisés</span>
        <span className="mh-est__toggle-range">{ratesSummary(estimate)}</span>
        {open ? <ChevronUp /> : <ChevronDown />}
      </button>
      {open && (
        <div className="mh-rates">
          {estimate.lines.map((l) => (
            <div key={l.siteName + l.rateType + l.unitAmount} className="mh-rates__line">
              <div className="mh-rates__body">
                <div className="mh-rates__site">{l.siteName}</div>
                <div className="mh-rates__calc">
                  {l.rateType === "HOURLY_RATE"
                    ? `${duration(l.minutes ?? 0)} × ${hourlyRate(l.unitAmount)}`
                    : `${l.count} consultation${l.count > 1 ? "s" : ""} × forfait ${euro(l.unitAmount)}`}
                </div>
              </div>
              <span className="mh-rates__amount">{euro(l.amount)}</span>
            </div>
          ))}
          <div className="mh-rates__total"><span>Total · {duration(encodedMinutes)}</span><strong>{euro(estimate.encodedAmount)}</strong></div>
        </div>
      )}
      {estimate.unratedCount > 0 && (
        <p className="mh-disclaimer">{estimate.unratedCount === 1 ? "1 prestation sans tarif n’est pas comptée." : `${estimate.unratedCount} prestations sans tarif ne sont pas comptées.`}</p>
      )}
      <p className="mh-disclaimer">Montant brut estimé avec votre tarif personnel, appliqué à chaque prestation. Ce n'est ni un salaire net, ni une facture, ni un paiement.</p>
    </section>
  );
}

function StatusLabel({ status, pill }: { status: MyHoursItem["status"]; pill?: boolean }) {
  const ok = status === "validated";
  return (
    <span className={`mh-status ${ok ? "mh-status--ok" : "mh-status--warn"} ${pill ? "mh-status--pill" : ""}`}>
      <span className="mh-status__dot" />
      {ok ? "Validé" : "En attente de validation"}
    </span>
  );
}

/** En-têtes de la piste : « DÉBUT · DURÉE TOTALE · FIN » (desktop) / « DÉBUT · TOTAL · FIN » (mobile). */
function TrackHeads({ short }: { short?: boolean }) {
  return (
    <span className="mh-tl-heads" aria-hidden>
      <span>DÉBUT</span><span className="mh-tl-heads__total">{short ? "TOTAL" : "DURÉE TOTALE"}</span><span>FIN</span>
    </span>
  );
}

/** Piste des heures ENCODÉES : début | durée totale | fin. Toujours neutre, jamais le planifié. */
function Track({ p }: { p: MyHoursItem }) {
  if (p.minutes === null) {
    return <span className="mh-tl mh-tl--missing">Heures non renseignées</span>;
  }
  return (
    <span className="mh-tl">
      {p.start ? <span className="mh-tl__val">{clock(p.start)}</span> : <span className="mh-tl__val mh-tl__val--none">—</span>}
      <span className="mh-tl__total"><ClockIcon size={13} strokeWidth={2.4} />{duration(p.minutes)}</span>
      {p.end ? (
        <span className="mh-tl__val">{clock(p.end)}{p.endsNextDay && <sup className="mh-tl__next">+1j</sup>}</span>
      ) : (
        <span className="mh-tl__val mh-tl__val--none">—</span>
      )}
    </span>
  );
}

/** Consultation valorisée au forfait : jamais une estimation horaire, jamais d'heures fictives. */
function isFlatFee(p: MyHoursItem): boolean {
  return p.estimate?.rateType === "CONSULTATION_FEE";
}

function rowLabel(p: MyHoursItem): string {
  const hours = p.minutes === null
    ? "heures non renseignées"
    : `${duration(p.minutes)}${p.start && p.end ? `, de ${clock(p.start)} à ${clock(p.end)}${p.endsNextDay ? " le lendemain" : ""}` : ""}`;
  return `${dateLong(p.date)}, ${p.surgeonName ?? "chirurgien non renseigné"}, ${p.site.name}, ${hours}${isFlatFee(p) ? ", forfait consultation" : ""}`;
}

function PrestationList({ items, onOpen }: { items: MyHoursItem[]; onOpen: (id: number) => void }) {
  return (
    <ul className="mh-list">
      <li className="mh-list__heads"><TrackHeads short /></li>
      {items.map((p) => (
        <li key={p.missionId}>
          <button type="button" className="mh-row" onClick={() => onOpen(p.missionId)} aria-label={rowLabel(p)}>
            <span className="mh-row__date"><span className="mh-row__dow">{dowShort(p.date)}</span><span className="mh-row__day">{dayOfMonth(p.date)}</span></span>
            <span className="mh-row__body">
              <span className="mh-row__surgeon">{p.surgeonName ?? "Chirurgien non renseigné"}</span>
              <span className="mh-row__site">{p.site.name}</span>
              {isFlatFee(p) && <span className="mh-fee-tag">Forfait consultation</span>}
              <StatusLabel status={p.status} />
            </span>
            <span className="mh-row__chev"><ChevronRight /></span>
            <Track p={p} />
          </button>
        </li>
      ))}
    </ul>
  );
}

function rateCell(p: MyHoursItem): string {
  if (!p.estimate) return "—";
  return p.estimate.rateType === "HOURLY_RATE" ? hourlyRate(p.estimate.unitAmount) : "Forfait consultation";
}

function PrestationTable({ items, summary, estimate, scope, onOpen }: { items: MyHoursItem[]; summary: MyHoursSummary; estimate: MyHoursEstimate | null; scope: string; onOpen: (id: number) => void }) {
  const hourly = estimate !== null;
  const cls = `mh-grid ${hourly ? "mh-grid--hourly" : ""}`;
  return (
    <section className="mh-card mh-table">
      <div className="mh-table__head">
        <h2 className="mh-section-head__title">Prestations</h2>
        <span className="mh-section-head__count">{scope}</span>
      </div>
      <div className={`${cls} mh-table__cols`} role="row">
        <span>Date</span><span>Chirurgien · Site</span><TrackHeads />
        {hourly && <><span className="mh-r">Tarif</span><span className="mh-r">Estimation</span></>}
        <span>Statut</span><span />
      </div>
      {items.map((p) => (
        <button key={p.missionId} type="button" className={`${cls} mh-table__row`} onClick={() => onOpen(p.missionId)} aria-label={rowLabel(p)}>
          <span className="mh-strong mh-num">{dateLong(p.date)}</span>
          <span className="mh-cell2"><span className="mh-strong mh-ellipsis">{p.surgeonName ?? "Chirurgien non renseigné"}</span><span className="mh-muted mh-ellipsis">{p.site.name}</span></span>
          <Track p={p} />
          {hourly && <>
            <span className="mh-r mh-faint mh-num">{rateCell(p)}</span>
            <span className="mh-r mh-num">{p.estimate ? euro(p.estimate.amount) : "—"}</span>
          </>}
          <span><StatusLabel status={p.status} pill /></span>
          <span className="mh-row__chev"><ChevronRight /></span>
        </button>
      ))}
      <div className={`${cls} mh-table__total`}>
        <span className="mh-strong">Total</span><span />
        <span className="mh-dur mh-num mh-c">{duration(summary.encodedMinutes)}</span>
        {hourly && <><span /><span className="mh-r mh-dur mh-num">{estimate ? euro(estimate.encodedAmount) : "—"}</span></>}
        <span /><span />
      </div>
    </section>
  );
}

function EmptyMonth({ month, isCurrentMonth, onGoToCurrentMonth }: { month: string; isCurrentMonth: boolean; onGoToCurrentMonth: () => void }) {
  return (
    <section className="mh-card mh-empty">
      <span className="mh-empty__icon"><ClockIcon size={28} strokeWidth={1.9} /></span>
      <h2 className="mh-empty__title">Aucune prestation encodée en {monthName(month)}</h2>
      <p className="mh-empty__text">Vos heures apparaissent ici dès qu'une mission est encodée.</p>
      {!isCurrentMonth && <button type="button" className="mh-btn-primary" onClick={onGoToCurrentMonth}>Revenir au mois en cours</button>}
    </section>
  );
}
