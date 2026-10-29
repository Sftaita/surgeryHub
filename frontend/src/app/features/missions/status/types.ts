/** Clés de statut affichées — une seule par mission (maquette « Statut missions » § 2a). */
export type MissionStatusKey =
  | 'brouillon'
  | 'publiee'
  | 'assignee'
  | 'encours'
  | 'noncouverte'
  | 'aencoder'
  | 'encodage'
  | 'soumise'
  | 'validee'
  | 'annulee'
  /** Statut backend hors maquette (DECLARED, REJECTED) : libellé backend, pastille neutre. */
  | 'autre';

/** État de l'encodage, lu depuis le statut et MissionEncodingOverview servis par le backend. */
export type EncodingState = 'none' | 'draft' | 'submitted' | 'validated';

/**
 * Position de la mission dans le temps, telle que le backend la dit — jamais une date
 * comparée côté client : IN_PROGRESS est posé par le cron de démarrage (D-064), la fin
 * passée se lit dans EncodingState TO_ENCODE / NOT_DECLARED (EncodingStateResolver).
 */
export type MissionPhase = 'upcoming' | 'started' | 'ended';

/** Entrée de deriveStatus(), construite par toStatusInput() depuis la mission de l'API. */
export interface MissionStatusInput {
  published: boolean;
  cancelled: boolean;
  cancelledAt?: string | null;        // ISO
  hasInstrumentiste: boolean;
  phase: MissionPhase;
  encoding: EncodingState;
  encodingDraftAt?: string | null;    // ISO — dernier enregistrement du brouillon
  submittedAt?: string | null;        // ISO
  /** D-172 — encodage finalisé par le chirurgien (audit backend, jamais déduit). */
  submittedBySurgeon?: boolean;
  locked?: boolean;                   // uniquement pertinent si encoding === 'validated'
  /**
   * Verrou réel le plus restrictif servi par le backend (MissionEncodingOverview.locks) et son
   * libellé backend : encodage verrouillé, calcul financier verrouillé, document émis, clôture
   * ne sont jamais confondus.
   */
  lockLevel?: MissionLockLevel | null;
  lockLabel?: string | null;
  lockedAt?: string | null;           // ISO — date de la validation, qui pose le verrou d'encodage
  lockedBy?: string | null;           // nom complet du validateur, ex. "Samy Ftaita"
}

export type MissionLockLevel = 'ENCODING' | 'FINANCIAL_CALCULATION' | 'DOCUMENT' | 'CLOSED';

export interface MissionStatus {
  key: MissionStatusKey;
  label: string;
  /** Ligne secondaire grise. Chaîne vide = pas de ligne. */
  caption: string;
  /** Seconde ligne grise, seulement quand une information métier ne tient pas dans la première (D-172). */
  detail?: string;
}
