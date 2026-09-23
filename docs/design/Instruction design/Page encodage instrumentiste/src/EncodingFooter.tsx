import React from 'react';
import { InterventionData } from './types';

interface EncodingFooterProps {
  interventions: InterventionData[];
  onValidate: () => void;
}

/** Pied vert profond : rappel de la progression + "Valider", inactif tant que tout n'est pas encodé. */
export function EncodingFooter({ interventions, onValidate }: EncodingFooterProps) {
  const done = interventions.filter((it) => it.materials.length > 0).length;
  const left = interventions.length - done;
  const allDone = left === 0 && interventions.length > 0;

  return (
    <div className="efoot">
      <div className="efoot__text">
        <p className="efoot__title tabular">Terminer l’encodage · {done}/{interventions.length}</p>
        <p className="efoot__sub">
          {allDone
            ? 'Tout est encodé. Vous pouvez valider la mission.'
            : `Encore ${left > 1 ? `${left} interventions` : '1 intervention'} et la mission est complète.`}
        </p>
      </div>
      <button type="button" className={`efoot__cta${allDone ? '' : ' efoot__cta--off'}`} onClick={onValidate} disabled={!allDone}>
        {allDone && <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.8} strokeLinecap="round" strokeLinejoin="round"><path d="m5 13 4 4L19 7" /></svg>}
        Valider
      </button>
    </div>
  );
}
