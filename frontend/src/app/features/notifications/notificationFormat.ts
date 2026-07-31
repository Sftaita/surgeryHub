import type { NotificationItem } from "./api/notifications.api";

const TITLES: Record<string, string> = {
  MISSION_CANCELLED: "Mission annulée",
  MISSION_COVERED: "Mission couverte",
  MISSION_DECLARED: "Mission déclarée",
  MISSION_DECLARED_APPROVED: "Déclaration approuvée",
  MISSION_DECLARED_REJECTED: "Déclaration refusée",
  MISSION_REASSIGNED: "Mission réassignée",
  MISSION_UNCOVERED: "Mission non couverte",
  PLANNING_ALERT: "Alerte planning",
  PLANNING_ALERT_RAISED: "Alerte planning",
  PLANNING_ALERT_REASSIGNED_AWAY: "Mission retirée de votre planning",
  PLANNING_ALERT_REASSIGNED_TO: "Nouvelle mission assignée",
  PLANNING_DEPLOYED: "Planning déployé",
  PLANNING_MISSION_ASSIGNED: "Mission assignée",
  PLANNING_OPEN_MISSIONS_AVAILABLE: "Nouvelles offres disponibles",
  PLANNING_RESENT_MANUAL: "Planning renvoyé",
};

/**
 * `NotificationEvent` (backend) n'a pas de titre/corps préformaté — seulement
 * `eventType` + `payload` bruts. On dérive un texte lisible ici plutôt que
 * d'exposer l'eventType brut à l'utilisateur ; type inconnu → repli générique
 * (jamais un écran cassé pour un eventType ajouté côté backend sans être
 * répercuté ici).
 */
export function formatNotificationTitle(n: Pick<NotificationItem, "eventType">): string {
  return TITLES[n.eventType] ?? "Notification";
}

export function formatNotificationBody(n: Pick<NotificationItem, "payload" | "eventType">): string {
  const payload = n.payload ?? {};
  const siteName = typeof payload.siteName === "string" ? payload.siteName : null;
  const missionDate = typeof payload.missionDate === "string" ? payload.missionDate : null;

  if (n.eventType === "PLANNING_RESENT_MANUAL") {
    const from = typeof payload.periodFrom === "string" ? payload.periodFrom : null;
    const to = typeof payload.periodTo === "string" ? payload.periodTo : null;
    return from && to ? `Période du ${from} au ${to}` : "";
  }

  if (missionDate && siteName) return `${missionDate} — ${siteName}`;
  if (siteName) return siteName;
  if (missionDate) return missionDate;
  return "";
}
