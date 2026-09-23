import React from 'react';
import { GapTone } from './types';
import { GAP_BAR } from './tokens';

/** Jauge compacte du tableau : "8h45 / 10h00" + barre de remplissage colorée par l'écart. */
export function HoursGauge({ real, plan, pct, tone }: { real: string; plan: string; pct: string; tone: GapTone }) {
  const empty = real === '—' || real === 'non démarré';
  return (
    <div className="sec-hours">
      <div className="sec-hours__line tabular">
        <span className="sec-hours__real" style={{ color: empty ? 'var(--gray-400)' : 'var(--gray-950)' }}>
          {empty ? 'non démarré' : real}
        </span>
        <span className="sec-hours__plan">/ {plan}</span>
      </div>
      <div className="sec-hours__track">
        <div className="sec-hours__fill" style={{ width: pct, background: GAP_BAR[tone] }} />
      </div>
    </div>
  );
}
