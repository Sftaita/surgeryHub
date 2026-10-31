import type { MissionType } from "../../missions/api/missions.types";

/**
 * Surgeon mission request types (Lot 5, D-099) — mirrors SurgeonMissionRequestController's
 * serialize(). `status` is one of PENDING/ACCEPTED/REJECTED, both terminal states final
 * in V1 (no CANCELLED, no re-review). `createdMissionId` is set only once ACCEPTED.
 */
export type SurgeonMissionRequestStatus = "PENDING" | "ACCEPTED" | "REJECTED";

export interface SurgeonMissionRequest {
  id: number;
  site: { id: number; name: string } | null;
  type: MissionType;
  startAt: string; // ISO
  endAt: string; // ISO
  comment: string | null;
  status: SurgeonMissionRequestStatus;
  createdAt: string; // ISO
  reviewedAt: string | null;
  reviewComment: string | null;
  createdMissionId: number | null;
}

export interface CreateSurgeonMissionRequestBody {
  siteId: number;
  type: MissionType;
  startAt: string;
  endAt: string;
  comment?: string;
}
