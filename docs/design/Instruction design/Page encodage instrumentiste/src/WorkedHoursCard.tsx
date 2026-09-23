import React from 'react';
import { WorkedHours } from './types';

interface WorkedHoursCardProps {
  hours?: WorkedHours;
  /** Ouvre la saisie des heures. */
  onOpen: () => void;
}

/**
 * Carte "Heures prestées" — zone 1.
 * Volontairement NEUTRE (blanc + gris, aucune touche de vert, pas de bandeau foncé) :
 * c'est ce qui la distingue au premier coup d'œil des cartes intervention.
 */
export function WorkedHoursCard({ hours, onOpen }: WorkedHoursCardProps) {
  const filled = !!hours;
  return (
    <button type="button" className="hours" onClick={onOpen}>
      <span className="hours__icon" aria-hidden="true">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3.5 2" /></svg>
      </span>
      <span className="hours__info">
        <span className="hours__label">Heures prestées</span>
        <span className="hours__detail tabular">
          {filled
            ? `${hours!.start} → ${hours!.end}${hours!.breakMinutes ? ` · pause ${hours!.breakMinutes} min` : ''}`
            : 'Non renseignées — appuyez pour saisir'}
        </span>
      </span>
      {filled && (
        <span className="hours__totalwrap">
          <span className="hours__total tabular">{hours!.total}</span>
          <span className="hours__totallabel">TOTAL NET</span>
        </span>
      )}
      <span className="hours__chev" aria-hidden="true">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.6} strokeLinecap="round" strokeLinejoin="round"><path d="m9 6 6 6-6 6" /></svg>
      </span>
    </button>
  );
}
