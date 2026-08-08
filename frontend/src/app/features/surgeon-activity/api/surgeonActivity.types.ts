/**
 * Surgeon activity types (Lot 4, D-098) — mirrors SurgeonActivityController's response.
 * `interventionTypeId` is `null` only for pre-Lot-5 legacy rows without an InterventionType
 * FK (grouped separately by their snapshot code — see SurgeonActivityService docblock).
 */
export interface SurgeonActivityIntervention {
  interventionTypeId: number | null;
  label: string;
  count: number;
}

export interface SurgeonActivity {
  period: { from: string; to: string };
  missionCount: number;
  interventionCount: number;
  /** Déjà triée par le backend : count DESC puis label ASC. */
  interventions: SurgeonActivityIntervention[];
}
