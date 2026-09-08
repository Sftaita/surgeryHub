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
  PreviewLineV2,
  PreviewResponseV2,
  GeneratedPlanningV2,
  DeployResponseV2,
  CoverageSummary,
  DraftReopenResponseV2,
  DraftUpdateResultV2,
  MissionAuditEvent,
  MissionEligibilityResponse,
  AlertEligibilityResponse,
  RosterEligibilityResponse,
  EligibilityEnforcementPolicy,
  VerifyConflictsResponse,
  AbsenceCommunicationSiteSettingV2,
  AbsenceCommunicationSiteSettingUpdateV2,
  BackfillPreviewV2,
  BackfillExecuteResponseV2,
  AbsenceCommunicationTypeV2,
  AbsenceCommunicationGlobalStatusV2,
  AbsenceCommunicationListResponseV2,
  AbsenceCommunicationDetailV2,
  ReleasedRoomSlotListResponse,
  ReleasedRoomSlotCountResponse,
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

// ── Communication des absences chirurgiens — Lot A/B (D-114) ────────────────

export async function getAbsenceCommunicationSettings(): Promise<{ items: AbsenceCommunicationSiteSettingV2[] }> {
  const res = await apiClient.get("/api/planning/absence-communication-settings");
  return res.data;
}

export async function updateAbsenceCommunicationSettings(
  siteId: number,
  data: AbsenceCommunicationSiteSettingUpdateV2,
): Promise<AbsenceCommunicationSiteSettingV2> {
  const res = await apiClient.patch(`/api/planning/absence-communication-settings/${siteId}`, data);
  return res.data;
}

// ── Communication des absences chirurgiens — Lot C (D-114) : rattrapage ──────

export async function previewAbsenceCommunicationBackfill(createdFrom: string): Promise<BackfillPreviewV2> {
  const res = await apiClient.post("/api/planning/absence-communications/backfill/preview", { createdFrom });
  return res.data;
}

export async function executeAbsenceCommunicationBackfill(createdFrom: string, absenceIds: number[]): Promise<BackfillExecuteResponseV2> {
  const res = await apiClient.post("/api/planning/absence-communications/backfill/execute", { createdFrom, absenceIds });
  return res.data;
}

// ── Communication des absences chirurgiens — Lot C (D-114) : journal manager ──

export interface AbsenceCommunicationJournalFilters {
  siteId?: number;
  surgeonId?: number;
  type?: AbsenceCommunicationTypeV2;
  status?: AbsenceCommunicationGlobalStatusV2;
  periodFrom?: string;
  periodTo?: string;
  page?: number;
  limit?: number;
}

export async function getAbsenceCommunicationJournal(filters: AbsenceCommunicationJournalFilters = {}): Promise<AbsenceCommunicationListResponseV2> {
  const res = await apiClient.get("/api/planning/absence-communications", { params: filters });
  return res.data;
}

export async function getAbsenceCommunicationDetail(id: number): Promise<AbsenceCommunicationDetailV2> {
  const res = await apiClient.get(`/api/planning/absence-communications/${id}`);
  return res.data;
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

export async function getEligibleInstrumentists(alertId: number): Promise<AlertEligibilityResponse> {
  const res = await apiClient.get(`/api/planning/alerts/${alertId}/eligible-instrumentists`);
  return res.data as AlertEligibilityResponse;
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

// ── Drafts — CAS D (D-115) ───────────────────────────────────────────────────

/**
 * "Ouvrir le brouillon" — reconstructs the editor from this draft's real, persisted
 * Missions (via the same claimMission() matching preview() already does for any
 * COVERED/MODIFIED line), never a blank re-preview. `divergent` is informational only.
 */
export async function reopenDraft(versionId: number): Promise<DraftReopenResponseV2> {
  const res = await apiClient.get(`/api/planning/v2/drafts/${versionId}`);
  return res.data;
}

/**
 * Saves further editor changes directly onto this draft's own Missions — never a new
 * generate()/PlanningVersion. Same `lines` shape the editor already sends to generate().
 */
export async function updateDraft(versionId: number, lines: PreviewLineV2[]): Promise<DraftUpdateResultV2> {
  const res = await apiClient.patch(`/api/planning/v2/drafts/${versionId}`, { lines }, { timeout: 30_000 });
  return res.data;
}

/**
 * "Supprimer le brouillon" — only ever succeeds for a version that is still entirely
 * DRAFT (backend enforces this, 409 PLANNING_VERSION_NOT_DRAFT otherwise). Never touches
 * a SurgeonSchedulePost or any mission outside this version.
 */
export async function deletePlanningVersionDraft(versionId: number): Promise<void> {
  await apiClient.delete(`/api/planning/versions/${versionId}`);
}

// ── Modification mode (Planning V2 unified editor) — Batch 16 ────────────────

export interface ApplyModificationsResult {
  created: number;
  updated: number;
  cancelled: number;
  released: number;
  unchanged: number;
  /** D-090 — planning-visible changes only (added/removed/modified in the diff), never a
   *  per-line-sent or per-cell-touched count. This is the number to show the manager. */
  functionalChanges: number;
  /** Distinct people who received a change-summary email for this operation. */
  usersNotified: number;
  /** Always equal to usersNotified today (one email per notified person, never more). */
  emailsSent: number;
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

/**
 * "Vérifier les conflits" — a manual safety-net audit of an already-ACTIVE PlanningVersion,
 * never a regeneration. See backend PlanningVersionAuditService (D-106) for the full rule
 * set; this call is idempotent and safe to invoke repeatedly.
 */
export async function verifyConflicts(versionId: number): Promise<VerifyConflictsResponse> {
  const res = await apiClient.post(
    `/api/planning/versions/${versionId}/verify-conflicts`,
    {},
    { timeout: 30_000 },
  );
  return res.data;
}

export interface ResendPlanningResult {
  missionCount: number;
  email: string;
}

/**
 * D-090 (anomalie fonctionnelle 1) — "Renvoyer le planning par e-mail" à un seul
 * utilisateur, indépendamment de tout redéploiement/diff. `versionId` doit être la version
 * actuellement publiée (ACTIVE) — le backend refuse explicitement un brouillon.
 */
export async function resendPlanning(versionId: number, userId: number): Promise<ResendPlanningResult> {
  const res = await apiClient.post(
    `/api/planning/versions/${versionId}/resend/${userId}`,
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

export async function fetchMissionEligibleInstrumentists(
  missionId: number,
  policy?: EligibilityEnforcementPolicy,
): Promise<MissionEligibilityResponse> {
  const res = await apiClient.get(`/api/missions/${missionId}/eligible-instrumentists`, {
    params: policy ? { policy } : undefined,
  });
  return res.data as MissionEligibilityResponse;
}

/**
 * D-102 (Lot 2) — eligibility-aware roster for a slot with no persisted Mission yet
 * (Preview Editor: new/edited preview line, bulk-assign, Mode Modification "add
 * mission" draft). Backend is the source of truth for ABSENT/SCHEDULE_CONFLICT/
 * NO_SITE_MEMBERSHIP — never recomputed here.
 */
export async function fetchRosterEligibility(params: {
  siteId?: number | null;
  date: string;
  startTime: string;
  endTime: string;
  excludeMissionId?: number | null;
  policy?: EligibilityEnforcementPolicy;
}): Promise<RosterEligibilityResponse> {
  const res = await apiClient.get(`/api/planning/v2/eligible-instrumentists`, {
    params: {
      siteId: params.siteId ?? undefined,
      date: params.date,
      startTime: params.startTime,
      endTime: params.endTime,
      excludeMissionId: params.excludeMissionId ?? undefined,
      policy: params.policy,
    },
  });
  return res.data as RosterEligibilityResponse;
}

/** « Salles libérées » (Lot D) — vue manager, tous les sites. */
export async function getAvailableRoomsForManager(params?: {
  siteId?: number;
  status?: string;
  surgeonId?: number;
  includePast?: boolean;
  page?: number;
  limit?: number;
  dateFrom?: string;
  dateTo?: string;
  period?: ShiftPeriod;
}): Promise<ReleasedRoomSlotListResponse> {
  const res = await apiClient.get("/api/planning/available-rooms", { params });
  return res.data;
}

/**
 * « Salles libérées » (Lot D) — vue chirurgien, scopée serveur à ses propres affiliations.
 * `dateFrom`/`dateTo` (intégration planning chirurgien, revue 2026-09-07) permettent au
 * calendrier de ne requêter que la fenêtre visible (mois/semaine affiché) — jamais tout
 * l'historique. `includePast` n'existe volontairement pas ici : le backend l'ignore de toute
 * façon côté chirurgien (§9), inutile de l'exposer côté client.
 */
export async function getMyAvailableRooms(params?: {
  siteId?: number;
  status?: string;
  page?: number;
  limit?: number;
  dateFrom?: string;
  dateTo?: string;
  period?: ShiftPeriod;
}): Promise<ReleasedRoomSlotListResponse> {
  const res = await apiClient.get("/api/me/available-rooms", { params });
  return res.data;
}

/** Compteur seul (badge CTA planning chirurgien) — jamais la liste complète. */
export async function getMyAvailableRoomsCount(params?: {
  siteId?: number;
  dateFrom?: string;
  dateTo?: string;
  period?: ShiftPeriod;
}): Promise<ReleasedRoomSlotCountResponse> {
  const res = await apiClient.get("/api/me/available-rooms/count", { params });
  return res.data;
}
