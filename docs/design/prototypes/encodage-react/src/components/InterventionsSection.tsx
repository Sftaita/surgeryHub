import React from 'react';
import { InterventionData } from '../types';
import { InterventionCard } from './InterventionCard';
import { AddButton } from './AddButton';

interface InterventionsSectionProps {
  interventions: InterventionData[];
  onToggle: (id: string) => void;
  onQtyInc: (interventionId: string, materialId: string) => void;
  onQtyDec: (interventionId: string, materialId: string) => void;
  onAddMaterial: (interventionId: string) => void;
  onAddIntervention: () => void;
}

/** Card 3 — eyebrow + list of InterventionCard + always-visible "Ajouter une intervention". */
export function InterventionsSection({ interventions, onToggle, onQtyInc, onQtyDec, onAddMaterial, onAddIntervention }: InterventionsSectionProps) {
  return (
    <>
      <div className="section-eyebrow"><span>INTERVENTIONS</span><span className="section-eyebrow__rule" /></div>
      {interventions.map((it) => (
        <InterventionCard
          key={it.id}
          intervention={it}
          onToggle={() => onToggle(it.id)}
          onQtyInc={(mid) => onQtyInc(it.id, mid)}
          onQtyDec={(mid) => onQtyDec(it.id, mid)}
          onAddMaterial={() => onAddMaterial(it.id)}
        />
      ))}
      <AddButton onClick={onAddIntervention}>Ajouter une intervention</AddButton>
    </>
  );
}
