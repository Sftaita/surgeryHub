import { describe, it, expect } from "vitest";
import { candidateGhostLabel, conflictSentence } from "./eligibilityReasons";
import type { CandidateEligibility } from "./planningV2.types";

function candidate(overrides: Partial<CandidateEligibility> = {}): CandidateEligibility {
  return {
    id: 24, name: "Françoise Detrembleur", email: "f@x.fr", eligible: false, selectable: true,
    reasons: ["SCHEDULE_CONFLICT"], unavailability: null,
    conflict: {
      missionId: 1085, siteName: "CHIREC Delta", surgeonName: "Philippe Schiepers", missionStatus: "IN_PROGRESS",
      source: "PERSISTED", startAt: "2026-10-23T07:45:00+02:00", endAt: "2026-10-23T18:00:00+02:00",
    },
    ...overrides,
  };
}

// D-165 — a conflict says with whom, when, and whether it is saved or only proposed.
describe("conflictSentence()", () => {
  it("names the responsible mission's surgeon and times — published planning", () => {
    expect(conflictSentence(candidate())).toBe(
      "Françoise Detrembleur est déjà affectée au Dr Philippe Schiepers (CHIREC Delta) de 07:45 à 18:00 — planning publié.",
    );
  });

  it("tells a line proposed in the editor apart from a saved mission", () => {
    const proposed = candidate({ conflict: { ...candidate().conflict!, missionId: null, missionStatus: null, source: "PROPOSED", endAt: "2026-10-23T14:01:00+02:00" } });
    expect(conflictSentence(proposed)).toBe(
      "Françoise Detrembleur est déjà affectée au Dr Philippe Schiepers (CHIREC Delta) de 07:45 à 14:01 — modification proposée, non enregistrée.",
    );
  });

  it("says « brouillon enregistré » for a saved DRAFT mission", () => {
    const draft = candidate({ conflict: { ...candidate().conflict!, missionStatus: "DRAFT" } });
    expect(conflictSentence(draft)).toMatch(/— brouillon enregistré\.$/);
  });

  it("returns null without a schedule conflict", () => {
    expect(conflictSentence(candidate({ reasons: [], conflict: null }))).toBeNull();
  });
});

describe("candidateGhostLabel() — SCHEDULE_CONFLICT", () => {
  it("shows who and when instead of a bare « déjà affectée »", () => {
    expect(candidateGhostLabel(candidate())).toBe("Conflit d'horaire · Dr Philippe Schiepers 07:45–18:00");
  });

  it("flags a proposed (unsaved) line", () => {
    const proposed = candidate({ conflict: { ...candidate().conflict!, source: "PROPOSED" } });
    expect(candidateGhostLabel(proposed)).toBe("Conflit d'horaire · Dr Philippe Schiepers 07:45–18:00 (non enregistré)");
  });

  it("keeps the former site-only label when the backend sent no surgeon/times", () => {
    const legacy = candidate({ conflict: { missionId: 77, siteName: "Alpha", startAt: null, endAt: null } });
    expect(candidateGhostLabel(legacy)).toBe("Conflit d'horaire (Alpha)");
  });
});
