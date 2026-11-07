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

describe("D-143 — notifications d'encodage", () => {
  it("relance : reprend le texte serveur", () => {
    expect(formatNotificationTitle({ eventType: "ENCODING_REMINDER" })).toBe("Encodage à finaliser");
    expect(formatNotificationBody({ eventType: "ENCODING_REMINDER", payload: { body: "Gardez votre journée en mémoire !" } } as any)).toBe("Gardez votre journée en mémoire !");
  });

  it("escalade J+4 : instrumentiste, date, chirurgien, éléments manquants", () => {
    expect(formatNotificationBody({ eventType: "ENCODING_ESCALATION", payload: {
      instrumentistName: "Diane D.", missionDate: "14/07/2026", surgeonName: "Dr X", missing: ["Heure réelle de fin"],
    } } as any)).toBe("Diane D. — mission du 14/07/2026 — Dr X. Manquant : Heure réelle de fin.");
  });

  it("soumission chirurgien : une correction est annoncée comme telle", () => {
    expect(formatNotificationBody({ eventType: "ENCODING_SUBMITTED", payload: { missionDate: "14/07/2026", resubmitted: true } } as any))
      .toContain("a été corrigé");
  });

  it("D-148 — correction demandée : mission identifiée et motif du manager", () => {
    const returned = { eventType: "ENCODING_CORRECTION_REQUESTED", payload: { missionId: 435, missionDate: "14/07/2026", action: "RETURNED", reason: "Merci de renseigner vos horaires réels." } };
    expect(formatNotificationTitle(returned)).toBe("Correction de votre encodage demandée");
    expect(formatNotificationBody(returned as any)).toBe("L'encodage de votre mission #435 du 14/07/2026 vous a été renvoyé pour correction. Motif : Merci de renseigner vos horaires réels.");
    const reopened = { ...returned, payload: { ...returned.payload, action: "REOPENED" } };
    expect(formatNotificationBody(reopened as any)).toContain("a été rouvert pour correction");
  });

  it("D-154 — encodage complété par le chirurgien : une notification lisible, sans détail d'encodage", () => {
    const n = { eventType: "ENCODING_UPDATED_BY_SURGEON", payload: { missionId: 501, missionDate: "15/10/2026", surgeonName: "Paul Martin" } };
    expect(formatNotificationTitle(n)).toBe("Encodage complété par le chirurgien");
    expect(formatNotificationBody(n as any)).toBe("Paul Martin a complété ou corrigé les interventions / le matériel de votre mission du 15/10/2026. Consultez votre encodage.");
  });

  it("D-171 — encodage repris par un manager : données conservées, heures manquantes signalées", () => {
    const n = { eventType: "ENCODING_TAKEN_OVER", payload: { missionId: 1079, missionDate: "22/10/2026", managerName: "Sophie Recette", hoursMissing: true } };
    expect(formatNotificationTitle(n)).toBe("Encodage repris et validé");
    expect(formatNotificationBody(n as any)).toBe("Sophie Recette a repris, terminé et validé l'encodage de votre mission du 22/10/2026 ; ce que vous aviez encodé est conservé. Vos heures prestées restent à déclarer.");
    expect(formatNotificationBody({ ...n, payload: { ...n.payload, hoursMissing: false } } as any)).not.toContain("heures");
  });

  it("signalement côté instrumentiste : catégorie seulement, jamais le commentaire", () => {
    const body = formatNotificationBody({ eventType: "ENCODING_ANOMALY_INSTRUMENTIST", payload: { missionDate: "14/07/2026", type: "HOURS_INCORRECT", comment: "secret" } } as any);
    expect(body).toContain("(horaires)");
    expect(body).not.toContain("secret");
  });
});

describe("formatNotificationBody — PLANNING_MISSION_RELEASED (H2, D-127 ; formulation neutre D-151)", () => {
  it("dit seulement que l'affectation n'a pas pu être maintenue : jamais qui, jamais pourquoi, jamais une offre", () => {
    const n = { eventType: "PLANNING_MISSION_RELEASED", payload: { missionDate: "13/11/2026", startTime: "08:00", siteName: "Delta", assignmentEnded: true } };
    expect(formatNotificationTitle(n)).toBe("Affectation annulée");
    const body = formatNotificationBody(n);
    expect(body).toBe("Votre affectation à la mission du 13/11/2026 à 08:00, prévue à Delta, n'a pas pu être maintenue.");
    expect(body).not.toMatch(/disponible|reprendre|offre|chirurgien|refus|annulée/i);
  });

  it("donne la même formulation à l'ancienne instrumentiste d'une réaffectation, et garde le texte d'ajout pour la nouvelle", () => {
    const ended = { eventType: "PLANNING_MISSION_REASSIGNED", payload: { missionDate: "13/11/2026", startTime: "08:00", siteName: "Delta", assignmentEnded: true } };
    expect(formatNotificationTitle(ended)).toBe("Affectation annulée");
    expect(formatNotificationBody(ended)).toContain("n'a pas pu être maintenue");
    expect(formatNotificationTitle({ eventType: "PLANNING_MISSION_REASSIGNED", payload: { siteName: "Delta" } })).toBe("Mission réassignée");
  });
});

describe("D-151 — acceptation volontaire et origine réelle côté managers", () => {
  it("dit au chirurgien qui a accepté et jusqu'à quand il peut refuser", () => {
    const n = {
      eventType: "SURGEON_POST_COVERED",
      payload: { acceptedVoluntarily: true, instrumentistName: "Iris Martin", missionDate: "20/07/2030", startTime: "08:00", siteName: "Delta", refusalDeadlineAt: "2030-07-16T09:30:00+02:00" },
    };
    expect(formatNotificationTitle(n)).toBe("Instrumentiste affecté à votre mission");
    expect(formatNotificationBody(n)).toMatch(/^Iris Martin a accepté votre mission du 20\/07\/2030 à 08:00, à Delta\. Vous pouvez signaler un refus d'affectation jusqu'au 16\/07\/2030 à \d\d:30\.$/);
  });

  it("garde le titre historique pour une couverture décidée par le manager", () => {
    expect(formatNotificationTitle({ eventType: "SURGEON_POST_COVERED", payload: { instrumentistName: "Iris" } })).toBe("Poste couvert");
  });

  it("dit aux managers l'origine réelle du refus et de la demande d'intervention", () => {
    const refused = { eventType: "ASSIGNMENT_REFUSED_BY_SURGEON", payload: { surgeonName: "Simon Chirurgien", fromInstrumentistName: "Iris Martin", missionDate: "20/07/2030", siteName: "Delta" } };
    expect(formatNotificationTitle(refused)).toBe("Refus d'affectation par le chirurgien");
    expect(formatNotificationBody(refused)).toBe("Dr Simon Chirurgien a refusé l'affectation de Iris Martin (mission du 20/07/2030 — Delta). La mission est remise à disposition des autres instrumentistes.");

    const review = { eventType: "ASSIGNMENT_REVIEW_REQUESTED", payload: { surgeonName: "Simon Chirurgien", instrumentistName: "Iris Martin", missionDate: "20/07/2030", siteName: "Delta" } };
    expect(formatNotificationTitle(review)).toBe("Intervention demandée sur une affectation");
    expect(formatNotificationBody(review)).toBe("Dr Simon Chirurgien demande votre intervention sur l'affectation de Iris Martin (mission du 20/07/2030 — Delta).");
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

describe("ROOM_SLOT_AVAILABLE (D-161) — texte construit depuis le payload serveur", () => {
  it("titre et corps : site, date, horaire", () => {
    const n = {
      eventType: "ROOM_SLOT_AVAILABLE",
      payload: { roomSlotId: 42, siteName: "CHIREC Delta", dayLabel: "23 décembre", startTime: "07:45", endTime: "13:00", periodLabel: "matin" },
    };
    expect(formatNotificationTitle(n)).toBe("Salle opératoire disponible");
    expect(formatNotificationBody(n)).toBe("CHIREC Delta — 23 décembre, 07:45–13:00. Une salle vient de se libérer et peut être récupérée.");
  });

  it("sans horaire configuré : la période remplace l'horaire", () => {
    expect(formatNotificationBody({
      eventType: "ROOM_SLOT_AVAILABLE",
      payload: { siteName: "CHIREC Delta", dayLabel: "23 décembre", startTime: null, endTime: null, periodLabel: "journée" },
    })).toBe("CHIREC Delta — 23 décembre, journée. Une salle vient de se libérer et peut être récupérée.");
  });
});
