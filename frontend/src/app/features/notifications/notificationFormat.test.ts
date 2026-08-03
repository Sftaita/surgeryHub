import { describe, it, expect } from "vitest";
import { formatNotificationTitle } from "./notificationFormat";

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
    "PLANNING_RESENT_MANUAL",
  ];

  it.each(REAL_EVENT_TYPES)("a un titre lisible dédié pour %s (jamais le repli générique)", (eventType) => {
    expect(formatNotificationTitle({ eventType })).not.toBe("Notification");
  });

  it("retombe sur le repli générique pour un eventType inconnu", () => {
    expect(formatNotificationTitle({ eventType: "SOMETHING_NEW_NOT_YET_MAPPED" })).toBe("Notification");
  });
});
