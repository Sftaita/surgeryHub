import React, { useState } from 'react';
import { MissionRow, FilterGroup } from './types';
import { AttentionCard } from './AttentionCard';
import { WeekFunnel, FunnelSegment } from './WeekFunnel';
import { MissionsTable } from './MissionsTable';
import { MissionDrawer } from './MissionDrawer';
import { FiltersPanel } from './FiltersPanel';
import { Icon } from './Icon';
import './encodings.css';

interface Props {
  rows: MissionRow[];
  filterGroups: FilterGroup[];
  funnel: FunnelSegment[];
  periodLabel: string;
  dayLabel: (date: string) => string;
  onToggleFilter: (groupLabel: string, optionLabel: string) => void;
  onClearFilters: () => void;
  onRelance: (id: number) => void;
  onValidate: (id: number) => void;
}

const PERIODS = ['Aujourd’hui', 'Hier', '7 jours', 'Ce mois'];
const TABS = ['Toutes les missions', 'À traiter', 'Par instrumentiste'];

/**
 * Page "Suivi des encodages".
 *
 * Deux règles de layout à respecter :
 *  1. À l'ouverture du tiroir, la colonne de contenu SE RÉTRÉCIT (classe .sec-page--drawer),
 *     elle n'est pas recouverte : l'admin garde la liste sous les yeux et enchaîne les validations.
 *  2. Sous 1100px, le tiroir devient une feuille plein écran et le tableau des cartes (CSS seul).
 */
export function EncodingsPage(props: Props) {
  const { rows, filterGroups, funnel, periodLabel, dayLabel, onToggleFilter, onClearFilters, onRelance, onValidate } = props;
  const [openId, setOpenId] = useState<number | null>(null);
  const [period, setPeriod] = useState(periodLabel);
  const [tab, setTab] = useState(TABS[0]);
  const [filtersOpen, setFiltersOpen] = useState(false);

  const selected = rows.find((r) => r.id === openId) || null;
  const toTreat = rows.filter((r) => r.state !== 'Validé').length;
  const activeChips = filterGroups.flatMap((g) => g.options.filter((o) => o.on).map((o) => ({ group: g.label, option: o.label })));

  return (
    <div className={`sec-page${selected ? ' sec-page--drawer' : ''}`}>
      <div className="sec-page__main">
        <header className="sec-topbar">
          <div className="sec-topbar__titles">
            <h1 className="sec-topbar__title">Suivi des encodages</h1>
            <p className="sec-topbar__sub">Semaine du 7 au 13 septembre · dernière synchro il y a 2 min</p>
          </div>
          <label className="sec-search">
            <Icon name="search" size={16} stroke={2.2} />
            <input placeholder="Rechercher une mission…" />
          </label>
          <div className="sec-segmented">
            {PERIODS.map((p) => (
              <button type="button" key={p} onClick={() => setPeriod(p)}
                className={`sec-segmented__item${period === p ? ' sec-segmented__item--on' : ''}`}>{p}</button>
            ))}
          </div>
          <div className="sec-navarrows">
            <button type="button" aria-label="Semaine précédente"><Icon name="chevronLeft" size={17} /></button>
            <button type="button" aria-label="Semaine suivante"><Icon name="chevronRight" size={17} /></button>
          </div>
        </header>

        <div className="sec-page__content">
          <div className="sec-summaryrow">
            <AttentionCard total={toTreat} late={rows.filter((r) => r.late).length}
              toRemind={rows.filter((r) => r.state === 'À encoder').length}
              toValidate={rows.filter((r) => r.state === 'Soumis').length} />
            <WeekFunnel segments={funnel} missions={rows.length} anomalies={0} />
          </div>

          <div className="sec-toolbar">
            <div className="sec-tabs">
              {TABS.map((t) => (
                <button type="button" key={t} onClick={() => setTab(t)}
                  className={`sec-tabs__item${tab === t ? ' sec-tabs__item--on' : ''}`}>
                  {t}
                  {t === 'À traiter' && <span className="sec-tabs__count tabular">{toTreat}</span>}
                </button>
              ))}
            </div>
            <span className="sec-day__spacer" />
            {activeChips.map((c) => (
              <button type="button" className="sec-chip" key={c.group + c.option} onClick={() => onToggleFilter(c.group, c.option)}>
                <span className="sec-chip__field">{c.group}</span> {c.option}
                <Icon name="close" size={13} stroke={2.6} />
              </button>
            ))}
            <button type="button" className="sec-btn sec-btn--ghost" onClick={() => setFiltersOpen((v) => !v)}>
              <Icon name="filter" size={15} stroke={2.2} />Filtres
            </button>
            <button type="button" className="sec-btn sec-btn--ghost"><Icon name="download" size={15} stroke={2.2} />Exporter</button>

            {filtersOpen && (
              <FiltersPanel groups={filterGroups} matchCount={rows.length} onToggle={onToggleFilter}
                onClear={onClearFilters} onCancel={() => setFiltersOpen(false)} onApply={() => setFiltersOpen(false)} />
            )}
          </div>

          <MissionsTable rows={rows} selectedId={selected?.id} onOpen={setOpenId} dayLabel={dayLabel} />
        </div>
      </div>

      {selected && (
        <MissionDrawer mission={selected} onClose={() => setOpenId(null)}
          onRelance={() => onRelance(selected.id)} onValidate={() => onValidate(selected.id)} />
      )}
    </div>
  );
}
