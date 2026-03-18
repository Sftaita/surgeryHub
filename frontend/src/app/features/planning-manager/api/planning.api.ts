import { apiClient } from "../../api/apiClient";

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

export type TemplateType = "PAIR" | "IMPAIR";
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

export interface PlanningSlot {
  id: number;
  dayOfWeek: number;
  period: SlotPeriod;
  startTime: string;
  endTime: string;
  missionType: "BLOCK" | "CONSULTATION";
  surgeon: UserRef;
  instrumentist?: UserRef | null;
  site?: { id: number; name: string } | null;
}

export interface PlanningTemplate {
  id: number;
  type: TemplateType;
  dateStart: string;
  dateEnd: string | null;
  site?: { id: number; name: string } | null;
  slots: PlanningSlot[];
  createdAt: string;
}

export interface Absence {
  id: number;
  user: UserRef;
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
}

export interface SuggestedInstrumentist {
  id: number;
  name: string;
  email: string;
  score: number;
  hasHistory: boolean;
  specialtyMatch: boolean;
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
  dateStart: string;
  dateEnd?: string | null;
  siteId?: number | null;
}): Promise<PlanningTemplate> {
  const res = await apiClient.post("/api/planning/templates", data);
  return res.data;
}

export async function deleteTemplate(id: number): Promise<void> {
  await apiClient.delete(`/api/planning/templates/${id}`);
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
  data: Partial<{ startTime: string; endTime: string; instrumentistId: number | null; missionType: string }>
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

export async function deleteAbsence(id: number): Promise<void> {
  await apiClient.delete(`/api/absences/${id}`);
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
}): Promise<{ created: number; updated: number; skipped: number }> {
  const res = await apiClient.post("/api/planning/generate", data);
  return res.data;
}

export async function getSuggestedInstrumentists(missionId: number): Promise<SuggestedInstrumentist[]> {
  const res = await apiClient.get(`/api/missions/${missionId}/suggested-instrumentists`);
  return res.data;
}

// ─── Deploy API ───────────────────────────────────────────────────────────────

export async function deployPlanning(data: {
  from: string;
  to: string;
  siteId?: number | null;
}): Promise<{ instrumentistsPdfsSent: number; surgeonsPdfsSent: number }> {
  const res = await apiClient.post("/api/planning/deploy", data);
  return res.data;
}

// ─── User specialties API ─────────────────────────────────────────────────────

export async function updateUserSpecialties(userId: number, specialties: string[]): Promise<void> {
  await apiClient.patch(`/api/users/${userId}/specialties`, { specialties });
}
