import React from 'react';

export interface FunnelSegment { label: string; count: number; color: string; }

/** Entonnoir de la semaine : une barre segmentée proportionnelle + légende chiffrée. */
export function WeekFunnel({ segments, missions, anomalies }: { segments: FunnelSegment[]; missions: number; anomalies: number }) {
  return (
    <section className="sec-funnel">
      <div className="sec-card__head">
        <span className="sec-eyebrow">AVANCEMENT DE LA SEMAINE</span>
        <span className="sec-summary tabular">{missions} missions · {anomalies} anomalie{anomalies > 1 ? 's' : ''}</span>
      </div>
      <div className="sec-funnel__bar">
        {segments.map((s) => (
          <span key={s.label} title={`${s.count} ${s.label}`} style={{ flex: s.count, background: s.color }} />
        ))}
      </div>
      <div className="sec-funnel__legend">
        {segments.map((s) => (
          <div className="sec-funnel__item" key={s.label}>
            <span className="sec-funnel__top">
              <span className="sec-funnel__dot" style={{ background: s.color }} />
              <span className="sec-funnel__num tabular">{s.count}</span>
            </span>
            <span className="sec-funnel__label">{s.label}</span>
          </div>
        ))}
      </div>
    </section>
  );
}
