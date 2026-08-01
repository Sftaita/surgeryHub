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

// ─── Versions list API ────────────────────────────────────────────────────────

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

// ─── User specialties API ─────────────────────────────────────────────────────

export async function updateUserSpecialties(userId: number, specialties: string[]): Promise<void> {
  await apiClient.patch(`/api/users/${userId}/specialties`, { specialties });
}
