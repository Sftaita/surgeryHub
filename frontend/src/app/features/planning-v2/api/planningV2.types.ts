// Planning V2 — types frozen per docs/planning-v2-architecture-freeze.md §B.
// Parallel to V1's planning.api.ts types — V1 is untouched, these are net-new.

export type MissionType = "BLOCK" | "CONSULTATION";
export type ShiftPeriod = "MATIN" | "APRES_MIDI" | "JOURNEE";
export type RecurrenceFrequency = "WEEKLY" | "MONTHLY";
export type OccurrenceExceptionType = "CANCELLED" | "MOVED" | "TIME_OVERRIDE" | "INSTRUMENTIST_OVERRIDE";
export type PlanningAlertType =
  | "SURGEON_ABSENCE"
  | "INSTRUMENTIST_ABSENCE"
  | "SURGEON_CONFLICT"
  | "INSTRUMENTIST_CONFLICT"
  | "REASSIGNMENT_REQUIRED"
  | "OCCURRENCE_CANCELLED";
export type PlanningAlertStatus = "OPEN" | "ACKNOWLEDGED" | "RESOLVED" | "IGNORED";
export type PreviewLineStatus = "SKIPPED" | "UNCOVERED" | "COVERED" | "MODIFIED" | "CONFLICT";
export type MissionStatus =
  | "DRAFT" | "OPEN" | "DECLARED" | "ASSIGNED" | "REJECTED"
  | "SUBMITTED" | "VALIDATED" | "CLOSED" | "IN_PROGRESS" | "CANCELLED";

export interface UserRefV2 {
  id: number;
  email: string;
  name?: string;
}

export interface SiteRefV2 {
  id: number;
  name: string;
}

// ── Recurrence ───────────────────────────────────────────────────────────────

export interface RecurrenceRuleV2 {
  frequency: RecurrenceFrequency;
  interval: number;
  weekdays: number[]; // ISO 1=Monday..7=Sunday — required for both WEEKLY and MONTHLY
  anchorDate: string; // YYYY-MM-DD
  monthWeeks: number[]; // MONTHLY only: occurrence numbers in the month (1-5), empty for WEEKLY
}

const WEEKDAY_LABELS = ["", "lun", "mar", "mer", "jeu", "ven", "sam", "dim"];
const WEEKDAY_PLURAL_LABELS = ["", "lundis", "mardis", "mercredis", "jeudis", "vendredis", "samedis", "dimanches"];
const WEEKDAY_NTH_PLURAL_LABELS: Record<number, string> = { 1: "1ers", 2: "2es", 3: "3es", 4: "4es", 5: "5es" };

/** Joins items the French way: "a", "a et b", "a, b et c". */
function joinFr(items: string[]): string {
  if (items.length <= 1) return items[0] ?? "";
  return `${items.slice(0, -1).join(", ")} et ${items[items.length - 1]}`;
}

/**
 * Short human summary, e.g. "Toutes les semaines · lun" or "Tous les 2es et 3es jeudis du mois".
 * MONTHLY's weekday/occurrence are both explicit on the rule itself (weekdays + monthWeeks) —
 * no longer derived from the post's startDate (see PlanningGeneratorServiceV2::isOccurrenceActive).
 */
export function summarizeRecurrence(rule: RecurrenceRuleV2): string {
  const days = rule.weekdays.map((d) => WEEKDAY_LABELS[d]).filter(Boolean).join(", ");
  if (rule.frequency === "MONTHLY") {
    if (rule.weekdays.length > 0 && rule.monthWeeks.length > 0) {
      const weeksLabel = joinFr(
        [...rule.monthWeeks].sort((a, b) => a - b).map((w) => WEEKDAY_NTH_PLURAL_LABELS[w] ?? `${w}es`)
      );
      const daysLabel = joinFr(
        [...rule.weekdays].sort((a, b) => a - b).map((d) => WEEKDAY_PLURAL_LABELS[d]).filter(Boolean)
      );
      return `Tous les ${weeksLabel} ${daysLabel} du mois`;
    }
    return rule.interval === 1 ? "Tous les mois" : `Tous les ${rule.interval} mois`;
  }
  const cadence = rule.interval === 1 ? "Toutes les semaines" : `Une semaine sur ${rule.interval}`;
  return days ? `${cadence} · ${days}` : cadence;
}

// ── Surgeon posts ────────────────────────────────────────────────────────────

export interface SurgeonSchedulePostV2 {
  id: number;
  surgeon: UserRefV2;
  site: SiteRefV2;
  type: MissionType;
  period: ShiftPeriod;
  instrumentist: UserRefV2 | null;
  startDate: string;
  endDate: string | null;
  active: boolean;
  recurrence: RecurrenceRuleV2;
}

export interface SurgeonPostInput {
  surgeonId: number;
  siteId: number;
  type: MissionType;
  period: ShiftPeriod;
  instrumentistId?: number | null;
  startDate: string;
  endDate?: string | null;
  recurrence: {
    frequency: RecurrenceFrequency;
    interval: number;
    weekdays?: number[];
    anchorDate: string;
    monthWeeks?: number[];
  };
}

// ── Occurrence exceptions ────────────────────────────────────────────────────

export interface PlanningOccurrenceExceptionV2 {
  id: number;
  postId: number;
  occurrenceDate: string;
  type: OccurrenceExceptionType;
  overrideDate: string | null;
  overrideInstrumentist: UserRefV2 | null;
  overrideStartTime: string | null;
  overrideEndTime: string | null;
  createdAt: string;
}

export interface ExceptionInput {
  type: OccurrenceExceptionType;
  occurrenceDate: string;
  overrideDate?: string | null;
  overrideInstrumentistId?: number | null;
  overrideStartTime?: string | null;
  overrideEndTime?: string | null;
}

// ── Shift periods ────────────────────────────────────────────────────────────

export interface ShiftPeriodConfigV2 {
  id: number;
  site: SiteRefV2;
  period: ShiftPeriod;
  startTime: string;
  endTime: string;
  active: boolean;
}

// ── Site groups ──────────────────────────────────────────────────────────────

export interface SiteGroupV2 {
  id: number;
  name: string;
  createdAt: string;
  sites: SiteRefV2[];
}

// ── Alerts ───────────────────────────────────────────────────────────────────

export interface PlanningAlertMissionV2 {
  id: number;
  status: MissionStatus;
  startAt: string;
  endAt: string;
  site: SiteRefV2 | null;
  surgeon: UserRefV2 | null;
  instrumentist: UserRefV2 | null;
}

export interface PlanningAlertActionsV2 {
  canAcknowledge: boolean;
  canResolve: boolean;
  canIgnore: boolean;
  canReassign: boolean;
  canOpenAsAvailable: boolean;
  recommendedAction: "REASSIGN" | "REVIEW" | "NONE";
}

export interface PlanningAlertV2 {
  id: number;
  type: PlanningAlertType;
  status: PlanningAlertStatus;
  detectedAt: string;
  resolvedAt: string | null;
  resolvedBy: UserRefV2 | null;
  resolutionNote: string | null;
  mission: PlanningAlertMissionV2;
  absence: { id: number; dateStart: string; dateEnd: string; reason: string | null } | null;
  actions: PlanningAlertActionsV2;
}

export interface PlanningAlertListResponse {
  items: PlanningAlertV2[];
  total: number;
  page: number;
  limit: number;
}

export interface EligibleInstrumentistV2 {
  id: number;
  email: string;
  name: string;
  sites: string[];
}

// ── Generation (Batch 9) ─────────────────────────────────────────────────────

export interface PreviewLineV2 {
  date: string;
  postId: number;
  surgeonId: number;
  surgeonName: string;
  missionType: MissionType;
  startTime: string;
  endTime: string;
  siteId: number | null;
  siteName: string | null;
  instrumentistId: number | null;
  instrumentistName: string | null;
  status: PreviewLineStatus;
  existingMissionId: number | null;
  existingInstrumentistId: number | null;
  existingInstrumentistName: string | null;
  freedFrom: boolean;
}

export interface PreviewSummaryV2 {
  total: number;
  covered: number;
  uncovered: number;
  skipped: number;
  conflict: number;
  modified: number;
}

export interface PreviewResponseV2 {
  lines: PreviewLineV2[];
  summary: PreviewSummaryV2;
  previewVersion: string;
  generatedAt: string;
}

export interface GeneratedPlanningV2 {
  versionId: number;
  created: number;
  updated: number;
  skipped: number;
}

export interface DeployResponseV2 {
  deploymentId: number | null;
  missionCount: number;
  openPoolCount: number;
}

export type GenerationTarget =
  | { siteId: number; siteGroupId?: null }
  | { siteId?: null; siteGroupId: number };

// ── Living planning — Batch 15F/15G ──────────────────────────────────────────

export interface CoverageSummary {
  versionId: number;
  total: number;
  covered: number;
  open: number;
  cancelled: number;
  coveragePercent: number | null;
}

export interface MissionAuditEvent {
  eventType: string;
  occurredAt: string;
  actorId: number | null;
  actorName: string | null;
  payload: Record<string, unknown> | null;
}

// ── Eligibility — Batch 15D/15G ───────────────────────────────────────────────

export type EligibilityReason =
  | "INACTIVE"
  | "NO_SITE_MEMBERSHIP"
  | "ABSENT"
  | "SCHEDULE_CONFLICT"
  | "ALREADY_ASSIGNED"
  | "INCOMPATIBLE_STATUS";

export interface EligibleCandidate {
  id: number;
  name: string;
  email: string;
}

export interface IneligibleCandidate extends EligibleCandidate {
  reasons: EligibilityReason[];
}

export interface MissionEligibilityResponse {
  missionId: number;
  missionStatus: string;
  eligible: EligibleCandidate[];
  ineligible: IneligibleCandidate[];
}
