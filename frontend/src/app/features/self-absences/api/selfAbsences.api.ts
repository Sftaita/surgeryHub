import { apiClient } from "../../../api/apiClient";
import type {
  SelfAbsence,
  AbsenceImpactedMission,
  CreateSelfAbsenceBody,
  UpdateSelfAbsenceBody,
  SelfAbsenceMutationResult,
} from "./selfAbsences.types";

/**
 * Self-service absence API (Lot 3, D-097) — GET/POST/PATCH/DELETE /api/absences/mine.
 * Shared as-is by both /app/s/absences and /app/i/absences (never a role-specific client).
 * Ownership is always server-enforced (absence.user = authenticated user) — no userId is
 * ever sent from here, not even on create.
 */

export async function fetchMyAbsences(): Promise<SelfAbsence[]> {
  const { data } = await apiClient.get("/api/absences/mine");
  return data;
}

export async function fetchAbsenceImpactPreview(dateStart: string, dateEnd: string): Promise<AbsenceImpactedMission[]> {
  const { data } = await apiClient.get("/api/absences/mine/impact-preview", { params: { dateStart, dateEnd } });
  return data;
}

export async function createMyAbsence(body: CreateSelfAbsenceBody): Promise<SelfAbsenceMutationResult> {
  const { data } = await apiClient.post("/api/absences/mine", body);
  return data;
}

export async function updateMyAbsence(id: number, body: UpdateSelfAbsenceBody): Promise<SelfAbsenceMutationResult> {
  const { data } = await apiClient.patch(`/api/absences/mine/${id}`, body);
  return data;
}

export async function deleteMyAbsence(id: number, notifyBlockManagementCancellation?: boolean): Promise<void> {
  await apiClient.delete(`/api/absences/mine/${id}`, {
    params: notifyBlockManagementCancellation !== undefined ? { notifyBlockManagementCancellation } : undefined,
  });
}

/** Communication des absences chirurgiens — Lot B (D-114). */
export interface AbsenceDeletionInfoSite {
  siteId: number;
  siteName: string | null;
  notificationSentAt: string | null;
}

export interface AbsenceDeletionInfo {
  blockManagementAlreadyNotified: boolean;
  sites: AbsenceDeletionInfoSite[];
}

export async function fetchMyAbsenceDeletionInfo(id: number): Promise<AbsenceDeletionInfo> {
  const { data } = await apiClient.get(`/api/absences/mine/${id}/deletion-info`);
  return data;
}

/** BUG A (2026-09-09) — "Retirer mon absence pour ce jour" from the claim-blocked-by-absence UX. */
export interface RemoveAbsenceDayResult {
  removedDate: string;
  remainingAbsences: SelfAbsence[];
}

export async function removeMyAbsenceDay(absenceId: number, date: string): Promise<RemoveAbsenceDayResult> {
  const { data } = await apiClient.post(`/api/absences/mine/${absenceId}/remove-day`, { date });
  return data;
}
