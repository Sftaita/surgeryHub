import React from 'react';

interface QtyStepperProps {
  qty: number;
  onInc: () => void;
  onDec: () => void;
  min?: number;
  label?: string;
}

/** Quantité : deux boutons 32px dans une pilule creusée, chiffres tabulaires, jamais de champ clavier. */
export function QtyStepper({ qty, onInc, onDec, min = 1, label }: QtyStepperProps) {
  return (
    <div className="qty" role="group" aria-label={label ? `Quantité — ${label}` : 'Quantité'}>
      <button type="button" className="qty__btn" onClick={onDec} disabled={qty <= min} aria-label="Diminuer la quantité">−</button>
      <span className="qty__value tabular" aria-live="polite">x{qty}</span>
      <button type="button" className="qty__btn" onClick={onInc} aria-label="Augmenter la quantité">+</button>
    </div>
  );
}
