import React from 'react';

interface StepperRowProps {
  label: string;
  value: string;
  onMinus: () => void;
  onPlus: () => void;
}

/** The "sélectionneur d'heures" pattern: no keyboard input, +/- 46px buttons, tabular value. */
export function StepperRow({ label, value, onMinus, onPlus }: StepperRowProps) {
  return (
    <div className="stepper-row">
      <span className="stepper-row__label">{label}</span>
      <button type="button" className="stepper-row__btn" aria-label={`Diminuer ${label}`} onClick={onMinus}>−</button>
      <span className="stepper-row__value">{value}</span>
      <button type="button" className="stepper-row__btn" aria-label={`Augmenter ${label}`} onClick={onPlus}>+</button>
    </div>
  );
}
