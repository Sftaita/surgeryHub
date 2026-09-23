import React from 'react';
import { MaterialLineData } from './types';
import { QtyStepper } from './QtyStepper';

interface MaterialRowProps {
  material: MaterialLineData;
  onInc: () => void;
  onDec: () => void;
  /** Retire la référence de l'intervention. Sans ce callback, la corbeille n'est pas rendue. */
  onRemove?: () => void;
}

function initials(brand: string) {
  return brand.split(/[\s&·-]+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
}

/** Une référence matériel : chip fabricant, nom + badge catégorie, fabricant · référence, quantité, suppression. */
export function MaterialRow({ material, onInc, onDec, onRemove }: MaterialRowProps) {
  const chip = material.chip || initials(material.brand);
  return (
    <div className="mat">
      <span className="mat__chip" aria-hidden="true">{chip}</span>
      <div className="mat__info">
        <div className="mat__nameline">
          <span className="mat__name">{material.name}</span>
          {material.tag && <span className="tag tag--cat">{material.tag}</span>}
          {material.isNew && <span className="tag tag--new">Nouveau</span>}
          {material.notFound && <span className="tag tag--warn">À préciser</span>}
        </div>
        <div className="mat__meta">{material.brand} · {material.reference}</div>
      </div>
      <QtyStepper qty={material.qty} onInc={onInc} onDec={onDec} label={material.name} />
      {onRemove && (
        <button type="button" className="mat__remove" onClick={onRemove} aria-label={`Retirer ${material.name}`} title="Retirer ce matériel">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
        </button>
      )}
    </div>
  );
}
