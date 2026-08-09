import type { MissionType } from "../../missions/api/missions.types";

/**
 * Manager-side surgeon mission request types (Lot 5, D-099) — mirrors
 * ManagerSurgeonMissionRequestController's serialize().
 */
export interface ManagerSurgeonMissionRequest {
  id: number;
  surgeon: { id: number; displayName: string } | null;
  site: { id: number; name: string } | null;
  type: MissionType;
  startAt: string; // ISO
  endAt: string; // ISO
  comment: string | null;
  status: "PENDING" | "ACCEPTED" | "REJECTED";
  createdAt: string; // ISO
  reviewedBy: { id: number; displayName: string } | null;
  reviewedAt: string | null;
  reviewComment: string | null;
  createdMissionId: number | null;
}
