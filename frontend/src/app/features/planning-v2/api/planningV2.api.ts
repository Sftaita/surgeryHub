import { apiClient } from "../../../api/apiClient";
import type {
  SurgeonSchedulePostV2,
  SurgeonPostInput,
  PlanningOccurrenceExceptionV2,
  ExceptionInput,
  ShiftPeriodConfigV2,
  ShiftPeriod,
  SiteGroupV2,
  PlanningAlertV2,
  PlanningAlertListResponse,
  EligibleInstrumentistV2,
  PreviewLineV2,
  PreviewResponseV2,
  GeneratedPlanningV2,
  DeployResponseV2,
  CoverageSummary,
  MissionAuditEvent,
  MissionEligibilityResponse,
} from "./planningV2.types";

/** Same pattern as every other page-local helper in this codebase (no shared util exists). */
export function extractErrorV2(err: unknown): string {
  const e = err as any;
  // apiClient's response interceptor already retries once via refresh-token before a 401 ever
  // reaches here — a 401 surfacing at this point means the session is genuinely, definitively
  // expired (refresh itself failed, or there was no refresh token to try). Every planning
  // mutation (preview/generate/deploy/apply-modifications/cancel-all) routes its errors
  // through this function, so this is the single place that guarantees none of them can ever
  // show a generic/misleading message for this specific case.
  if (e?.response?.status === 401) {
    return "Votre session a expiré. Reconnectez-vous pour enregistrer vos modifications.";
  }
  return e?.response?.data?.error?.message ?? e?.message ?? String(err);
}

// ── Surgeon posts (Batch 6) ──────────────────────────────────────────────────

export async function getSurgeonPosts(params?: {
  siteId?: number;
  siteGroupId?: number;
  surgeonId?: number;
  active?: boolean;
  type?: string;
}): Promise<{ items: SurgeonSchedulePostV2[] }> {
  const res = await apiClient.get("/api/planning/surgeon-posts", { params });
  return res.data;
}

export async function createSurgeonPost(data: SurgeonPostInput): Promise<SurgeonSchedulePostV2> {
  const res = await apiClient.post("/api/planning/surgeon-posts", data);
  return res.data;
}

export async function updateSurgeonPost(id: number, data: Partial<SurgeonPostInput>): Promise<SurgeonSchedulePostV2> {
  const res = await apiClient.patch(`/api/planning/surgeon-posts/${id}`, data);
  return res.data;
}

export async function deactivateSurgeonPost(id: number): Promise<void> {
  await apiClient.delete(`/api/planning/surgeon-posts/${id}`);
}

export async function reactivateSurgeonPost(id: number): Promise<SurgeonSchedulePostV2> {
  const res = await apiClient.patch(`/api/planning/surgeon-posts/${id}`, { active: true });
  return res.data;
}

// ── Occurrence exceptions (Batch 6) ──────────────────────────────────────────

export async function getPostExceptions(postId: number): Promise<{ items: PlanningOccurrenceExceptionV2[] }> {
  const res = await apiClient.get(`/api/planning/surgeon-posts/${postId}/exceptions`);
  return res.data;
}

export async function createPostException(postId: number, data: ExceptionInput): Promise<PlanningOccurrenceExceptionV2> {
  const res = await apiClient.post(`/api/planning/surgeon-posts/${postId}/exceptions`, data);
  return res.data;
}

export async function updateException(id: number, data: Partial<ExceptionInput>): Promise<PlanningOccurrenceExceptionV2> {
  const res = await apiClient.patch(`/api/planning/exceptions/${id}`, data);
  return res.data;
}

export async function deleteException(id: number): Promise<void> {
  await apiClient.delete(`/api/planning/exceptions/${id}`);
}

// ── Shift periods (Batch 6) ──────────────────────────────────────────────────

export async function getShiftPeriods(siteId?: number): Promise<{ items: ShiftPeriodConfigV2[] }> {
  const res = await apiClient.get("/api/planning/shift-periods", { params: siteId ? { siteId } : undefined });
  return res.data;
}

export async function createShiftPeriod(data: {
  siteId: number;
  period: ShiftPeriod;
  startTime: string;
  endTime: string;
}): Promise<ShiftPeriodConfigV2> {
  const res = await apiClient.post("/api/planning/shift-periods", data);
  return res.data;
}

export async function updateShiftPeriod(
  id: number,
  data: Partial<{ period: ShiftPeriod; startTime: string; endTime: string; active: boolean }>,
): Promise<ShiftPeriodConfigV2> {
  const res = await apiClient.patch(`/api/planning/shift-periods/${id}`, data);
  return res.data;
}

export async function deactivateShiftPeriod(id: number): Promise<void> {
  await apiClient.delete(`/api/planning/shift-periods/${id}`);
}

// ── Site groups (Batch 6) ────────────────────────────────────────────────────

export async function getSiteGroups(): Promise<{ items: SiteGroupV2[] }> {
  const res = await apiClient.get("/api/planning/site-groups");
  return res.data;
}

export async function createSiteGroup(name: string): Promise<SiteGroupV2> {
  const res = await apiClient.post("/api/planning/site-groups", { name });
  return res.data;
}

export async function renameSiteGroup(id: number, name: string): Promise<SiteGroupV2> {
  const res = await apiClient.patch(`/api/planning/site-groups/${id}`, { name });
  return res.data;
}

export async function deleteSiteGroup(id: number): Promise<void> {
  await apiClient.delete(`/api/planning/site-groups/${id}`);
}

export async function addSiteToGroup(groupId: number, siteId: number): Promise<SiteGroupV2> {
  const res = await apiClient.post(`/api/planning/site-groups/${groupId}/sites`, { siteId });
  return res.data;
}

export async function removeSiteFromGroup(groupId: number, siteId: number): Promise<SiteGroupV2> {
  const res = await apiClient.delete(`/api/planning/site-groups/${groupId}/sites/${siteId}`);
  return res.data;
}

// ── Alerts (Batch 4/5) ───────────────────────────────────────────────────────

export async function getAlerts(params?: {
  status?: string;
  type?: string;
  siteId?: number;
  surgeonId?: number;
  instrumentistId?: number;
  missionStatus?: string;
  from?: string;
  to?: string;
  page?: number;
  limit?: number;
}): Promise<PlanningAlertListResponse> {
  const res = await apiClient.get("/api/planning/alerts", { params });
  return res.data;
}

export async function getAlert(id: number): Promise<PlanningAlertV2> {
  const res = await apiClient.get(`/api/planning/alerts/${id}`);
  return res.data;
}

export async function acknowledgeAlert(id: number): Promise<PlanningAlertV2> {
  const res = await apiClient.post(`/api/planning/alerts/${id}/acknowledge`);
  return res.data;
}

export async function resolveAlert(id: number, note?: string): Promise<PlanningAlertV2> {
  const res = await apiClient.post(`/api/planning/alerts/${id}/resolve`, note ? { note } : {});
  return res.data;
}

export async function ignoreAlert(id: number, note?: string): Promise<PlanningAlertV2> {
  const res = await apiClient.post(`/api/planning/alerts/${id}/ignore`, note ? { note } : {});
  return res.data;
}

export async function reassignAlert(id: number, instrumentistId: number, note?: string): Promise<PlanningAlertV2> {
  const res = await apiClient.post(`/api/planning/alerts/${id}/reassign`, { instrumentistId, ...(note ? { note } : {}) });
  return res.data;
}

export async function openAlertAsAvailable(id: number, note?: string): Promise<PlanningAlertV2> {
  const res = await apiClient.post(`/api/planning/alerts/${id}/open-as-available`, note ? { note } : {});
  return res.data;
}

export async function getEligibleInstrumentists(alertId: number): Promise<{ items: EligibleInstrumentistV2[] }> {
  const res = await apiClient.get(`/api/planning/alerts/${alertId}/eligible-instrumentists`);
  return res.data;
}

// ── Generation (Batch 9) ─────────────────────────────────────────────────────

export interface GenerationTargetInput {
  siteId?: number | null;
  siteGroupId?: number | null;
  year: number;
  month: number;
}

export async function previewPlanningV2(data: GenerationTargetInput): Promise<PreviewResponseV2> {
  const res = await apiClient.post("/api/planning/v2/preview", data);
  return res.data;
}

export interface GeneratePlanningV2Input extends GenerationTargetInput {
  previewVersion?: string;
  lines?: PreviewLineV2[];
}

export async function generatePlanningV2(data: GeneratePlanningV2Input): Promise<GeneratedPlanningV2> {
  const res = await apiClient.post("/api/planning/v2/generate", data);
  return res.data;
}

export async function deployPlanningV2(planningVersionId: number, sendPdf: boolean): Promise<DeployResponseV2> {
  const res = await apiClient.post(
    "/api/planning/v2/deploy",
    { planningVersionId, sendPdf },
    { timeout: 30_000 },
  );
  return res.data;
}

// ── Modification mode (Planning V2 unified editor) — Batch 16 ────────────────

export interface ApplyModificationsResult {
  created: number;
  updated: number;
  cancelled: number;
  released: number;
  unchanged: number;
}

/**
 * Applies a batch of editor-staged changes to an already-deployed PlanningVersion —
 * "Redéployer" in Modification mode. Triggers exactly one targeted, diff-based summary
 * email per actually-affected person server-side; never a global resend.
 */
export async function applyModifications(
  versionId: number,
  lines: PreviewLineV2[],
): Promise<ApplyModificationsResult> {
  const res = await apiClient.post(
    `/api/planning/versions/${versionId}/apply-modifications`,
    { lines },
    { timeout: 30_000 },
  );
  return res.data;
}

/**
 * "Supprimer ce mois" — cancels every cancellable mission (ASSIGNED/OPEN) of an
 * already-deployed PlanningVersion in one batch. Never a hard delete: the version and its
 * audit history are preserved, missions become CANCELLED, and exactly one targeted summary
 * email is sent per actually-affected person — same guarantees as applyModifications.
 */
export async function cancelAllMissions(versionId: number): Promise<ApplyModificationsResult> {
  const res = await apiClient.post(
    `/api/planning/versions/${versionId}/cancel-all`,
    {},
    { timeout: 30_000 },
  );
  return res.data;
}

// ── Living planning — Batch 15G ───────────────────────────────────────────────

export async function fetchCoverageSummary(versionId: number): Promise<CoverageSummary> {
  const res = await apiClient.get(`/api/planning/versions/${versionId}/coverage-summary`);
  return res.data as CoverageSummary;
}

export async function releaseMission(id: number): Promise<void> {
  await apiClient.post(`/api/missions/${id}/release`);
}

export async function cancelMission(id: number, reason?: string): Promise<void> {
  await apiClient.post(`/api/missions/${id}/cancel`, reason ? { reason } : {});
}

export async function reassignMission(id: number, instrumentistId: number): Promise<void> {
  await apiClient.post(`/api/missions/${id}/reassign`, { instrumentistId });
}

export async function fetchMissionAudit(id: number): Promise<MissionAuditEvent[]> {
  const res = await apiClient.get(`/api/missions/${id}/audit`);
  return res.data as MissionAuditEvent[];
}

export async function fetchMissionEligibleInstrumentists(missionId: number): Promise<MissionEligibilityResponse> {
  const res = await apiClient.get(`/api/missions/${missionId}/eligible-instrumentists`);
  return res.data as MissionEligibilityResponse;
}
