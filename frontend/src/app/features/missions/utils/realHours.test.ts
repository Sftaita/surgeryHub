import { describe, it, expect } from "vitest";
import { realHoursOf } from "./realHours";
import type { MissionExecutionInfo } from "../api/missions.api";

const base: MissionExecutionInfo = {
  missionId: 1, hasExecutionRecord: true, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null,
  hoursSource: "INSTRUMENTIST", effectiveDurationMinutes: 300, effectiveDurationSource: "PLANNED", disputes: [],
} as MissionExecutionInfo;

describe("realHoursOf — ce qui a réellement été déclaré, jamais complété par le planning", () => {
  it("début et fin déclarés : bornes et durée calculée par le backend", () => {
    const r = realHoursOf({ ...base, actualStartAt: "2026-10-05T07:00:00+02:00", actualEndAt: "2026-10-05T15:30:00+02:00", effectiveDurationMinutes: 510, effectiveDurationSource: "ACTUAL_TIMES" });
    expect(r).toEqual({ kind: "TIMES", start: "2026-10-05T07:00:00+02:00", end: "2026-10-05T15:30:00+02:00", minutes: 510, note: null });
  });

  it("durée seule (cas #1218) : aucune borne inventée, durée déclarée et explication", () => {
    const r = realHoursOf({ ...base, actualDurationMinutes: 510, effectiveDurationMinutes: 510, effectiveDurationSource: "ACTUAL_EXPLICIT" });
    expect(r.kind).toBe("DURATION_ONLY");
    expect(r.start).toBeNull();
    expect(r.end).toBeNull();
    expect(r.minutes).toBe(510);
    expect(r.note).toBe("Une durée réelle de 8h30 a été déclarée, mais les heures de début et de fin n'ont pas été renseignées.");
  });

  it("aucune heure : NONE, même si le backend sert le planifié de repli", () => {
    expect(realHoursOf(null).kind).toBe("NONE");
    expect(realHoursOf({ ...base, hasExecutionRecord: false }).kind).toBe("NONE");
    const empty = realHoursOf(base);
    expect(empty).toEqual({ kind: "NONE", start: null, end: null, minutes: null, note: null });
  });

  it("début seul : déclaration incomplète, le planifié de repli n'est jamais une durée réelle", () => {
    const r = realHoursOf({ ...base, actualStartAt: "2026-10-06T07:00:00+02:00" });
    expect(r.kind).toBe("PARTIAL");
    expect(r.start).toBe("2026-10-06T07:00:00+02:00");
    expect(r.end).toBeNull();
    expect(r.minutes).toBeNull();
    expect(r.note).toBe("Déclaration incomplète : l'heure de fin n'a pas été renseignée. Aucune durée réelle ne peut être comparée au planifié.");
  });

  it("fin seule avec durée : incomplète, la durée déclarée est conservée", () => {
    const r = realHoursOf({ ...base, actualEndAt: "2026-10-06T15:30:00+02:00", actualDurationMinutes: 480 });
    expect(r.kind).toBe("PARTIAL");
    expect(r.minutes).toBe(480);
    expect(r.note).toBe("Déclaration incomplète : l'heure de début n'a pas été renseignée.");
  });
});

describe("realHoursOf — D-156 présence commune d'une prestation multi-salles", () => {
  const presence = { id: 9, revision: 2, actualStartAt: "2026-11-04T07:45:00+01:00", actualEndAt: "2026-11-04T18:00:00+01:00", totalMinutes: 615, missionIds: [1, 2] };

  it("bornes de la présence commune, durée = part imputée à cette mission, jamais « durée seule »", () => {
    const r = realHoursOf({ ...base, actualDurationMinutes: 315, effectiveDurationMinutes: 315, effectiveDurationSource: "PRESENCE_SHARE", presence });
    expect(r.kind).toBe("PRESENCE_SHARE");
    expect(r.start).toBe(presence.actualStartAt);
    expect(r.end).toBe(presence.actualEndAt);
    expect(r.minutes).toBe(315);
    expect(r.note).toBe("Présence commune de la prestation multi-salles (10h15) : imputation financière de 5h15 à cette mission.");
  });

  it("part nulle : expliquée, heures comptées sur une autre mission", () => {
    const r = realHoursOf({ ...base, actualDurationMinutes: 0, effectiveDurationMinutes: 0, effectiveDurationSource: "PRESENCE_SHARE", presence });
    expect(r.minutes).toBe(0);
    expect(r.note).toContain("les heures sont comptées sur une autre mission de la prestation");
  });
});
