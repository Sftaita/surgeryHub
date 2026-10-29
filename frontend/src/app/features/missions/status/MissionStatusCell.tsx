import type { MissionStatus } from './types';
import './mission-status.css';

interface Props {
  status: MissionStatus;
  /** Tiroir de détail : pastille seule, même vocabulaire que la liste. */
  hideCaption?: boolean;
}

/** Cellule « Statut » de la liste des missions : une pastille + une ligne grise optionnelle. */
export function MissionStatusCell({ status, hideCaption = false }: Props) {
  return (
    <div className="mst-cell" data-testid="mission-status" data-status-key={status.key}>
      <span className={'mst-pill mst-pill--' + status.key}>
        <span className="mst-dot" aria-hidden="true" />
        {status.label}
      </span>
      {status.caption && !hideCaption ? <span className="mst-caption" title={status.caption}>{status.caption}</span> : null}
      {status.detail && !hideCaption ? <span className="mst-caption mst-caption--detail" title={status.detail}>{status.detail}</span> : null}
    </div>
  );
}
