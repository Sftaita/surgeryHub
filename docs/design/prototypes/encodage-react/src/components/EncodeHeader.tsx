import React from 'react';
import { MissionData } from '../types';

interface EncodeHeaderProps {
  mission: MissionData;
  savedAt: string;
  onBack: () => void;
}

/** Dark header (distinct from the app's main brand header) — signals "you're inside a task", not a tab. */
export function EncodeHeader({ mission, savedAt, onBack }: EncodeHeaderProps) {
  return (
    <div className="encode-header">
      <div className="encode-header__top">
        <button type="button" className="encode-header__back" aria-label="Retour" onClick={onBack}>
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round"><path d="m15 18-6-6 6-6" /></svg>
        </button>
        <span className="encode-header__eyebrow">ENCODAGE MISSION</span>
        <span style={{ flex: 1 }} />
        <span className="encode-header__saved">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M17.5 19a4.5 4.5 0 1 0-.9-8.9 6 6 0 1 0-11.1 3" /><path d="m9 15 3 3 5-5" /></svg>
          {savedAt}
        </span>
      </div>
      <h1 className="encode-header__title">Mission #{mission.number}</h1>
      <div className="encode-header__tags">
        <span className="encode-header__tag">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
          {mission.date}
        </span>
        <span className="encode-header__tag">{mission.type}</span>
      </div>
    </div>
  );
}
