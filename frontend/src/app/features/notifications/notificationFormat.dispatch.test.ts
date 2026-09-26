import { describe, it, expect } from "vitest";
import { formatNotificationBody, formatNotificationTitle } from "./notificationFormat";
import { notificationTypeLabel } from "./notificationTypeLabels";

// D-125 — a direct assignment is a CONFIRMATION (never phrased as a request); a nominative
// request asks for an answer; a refusal tells the manager the mission is still uncovered.
describe("notificationFormat — D-125 demande nominative / attribution directe", () => {
  const payload = { missionDate: "06/10/2026", startTime: "08:00", endTime: "13:00", siteName: "Delta", instrumentistName: "Salve Decorte" };

  it("MISSION_ASSIGNED_DIRECTLY — confirmation, aucune action requise", () => {
    const n = { eventType: "MISSION_ASSIGNED_DIRECTLY", payload };
    expect(formatNotificationTitle(n)).toBe("Mission attribuée");
    const body = formatNotificationBody(n);
    expect(body).toBe("06/10/2026 08:00–13:00 — Delta. Déjà confirmée, aucune action n'est requise.");
    expect(body).not.toMatch(/accept|refus/i);
  });

  it("MISSION_OFFERED — demande : accepter ou refuser", () => {
    const n = { eventType: "MISSION_OFFERED", payload };
    expect(formatNotificationTitle(n)).toBe("Mission proposée — réponse attendue");
    expect(formatNotificationBody(n)).toBe("06/10/2026 08:00–13:00 — Delta. Acceptez-la ou refusez-la depuis vos offres.");
  });

  it("MISSION_OFFER_DECLINED — le manager sait que la mission reste à couvrir", () => {
    const n = { eventType: "MISSION_OFFER_DECLINED", payload };
    expect(formatNotificationTitle(n)).toBe("Demande refusée");
    expect(formatNotificationBody(n)).toBe("Salve Decorte a refusé la mission du 06/10/2026 08:00–13:00 — Delta. Elle reste à couvrir.");
  });

  it("libellés de préférences lisibles", () => {
    expect(notificationTypeLabel("MISSION_OFFERED")).toBe("Mission proposée personnellement");
    expect(notificationTypeLabel("MISSION_ASSIGNED_DIRECTLY")).toBe("Mission attribuée directement (confirmation)");
    expect(notificationTypeLabel("MISSION_OFFER_DECLINED")).toBe("Demande de mission refusée");
  });
});
