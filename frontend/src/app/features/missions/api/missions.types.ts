export type AllowedAction =
  | "view"
  | "edit"
  | "publish"
  | "claim" // Lot 3 — Instrumentist
  | "submit"
  | "cancel"
  | "delete"
  | "edit_encoding"
  // Lot F1 — Missions DECLARED
  | "approve"
  | "reject"
  | "edit_hours"
  | "declare"
  // compat éventuelle si backend renvoie "encoding" (reste piloté par allowedActions)
  | "encoding"
  // Lot 7 (D-070) — cycle de vie de l'encodage
  | "start_encoding"
  | "validate"
  | "reopen"
  // D-171 — manager : terminer et valider lui-même un encodage encore ouvert
  | "complete_and_validate"
  // Lot 6 (D-100) — lecture seule chirurgien, jamais un droit d'édition
  | "view_encoding"
  // D-120 — cockpit Suivi des encodages, relance manuelle
  | "remind"
  // D-136 — rappel des heures réelles manquantes (distinct de "remind")
  | "remind_hours"
  // D-125 — manager : (re)diffuser une mission OPEN sans demande en attente ;
  // instrumentiste : refuser une demande nominative ('claim' = accepter)
  | "dispatch"
  | "decline_offer"
  // D-151 — chirurgien de la mission : refuser l'instrumentiste qui l'a acceptée (dans le
  // délai), sinon demander l'intervention du manager. Décidé par le backend uniquement.
  | "refuse_assignment"
  | "request_assignment_review"
  // D-155 — activité exécutée sans instrumentiste : déclarer (chirurgien de la mission, manager), annuler (manager).
  | "declare_executed_without_instrumentist"
  | "cancel_executed_without_instrumentist"
  // Manager : réaffecter une mission ASSIGNED (déjà renvoyé par le backend).
  | "reassign"
  // D-165 — manager : corriger l'horaire prévu (OPEN/ASSIGNED, et IN_PROGRESS pour ses heures du jour).
  | "reschedule";

export type SchedulePrecision = "APPROXIMATE" | "EXACT";
export type MissionType = "BLOCK" | "CONSULTATION";

/**
 * MissionStatus (frontend) — strictement aligné sur App\Enum\MissionStatus (backend).
 * Le frontend ne déduit jamais un droit par statut : seul allowedActions fait foi.
 */
export type MissionStatus =
  | "DRAFT"
  | "OPEN"
  | "DECLARED"
  | "ASSIGNED"
  | "REJECTED"
  | "SUBMITTED"
  | "VALIDATED"
  | "CLOSED"
  | "IN_PROGRESS"
  | "ENCODING_IN_PROGRESS"
  | "CANCELLED";

export type CreateMissionBody = {
  siteId: number;
  type: MissionType;
  schedulePrecision: SchedulePrecision;
  startAt: string;
  endAt: string;
  surgeonUserId: number;
};

export type CreateMissionResult = Mission;

export type PaginatedResponse<T> = {
  items: T[];
  total: number;
  page?: number;
  limit?: number;
  /** D-172 — GET /api/missions : missions annulées masquées par `excludeCancelled` (null : aucun masquage). */
  cancelledExcluded?: number | null;
};

/**
 * D-172 — état d'encodage d'une mission, distinct de son statut de planification
 * (EncodingStateResolver côté backend, jamais recalculé ici).
 */
export type MissionEncodingState =
  | "UPCOMING" | "TO_ENCODE" | "IN_PROGRESS" | "SUBMITTED" | "VALIDATED" | "LOCKED" | "NOT_APPLICABLE" | "NOT_DECLARED";

/** D-172 — un verrou réel confirmé par le backend, par niveau (jamais déduit d'un statut). */
export type MissionLock = {
  level: "ENCODING" | "FINANCIAL_CALCULATION" | "DOCUMENT" | "CLOSED";
  label: string;
  reason: string;
};

export type MissionEncodingActor = { name: string | null; role: "INSTRUMENTIST" | "SURGEON" | "MANAGER" | null; at: string };

/** D-172 — servi aux managers seulement (liste et détail), null sinon. */
export type MissionEncodingOverview = {
  state: MissionEncodingState;
  stateLabel: string;
  /** Début planifié passé, avec la même horloge serveur que `state` — jamais comparé côté client. */
  started?: boolean;
  /** Activité déclarée réalisée sans instrumentiste (D-155) — un fait backend. */
  executedWithoutInstrumentist?: boolean;
  /** Interventions et lignes de matériel enregistrées — jamais une preuve de finalisation. */
  interventionCount?: number | null;
  materialLineCount?: number | null;
  /** Auteur de la dernière finalisation (soumission) de l'encodage. */
  finalizedBy: MissionEncodingActor | null;
  /** Auteur de la validation, quand l'encodage est validé. */
  validatedBy: MissionEncodingActor | null;
  locks: MissionLock[];
};

export type SiteListItem = {
  id: number;
  name: string;
  address?: string;
  timezone?: string;
};

export type UserListItem = {
  id: number;
  email: string;
  firstname?: string | null;
  lastname?: string | null;
  active?: boolean;
  employmentType?: string | null;
  displayName?: string | null;
  defaultCurrency?: string | null;
};

/**
 * Lot 2b (correction finale) — /api/instrumentists
 * displayName = libellé principal UI
 * aucun lien instrumentiste↔site côté frontend
 */
export type InstrumentistListItem = {
  id: number;
  email: string;
  firstname?: string | null;
  lastname?: string | null;
  active?: boolean;
  employmentType?: string | null;
  defaultCurrency?: string | null;
  displayName?: string | null;
};

export type InstrumentistsResponse = PaginatedResponse<InstrumentistListItem>;

export interface SiteRef {
  id: number;
  name?: string;
  address?: string;
  timezone?: string;
  photoPath?: string | null;
}

export interface UserRef {
  id: number;
  email: string;
  firstname?: string | null;
  lastname?: string | null;
  active?: boolean;
  employmentType?: string | null;
  displayName?: string | null;
  specialties?: string[];
  profilePicturePath?: string | null;
}

/**
 * Lot F5 — InstrumentistService (service:read / service:read_manager)
 * IMPORTANT:
 * - UI instrumentiste n'affiche jamais de champs financiers.
 * - Backend peut inclure plus de champs selon serializer groups.
 */
export interface InstrumentistService {
  id: number;

  serviceType?: string | null;
  employmentTypeSnapshot?: string | null;

  /**
   * Backend: decimal string le plus souvent.
   * On garde string|number pour robustesse UI.
   */
  hours?: string | number | null;
  hoursSource?: string | null;
  status?: string | null;

  /**
   * Champs potentiellement présents côté manager (service:read_manager).
   * ⚠️ Interdit d'affichage dans l'UI F5 (on ne les utilise pas).
   */
  consultationFeeApplied?: string | number | null;
  computedAmount?: string | number | null;

  mission?: { id: number } | null;

  [key: string]: unknown;
}

export interface Mission {
  id: number;

  type: MissionType;
  schedulePrecision: SchedulePrecision;

  startAt: string;
  endAt: string;

  site?: SiteRef | null;
  siteId?: number;

  status?: MissionStatus;

  surgeon?: UserRef | null;
  instrumentist?: UserRef | null;

  allowedActions?: AllowedAction[];

  /**
   * Socle chirurgien (Lot 2, D-095) — calculé backend (PlanningCoverageService::isCovered(),
   * source unique) : status ∈ {ASSIGNED, SUBMITTED, VALIDATED, CLOSED, IN_PROGRESS}. Le
   * frontend ne doit jamais recalculer un second set de statuts "couvert"/"à couvrir".
   */
  covered?: boolean;

  /**
   * Lot F5 — bloc service (si présent dans MissionDetailDto).
   * IMPORTANT: pas d'inférence côté frontend.
   */
  service?: InstrumentistService | null;

  /** Lot 7 (D-070) suite — commentaire instrumentiste requis à la clôture si aucune
   *  ligne de matériel active n'a été encodée (décrit les interventions réalisées).
   *  Conservé pour traçabilité même après une resoumission ultérieure avec matériel —
   *  ne jamais l'afficher comme justification active sans vérifier submittedWithoutMaterial. */
  noMaterialComment?: string | null;

  /** Figé à chaque clôture d'encodage : cette soumission précise avait-elle 0 ligne
   *  de matériel active ? Seule condition valide pour afficher noMaterialComment comme
   *  justification "aucun matériel" au manager (voir MissionDetailPage). */
  submittedWithoutMaterial?: boolean | null;

  /** D-120 — infos de relance d'encodage (cockpit Suivi des encodages). Calculées/lues
   *  côté backend (EncodingReminderService) — jamais recalculées côté frontend. */
  automaticReminderSentAt?: string | null;
  nextAutomaticReminderAt?: string | null;
  lastManualReminderAt?: string | null;
  lastManualReminderByName?: string | null;
  /** D-143 — canal de la dernière relance manuelle ('push' | 'email'). */
  lastManualReminderChannel?: string | null;
  /** D-143 — relances automatiques déjà envoyées (manager uniquement). */
  automaticReminders?: AutomaticReminder[];
  /** D-143 — dernière soumission de l'encodage (null si jamais soumis). */
  submittedAt?: string | null;

  /** D-125 — demande nominative, calculée backend (jamais re-déduite) : PENDING = OPEN, non
   *  couverte, en attente de la réponse de cet instrumentiste ; DECLINED = refusée. */
  targetedOffer?: TargetedOffer | null;
  /** D-148 — pourquoi l'encodage ne peut pas être rouvert (clôture, verrou financier), managers seulement. */
  encodingCorrectionBlockedReason?: string | null;
  /** D-153 — conséquence financière d'une correction (calcul actif à recalculer après revalidation), managers seulement. */
  encodingCorrectionFinancialNotice?: string | null;
  /** D-155 — activité exécutée sans instrumentiste (null sinon) : aucune heure, facturation firme seule. */
  executedWithoutInstrumentist?: { declaredAt: string | null; declaredByName: string | null } | null;
  /** D-171 — pourquoi « Valider l'encodage » n'est pas proposé (managers seulement), affiché tel quel. */
  encodingValidationBlockedReason?: string | null;
  /** D-171 — empreinte de l'encodage lu (managers, quand « Terminer et valider » est proposé), renvoyée telle quelle. */
  encodingRevision?: string | null;
  /** D-171 — « Terminer et valider » est une reprise : confirmation explicite exigée (managers). */
  encodingTakeover?: { instrumentistName: string; hoursMissing: boolean } | null;
  /** D-171 — dernière reprise de l'encodage par un manager (managers et instrumentiste affectée). */
  lastEncodingTakeover?: { byName: string | null; at: string; hoursMissing: boolean } | null;
  /** D-171 — encodage validé sans les heures prestées, toujours manquantes : seules elles restent déclarables. */
  hoursPendingAfterValidation?: boolean;

  /** D-172 — état d'encodage, auteur et verrous réels (managers seulement). */
  encoding?: MissionEncodingOverview | null;

  /** D-151 — affectation en cours, servie au chirurgien de la mission et aux managers seulement. */
  currentAssignment?: CurrentAssignment | null;
  /** D-151 — "ENDED" : l'instrumentiste qui consulte a eu une affectation à cette mission, terminée. */
  myAssignmentStatus?: "ENDED" | null;

  [key: string]: unknown;
}

/**
 * D-151 — calculé par le backend (MissionAssignmentRefusalPolicy), jamais recalculé ici :
 * refusable / refusalBlockedReason disent ce que le chirurgien peut faire maintenant.
 */
export type CurrentAssignment = {
  id: number;
  instrumentist: { id: number; name: string };
  source: string;
  assignedAt: string;
  /** Acceptations volontaires seulement (pool / demande nominative), sinon null. */
  refusalDeadlineAt: string | null;
  refusable: boolean;
  refusalBlockedReason:
    | "ASSIGNMENT_ALREADY_ENDED"
    | "ASSIGNMENT_NOT_REFUSABLE"
    | "REFUSAL_WINDOW_EXPIRED"
    | "REFUSAL_REQUIRES_MANAGER"
    | null;
  reviewRequestedAt: string | null;
};

export type TargetedOffer = {
  status: "PENDING" | "DECLINED";
  instrumentist: { id: number; name: string } | null;
  offeredAt: string | null;
  declinedAt: string | null;
};

/**
 * V1 "polling intelligent" — GET /api/instrumentist/missions/sync?since=ISO_DATE
 *
 * IMPORTANT:
 * - lastSyncAt côté client doit être dérivé de `serverTime` (jamais de l'heure locale).
 * - aucune donnée patient n'est jamais présente ici (MissionListDto).
 * - allowedActions reste la seule source de vérité pour les droits côté frontend.
 */
export type MissionSyncResponse = {
  serverTime: string;
  changed: boolean;
  missions: Mission[];
  removedMissionIds: number[];
};

/** D-143 — une relance automatique J+1/J+2/J+4 déjà envoyée (historique serveur). */
export type AutomaticReminder = {
  stage: "D1" | "D2" | "D4";
  sentAt: string | null;
  channels: string[];
  managersNotified: number | null;
};
