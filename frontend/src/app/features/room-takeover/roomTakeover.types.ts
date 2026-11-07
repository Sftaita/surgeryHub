/**
 * D-177 — reprise de salle : priorité exclusive de 48 h, historique manager, correspondance
 * demande chirurgien ↔ salle libérée. Miroir exact des payloads backend : rien n'est déduit
 * côté client (état, échéance, correspondance, actions possibles).
 */

/** État opérationnel d'une salle libérée (ReleasedRoomSlotHistoryService::operationalState). */
export type RoomOperationalStateName =
  | "AVAILABLE"
  | "PRIORITY_PENDING"
  | "POOL_OPEN"
  | "INSTRUMENTIST_CONFIRMED"
  | "IN_PROGRESS_OR_DONE"
  | "MISSION_CANCELLED"
  | "DRAFT";

export interface RoomPriority {
  publicationId: number | null;
  instrumentist: { id: number; name: string } | null;
  offeredAt: string | null;
  expiresAt: string | null;
  /** Cas D — la mission commence avant la fin théorique des 48 h (alerte manager). */
  startsBeforeExpiry: boolean;
}

export interface RoomOperationalState {
  state: RoomOperationalStateName;
  priority: RoomPriority | null;
  nextAutomaticAction: { type: "PRIORITY_EXPIRY"; at: string | null } | null;
}

export interface RoomHistoryActor {
  id: number;
  name: string;
  system: boolean;
}

export interface RoomHistoryEvent {
  key: string;
  at: string;
  /** AuditEventType, ou ABSENCE_DECLARED / PRIORITY_OFFER_DELIVERY / POOL_SOLICITED. */
  type: string;
  actor: RoomHistoryActor | null;
  missionId: number | null;
  details: Record<string, any>;
}

export interface RoomHistory {
  slotId: number;
  current: RoomOperationalState;
  events: RoomHistoryEvent[];
}

/** ReleasedRoomRequestMatcher — correspondance d'une demande chirurgien avec les salles libérées. */
export type ReleasedRoomMatchStatus = "NONE" | "CERTAIN" | "AMBIGUOUS" | "ALREADY_TAKEN_OVER";

export interface ReleasedRoomMatchCandidate {
  slotId: number;
  status: "AVAILABLE" | "CLAIMED";
  occurrenceDate: string;
  period: string;
  startTime: string;
  endTime: string;
  originalSurgeon: { id: number; name: string } | null;
  /** La demande tient entièrement dans les horaires de cette salle. */
  coversRequest: boolean;
}

export interface ReleasedRoomMatch {
  status: ReleasedRoomMatchStatus;
  certainSlotId: number | null;
  candidates: ReleasedRoomMatchCandidate[];
  takenOver: ReleasedRoomMatchCandidate | null;
}

/** Choix explicite du manager à l'acceptation (`roomSlot` du body). */
export type RoomSlotDecision =
  | { decision: "TAKE_OVER"; slotId: number }
  | { decision: "NONE"; justification: string };
