import { apiClient } from "../../../api/apiClient";

// ─── Constants ───────────────────────────────────────────────────────────────

export const SPECIALTIES = [
  { value: "GENOU",          label: "Genou" },
  { value: "EPAULE",         label: "Épaule" },
  { value: "HANCHE",         label: "Hanche" },
  { value: "RACHIS",         label: "Rachis" },
  { value: "MAIN",           label: "Main / Poignet" },
  { value: "PIED",           label: "Pied / Cheville" },
  { value: "NEUROCHIRURGIE", label: "Neurochirurgie" },
  { value: "CARDIOTHORACIQUE", label: "Cardiothoracique" },
  { value: "VISCERAL",       label: "Viscéral" },
  { value: "UROLOGIE",       label: "Urologie" },
  { value: "GYNECOLOGIE",    label: "Gynécologie" },
  { value: "PEDIATRIQUE",    label: "Pédiatrique" },
];

export const DAY_LABELS = ["", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"];

// ─── Types ───────────────────────────────────────────────────────────────────

export type TemplateType = "PAIR" | "IMPAIR" | "TOUTES";
export type SlotPeriod = "AM" | "PM";
export type CoverageStatus = "COVERED" | "UNCOVERED" | "MODIFIED" | "CONFLICT" | "SKIPPED";

export interface UserRef {
  id: number;
  firstname?: string | null;
  lastname?: string | null;
  email: string;
  specialties?: string[];
}

export function userName(u: UserRef): string {
  const n = `${u.firstname ?? ""} ${u.lastname ?? ""}`.trim();
  return n || u.email;
}

/** Compact user reference as serialized inside PlanningSlot (backend returns {id, name}) */
export interface SlotUser {
  id: number;
  name: string;
}

export interface PlanningSlot {
  id: number;
  dayOfWeek: number;
  period: SlotPeriod;
  startTime: string;
  endTime: string;
  missionType: "BLOCK" | "CONSULTATION";
  surgeon: SlotUser;
  instrumentist?: SlotUser | null;
  site?: { id: number; name: string } | null;
}

export interface PlanningTemplate {
  id: number;
  type: TemplateType;
  label?: string | null;
  site: { id: number; name: string };
  slots: PlanningSlot[];
  createdAt: string;
}

export type PersonRole = "INSTRUMENTIST" | "SURGEON";

export interface AbsenceUserRef extends UserRef {
  role: PersonRole | null;
}

export interface Absence {
  id: number;
  user: AbsenceUserRef;
  dateStart: string;
  dateEnd: string;
  reason: string | null;
  createdAt: string;
}

export interface PreviewLine {
  date: string;
  slotId: number;
  surgeonId: number;
  surgeonName: string;
  missionType: "BLOCK" | "CONSULTATION";
  startTime: string;
  endTime: string;
  siteId: number | null;
  siteName: string | null;
  instrumentistId: number | null;
  instrumentistName: string | null;
  status: CoverageStatus;
  existingMissionId: number | null;
  /** MODIFIED only: who is currently assigned in the existing mission */
  existingInstrumentistId: number | null;
  existingInstrumentistName: string | null;
  /** true when the instrumentist was auto-assigned from a freed (SKIPPED) slot */
  freedFrom: boolean;
}

export interface SuggestedInstrumentist {
  id: number;
  name: string;
  email: string;
  score: number;
  hasHistory: boolean;
  specialtyMatch: boolean;
}

// ─── Missions (planning context) ─────────────────────────────────────────────

export async function createMission(data: {
  siteId: number;
  type: "BLOCK" | "CONSULTATION";
  startAt: string;
  endAt: string;
  surgeonUserId: number;
  instrumentistUserId?: number; // direct assignment (Option B)
}): Promise<{ id: number }> {
  const res = await apiClient.post("/api/missions", {
    schedulePrecision: "EXACT",
    ...data,
  });
  return res.data;
}

export async function publishMission(
  missionId: number,
  scope: "POOL" | "TARGETED",
  targetUserId?: number,
): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/publish`, {
    scope,
    ...(targetUserId ? { targetUserId } : {}),
  });
}

// ─── Templates API ────────────────────────────────────────────────────────────

export async function getTemplates(): Promise<PlanningTemplate[]> {
  const res = await apiClient.get("/api/planning/templates");
  return res.data;
}

export async function getTemplate(id: number): Promise<PlanningTemplate> {
  const res = await apiClient.get(`/api/planning/templates/${id}`);
  return res.data;
}

export async function createTemplate(data: {
  type: TemplateType;
  siteId: number;
  label?: string;
}): Promise<PlanningTemplate> {
  const res = await apiClient.post("/api/planning/templates", data);
  return res.data;
}

export async function deleteTemplate(id: number): Promise<void> {
  await apiClient.delete(`/api/planning/templates/${id}`);
}

export async function cloneTemplate(id: number): Promise<PlanningTemplate> {
  const res = await apiClient.post(`/api/planning/templates/${id}/clone`);
  return res.data;
}

export async function renameTemplate(id: number, label: string | null): Promise<PlanningTemplate> {
  const res = await apiClient.patch(`/api/planning/templates/${id}`, { label });
  return res.data;
}

export async function patchTemplate(id: number, data: { label?: string | null; type?: TemplateType }): Promise<PlanningTemplate> {
  const res = await apiClient.patch(`/api/planning/templates/${id}`, data);
  return res.data;
}

export async function addSlot(
  templateId: number,
  data: {
    dayOfWeek: number;
    period: SlotPeriod;
    startTime: string;
    endTime: string;
    surgeonId: number;
    missionType: "BLOCK" | "CONSULTATION";
    instrumentistId?: number | null;
    siteId?: number | null;
  }
): Promise<PlanningSlot> {
  const res = await apiClient.post(`/api/planning/templates/${templateId}/slots`, data);
  return res.data;
}

export async function updateSlot(
  templateId: number,
  slotId: number,
  data: Partial<{
    startTime: string;
    endTime: string;
    instrumentistId: number | null;
    missionType: string;
    dayOfWeek: number;
    period: string;
    surgeonId: number;
  }>
): Promise<PlanningSlot> {
  const res = await apiClient.put(`/api/planning/templates/${templateId}/slots/${slotId}`, data);
  return res.data;
}

export async function deleteSlot(templateId: number, slotId: number): Promise<void> {
  await apiClient.delete(`/api/planning/templates/${templateId}/slots/${slotId}`);
}

// ─── Absences API ─────────────────────────────────────────────────────────────

export async function getAbsences(params?: {
  userId?: number;
  from?: string;
  to?: string;
}): Promise<Absence[]> {
  const res = await apiClient.get("/api/absences", { params });
  return res.data;
}

export async function createAbsence(data: {
  userId: number;
  dateStart: string;
  dateEnd: string;
  reason?: string;
}): Promise<Absence> {
  const res = await apiClient.post("/api/absences", data);
  return res.data;
}

/**
 * "Jours isolés" mode — creates one single-day Absence row (dateStart === dateEnd) per
 * selected date, sequentially. No new backend endpoint: the model already accepts
 * dateStart === dateEnd, and every consumer (generator V1/V2, score service, alert engine)
 * already reads Absence as one-row-per-interval, so N isolated days are just N existing
 * rows — see docs/decisions.md for the ADR.
 */
export async function createIsolatedDayAbsences(data: {
  userId: number;
  dates: string[];
  reason?: string;
}): Promise<Absence[]> {
  const created: Absence[] = [];
  for (const date of data.dates) {
    created.push(await createAbsence({ userId: data.userId, dateStart: date, dateEnd: date, reason: data.reason }));
  }
  return created;
}

export async function deleteAbsence(id: number): Promise<void> {
  await apiClient.delete(`/api/absences/${id}`);
}

// ─── Absences — manager reminder emails (D-051) ───────────────────────────────

export interface AbsenceReminderPerson {
  id: number;
  name: string;
  email: string;
  role: PersonRole;
}

export interface MissingAbsencesPreview {
  count: number;
  people: AbsenceReminderPerson[];
}

export interface EncodedAbsenceGroup {
  user: AbsenceReminderPerson;
  absences: Array<{ dateStart: string; dateEnd: string; reason: string | null }>;
}

export interface EncodedAbsencesPreview {
  count: number;
  groups: EncodedAbsenceGroup[];
}

/** Both actions send one individual email per selected person — never a single fixed recipient. See D-051. */
export interface AbsenceReminderSendResult {
  sent: boolean;
  count: number;
}

export async function getMissingAbsencesPreview(): Promise<MissingAbsencesPreview> {
  const res = await apiClient.get("/api/planning/absences/missing-preview");
  return res.data;
}

export async function getEncodedAbsencesPreview(): Promise<EncodedAbsencesPreview> {
  const res = await apiClient.get("/api/planning/absences/encoded-preview");
  return res.data;
}

/** Sends one individual email per selected person, to their own address — see D-051. */
export async function requestMissingAbsences(message: string | undefined, userIds: number[]): Promise<AbsenceReminderSendResult> {
  const res = await apiClient.post("/api/planning/absences/request-missing", {
    ...(message ? { message } : {}),
    userIds,
  });
  return res.data;
}

/** Sends one individual email per selected person, to their own address — see D-051. */
export async function confirmEncodedAbsences(message: string | undefined, userIds: number[]): Promise<AbsenceReminderSendResult> {
  const res = await apiClient.post("/api/planning/absences/confirm-encoded", {
    ...(message ? { message } : {}),
    userIds,
  });
  return res.data;
}

// ─── Generation API ───────────────────────────────────────────────────────────

export async function previewPlanning(data: {
  from: string;
  to: string;
  siteId?: number | null;
  surgeonId?: number | null;
}): Promise<PreviewLine[]> {
  const res = await apiClient.post("/api/planning/preview", data);
  return res.data;
}

export async function generatePlanning(data: {
  from: string;
  to: string;
  siteId?: number | null;
  surgeonId?: number | null;
}): Promise<{ versionId: number; created: number; updated: number; skipped: number }> {
  const res = await apiClient.post("/api/planning/generate", data);
  return res.data;
}

export async function getSuggestedInstrumentists(missionId: number): Promise<SuggestedInstrumentist[]> {
  const res = await apiClient.get(`/api/missions/${missionId}/suggested-instrumentists`);
  return res.data;
}

export async function assignInstrumentist(missionId: number, instrumentistId: number | null): Promise<void> {
  await apiClient.post(`/api/missions/${missionId}/assign-instrumentist`, { instrumentistId });
}

// ─── Version API ──────────────────────────────────────────────────────────────

export interface PlanningVersionAllowedActions {
  view:        boolean;
  deploy:      boolean;  // true only when status == DRAFT
  delete:      boolean;  // true only when status == DRAFT
  downloadPdf: boolean;
  viewDiff:    boolean;
}

export interface PlanningVersionLastDeployment {
  status:      "PENDING" | "PROCESSING" | "DONE" | "FAILED";
  deployedAt:  string;
  startedAt:   string | null;
  completedAt: string | null;
  hasError:    boolean;
}

export interface PlanningVersionSummary {
  id: number;
  versionNumber: number;
  status: "DRAFT" | "ACTIVE" | "ARCHIVED";
  periodStart: string;
  periodEnd: string;
  generatedAt: string;
  deployedAt: string | null;
  archivedAt: string | null;
  site: { id: number; name: string } | null;
  generatedBy: { id: number | null; email: string | null };
  summary: {
    total: number;
    draft: number;               // DRAFT — en attente de déploiement
    open: number;                // OPEN — publiées, disponibles pool
    assigned: number;            // ASSIGNED+ — avec instrumentiste confirmé
    withoutInstrumentist: number; // DRAFT ou OPEN sans instrumentiste
    surgeonCount?: number;
    instrumentistCount?: number;
  };
  // Only present when fetched via list or show endpoint (not from inline generatePlanning result)
  allowedActions?: PlanningVersionAllowedActions;
  lastDeployment?: PlanningVersionLastDeployment | null;
}

export async function getPlanningVersion(versionId: number): Promise<PlanningVersionSummary> {
  const res = await apiClient.get(`/api/planning/versions/${versionId}`);
  return res.data;
}

// ─── Deploy API ───────────────────────────────────────────────────────────────

export async function deployPlanning(data: {
  from: string;
  to: string;
  siteId?: number | null;
  versionId?: number | null;
  selectedUncoveredMissionIds?: number[];
  sendChangeSummary?: boolean;
}): Promise<{ deploymentId: number | null; missionCount: number; openPoolCount: number }> {
  // Deploy does bulk SQL UPDATEs synchronously — allow up to 30 s for large plannings.
  // PDFs and emails are async (Messenger worker), so they don't contribute to this timeout.
  const res = await apiClient.post("/api/planning/deploy", data, { timeout: 30_000 });
  return res.data;
}

// ─── Diff API ─────────────────────────────────────────────────────────────────

export interface MissionDiffEntry {
  date: string;
  period: "AM" | "PM";
  startAt: string;
  endAt: string;
  missionType: string;
  surgeonId: number | null;
  surgeonName: string;
  instrumentistId: number | null;
  instrumentistName: string | null;
  siteName: string | null;
}

export interface PlanningDiff {
  added: MissionDiffEntry[];
  removed: MissionDiffEntry[];
  modified: Array<{
    mission: MissionDiffEntry;
    changes: {
      schedule?:     { from: { startAt: string; endAt: string }; to: { startAt: string; endAt: string } };
      instrumentist?: { from: { id: number; name: string | null } | null; to: { id: number; name: string | null } | null };
      surgeon?:      { from: { id: number; name: string | null }; to: { id: number; name: string | null } };
      site?:         { from: string | null; to: string | null };
    };
  }>;
}

export async function getVersionDiff(versionId: number): Promise<PlanningDiff> {
  const res = await apiClient.get(`/api/planning/versions/${versionId}/diff`);
  return res.data;
}

// ─── Versions list/delete/pdf API ────────────────────────────────────────────

export interface PlanningVersionsPage {
  items: PlanningVersionSummary[];
  total: number;
  page:  number;
  limit: number;
}

export async function listPlanningVersions(params?: {
  page?:       number;
  limit?:      number;
  status?:     string;
  periodFrom?: string;
  periodTo?:   string;
  siteId?:     number;
}): Promise<PlanningVersionsPage> {
  const res = await apiClient.get("/api/planning/versions", { params });
  return res.data;
}

export async function deletePlanningVersion(versionId: number): Promise<void> {
  await apiClient.delete(`/api/planning/versions/${versionId}`);
}

export async function downloadPlanningVersionPdf(versionId: number): Promise<Blob> {
  const res = await apiClient.get(`/api/planning/versions/${versionId}/pdf`, {
    responseType: "blob",
    timeout: 60_000,
  });
  return res.data;
}

export function triggerVersionPdfDownload(version: Pick<PlanningVersionSummary, "id" | "versionNumber" | "periodStart" | "periodEnd">): void {
  downloadPlanningVersionPdf(version.id).then((blob) => {
    const url = URL.createObjectURL(blob);
    const a   = document.createElement("a");
    a.href     = url;
    a.download = `planning-v${version.versionNumber}-${version.periodStart}-${version.periodEnd}.pdf`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  });
}

// ─── User specialties API ─────────────────────────────────────────────────────

export async function updateUserSpecialties(userId: number, specialties: string[]): Promise<void> {
  await apiClient.patch(`/api/users/${userId}/specialties`, { specialties });
}
