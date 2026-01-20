import type { MissionType, SchedulePrecision } from "./missions.types";

export type MissionPatchBody = {
  startAt: string;
  endAt: string;
  schedulePrecision: SchedulePrecision;
  type: MissionType;

  /**
   * Lot 2a (verrouillé) :
   * Le site n’est pas éditable côté UI, mais l’API PATCH l’exige.
   * Le frontend renvoie donc le siteId inchangé, dérivé de la mission.
   */
  siteId: number;
};

export type PublishScope = "POOL" | "TARGETED";

export type PublishMissionBody =
  | { scope: "POOL" }
  | { scope: "TARGETED"; targetUserId: number };

/**
 * SAFE helper
 * Peut être appelé avec mission undefined au premier render.
 * Utilisé pour dériver le siteId à renvoyer dans MissionPatchBody (Lot 2a).
 */
export function getMissionSiteId(
  m?: {
    siteId?: number;
    site?: { id: number } | null;
  } | null
): number | undefined {
  if (!m) return undefined;
  if (typeof m.siteId === "number") return m.siteId;
  if (m.site && typeof m.site.id === "number") return m.site.id;
  return undefined;
}
