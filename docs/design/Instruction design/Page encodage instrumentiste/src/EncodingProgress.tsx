import React from 'react';
import { InterventionData } from './types';

interface EncodingProgressProps {
  interventions: InterventionData[];
  /** Points affichés à droite (15 pts par intervention encodée par défaut). */
  pointsPerIntervention?: number;
}

/** Compteur "2 / 3", jauge segmentée et phrase d'incitation. Une intervention compte comme encodée dès qu'elle a au moins un matériel. */
export function EncodingProgress({ interventions, pointsPerIntervention = 15 }: EncodingProgressProps) {
  const done = interventions.filter((it) => it.materials.length > 0).length;
  const left = interventions.length - done;
  const allDone = left === 0;

  return (
    <section className="prog" aria-label="Progression de l’encodage">
      <div className="prog__top">
        <p className="prog__count">
          <span className="prog__num tabular">{done} / {interventions.length}</span>
          <span className="prog__label">{allDone ? 'Mission complète' : 'interventions encodées'}</span>
        </p>
        <span className="prog__points tabular">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round"><path d="M8 21h8M12 17v4M7 4h10v4a5 5 0 0 1-10 0Z" /><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3" /></svg>
          +{done * pointsPerIntervention} pts
        </span>
      </div>

      <div className="prog__bar" role="progressbar" aria-valuemin={0} aria-valuemax={interventions.length} aria-valuenow={done}>
        {interventions.map((it) => (
          <span key={it.id} className={`prog__seg${it.materials.length > 0 ? ' prog__seg--done' : ''}`} />
        ))}
      </div>

      <p className="prog__nudge">
        {allDone ? (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--green-600)" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><path d="m8.5 12.5 2.5 2.5 4.5-5" /></svg>
        ) : (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--amber-600)" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 8v5l3 2" /></svg>
        )}
        {allDone
          ? 'Tout est encodé. Vous pouvez valider la mission.'
          : `Encore ${left > 1 ? `${left} interventions` : '1 intervention'} et la mission est complète.`}
      </p>
    </section>
  );
}
