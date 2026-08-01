import React from 'react';
import { MissionData } from '../types';

/** Card 1 — mission info, read-only, no controls of any kind. */
export function MissionReadOnlyCard({ mission }: { mission: MissionData }) {
  return (
    <div className="mission-card">
      <div className="mission-card__top">
        <span className="mission-card__avatar">
          <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="8" r="5" /><path d="M20 21a8 8 0 0 0-16 0" /></svg>
        </span>
        <div className="mission-card__body">
          <div className="mission-card__surgeon">{mission.surgeon}</div>
          <div className="mission-card__specialty">{mission.specialty}</div>
        </div>
      </div>
      <div className="mission-card__rule" />
      <div className="mission-card__lines">
        <div className="mission-card__line">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" /></svg>
          <span><strong>{mission.site}</strong> · {mission.address}</span>
        </div>
        <div className="mission-card__line">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="17" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
          <span className="tabular">{mission.date} · {mission.scheduled}</span>
        </div>
      </div>
    </div>
  );
}
