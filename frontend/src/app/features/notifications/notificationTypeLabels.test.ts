import { describe, expect, it } from "vitest";
import { notificationTypeLabel } from "./notificationTypeLabels";

// Types proposés dans les préférences (NotificationType::isConfigurable() côté backend, tous
// rôles confondus). Un type ajouté là-bas sans libellé ici s'afficherait avec son code.
const VISIBLE_TYPES = [
  "PLANNING_ALERT", "PLANNING_DEPLOYED_INSTRUMENTIST", "PLANNING_DEPLOYED_SURGEON", "PLANNING_DEPLOYED_MANAGER",
  "OPEN_MISSION_AVAILABLE", "SURGEON_POST_COVERED", "SURGEON_POST_UNCOVERED", "PLANNING_MISSION_REASSIGNED",
  "PLANNING_MISSION_CANCELLED", "PLANNING_MISSION_RELEASED", "PLANNING_CHANGE_SUMMARY", "MISSION_OFFERED",
  "MISSION_ASSIGNED_DIRECTLY", "MISSION_OFFER_DECLINED", "ABSENCE_SURGEON_MISSION_OPENED", "ABSENCE_MISSION_CANCELLED",
  "ABSENCE_INSTRUMENTIST_REASSIGNED", "ABSENCE_SELF_DECLARED", "ABSENCE_OCCURRENCE_CANCELLED", "ABSENCE_OCCURRENCE_UNCOVERED",
  "MISSION_UNCOVERED_ESCALATION", "ABSENCE_OCCURRENCE_RESTORED", "MISSION_RESTORED", "ABSENCE_IMPACT_SUMMARY",
  "SURGEON_MISSION_OPEN_PUBLISHED", "CATALOGUE_REQUEST_RESOLVED", "CATALOGUE_REQUEST_IGNORED", "CATALOGUE_REQUEST_CREATED",
  "SURGEON_MISSION_REQUEST_CREATED", "SURGEON_MISSION_REQUEST_ACCEPTED", "SURGEON_MISSION_REQUEST_REJECTED",
  "ENCODING_ANOMALY_REPORTED", "ENCODING_ANOMALY_RESOLVED", "ROOM_TAKEOVER_MISSION_OFFER", "MISSION_DECLARED",
  "MISSION_DECLARED_APPROVED", "MISSION_DECLARED_REJECTED", "ENCODING_SUBMITTED", "ENCODING_ANOMALY_INSTRUMENTIST",
  "ENCODING_CORRECTION_REQUESTED", "ENCODING_UPDATED_BY_SURGEON", "ASSIGNMENT_REFUSED_BY_SURGEON", "ASSIGNMENT_REVIEW_REQUESTED", "ROOM_SLOT_AVAILABLE",
];

describe("notificationTypeLabel", () => {
  it.each(VISIBLE_TYPES)("%s a un libellé français, jamais son code technique", (type) => {
    const label = notificationTypeLabel(type);
    expect(label).not.toBe(type);
    expect(label).not.toMatch(/^[A-Z_]+$/);
  });

  it("libellés des types corrigés en recette", () => {
    expect(notificationTypeLabel("MISSION_RESTORED")).toBe("Mission rétablie après l'annulation d'une absence");
    expect(notificationTypeLabel("SURGEON_MISSION_REQUEST_ACCEPTED")).toBe("Votre demande de mission acceptée");
    expect(notificationTypeLabel("SURGEON_MISSION_REQUEST_REJECTED")).toBe("Votre demande de mission refusée");
    expect(notificationTypeLabel("ENCODING_ANOMALY_RESOLVED")).toBe("Anomalie d'encodage résolue");
  });
});
