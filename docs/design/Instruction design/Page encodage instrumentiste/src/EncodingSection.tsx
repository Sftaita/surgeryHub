import React from 'react';
import { InterventionData, WorkedHours } from './types';
import { WorkedHoursCard } from './WorkedHoursCard';
import { EncodingProgress } from './EncodingProgress';
import { InterventionsList } from './InterventionsList';
import { EncodingFooter } from './EncodingFooter';
import './interventions.css';

interface EncodingSectionProps {
  interventions: InterventionData[];
  hours?: WorkedHours;
  onOpenHours: () => void;
  onQtyInc: (interventionId: string, materialId: string) => void;
  onQtyDec: (interventionId: string, materialId: string) => void;
  onRemoveMaterial?: (interventionId: string, materialId: string) => void;
  onAddMaterial: (interventionId: string) => void;
  onEditIntervention?: (interventionId: string) => void;
  onDeleteIntervention?: (interventionId: string) => void;
  onAddIntervention: () => void;
  onValidate: () => void;
  /** Désactive le défilement automatique des cartes (déconseillé — voir DOCUMENTATION §6). */
  autoScroll?: boolean;
}

/**
 * Écran d'encodage, option "2b" validée.
 *
 * DEUX ZONES, jamais mélangées (voir DOCUMENTATION §1) :
 *   Zone 1 — "TEMPS DE TRAVAIL"          : bac neutre gris, une seule carte, aucun vert.
 *   Zone 2 — "MATÉRIEL PAR INTERVENTION" : bac vert clair, progression + cartes + validation.
 * Chaque zone porte un numéro d'étape et un titre : ce sont deux déclarations distinctes,
 * pas une seule liste. Ne jamais fusionner les deux bacs.
 */
export function EncodingSection(props: EncodingSectionProps) {
  const { interventions, hours, onOpenHours, onValidate, autoScroll = true, ...list } = props;
  return (
    <div className="enc">
      <section className="zone zone--time" aria-labelledby="zone-temps">
        <header className="zone__head">
          <span className="zone__step" aria-hidden="true">1</span>
          <h2 className="zone__title" id="zone-temps">TEMPS DE TRAVAIL</h2>
          <span className="zone__meta">1 saisie</span>
        </header>
        <WorkedHoursCard hours={hours} onOpen={onOpenHours} />
      </section>

      <section className="zone zone--work" aria-labelledby="zone-materiel">
        <header className="zone__head">
          <span className="zone__step" aria-hidden="true">2</span>
          <h2 className="zone__title" id="zone-materiel">MATÉRIEL PAR INTERVENTION</h2>
          <span className="zone__meta tabular">
            {interventions.filter((i) => i.materials.length > 0).length} / {interventions.length}
          </span>
        </header>
        <EncodingProgress interventions={interventions} />
        <InterventionsList interventions={interventions} autoScroll={autoScroll} {...list} />
        <EncodingFooter interventions={interventions} onValidate={onValidate} />
      </section>
    </div>
  );
}
