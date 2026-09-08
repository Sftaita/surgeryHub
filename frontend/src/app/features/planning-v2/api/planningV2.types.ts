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
  | "OCCURRENCE_CANCELLED"
  | "INSTRUMENTIST_INACTIVE";
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

// ── Communication des absences chirurgiens (Lot A/B, D-114) ─────────────────

export interface AbsenceCommunicationSiteSettingV2 {
  site: SiteRefV2;
  notifyColleaguesEnabled: boolean;
  /** Lot B */
  notifyBlockManagementEnabled: boolean;
  /**
   * Revue post-déploiement (D-114) — lecture seule ici, sourcée depuis la fiche
   * établissement (`Hospital`). Se modifie via `PATCH /api/sites/{id}`
   * (voir `updateHospitalBlockManagementContact` dans `sites.api.ts`), jamais depuis cet
   * endpoint.
   */
  blockManagementContactEmail: string | null;
  blockManagementContactCc: string[];
  blockManagementDelayDays: number | null;
}

export interface AbsenceCommunicationSiteSettingUpdateV2 {
  notifyColleaguesEnabled?: boolean;
  notifyBlockManagementEnabled?: boolean;
  blockManagementDelayDays?: number | null;
}

// ── Communication des absences chirurgiens — Lot C (D-114) : rattrapage ──────

export type BackfillRoomReleaseStatus = "WILL_SEND" | "NO_NEW_OCCURRENCE" | "NO_RECIPIENT" | "NO_FUTURE_BLOCK" | "DISABLED";
export type BackfillBlockManagementStatus = "WILL_SEND_NOW" | "WILL_SCHEDULE" | "ALREADY_PROCESSED" | "DISABLED" | "MISSING_CONFIG" | "ABSENCE_ALREADY_ENDED";

export interface BackfillSiteActionV2 {
  siteId: number;
  siteName: string;
  futureBlockOccurrenceCount: number;
  roomRelease: { status: BackfillRoomReleaseStatus; recipientCount: number; newOccurrenceCount: number };
  blockManagement: { status: BackfillBlockManagementStatus; scheduledAt: string | null };
}

export interface BackfillAbsenceItemV2 {
  absenceId: number;
  surgeonId: number | null;
  surgeonName: string | null;
  createdAt: string | null;
  dateStart: string;
  dateEnd: string;
  selectable: boolean;
  sites: BackfillSiteActionV2[];
}

export interface BackfillPreviewV2 {
  createdFrom: string;
  summary: {
    totalAbsencesAnalyzed: number;
    ignoredOlderAbsences: number;
    eligibleAbsences: number;
    noActionAbsences: number;
    roomReleaseEmailsPotential: number;
    blockManagementImmediate: number;
    blockManagementScheduled: number;
    alreadyProcessedSites: number;
  };
  items: BackfillAbsenceItemV2[];
}

export type BackfillExecuteItemStatus = "PROCESSED" | "SKIPPED_NOT_FOUND" | "SKIPPED_BEFORE_CUTOFF" | "SKIPPED_NOT_A_SURGEON" | "ERROR";

export interface BackfillExecuteResultItemV2 {
  absenceId: number;
  status: BackfillExecuteItemStatus;
  blockManagementSkippedReason?: string | null;
  newCommunicationCount?: number;
  error?: string;
}

export interface BackfillExecuteResponseV2 {
  createdFrom: string;
  results: BackfillExecuteResultItemV2[];
}

// ── Communication des absences chirurgiens — Lot C (D-114) : journal manager ──

export type AbsenceCommunicationTypeV2 = "ROOM_RELEASE" | "BLOCK_MANAGEMENT_ABSENCE" | "BLOCK_MANAGEMENT_MODIFICATION" | "BLOCK_MANAGEMENT_CANCELLATION";
export type AbsenceCommunicationGlobalStatusV2 = "SCHEDULED" | "SENT" | "FAILED" | "CANCELLED";

export interface AbsenceCommunicationListItemV2 {
  id: number;
  type: AbsenceCommunicationTypeV2;
  revisionNumber: number;
  surgeon: { id: number; name: string } | null;
  site: { id: number; name: string } | null;
  absenceId: number | null;
  absenceDateStart: string;
  absenceDateEnd: string;
  createdAt: string | null;
  globalStatus: AbsenceCommunicationGlobalStatusV2;
  deliveryCount: number;
  sentCount: number;
  failedCount: number;
  cancelledCount: number;
  scheduledCount: number;
}

export interface AbsenceCommunicationDeliveryV2 {
  id: number;
  to: string;
  cc: string[];
  status: AbsenceCommunicationGlobalStatusV2;
  attemptCount: number;
  scheduledAt: string | null;
  sentAt: string | null;
  cancelledAt: string | null;
  lastError: string | null;
}

export interface AbsenceCommunicationDetailV2 extends AbsenceCommunicationListItemV2 {
  subject: string;
  body: string;
  occurrences: Array<{ postId: number; date: string; period: string }>;
  replyTo: string | null;
  deliveries: AbsenceCommunicationDeliveryV2[];
}

export interface AbsenceCommunicationListResponseV2 {
  items: AbsenceCommunicationListItemV2[];
  page: number;
  limit: number;
  total: number;
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

/** D-091 — only populated for SURGEON_CONFLICT/INSTRUMENTIST_CONFLICT alerts. */
export interface PlanningAlertConflictV2 {
  personName: string | null;
  missionSiteName: string | null;
  missionStartAt: string | null;
  missionEndAt: string | null;
  conflictingMissionId: number;
  conflictingSiteName: string | null;
  conflictingStartAt: string | null;
  conflictingEndAt: string | null;
  crossSite: boolean | null;
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
  conflict: PlanningAlertConflictV2 | null;
  actions: PlanningAlertActionsV2;
}

export interface PlanningAlertListResponse {
  items: PlanningAlertV2[];
  total: number;
  page: number;
  limit: number;
}

/** @deprecated D-102 (Lot 2) — superseded by CandidateEligibility, kept only if referenced elsewhere. */
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
  surgeonPhotoPath?: string | null;
  instrumentistPhotoPath?: string | null;
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

/** D-101 — an instrumentist the backend refused to keep (ABSENT/SCHEDULE_CONFLICT/INACTIVE/NO_SITE_MEMBERSHIP). */
export interface RejectedAssignmentV2 {
  missionId: number | null;
  date: string | null;
  requestedInstrumentistId: number;
  requestedInstrumentistName: string;
  reasons: string[];
}

export interface GeneratedPlanningV2 {
  versionId: number;
  created: number;
  updated: number;
  skipped: number;
  rejectedAssignments: RejectedAssignmentV2[];
}

export interface DeployResponseV2 {
  deploymentId: number | null;
  missionCount: number;
  openPoolCount: number;
}

// ── Drafts — CAS D (D-115) ───────────────────────────────────────────────────

export interface DraftVersionSummaryV2 {
  id: number;
  status: "DRAFT" | "ACTIVE" | "ARCHIVED";
  periodStart: string;
  periodEnd: string;
  siteId: number | null;
  siteName: string | null;
  generatedAt: string;
}

export interface DraftReopenResponseV2 {
  version: DraftVersionSummaryV2;
  lines: PreviewLineV2[];
  summary: PreviewSummaryV2;
  previewVersion: string;
  /** Informational only — a Post/ShiftPeriodConfig/absence changed since this draft was
   *  generated. Never blocks the reopen, never replaces anything already saved. */
  divergent: boolean;
  generatedAt: string;
}

export interface DraftUpdateResultV2 {
  created: number;
  updated: number;
  removed: number;
  skipped: number;
  rejectedAssignments: RejectedAssignmentV2[];
}

// ── Manual conflict audit — Lot 6, D-106 ─────────────────────────────────────

export type VerifyConflictsIssueType =
  | "SURGEON_ABSENCE"
  | "INSTRUMENTIST_ABSENCE"
  | "FORGOTTEN_RESTORATION"
  | "FORGOTTEN_OCCURRENCE_RESTORATION"
  | "INSTRUMENTIST_INACTIVE"
  | "SURGEON_CONFLICT"
  | "INSTRUMENTIST_CONFLICT";

export interface VerifyConflictsIssue {
  type: VerifyConflictsIssueType;
  action: "CANCELLED" | "RELEASED_TO_POOL" | "RESTORED_ASSIGNED" | "RESTORED_OPEN" | "RESTORED" | "ALERT_CREATED";
  missionId?: number;
  postId?: number;
  conflictingMissionId?: number | null;
}

export interface VerifyConflictsResponse {
  checkedMissions: number;
  issuesFound: number;
  automaticCorrections: number;
  alertsCreated: number;
  alertsResolved: number;
  issues: VerifyConflictsIssue[];
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

// ── Eligibility — D-101/D-102 (Lot 1/Lot 2) ──────────────────────────────────

export type EligibilityReason =
  | "INACTIVE"
  | "NO_SITE_MEMBERSHIP"
  | "ABSENT"
  | "SCHEDULE_CONFLICT"
  | "ALREADY_ASSIGNED"
  | "INCOMPATIBLE_STATUS";

/** Mirrors backend App\Enum\EligibilityEnforcementPolicy (D-101) — never reimplemented client-side. */
export type EligibilityEnforcementPolicy = "STRICT_ASSIGNMENT" | "PLANNING_MODIFICATION";

export interface UnavailabilityDetail {
  type: "ABSENCE";
  dateStart: string;
  dateEnd: string;
}

export interface ConflictDetail {
  missionId: number;
  siteName: string | null;
  startAt: string | null;
  endAt: string | null;
}

/**
 * D-102 — single canonical candidate shape for every manager-facing instrumentist
 * picker. `eligible` is the raw, policy-agnostic fact (empty reasons); `selectable` is
 * contextualized under the response's `policy` (never recomputed client-side — see
 * `MissionEligibilityService::serializeCandidate()`/`selectableUnder()`). `reasons`
 * always lists everything found, even when non-blocking under this policy (e.g. a
 * SCHEDULE_CONFLICT warning that stays selectable under PLANNING_MODIFICATION).
 */
export interface CandidateEligibility {
  id: number;
  name: string;
  email: string;
  eligible: boolean;
  selectable: boolean;
  reasons: EligibilityReason[];
  unavailability: UnavailabilityDetail | null;
  conflict: ConflictDetail | null;
  /** Only present on the PlanningAlert-scoped endpoint. */
  sites?: string[];
}

export interface MissionEligibilityResponse {
  missionId: number;
  missionStatus: string;
  policy: EligibilityEnforcementPolicy;
  candidates: CandidateEligibility[];
}

export interface AlertEligibilityResponse {
  missionId: number;
  policy: EligibilityEnforcementPolicy;
  candidates: CandidateEligibility[];
}

/** D-102 — Preview Editor missionless/hypothetical-slot roster (no persisted Mission yet). */
export interface RosterEligibilityResponse {
  policy: EligibilityEnforcementPolicy;
  candidates: CandidateEligibility[];
}

/**
 * « Salles libérées » (Lot D, post D-114) — indépendant des emails Room Release et du toggle
 * `notifyColleaguesEnabled` : un créneau BLOCK réellement libéré est visible ici même si les
 * emails collègues sont désactivés pour ce site.
 */
export interface ReleasedRoomSlotV2 {
  id: number;
  site: { id: number; name: string } | null;
  occurrenceDate: string;
  period: ShiftPeriod;
  startTime: string | null;
  endTime: string | null;
  surgeon: { id: number; name: string } | null;
  status: "AVAILABLE";
  createdAt: string;
}

export interface ReleasedRoomSlotListResponse {
  items: ReleasedRoomSlotV2[];
  page: number;
  limit: number;
  total: number;
}

/** Intégration planning chirurgien (revue 2026-09-07) — badge CTA, `GET /api/me/available-rooms/count`. */
export interface ReleasedRoomSlotCountResponse {
  count: number;
}
