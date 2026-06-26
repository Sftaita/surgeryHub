import type { RecurrenceFrequency, RecurrenceRuleV2 } from "./planningV2.types";

/**
 * Friendly recurrence presets shown in a single Select (per the handoff spec), mapped
 * onto the model the backend understands. "Semaines paires/impaires" are WEEKLY+interval=2
 * anchored to a fixed-parity reference date; the anchors below match the ones already used
 * by the V1→V2 PAIR/IMPAIR backfill (2024-01-01 = odd ISO week, 2024-01-08 = even ISO week).
 *
 * MONTHLY_NTH_WEEKDAY ("Certains jours du mois") generalizes the old fixed MONTHLY_1ST..4TH
 * presets: the backend (Batch 14A/14B) now stores explicit weekdays[] + monthWeeks[] (1-5)
 * on the rule itself instead of deriving the weekday from the post's startDate, and
 * PlanningGeneratorServiceV2's nth-weekday matching is covered by a real test matrix
 * (PlanningGeneratorServiceV2MonthlyTest) — re-enabled in the create/edit picker as of Batch 14C.
 */
export type RecurrencePresetKey =
  | "WEEKLY"
  | "EVEN_WEEKS"
  | "ODD_WEEKS"
  | "EVERY_OTHER_WEEK"
  | "MONTHLY_NTH_WEEKDAY";

export const RECURRENCE_PRESET_OPTIONS: Array<{ key: RecurrencePresetKey; label: string }> = [
  { key: "WEEKLY", label: "Toutes les semaines" },
  { key: "EVEN_WEEKS", label: "Semaines paires" },
  { key: "ODD_WEEKS", label: "Semaines impaires" },
  { key: "EVERY_OTHER_WEEK", label: "Une semaine sur deux" },
  { key: "MONTHLY_NTH_WEEKDAY", label: "Certains jours du mois" },
];

/** What the create/edit post form offers. Identical to the full catalogue — nothing is hidden. */
export const LAUNCH_RECURRENCE_PRESET_OPTIONS = RECURRENCE_PRESET_OPTIONS;

const EVEN_WEEK_ANCHOR = "2024-01-08";
const ODD_WEEK_ANCHOR = "2024-01-01";

export function presetIsMonthly(key: RecurrencePresetKey): boolean {
  return key.startsWith("MONTHLY_");
}

export interface RecurrenceRuleInput {
  frequency: RecurrenceFrequency;
  interval: number;
  weekdays: number[];
  anchorDate: string;
  monthWeeks: number[];
}

/**
 * Builds the recurrence payload for a preset. `weekdays` is required for both the
 * WEEKLY-family presets and MONTHLY_NTH_WEEKDAY; `monthWeeks` only applies to the latter.
 */
export function presetToRecurrence(
  key: RecurrencePresetKey,
  weekdays: number[],
  startDate: string,
  monthWeeks: number[] = [],
): RecurrenceRuleInput {
  switch (key) {
    case "WEEKLY":
      return { frequency: "WEEKLY", interval: 1, weekdays, anchorDate: startDate || ODD_WEEK_ANCHOR, monthWeeks: [] };
    case "EVERY_OTHER_WEEK":
      return { frequency: "WEEKLY", interval: 2, weekdays, anchorDate: startDate || ODD_WEEK_ANCHOR, monthWeeks: [] };
    case "EVEN_WEEKS":
      return { frequency: "WEEKLY", interval: 2, weekdays, anchorDate: EVEN_WEEK_ANCHOR, monthWeeks: [] };
    case "ODD_WEEKS":
      return { frequency: "WEEKLY", interval: 2, weekdays, anchorDate: ODD_WEEK_ANCHOR, monthWeeks: [] };
    case "MONTHLY_NTH_WEEKDAY":
      return { frequency: "MONTHLY", interval: 1, weekdays, anchorDate: startDate || ODD_WEEK_ANCHOR, monthWeeks };
  }
}

/** Best-effort reverse mapping, used to pre-select a preset when editing an existing post. */
export function recurrenceToPreset(rule: RecurrenceRuleV2): RecurrencePresetKey {
  if (rule.frequency === "MONTHLY") {
    return "MONTHLY_NTH_WEEKDAY";
  }
  if (rule.interval === 2) {
    if (rule.anchorDate === EVEN_WEEK_ANCHOR) return "EVEN_WEEKS";
    if (rule.anchorDate === ODD_WEEK_ANCHOR) return "ODD_WEEKS";
    return "EVERY_OTHER_WEEK";
  }
  return "WEEKLY";
}
