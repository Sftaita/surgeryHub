import type { Mission } from '../api/missions.types';
import { formatMissionStatus } from '../utils/missions.format';
import { deriveStatus } from './deriveStatus';
import type { EncodingState, MissionLockLevel, MissionPhase, MissionStatus, MissionStatusInput } from './types';

/** Du plus restrictif au moins restrictif : seul le premier verrou servi est écrit. */
const LOCK_PRIORITY: MissionLockLevel[] = ['CLOSED', 'DOCUMENT', 'FINANCIAL_CALCULATION', 'ENCODING'];

type StatusSource = Pick<Mission, 'status' | 'instrumentist' | 'encoding' | 'submittedAt'>;

/**
 * Adaptateur API → MissionStatusInput. Ne lit que des faits servis par le backend : statut,
 * EncodingState (EncodingStateResolver) et verrous réels (D-172). Aucune date comparée.
 */
export function toStatusInput(mission: StatusSource): MissionStatusInput {
  const status = String(mission.status ?? '');
  const enc = mission.encoding ?? null;
  const state = enc?.state;

  const encoding: EncodingState =
    status === 'VALIDATED' || status === 'CLOSED' || state === 'VALIDATED' || state === 'LOCKED' ? 'validated'
    : status === 'SUBMITTED' || state === 'SUBMITTED' ? 'submitted'
    : status === 'ENCODING_IN_PROGRESS' || state === 'IN_PROGRESS' ? 'draft'
    : 'none';

  // `started` : début planifié passé, servi avec la même horloge que `state` (jamais l'horloge
  // du navigateur) — une mission OPEN commencée est « Non couverte » avant sa fin.
  const phase: MissionPhase =
    state === 'TO_ENCODE' || state === 'NOT_DECLARED' ? 'ended'
    : status === 'IN_PROGRESS' || enc?.started === true ? 'started'
    : 'upcoming';

  const lock = LOCK_PRIORITY.map((level) => enc?.locks.find((l) => l.level === level)).find((l) => l != null) ?? null;

  return {
    published: status !== 'DRAFT',
    cancelled: status === 'CANCELLED',
    hasInstrumentiste: mission.instrumentist != null,
    phase,
    encoding,
    submittedAt: mission.submittedAt ?? enc?.finalizedBy?.at ?? null,
    submittedBySurgeon: enc?.finalizedBy?.role === 'SURGEON',
    locked: lock != null,
    lockLevel: lock?.level ?? null,
    lockLabel: lock?.label ?? null,
    // Le verrou d'encodage est posé par la validation (D-070) : sa date et son auteur.
    lockedAt: enc?.validatedBy?.at ?? null,
    lockedBy: enc?.validatedBy?.name ?? null,
  };
}

/** Statut unique affiché pour une mission de l'API. */
export function missionDisplayStatus(mission: StatusSource): MissionStatus {
  const status = String(mission.status ?? '');
  if (status === 'DECLARED' || status === 'REJECTED') {
    return { key: 'autre', label: formatMissionStatus(status), caption: '' };
  }
  return deriveStatus(toStatusInput(mission));
}
