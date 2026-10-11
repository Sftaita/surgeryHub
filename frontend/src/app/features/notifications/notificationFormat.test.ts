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
    "PLANNING_CHANGE_SUMMARY",
  ];

  it.each(REAL_EVENT_TYPES)("a un titre lisible dédié pour %s (jamais le repli générique)", (eventType) => {
    expect(formatNotificationTitle({ eventType })).not.toBe("Notification");
  });

  it("retombe sur le repli générique pour un eventType inconnu", () => {
    expect(formatNotificationTitle({ eventType: "SOMETHING_NEW_NOT_YET_MAPPED" })).toBe("Notification");
  });
});

/**
 * CAS B (D-118) — ABSENCE_INSTRUMENTIST_REASSIGNED leads with the new assignment
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

describe("formatNotificationBody — ROOM_TAKEOVER_MISSION_OFFER (D-124)", () => {
  it("contextualise l'offre avec le repreneur, le chirurgien absent, la date et le site (payload serveur uniquement)", () => {
    const n = {
      eventType: "ROOM_TAKEOVER_MISSION_OFFER",
      payload: { takenByName: "Dr Bruno", originalSurgeonName: "Dr Alain", missionDate: "15/10/2026", siteName: "Delta" },
    };
    expect(formatNotificationTitle(n)).toBe("Salle reprise — mission disponible");
    expect(formatNotificationBody(n)).toBe(
      "Dr Bruno reprend la salle de Dr Alain le 15/10/2026 à Delta. Une mission est disponible pour ce créneau.",
    );
  });
});

describe("formatNotificationBody — PLANNING_MISSION_RELEASED (H2, D-127)", () => {
  it("dit que la mission ne fait plus partie du planning, sans jamais la proposer", () => {
    const n = { eventType: "PLANNING_MISSION_RELEASED", payload: { missionDate: "13/11/2026", siteName: "Delta" } };
    expect(formatNotificationTitle(n)).toBe("Mission retirée de votre planning");
    const body = formatNotificationBody(n);
    expect(body).toBe("13/11/2026 — Delta — cette mission ne fait plus partie de votre planning.");
    expect(body).not.toMatch(/disponible|reprendre|offre/i);
  });
});

describe("H3 (D-129) — corps des notifications pool et récapitulatif", () => {
  it("décrit une mission disponible depuis le payload serveur (date, heures, site, chirurgien)", () => {
    const body = formatNotificationBody({
      eventType: "OPEN_MISSION_AVAILABLE",
      payload: { missionDate: "15/01/2027", startTime: "08:00", endTime: "16:00", siteName: "Repro Delta", surgeonName: "Philippe Schiepers" },
    });
    expect(body).toBe("15/01/2027 08:00–16:00 — Repro Delta — Dr Philippe Schiepers. Consultez vos offres pour la prendre.");
  });

  it("résume les changements d'une modification de planning", () => {
    const body = formatNotificationBody({
      eventType: "PLANNING_CHANGE_SUMMARY",
      payload: { periodLabel: "janvier 2027", addedCount: 1, removedCount: 2, modifiedCount: 0 },
    });
    expect(body).toBe("Planning de janvier 2027 : 1 ajout, 2 retraits.");
  });
});

describe("H4 (D-130) — informations d'absence N1 / N2", () => {
  it("affiche la phrase métier fournie par le serveur, jamais « mission annulée » avant publication", () => {
    const sentence = "Le Dr Philippe Schiepers sera absent le 25/06/2027. Le créneau sur lequel vous étiez prévue dans le planning en préparation est impacté.";
    expect(formatNotificationBody({ eventType: "ABSENCE_OCCURRENCE_CANCELLED", payload: { level: "DRAFT", sentence } })).toBe(sentence);
    expect(formatNotificationTitle({ eventType: "ABSENCE_OCCURRENCE_CANCELLED" })).not.toBe("Notification");
    expect(formatNotificationTitle({ eventType: "ABSENCE_OCCURRENCE_UNCOVERED" })).not.toBe("Notification");
  });
});
