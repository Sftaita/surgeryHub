import { describe, it, expect } from "vitest";
import {
  monthIdToYearMonth, yearMonthToMonthId, buildMonthChipIds,
  mergePreviewResponses, aggregateGenerated, aggregateDeploy,
  severityOf, filterLines, countBySeverity,
  groupLinesByDayAndSurgeon, formatDayHeader,
  lineKeyV2, getFreedInstrumentists,
} from "./generatePreviewGrouping";
import type { PreviewLineV2, PreviewResponseV2, GeneratedPlanningV2 } from "./planningV2.types";

function line(overrides: Partial<PreviewLineV2>): PreviewLineV2 {
  return {
    date: "2026-06-01", postId: 1, surgeonId: 1, surgeonName: "Dr Martin",
    missionType: "BLOCK", startTime: "08:00", endTime: "13:00",
    siteId: 1, siteName: "Delta", instrumentistId: null, instrumentistName: null,
    status: "COVERED", existingMissionId: null, existingInstrumentistId: null,
    existingInstrumentistName: null, freedFrom: false,
    ...overrides,
  };
}

describe("month id encoding", () => {
  it("round-trips year/month through the id", () => {
    const id = yearMonthToMonthId({ year: 2026, month: 11 });
    expect(monthIdToYearMonth(id)).toEqual({ year: 2026, month: 11 });
  });

  it("builds the current month + next 5 as chip ids", () => {
    const ids = buildMonthChipIds({ year: 2026, month: 6 }, 6);
    expect(ids.map(monthIdToYearMonth)).toEqual([
      { year: 2026, month: 6 }, { year: 2026, month: 7 }, { year: 2026, month: 8 },
      { year: 2026, month: 9 }, { year: 2026, month: 10 }, { year: 2026, month: 11 },
    ]);
  });

  it("rolls over into the next year", () => {
    const ids = buildMonthChipIds({ year: 2026, month: 11 }, 3);
    expect(ids.map(monthIdToYearMonth)).toEqual([
      { year: 2026, month: 11 }, { year: 2026, month: 12 }, { year: 2027, month: 1 },
    ]);
  });
});

describe("mergePreviewResponses()", () => {
  it("flattens lines across months, sorted by date, and sums summaries", () => {
    const june: PreviewResponseV2 = {
      lines: [line({ date: "2026-06-15" }), line({ date: "2026-06-01" })],
      summary: { total: 2, covered: 2, uncovered: 0, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-june",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    const july: PreviewResponseV2 = {
      lines: [line({ date: "2026-07-01", status: "CONFLICT" })],
      summary: { total: 1, covered: 0, uncovered: 0, skipped: 0, conflict: 1, modified: 0 },
      previewVersion: "v-july",
      generatedAt: "2026-07-01T00:00:00Z",
    };

    const merged = mergePreviewResponses([june, july]);

    expect(merged.lines.map((l) => l.date)).toEqual(["2026-06-01", "2026-06-15", "2026-07-01"]);
    expect(merged.summary).toEqual({ total: 3, covered: 2, uncovered: 0, skipped: 0, conflict: 1, modified: 0 });
    expect(merged.previewVersion).toBe("v-june");
    expect(merged.generatedAt).toBe("2026-06-01T00:00:00Z");
  });

  it("returns an empty merge for no responses", () => {
    expect(mergePreviewResponses([])).toEqual({
      lines: [], summary: { total: 0, covered: 0, uncovered: 0, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "", generatedAt: "",
    });
  });
});

describe("aggregateGenerated() / aggregateDeploy()", () => {
  it("sums created/updated/skipped across per-month versions", () => {
    const versions: GeneratedPlanningV2[] = [
      { versionId: 10, created: 5, updated: 1, skipped: 0 },
      { versionId: 11, created: 3, updated: 0, skipped: 2 },
    ];
    expect(aggregateGenerated(versions)).toEqual({ versions, created: 8, updated: 1, skipped: 2 });
  });

  it("sums missionCount/openPoolCount across per-version deploys", () => {
    expect(aggregateDeploy([
      { missionCount: 5, openPoolCount: 1 },
      { missionCount: 3, openPoolCount: 0 },
    ])).toEqual({ missionCount: 8, openPoolCount: 1 });
  });
});

describe("severity mapping (filter chips)", () => {
  it("maps every status to a severity bucket", () => {
    expect(severityOf("COVERED")).toBe("ok");
    expect(severityOf("UNCOVERED")).toBe("info");
    expect(severityOf("SKIPPED")).toBe("warn");
    expect(severityOf("MODIFIED")).toBe("warn");
    expect(severityOf("CONFLICT")).toBe("crit");
  });

  it("filters lines by severity, 'all' returning everything unchanged", () => {
    const lines = [line({ status: "COVERED" }), line({ status: "CONFLICT" }), line({ status: "SKIPPED" })];
    expect(filterLines(lines, "crit")).toEqual([lines[1]]);
    expect(filterLines(lines, "warn")).toEqual([lines[2]]);
    expect(filterLines(lines, "all")).toEqual(lines);
  });

  it("counts lines per severity bucket", () => {
    const lines = [
      line({ status: "COVERED" }), line({ status: "COVERED" }),
      line({ status: "MODIFIED" }), line({ status: "SKIPPED" }),
      line({ status: "CONFLICT" }),
    ];
    expect(countBySeverity(lines)).toEqual({ ok: 2, info: 0, warn: 2, crit: 1 });
  });
});

describe("groupLinesByDayAndSurgeon()", () => {
  it("groups by ascending date, then by surgeon in first-seen order", () => {
    const lines = [
      line({ date: "2026-06-01", surgeonId: 2, surgeonName: "Dr Dupont" }),
      line({ date: "2026-06-01", surgeonId: 1, surgeonName: "Dr Martin" }),
      line({ date: "2026-05-30", surgeonId: 1, surgeonName: "Dr Martin" }),
    ];

    const groups = groupLinesByDayAndSurgeon(lines);

    expect(groups.map((g) => g.dateKey)).toEqual(["2026-05-30", "2026-06-01"]);
    expect(groups[1].surgeons.map((s) => s.surgeonName)).toEqual(["Dr Dupont", "Dr Martin"]);
    expect(groups[1].postsCount).toBe(2);
  });

  it("groups multiple posts for the same surgeon on the same day under one surgeon group", () => {
    const lines = [
      line({ date: "2026-06-01", surgeonId: 1, postId: 1 }),
      line({ date: "2026-06-01", surgeonId: 1, postId: 2 }),
    ];
    const groups = groupLinesByDayAndSurgeon(lines);
    expect(groups).toHaveLength(1);
    expect(groups[0].surgeons).toHaveLength(1);
    expect(groups[0].surgeons[0].lines).toHaveLength(2);
  });

  it("returns no groups for an empty line list", () => {
    expect(groupLinesByDayAndSurgeon([])).toEqual([]);
  });
});

describe("formatDayHeader()", () => {
  it("formats a Monday in June", () => {
    expect(formatDayHeader("2026-06-01")).toBe("Lundi · 1 juin");
  });

  it("formats across a month boundary", () => {
    expect(formatDayHeader("2026-07-01")).toBe("Mercredi · 1 juil.");
  });
});

describe("lineKeyV2()", () => {
  it("keys by date + postId, not surgeon/instrumentist (stable across reassignment)", () => {
    const a = line({ date: "2026-06-01", postId: 5, instrumentistId: 1 });
    const b = { ...a, instrumentistId: 2, instrumentistName: "Someone else" };
    expect(lineKeyV2(a)).toBe(lineKeyV2(b));
    expect(lineKeyV2(a)).toBe("2026-06-01-5");
  });

  it("differs for the same date with a different postId", () => {
    const a = line({ date: "2026-06-01", postId: 1 });
    const b = line({ date: "2026-06-01", postId: 2 });
    expect(lineKeyV2(a)).not.toBe(lineKeyV2(b));
  });
});

describe("getFreedInstrumentists()", () => {
  it("suggests an instrumentist whose own post was SKIPPED that day and doesn't overlap the target slot", () => {
    const freedLine = line({
      date: "2026-06-01", postId: 1, status: "SKIPPED",
      instrumentistId: 7, instrumentistName: "Diane Lefebvre", surgeonName: "Dr Absent",
      startTime: "08:00", endTime: "13:00",
    });
    const target = line({ date: "2026-06-01", postId: 2, status: "UNCOVERED", startTime: "14:00", endTime: "18:00" });

    const freed = getFreedInstrumentists([freedLine, target], target);
    expect(freed).toHaveLength(1);
    expect(freed[0]).toMatchObject({ id: 7, name: "Diane Lefebvre" });
    expect(freed[0].reason).toContain("Dr Absent");
  });

  it("excludes a freed instrumentist who has another overlapping active post the same day", () => {
    const freedLine = line({ date: "2026-06-01", postId: 1, status: "SKIPPED", instrumentistId: 7, instrumentistName: "Diane", startTime: "08:00", endTime: "13:00" });
    const busyLine = line({ date: "2026-06-01", postId: 3, status: "COVERED", instrumentistId: 7, startTime: "09:00", endTime: "11:00" });
    const target = line({ date: "2026-06-01", postId: 2, status: "UNCOVERED", startTime: "10:00", endTime: "12:00" });

    expect(getFreedInstrumentists([freedLine, busyLine, target], target)).toEqual([]);
  });

  it("ignores freed instrumentists from a different day", () => {
    const freedLine = line({ date: "2026-06-02", postId: 1, status: "SKIPPED", instrumentistId: 7, instrumentistName: "Diane", startTime: "08:00", endTime: "13:00" });
    const target = line({ date: "2026-06-01", postId: 2, status: "UNCOVERED", startTime: "08:00", endTime: "13:00" });

    expect(getFreedInstrumentists([freedLine, target], target)).toEqual([]);
  });
});
