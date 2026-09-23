import React from 'react';
import { MissionRow } from './types';
import { STATE_TONE, initials } from './tokens';
import { HoursGauge } from './HoursGauge';
import { Icon } from './Icon';

interface Props { row: MissionRow; selected: boolean; onOpen: () => void; }

/** Une ligne du tableau desktop (72px). Toute la ligne ouvre le tiroir. */
export function MissionRowItem({ row, selected, onOpen }: Props) {
  const tone = STATE_TONE[row.state];
  return (
    <button type="button" className={`sec-row${selected ? ' sec-row--selected' : ''}`} onClick={onOpen}>
      <span className="sec-row__time tabular">{row.time}</span>
      <span className="sec-row__nurse">
        <span className="sec-avatar">{initials(row.nurse)}</span>
        <span className="sec-row__nursename">{row.nurse}</span>
      </span>
      <span className="sec-row__who">
        <span className="sec-row__surgeon">{row.surgeon}</span>
        <span className="sec-row__site">{row.site}</span>
      </span>
      <span className="sec-row__state">
        <span className="sec-pill" style={{ background: tone.bg, color: tone.fg }}>{row.state}</span>
        {row.late && <span className="sec-pill sec-pill--late"><Icon name="clock" size={12} stroke={2.6} />retard</span>}
      </span>
      <span className="sec-row__hours">
        <HoursGauge real={row.realTotal} plan={row.planTotal} pct={row.realPct} tone={row.gapTone} />
      </span>
      <span className="sec-row__finance tabular" style={{ color: row.finance === '—' ? 'var(--gray-300)' : row.finance === 'à valider' ? 'var(--blue-700)' : 'var(--gray-950)' }}>
        {row.finance}
      </span>
      <span className="sec-row__chev"><Icon name="chevronRight" size={17} /></span>
    </button>
  );
}
