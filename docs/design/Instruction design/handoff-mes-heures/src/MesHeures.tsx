import React, { useMemo, useState } from 'react';
import './mes-heures.css';
import type { Estimate, Prestation, SiteFilter, SiteOption, Summary } from './types';
import { amountCents, durationMinutes, filterBySite, listSites, showSiteFilter, summarize } from './summarize';
import { clock, dateLong, dayOfMonth, dowShort, duration, endsNextDay, euro, hourlyRate, monthLabel, monthName, prestationCount, rateRange, timeRange } from './format';
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ClockIcon, InfoIcon } from './Icons';

export interface MesHeuresProps {
  year: number;
  /** 1–12 */
  month: number;
  /** Prestations ENCODÉES du mois (soumises ou validées). Jamais de missions seulement planifiées. */
  prestations: Prestation[];
  /** true si l'instrumentiste a une rémunération horaire paramétrée. false = section financière absente. */
  hourly: boolean;
  isCurrentMonth: boolean;
  isDesktop: boolean;
  onPrevMonth: () => void;
  onNextMonth: () => void;
  onGoToCurrentMonth: () => void;
  /** Ouvre le détail mission EXISTANT de l'app instrumentiste. */
  onOpenMission: (missionId: string) => void;
  /** Rangée logo / avatar du shell, rendue en haut du bandeau sur mobile. */
  headerTop?: React.ReactNode;
}

export function MesHeures(props: MesHeuresProps) {
  const { year, month, prestations, hourly, isDesktop } = props;
  const [site, setSite] = useState<SiteFilter>('all');
  const [ratesOpen, setRatesOpen] = useState(true);

  // Réinitialise le filtre au changement de mois.
  const monthKey = year + '-' + month;
  const [lastKey, setLastKey] = useState(monthKey);
  if (lastKey !== monthKey) { setLastKey(monthKey); setSite('all'); }

  // Les puces viennent du mois entier ; tout le reste (cartes, estimation, liste) suit le filtre.
  const sites = useMemo(() => listSites(prestations), [prestations]);
  const effectiveSite = sites.some((s) => s.id === site) ? site : 'all';
  const activeSite = sites.find((s) => s.id === effectiveSite) ?? null;
  const items = useMemo(() => filterBySite(prestations, effectiveSite), [prestations, effectiveSite]);
  const summary = useMemo(() => summarize(items, hourly), [items, hourly]);
  const empty = prestations.length === 0;
  const filterVisible = !empty && showSiteFilter(sites);
  const scope = activeSite ? `${prestationCount(summary.count)} · ${activeSite.name}` : prestationCount(summary.count);

  return (
    <div className={`mh mh--${isDesktop ? 'desktop' : 'mobile'}`}>
      <div className="mh-band-wrap">
        <header className="mh-band">
          <Waves />
          {!isDesktop && props.headerTop && <div className="mh-band__top">{props.headerTop}</div>}
          <div className="mh-band__row">
            <div className="mh-band__titles">
              <h1 className="mh-band__title">Mes heures</h1>
              {isDesktop && !empty && (
                <p className="mh-band__sub">{activeSite ? `${activeSite.name} · ${prestationCount(summary.count)}` : `Heures encodées et validées · ${prestationCount(summary.count)}`}</p>
              )}
            </div>
            {isDesktop && <MonthNav {...props} />}
          </div>
          {!isDesktop && <MonthNav {...props} />}
          {filterVisible && <SiteChips sites={sites} total={prestations.length} site={effectiveSite} onSite={setSite} />}
        </header>
      </div>

      <div className="mh-body">
        <StatCards summary={summary} empty={empty} isDesktop={isDesktop} estimate={isDesktop && !empty ? summary.estimate : null} />

        {!isDesktop && !empty && summary.estimate && (
          <EstimationCard estimate={summary.estimate} encodedMinutes={summary.encodedMinutes} open={ratesOpen} onToggle={() => setRatesOpen((o) => !o)} />
        )}

        {empty ? (
          <EmptyMonth month={month} isCurrentMonth={props.isCurrentMonth} onGoToCurrentMonth={props.onGoToCurrentMonth} />
        ) : isDesktop ? (
          <PrestationTable items={items} summary={summary} scope={scope} hourly={!!summary.estimate} onOpen={props.onOpenMission} />
        ) : (
          <section className="mh-list-section">
            <div className="mh-section-head">
              <h2 className="mh-section-head__title">Prestations</h2>
              <span className="mh-section-head__count">{scope}</span>
            </div>
            <PrestationList items={items} onOpen={props.onOpenMission} />
          </section>
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

function MonthNav({ year, month, isCurrentMonth, onPrevMonth, onNextMonth }: MesHeuresProps) {
  return (
    <div className="mh-month">
      <button type="button" className="mh-month__btn" aria-label="Mois précédent" onClick={onPrevMonth}><ChevronLeft /></button>
      <div className="mh-month__label" aria-live="polite">
        <div className="mh-month__name">{monthLabel(year, month)}</div>
        <div className="mh-month__hint">{isCurrentMonth ? 'Mois en cours' : 'Mois passé'}</div>
      </div>
      <button type="button" className="mh-month__btn" aria-label="Mois suivant" onClick={onNextMonth} disabled={isCurrentMonth}><ChevronRight size={18} /></button>
    </div>
  );
}

/** Puces de site, dans le bandeau : elles pilotent tout ce qui est en dessous. */
function SiteChips({ sites, total, site, onSite }: { sites: SiteOption[]; total: number; site: SiteFilter; onSite: (s: SiteFilter) => void }) {
  const chips = [{ id: 'all', label: 'Tous les sites', count: total }, ...sites.map((s) => ({ id: s.id, label: s.shortName, count: s.count }))];
  return (
    <div className="mh-chips" role="group" aria-label="Filtrer par site">
      {chips.map((c) => (
        <button key={c.id} type="button" className={`mh-chip ${site === c.id ? 'mh-chip--active' : ''}`} aria-pressed={site === c.id} onClick={() => onSite(c.id)}>
          {c.label}<span className="mh-chip__count">{c.count}</span>
        </button>
      ))}
    </div>
  );
}

function StatCards({ summary, empty, isDesktop, estimate }: { summary: Summary; empty: boolean; isDesktop: boolean; estimate: Estimate | null }) {
  return (
    <div className={`mh-stats ${estimate ? 'mh-stats--3' : ''}`}>
      <div className="mh-card mh-stat">
        <div className="mh-stat__label">Heures encodées</div>
        <div className={`mh-stat__value ${empty ? 'mh-stat__value--empty' : ''}`}>{duration(summary.encodedMinutes)}</div>
        {!empty && (isDesktop ? (
          <div className="mh-stat__foot"><strong className="mh-warn">{duration(summary.pendingMinutes)}</strong> en attente + <strong className="mh-ok">{duration(summary.validatedMinutes)}</strong> validées</div>
        ) : (
          <div className="mh-stat__foot">dont <strong className="mh-warn">{duration(summary.pendingMinutes)}</strong> en attente</div>
        ))}
      </div>
      <div className="mh-card mh-stat">
        <div className="mh-stat__label">Heures validées</div>
        <div className={`mh-stat__value mh-stat__value--ok ${empty ? 'mh-stat__value--empty' : ''}`}>{duration(summary.validatedMinutes)}</div>
        {!empty && (
          <div className="mh-stat__progress">
            <div className="mh-bar" role="img" aria-label={`${summary.validatedPct} % des heures encodées sont validées`}><div className="mh-bar__fill" style={{ width: summary.validatedPct + '%' }} /></div>
            <span className="mh-stat__foot">{isDesktop ? summary.validatedPct + ' %' : summary.validatedPct + ' % des encodées'}</span>
          </div>
        )}
      </div>
      {estimate && (
        <div className="mh-card mh-stat">
          <Eyebrow />
          <div className="mh-est-row mh-est-row--compact"><span>Heures encodées</span><strong>≈ {euro(estimate.encodedCents)}</strong></div>
          <div className="mh-est-row mh-est-row--compact"><span>Heures validées</span><strong className="mh-ok">≈ {euro(estimate.validatedCents)}</strong></div>
          <div className="mh-disclaimer mh-disclaimer--compact">Brut, selon votre tarif personnel. Ni salaire net, ni facture, ni paiement.</div>
        </div>
      )}
    </div>
  );
}

function Eyebrow() {
  return <div className="mh-eyebrow"><InfoIcon /><span>ESTIMATION INDICATIVE</span></div>;
}

function EstimationCard({ estimate, encodedMinutes, open, onToggle }: { estimate: Estimate; encodedMinutes: number; open: boolean; onToggle: () => void }) {
  return (
    <section className="mh-card mh-est">
      <Eyebrow />
      <div className="mh-est__rows">
        <div className="mh-est-row mh-est-row--rule"><span>Sur les heures encodées</span><strong>≈ {euro(estimate.encodedCents)}</strong></div>
        <div className="mh-est-row"><span>Sur les heures validées</span><strong className="mh-ok">≈ {euro(estimate.validatedCents)}</strong></div>
      </div>
      <button type="button" className="mh-est__toggle" onClick={onToggle} aria-expanded={open}>
        <span className="mh-est__toggle-label">Tarifs horaires utilisés</span>
        <span className="mh-est__toggle-range">{rateRange(estimate.minRateCents, estimate.maxRateCents)}{estimate.minRateCents !== estimate.maxRateCents ? ' selon le site' : ''}</span>
        {open ? <ChevronUp /> : <ChevronDown />}
      </button>
      {open && (
        <div className="mh-rates">
          {estimate.lines.map((l) => (
            <div key={l.siteName + l.rateCents} className="mh-rates__line">
              <div className="mh-rates__body">
                <div className="mh-rates__site">{l.siteName}</div>
                <div className="mh-rates__calc">{duration(l.minutes)} × {hourlyRate(l.rateCents)}</div>
              </div>
              <span className="mh-rates__amount">{euro(l.amountCents)}</span>
            </div>
          ))}
          <div className="mh-rates__total"><span>Total · {duration(encodedMinutes)}</span><strong>{euro(estimate.encodedCents)}</strong></div>
        </div>
      )}
      {estimate.unratedCount > 0 && (
        <p className="mh-disclaimer">{estimate.unratedCount === 1 ? '1 prestation sans tarif n’est pas comptée.' : `${estimate.unratedCount} prestations sans tarif ne sont pas comptées.`}</p>
      )}
      <p className="mh-disclaimer">Montant brut estimé avec votre tarif horaire personnel, appliqué à chaque prestation. Ce n'est ni un salaire net, ni une facture, ni un paiement.</p>
    </section>
  );
}

function StatusLabel({ status, pill }: { status: Prestation['status']; pill?: boolean }) {
  const ok = status === 'validated';
  return (
    <span className={`mh-status ${ok ? 'mh-status--ok' : 'mh-status--warn'} ${pill ? 'mh-status--pill' : ''}`}>
      <span className="mh-status__dot" />
      {ok ? 'Validé' : 'En attente de validation'}
    </span>
  );
}

/** En-têtes de la piste : « DÉBUT · DURÉE TOTALE · FIN » (desktop) / « DÉBUT · TOTAL · FIN » (mobile). */
function TrackHeads({ short }: { short?: boolean }) {
  return (
    <span className="mh-tl-heads" aria-hidden>
      <span>DÉBUT</span><span className="mh-tl-heads__total">{short ? 'TOTAL' : 'DURÉE TOTALE'}</span><span>FIN</span>
    </span>
  );
}

/** Piste des heures ENCODÉES : début | durée totale (fin − début) | fin. Toujours neutre. */
function Track({ p }: { p: Prestation }) {
  return (
    <span className="mh-tl">
      <span className="mh-tl__val">{clock(p.start)}</span>
      <span className="mh-tl__total"><ClockIcon size={13} strokeWidth={2.4} />{duration(durationMinutes(p))}</span>
      <span className="mh-tl__val">{clock(p.end)}{endsNextDay(p.start, p.end) && <sup className="mh-tl__next">+1j</sup>}</span>
    </span>
  );
}

function rowLabel(p: Prestation): string {
  return `${dateLong(p.start)}, ${p.surgeonName}, ${p.siteName}, ${duration(durationMinutes(p))}, ${timeRange(p.start, p.end)}`;
}

function PrestationList({ items, onOpen }: { items: Prestation[]; onOpen: (id: string) => void }) {
  return (
    <ul className="mh-list">
      <li className="mh-list__heads"><TrackHeads short /></li>
      {items.map((p) => (
        <li key={p.missionId}>
          <button type="button" className="mh-row" onClick={() => onOpen(p.missionId)} aria-label={rowLabel(p)}>
            <span className="mh-row__date"><span className="mh-row__dow">{dowShort(p.start)}</span><span className="mh-row__day">{dayOfMonth(p.start)}</span></span>
            <span className="mh-row__body">
              <span className="mh-row__surgeon">{p.surgeonName}</span>
              <span className="mh-row__site">{p.siteName}</span>
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

function PrestationTable({ items, summary, scope, hourly, onOpen }: { items: Prestation[]; summary: Summary; scope: string; hourly: boolean; onOpen: (id: string) => void }) {
  const cls = `mh-grid ${hourly ? 'mh-grid--hourly' : ''}`;
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
      {items.map((p) => {
        const a = amountCents(p);
        return (
          <button key={p.missionId} type="button" className={`${cls} mh-table__row`} onClick={() => onOpen(p.missionId)} aria-label={rowLabel(p)}>
            <span className="mh-strong mh-num">{dateLong(p.start)}</span>
            <span className="mh-cell2"><span className="mh-strong mh-ellipsis">{p.surgeonName}</span><span className="mh-muted mh-ellipsis">{p.siteName}</span></span>
            <Track p={p} />
            {hourly && <>
              <span className="mh-r mh-faint mh-num">{p.rateCents != null ? hourlyRate(p.rateCents) : '—'}</span>
              <span className="mh-r mh-num">{a != null ? euro(a) : '—'}</span>
            </>}
            <span><StatusLabel status={p.status} pill /></span>
            <span className="mh-row__chev"><ChevronRight /></span>
          </button>
        );
      })}
      <div className={`${cls} mh-table__total`}>
        <span className="mh-strong">Total</span><span />
        <span className="mh-dur mh-num mh-c">{duration(summary.encodedMinutes)}</span>
        {hourly && <><span /><span className="mh-r mh-dur mh-num">{summary.estimate ? euro(summary.estimate.encodedCents) : '—'}</span></>}
        <span /><span />
      </div>
    </section>
  );
}

function EmptyMonth({ month, isCurrentMonth, onGoToCurrentMonth }: { month: number; isCurrentMonth: boolean; onGoToCurrentMonth: () => void }) {
  return (
    <section className="mh-card mh-empty">
      <span className="mh-empty__icon"><ClockIcon size={28} strokeWidth={1.9} /></span>
      <h2 className="mh-empty__title">Aucune prestation encodée en {monthName(month)}</h2>
      <p className="mh-empty__text">Vos heures apparaissent ici dès qu'une mission est encodée.</p>
      {!isCurrentMonth && <button type="button" className="mh-btn-primary" onClick={onGoToCurrentMonth}>Revenir au mois en cours</button>}
    </section>
  );
}
