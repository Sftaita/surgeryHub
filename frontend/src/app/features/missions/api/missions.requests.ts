export type SchedulePrecision = "APPROXIMATE" | "EXACT";
export type MissionType = "BLOCK" | "CONSULTATION";

export interface UpdateMissionRequest {
  startAt: string; // ISO 8601 avec timezone (ex: 2026-01-05T08:00:00+01:00)
  endAt: string; // ISO 8601 avec timezone
  schedulePrecision: SchedulePrecision;
  type: MissionType;
  siteId: number;
}

export type PublishScope = "POOL" | "TARGETED";

export type PublishMissionRequest =
  | { scope: "POOL" }
  | { scope: "TARGETED"; targetUserId: number };
