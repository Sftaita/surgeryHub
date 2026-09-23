import React from 'react';
import { FilterGroup } from './types';
import { Icon } from './Icon';

interface Props {
  groups: FilterGroup[];
  /** Nombre de missions correspondant à la sélection EN COURS — annoncé avant d'appliquer. */
  matchCount: number;
  onToggle: (groupLabel: string, optionLabel: string) => void;
  onClear: () => void;
  onCancel: () => void;
  onApply: () => void;
  /** 'popover' = ancré sous le bouton Filtres (desktop) · 'sheet' = feuille basse (mobile). */
  variant?: 'popover' | 'sheet';
}

/** Panneau de filtres. Familles : Site, État d'encodage, Instrumentiste, Écart horaire. */
export function FiltersPanel({ groups, matchCount, onToggle, onClear, onCancel, onApply, variant = 'popover' }: Props) {
  const active = groups.reduce((a, g) => a + g.options.filter((o) => o.on).length, 0);
  return (
    <div className={`sec-filters sec-filters--${variant}`} role="dialog" aria-label="Filtres">
      <header className="sec-filters__head">
        <span className="sec-filters__title">Filtres</span>
        {active > 0 && <span className="sec-pill sec-pill--count">{active} actifs</span>}
        <span className="sec-day__spacer" />
        <button type="button" className="sec-linkbtn" onClick={onClear}>Tout effacer</button>
      </header>
      <div className="sec-filters__body">
        {groups.map((g) => (
          <div className="sec-fgroup" key={g.label}>
            <span className="sec-eyebrow">{g.label}</span>
            <div className="sec-fgroup__options">
              {g.options.map((o) => (
                <button type="button" key={o.label} onClick={() => onToggle(g.label, o.label)}
                  className={`sec-fopt${o.on ? ' sec-fopt--on' : ''}`} aria-pressed={o.on}>
                  {o.on && <Icon name="check" size={13} stroke={3} />}
                  {o.label}
                  <span className="sec-fopt__count tabular">{o.count}</span>
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>
      <footer className="sec-filters__foot">
        <span className="sec-filters__match tabular">{matchCount} missions correspondent</span>
        <button type="button" className="sec-btn sec-btn--ghost" onClick={onCancel}>Annuler</button>
        <button type="button" className="sec-btn sec-btn--primary" onClick={onApply}>
          {variant === 'sheet' ? `Voir les ${matchCount} missions` : 'Appliquer'}
        </button>
      </footer>
    </div>
  );
}
