import type { RecurrenceRuleV2 } from "./planningV2.types";

/**
 * Friendly recurrence presets shown in a single Select (per the handoff spec), mapped
 * onto the existing interval+anchorDate model the backend already understands. No
 * backend change — "semaines paires/impaires" are just WEEKLY+interval=2 anchored to a
 * fixed-parity reference date; the anchors below match the ones already used by the
 * V1→V2 PAIR/IMPAIR backfill (2024-01-01 = odd ISO week, 2024-01-08 = even ISO week),
 * so presets stay consistent with existing seeded data.
 */
export type RecurrencePresetKey =
  | "WEEKLY"
  | "EVEN_WEEKS"
  | "ODD_WEEKS"
  | "EVERY_OTHER_WEEK"
  | "MONTHLY_1ST"
  | "MONTHLY_2ND"
  | "MONTHLY_3RD"
  | "MONTHLY_4TH";

/**
 * Full preset catalogue, including the MONTHLY_* "nth week of month" family. Kept around
 * so editing a pre-existing post with one of these rules still labels/round-trips
 * correctly — but NOT offered in the create/edit picker (see LAUNCH_RECURRENCE_PRESET_OPTIONS
 * below). Launch-safety audit (Batch 13): PlanningGeneratorServiceV2::isOccurrenceActive()'s
 * MONTHLY+monthlyNthWeekday branch is explicitly commented "not part of Batch 2's required
 * test matrix" and has zero recurrence-expansion test coverage — only post-creation/input
 * validation is tested (SurgeonSchedulePostServiceTest::test_create_accepts_monthly_recurrence_without_weekdays).
 * Hiding from the picker until that's backed by real tests; backend behavior is untouched.
 */
export const RECURRENCE_PRESET_OPTIONS: Array<{ key: RecurrencePresetKey; label: string }> = [
  { key: "WEEKLY", label: "Toutes les semaines" },
  { key: "EVEN_WEEKS", label: "Semaines paires" },
  { key: "ODD_WEEKS", label: "Semaines impaires" },
  { key: "EVERY_OTHER_WEEK", label: "Une semaine sur deux" },
  { key: "MONTHLY_1ST", label: "Première semaine du mois" },
  { key: "MONTHLY_2ND", label: "Deuxième semaine du mois" },
  { key: "MONTHLY_3RD", label: "Troisième semaine du mois" },
  { key: "MONTHLY_4TH", label: "Quatrième semaine du mois" },
];

/** Validated patterns only — what the create/edit post form actually offers for launch. */
export const LAUNCH_RECURRENCE_PRESET_OPTIONS = RECURRENCE_PRESET_OPTIONS.filter((o) => !presetIsMonthly(o.key));

const EVEN_WEEK_ANCHOR = "2024-01-08";
const ODD_WEEK_ANCHOR = "2024-01-01";

export function presetIsMonthly(key: RecurrencePresetKey): boolean {
  return key.startsWith("MONTHLY_");
}

/**
 * Builds the recurrence payload for a preset. `weekdays`/`startDate` are only needed
 * for WEEKLY-family presets (weekdays multi-select) — monthly presets derive their
 * weekday server-side from the post's startDate, so weekdays is sent empty.
 */
export function presetToRecurrence(
  key: RecurrencePresetKey,
  weekdays: number[],
  startDate: string,
): { frequency: "WEEKLY" | "MONTHLY"; interval: number; weekdays: number[]; anchorDate: string; monthlyNthWeekday: number | null } {
  switch (key) {
    case "WEEKLY":
      return { frequency: "WEEKLY", interval: 1, weekdays, anchorDate: startDate || ODD_WEEK_ANCHOR, monthlyNthWeekday: null };
    case "EVERY_OTHER_WEEK":
      return { frequency: "WEEKLY", interval: 2, weekdays, anchorDate: startDate || ODD_WEEK_ANCHOR, monthlyNthWeekday: null };
    case "EVEN_WEEKS":
      return { frequency: "WEEKLY", interval: 2, weekdays, anchorDate: EVEN_WEEK_ANCHOR, monthlyNthWeekday: null };
    case "ODD_WEEKS":
      return { frequency: "WEEKLY", interval: 2, weekdays, anchorDate: ODD_WEEK_ANCHOR, monthlyNthWeekday: null };
    case "MONTHLY_1ST":
      return { frequency: "MONTHLY", interval: 1, weekdays: [], anchorDate: startDate || ODD_WEEK_ANCHOR, monthlyNthWeekday: 1 };
    case "MONTHLY_2ND":
      return { frequency: "MONTHLY", interval: 1, weekdays: [], anchorDate: startDate || ODD_WEEK_ANCHOR, monthlyNthWeekday: 2 };
    case "MONTHLY_3RD":
      return { frequency: "MONTHLY", interval: 1, weekdays: [], anchorDate: startDate || ODD_WEEK_ANCHOR, monthlyNthWeekday: 3 };
    case "MONTHLY_4TH":
      return { frequency: "MONTHLY", interval: 1, weekdays: [], anchorDate: startDate || ODD_WEEK_ANCHOR, monthlyNthWeekday: 4 };
  }
}

/** Best-effort reverse mapping, used to pre-select a preset when editing an existing post. */
export function recurrenceToPreset(rule: RecurrenceRuleV2): RecurrencePresetKey {
  if (rule.frequency === "MONTHLY") {
    switch (rule.monthlyNthWeekday) {
      case 1: return "MONTHLY_1ST";
      case 2: return "MONTHLY_2ND";
      case 3: return "MONTHLY_3RD";
      case 4: return "MONTHLY_4TH";
      default: return "MONTHLY_1ST";
    }
  }
  if (rule.interval === 2) {
    if (rule.anchorDate === EVEN_WEEK_ANCHOR) return "EVEN_WEEKS";
    if (rule.anchorDate === ODD_WEEK_ANCHOR) return "ODD_WEEKS";
    return "EVERY_OTHER_WEEK";
  }
  return "WEEKLY";
}
