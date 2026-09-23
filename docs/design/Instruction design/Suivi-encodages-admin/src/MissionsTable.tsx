import React from 'react';
import { MissionRow } from './types';
import { MissionRowItem } from './MissionRowItem';
import { Icon } from './Icon';

interface Props {
  rows: MissionRow[];
  selectedId?: number;
  onOpen: (id: number) => void;
  /** Libellé long à partir de la clé `date` : "lun. 7 sept." → "Lundi 7 septembre". */
  dayLabel: (date: string) => string;
}

/** Tableau groupé par jour. Les en-têtes de jour sont collants (position: sticky). */
export function MissionsTable({ rows, selectedId, onOpen, dayLabel }: Props) {
  const order: string[] = [];
  const byDate: Record<string, MissionRow[]> = {};
  rows.forEach((r) => {
    if (!byDate[r.date]) { byDate[r.date] = []; order.push(r.date); }
    byDate[r.date].push(r);
  });

  return (
    <div className="sec-table">
      <div className="sec-table__head">
        <span className="sec-col-time">HEURE</span>
        <span className="sec-col-nurse">INSTRUMENTISTE</span>
        <span className="sec-col-who">CHIRURGIEN · SITE</span>
        <span className="sec-col-state">ENCODAGE</span>
        <span className="sec-col-hours">HEURES</span>
        <span className="sec-col-finance">FINANCE</span>
        <span className="sec-col-chev" />
      </div>
      <div className="sec-table__body">
        {order.map((date) => {
          const list = byDate[date];
          const late = list.filter((r) => r.late).length;
          const planned = list.reduce((a, r) => a + parseInt(r.planTotal, 10), 0);
          return (
            <React.Fragment key={date}>
              <div className="sec-day">
                <span className="sec-day__label">{dayLabel(date)}</span>
                <span className="sec-day__count tabular">{list.length} {list.length > 1 ? 'missions' : 'mission'}</span>
                <span className="sec-day__spacer" />
                {late > 0 && (
                  <span className="sec-day__alert"><Icon name="clock" size={12} stroke={2.6} />{late} en retard</span>
                )}
                <span className="sec-day__hours tabular">{planned}h planifiées</span>
              </div>
              {list.map((r) => (
                <MissionRowItem key={r.id} row={r} selected={r.id === selectedId} onOpen={() => onOpen(r.id)} />
              ))}
            </React.Fragment>
          );
        })}
      </div>
    </div>
  );
}
