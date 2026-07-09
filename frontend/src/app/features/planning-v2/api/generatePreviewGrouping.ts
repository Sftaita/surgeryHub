import type { GeneratedPlanningV2, PreviewLineStatus, PreviewLineV2, PreviewResponseV2 } from "./planningV2.types";

// ── Month chip selection ─────────────────────────────────────────────────────
// Months are encoded as a single int id = year*12 + (month-1), matching the
// encoding already used by GeneratePlanningTab's old single-month select, so
// chip ids stay comparable/sortable without a second lookup table.

export interface YearMonth {
  year: number;
  month: number; // 1-12
}

export function monthIdToYearMonth(id: number): YearMonth {
  return { year: Math.floor(id / 12), month: (id % 12) + 1 };
}

export function yearMonthToMonthId({ year, month }: YearMonth): number {
  return year * 12 + (month - 1);
}

/** Current month + next N months, per the handoff spec (current → +5). */
export function buildMonthChipIds(base: YearMonth, count = 6): number[] {
  const baseId = yearMonthToMonthId(base);
  return Array.from({ length: count }, (_, i) => baseId + i);
}

// ── Preview merging (multi-month) ────────────────────────────────────────────

export function mergePreviewResponses(responses: PreviewResponseV2[]): PreviewResponseV2 {
  const lines = responses.flatMap((r) => r.lines).sort((a, b) => a.date.localeCompare(b.date));
  const summary = responses.reduce(
    (acc, r) => ({
      total: acc.total + r.summary.total,
      covered: acc.covered + r.summary.covered,
      uncovered: acc.uncovered + r.summary.uncovered,
      skipped: acc.skipped + r.summary.skipped,
      conflict: acc.conflict + r.summary.conflict,
      modified: acc.modified + r.summary.modified,
    }),
    { total: 0, covered: 0, uncovered: 0, skipped: 0, conflict: 0, modified: 0 },
  );
  return {
    lines,
    summary,
    previewVersion: responses[0]?.previewVersion ?? "",
    generatedAt: responses[0]?.generatedAt ?? "",
  };
}

// ── Generate/deploy aggregation across multiple per-month versions ──────────

export interface AggregatedGenerated {
  versions: GeneratedPlanningV2[];
  created: number;
  updated: number;
  skipped: number;
}

export function aggregateGenerated(versions: GeneratedPlanningV2[]): AggregatedGenerated {
  return versions.reduce(
    (acc, v) => ({
      versions: [...acc.versions, v],
      created: acc.created + v.created,
      updated: acc.updated + v.updated,
      skipped: acc.skipped + v.skipped,
    }),
    { versions: [] as GeneratedPlanningV2[], created: 0, updated: 0, skipped: 0 },
  );
}

export interface AggregatedDeploy {
  missionCount: number;
  openPoolCount: number;
}

export function aggregateDeploy(results: AggregatedDeploy[]): AggregatedDeploy {
  return results.reduce(
    (acc, r) => ({ missionCount: acc.missionCount + r.missionCount, openPoolCount: acc.openPoolCount + r.openPoolCount }),
    { missionCount: 0, openPoolCount: 0 },
  );
}

// ── State-filter chips (Tout / OK / Mission ouverte / À surveiller / Conflits) ─

export type SeverityFilter = "all" | "ok" | "info" | "warn" | "crit";

const SEVERITY_BY_STATUS: Record<PreviewLineStatus, Exclude<SeverityFilter, "all">> = {
  COVERED: "ok",
  UNCOVERED: "info",
  SKIPPED: "warn",
  MODIFIED: "warn",
  CONFLICT: "crit",
};

export function severityOf(status: PreviewLineStatus): Exclude<SeverityFilter, "all"> {
  return SEVERITY_BY_STATUS[status];
}

export function filterLines(lines: PreviewLineV2[], filter: SeverityFilter): PreviewLineV2[] {
  if (filter === "all") return lines;
  return lines.filter((l) => severityOf(l.status) === filter);
}

export function countBySeverity(lines: PreviewLineV2[]): Record<Exclude<SeverityFilter, "all">, number> {
  const counts = { ok: 0, info: 0, warn: 0, crit: 0 };
  for (const l of lines) counts[severityOf(l.status)]++;
  return counts;
}

// ── Grouping: day → surgeon ───────────────────────────────────────────────────

export interface SurgeonGroup {
  surgeonId: number;
  surgeonName: string;
  lines: PreviewLineV2[];
}

export interface DayGroup {
  dateKey: string;
  postsCount: number;
  surgeons: SurgeonGroup[];
}

/** Groups by date (ascending), then by surgeon, preserving first-seen surgeon order within each day. */
export function groupLinesByDayAndSurgeon(lines: PreviewLineV2[]): DayGroup[] {
  const dayMap = new Map<string, Map<number, SurgeonGroup>>();

  for (const line of lines) {
    let surgeons = dayMap.get(line.date);
    if (!surgeons) {
      surgeons = new Map();
      dayMap.set(line.date, surgeons);
    }
    let group = surgeons.get(line.surgeonId);
    if (!group) {
      group = { surgeonId: line.surgeonId, surgeonName: line.surgeonName, lines: [] };
      surgeons.set(line.surgeonId, group);
    }
    group.lines.push(line);
  }

  return Array.from(dayMap.entries())
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([dateKey, surgeons]) => {
      const surgeonGroups = Array.from(surgeons.values());
      return {
        dateKey,
        postsCount: surgeonGroups.reduce((sum, g) => sum + g.lines.length, 0),
        surgeons: surgeonGroups,
      };
    });
}

// ── Line editing (Preview Editor — instrumentist reassignment before generate) ──

/** Unique key for a preview line — date + postId (never slotId, which doesn't exist pre-generation). */
export function lineKeyV2(line: PreviewLineV2): string {
  return `${line.date}-${line.postId}`;
}

function timeToMin(t: string): number {
  const [h, m] = t.split(":").map(Number);
  return h * 60 + (m || 0);
}

export interface FreedInstrumentist { id: number; name: string; reason: string }

/**
 * Instrumentists freed up on the same day as `target` (their own post was SKIPPED —
 * surgeon absent — and they have no other overlapping post that day), suggested as
 * quick-assign candidates for an UNCOVERED/CONFLICT line.
 */
export function getFreedInstrumentists(lines: PreviewLineV2[], target: PreviewLineV2): FreedInstrumentist[] {
  const tStart = timeToMin(target.startTime);
  const tEnd = timeToMin(target.endTime);
  const freed = new Map<number, FreedInstrumentist>();

  for (const l of lines) {
    if (l.date === target.date && l.status === "SKIPPED" && l.instrumentistId && l.instrumentistName) {
      freed.set(l.instrumentistId, {
        id: l.instrumentistId,
        name: l.instrumentistName,
        reason: `Libéré — ${l.surgeonName} est absent ce jour-là`,
      });
    }
  }
  for (const l of lines) {
    if (
      l.date === target.date &&
      l.status !== "SKIPPED" &&
      l.instrumentistId &&
      timeToMin(l.startTime) < tEnd &&
      timeToMin(l.endTime) > tStart
    ) {
      freed.delete(l.instrumentistId);
    }
  }
  return Array.from(freed.values());
}

const DAY_NAMES = ["Dimanche", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi"];
const MONTH_SHORT = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];

/** "Lundi · 1 juin" — used for day-group headers in the grouped preview. */
export function formatDayHeader(dateKey: string): string {
  const d = new Date(`${dateKey}T00:00:00`);
  return `${DAY_NAMES[d.getDay()]} · ${d.getDate()} ${MONTH_SHORT[d.getMonth()]}`;
}
