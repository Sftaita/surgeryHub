import { describe, it, expect } from "vitest";
import { formatNotificationBody, formatNotificationTitle } from "./notificationFormat";

/**
 * Point 4 (audit UX) — la table TITLES avait divergé de NotificationType (backend), la
 * quasi-totalité des notifications réelles retombait sur le repli générique
 * "Notification". Verrouille l'alignement sur chaque valeur réelle de l'enum.
 */
describe("formatNotificationTitle — alignement avec NotificationType (backend)", () => {
  const REAL_EVENT_TYPES = [
    "PLANNING_ALERT",
    "PLANNING_DEPLOYED_INSTRUMENTIST",
    "PLANNING_DEPLOYED_SURGEON",
    "PLANNING_DEPLOYED_MANAGER",
    "OPEN_MISSION_AVAILABLE",
    "SURGEON_POST_COVERED",
    "SURGEON_POST_UNCOVERED",
    "PLANNING_MISSION_REASSIGNED",
    "PLANNING_MISSION_CANCELLED",
    "PLANNING_MISSION_ADDED",
    "PLANNING_MISSION_UPDATED",
    "ABSENCE_INSTRUMENTIST_RELEASED",
    "ABSENCE_SURGEON_MISSION_OPENED",
    "ABSENCE_MISSION_CANCELLED",
    "ABSENCE_INSTRUMENTIST_REASSIGNED",
    "PLANNING_RESENT_MANUAL",
  ];

  it.each(REAL_EVENT_TYPES)("a un titre lisible dédié pour %s (jamais le repli générique)", (eventType) => {
    expect(formatNotificationTitle({ eventType })).not.toBe("Notification");
  });

  it("retombe sur le repli générique pour un eventType inconnu", () => {
    expect(formatNotificationTitle({ eventType: "SOMETHING_NEW_NOT_YET_MAPPED" })).toBe("Notification");
  });
});

/**
 * CAS B (D-117) — ABSENCE_INSTRUMENTIST_REASSIGNED leads with the new assignment
 * (payload.reassignedTo), not a repeat of the cancelled mission already named by the title.
 */
describe("formatNotificationBody — ABSENCE_INSTRUMENTIST_REASSIGNED", () => {
  it("affiche la nouvelle mission quand une seule réaffectation a eu lieu", () => {
    const body = formatNotificationBody({
      eventType: "ABSENCE_INSTRUMENTIST_REASSIGNED",
      payload: {
        date: "01/10/2026",
        siteName: "Delta",
        reassignedTo: [{ date: "01/10/2026", horaire: "13:00–18:00", siteName: "Delta" }],
      },
    });
    expect(body).toBe("Nouvelle mission : 01/10/2026 · 13:00–18:00 — Delta");
  });

  it("indique le nombre de missions supplémentaires quand plusieurs réaffectations ont eu lieu", () => {
    const body = formatNotificationBody({
      eventType: "ABSENCE_INSTRUMENTIST_REASSIGNED",
      payload: {
        date: "01/10/2026",
        siteName: "Delta",
        reassignedTo: [
          { date: "01/10/2026", horaire: "08:00–12:00", siteName: "Delta" },
          { date: "01/10/2026", horaire: "13:00–18:00", siteName: "Delta" },
        ],
      },
    });
    expect(body).toBe("Nouvelle mission : 01/10/2026 · 08:00–12:00 — Delta (+1 autre)");
  });

  it("retombe sur le repli générique si reassignedTo est absent ou vide", () => {
    const body = formatNotificationBody({
      eventType: "ABSENCE_INSTRUMENTIST_REASSIGNED",
      payload: { missionDate: "01/10/2026", siteName: "Delta", reassignedTo: [] },
    });
    expect(body).toBe("01/10/2026 — Delta");
  });
});
