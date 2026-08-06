/**
 * Self-service absence types (Lot 3, D-097) — mirrors SelfAbsenceController's
 * serialize()/serializeImpactedMission(). Single day is represented on the backend as
 * dateStart === dateEnd (see docs/decisions.md D-097) — this convention is never exposed to
 * the user, only used internally by AbsenceFormSheet to decide which segment to preselect.
 */
export interface SelfAbsence {
  id: number;
  dateStart: string; // "YYYY-MM-DD"
  dateEnd: string;
  reason: string | null;
  createdAt: string; // ISO
  /** false once dateEnd has fully passed — self-service edit/delete are then both forbidden. */
  editable: boolean;
}

/**
 * "Counterpart" is already resolved server-side relative to the viewer — the absent user is
 * necessarily either the mission's surgeon or its instrumentist, so this is simply "the other
 * party". `null` only happens when the viewer is the surgeon and the mission has no
 * instrumentist yet ("À couvrir").
 */
export interface AbsenceImpactedMission {
  missionId: number;
  startAt: string; // ISO
  endAt: string; // ISO
  siteName: string | null;
  counterpart: { id: number; name: string } | null;
}

export interface CreateSelfAbsenceBody {
  dateStart: string;
  dateEnd: string;
  reason?: string | null;
}

export interface UpdateSelfAbsenceBody {
  dateStart?: string;
  dateEnd?: string;
  reason?: string | null;
}

export interface SelfAbsenceMutationResult extends SelfAbsence {
  missionsImpactedCount: number;
}
