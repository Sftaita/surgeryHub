import React, { useState } from 'react';
import { WorkedHours } from '../types';
import { Modal } from './Modal';
import { StepperRow } from './StepperRow';

function fmtT(m: number) { return String(Math.floor(m / 60)).padStart(2, '0') + 'h' + String(m % 60).padStart(2, '0'); }
function fmtDur(m: number) { return Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0'); }

interface WorkedHoursSheetProps {
  initial: WorkedHours;
  scheduled: string;
  onClose: () => void;
  onSave: (h: WorkedHours) => void;
}

/**
 * Modal opened by clicking WorkedHoursRow. Steppers only (no keyboard),
 * optional "ends the next day" checkbox, live-recalculated total.
 * "Enregistrer les heures" is the only thing that writes to mission.hours.
 */
export function WorkedHoursSheet({ initial, scheduled, onClose, onSave }: WorkedHoursSheetProps) {
  const [h, setH] = useState<WorkedHours>(initial);
  const endEff = h.end + (h.nextDay ? 1440 : 0);
  const total = Math.max(0, endEff - h.start - h.pause);

  return (
    <Modal title="Heures prestées" onClose={onClose}>
      <div className="hours-sheet__planned">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
        Horaire prévu : <strong className="tabular">{scheduled}</strong>
      </div>

      <div className="form-stack">
        <StepperRow label="Début" value={fmtT(h.start)} onMinus={() => setH((s) => ({ ...s, start: Math.max(0, s.start - 15) }))} onPlus={() => setH((s) => ({ ...s, start: Math.min(s.nextDay ? 1425 : s.end - 15, s.start + 15) }))} />
        <StepperRow label="Fin" value={fmtT(h.end) + (h.nextDay ? ' (+1j)' : '')} onMinus={() => setH((s) => ({ ...s, end: Math.max(s.nextDay ? 0 : s.start + 15, s.end - 15) }))} onPlus={() => setH((s) => ({ ...s, end: Math.min(1425, s.end + 15) }))} />

        <label className="checkbox-line" style={{ paddingLeft: 86 }}>
          <input type="checkbox" checked={h.nextDay} onChange={(e) => setH((s) => ({ ...s, nextDay: e.target.checked }))} />
          Se termine le lendemain <span style={{ fontWeight: 400, color: 'var(--gray-400)' }}>(après minuit)</span>
        </label>

        <StepperRow label="Pause" value={h.pause + ' min'} onMinus={() => setH((s) => ({ ...s, pause: Math.max(0, s.pause - 15) }))} onPlus={() => setH((s) => ({ ...s, pause: Math.min(Math.max(0, endEff - s.start - 15), s.pause + 15) }))} />
      </div>

      <div className="total-box">
        <span>Total presté</span>
        <span className="total-box__value tabular">{fmtDur(total)}</span>
      </div>

      <button type="button" className="btn btn--primary btn--full" onClick={() => onSave(h)}>Enregistrer les heures</button>
      <button type="button" className="btn btn--ghost btn--full" onClick={onClose}>Annuler</button>
    </Modal>
  );
}
