import React from 'react';
import { WorkedHours } from '../types';

function fmtT(m: number) { return String(Math.floor(m / 60)).padStart(2, '0') + 'h' + String(m % 60).padStart(2, '0'); }
function fmtDur(m: number) { return Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0'); }
export function summarizeHours(h: WorkedHours) {
  const total = Math.max(0, h.end + (h.nextDay ? 1440 : 0) - h.start - h.pause);
  return `${fmtT(h.start)} → ${fmtT(h.end)}${h.nextDay ? ' (+1j)' : ''} · ${fmtDur(total)}`;
}

interface WorkedHoursRowProps {
  hours: WorkedHours | null;
  onClick: () => void;
}

/**
 * Card 2 — the "Heures prestées" row. This is a full-width BUTTON, not static
 * text: clicking it opens WorkedHoursSheet (see EncodeScreen / README for the
 * full open/close behavior). Value is muted gray until hours are saved, then
 * switches to bold black tabular text.
 */
export function WorkedHoursRow({ hours, onClick }: WorkedHoursRowProps) {
  return (
    <button type="button" className="hours-row" onClick={onClick}>
      <span className="hours-row__icon">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
      </span>
      <span className="hours-row__body">
        <span className="hours-row__label">Heures prestées</span>
        <span className={`hours-row__value ${hours ? 'hours-row__value--set' : ''} tabular`}>
          {hours ? summarizeHours(hours) : 'Non renseignées'}
        </span>
      </span>
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round" className="hours-row__chevron"><path d="m9 18 6-6-6-6" /></svg>
    </button>
  );
}
