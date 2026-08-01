import React from 'react';
import { MaterialLineData } from '../types';

interface MaterialLineProps {
  material: MaterialLineData;
  onInc: () => void;
  onDec: () => void;
}

/** Compact line: "Ancre FiberTak ........ x3" with small +/- (26px), never a full stepper. */
export function MaterialLine({ material, onInc, onDec }: MaterialLineProps) {
  return (
    <div className="material-line">
      <div className="material-line__info">
        <div className="material-line__name">{material.name}</div>
        <div className="material-line__meta">
          <span className="material-line__ref">{material.reference}</span>
          {material.isNew && <span className="badge badge--new">Nouveau</span>}
          {material.notFound && <span className="badge badge--warn">À préciser</span>}
        </div>
      </div>
      <div className="material-line__qty">
        <button type="button" aria-label="Diminuer la quantité" onClick={onDec}>−</button>
        <span className="tabular">x{material.qty}</span>
        <button type="button" aria-label="Augmenter la quantité" onClick={onInc}>+</button>
      </div>
    </div>
  );
}
