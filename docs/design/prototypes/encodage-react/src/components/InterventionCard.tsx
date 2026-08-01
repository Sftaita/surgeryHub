import React from 'react';
import { InterventionData } from '../types';
import { MaterialLine } from './MaterialLine';

interface InterventionCardProps {
  intervention: InterventionData;
  onToggle: () => void;
  onQtyInc: (materialId: string) => void;
  onQtyDec: (materialId: string) => void;
  onAddMaterial: () => void;
}

/** Compact accordion card — one per intervention within the mission. */
export function InterventionCard({ intervention, onToggle, onQtyInc, onQtyDec, onAddMaterial }: InterventionCardProps) {
  const count = intervention.materials.reduce((a, m) => a + m.qty, 0);
  return (
    <div className="intervention-card">
      <button type="button" className="intervention-card__head" onClick={onToggle}>
        <span className="intervention-card__dot" />
        <span className="intervention-card__name">{intervention.name}</span>
        <span className="intervention-card__count tabular">{count} {count > 1 ? 'matériels' : 'matériel'}</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round" className={`intervention-card__chevron ${intervention.open ? 'intervention-card__chevron--open' : ''}`}><path d="m6 9 6 6 6-6" /></svg>
      </button>
      {intervention.open && (
        <div className="intervention-card__body">
          {intervention.materials.map((m) => (
            <MaterialLine key={m.id} material={m} onInc={() => onQtyInc(m.id)} onDec={() => onQtyDec(m.id)} />
          ))}
          <button type="button" className="intervention-card__add" onClick={onAddMaterial}>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round"><path d="M12 5v14M5 12h14" /></svg>
            Ajouter du matériel
          </button>
        </div>
      )}
    </div>
  );
}
