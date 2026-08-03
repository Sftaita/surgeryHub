import type { NotificationItem } from "./api/notifications.api";

/**
 * Point 4 (audit UX) — cette table avait divergé de `NotificationType` (backend/src/
 * Enum/NotificationType.php) : la quasi-totalité des notifications réellement émises
 * retombait sur le repli générique "Notification", sans titre lisible. Clés alignées
 * sur les valeurs exactes de l'enum ci-dessus.
 */
const TITLES: Record<string, string> = {
  PLANNING_ALERT: "Alerte planning",
  PLANNING_DEPLOYED_INSTRUMENTIST: "Planning publié",
  PLANNING_DEPLOYED_SURGEON: "Planning publié",
  PLANNING_DEPLOYED_MANAGER: "Déploiement confirmé",
  OPEN_MISSION_AVAILABLE: "Nouvelle offre disponible",
  SURGEON_POST_COVERED: "Poste couvert",
  SURGEON_POST_UNCOVERED: "Poste non couvert",
  PLANNING_MISSION_REASSIGNED: "Mission réassignée",
  PLANNING_MISSION_CANCELLED: "Mission annulée",
  PLANNING_MISSION_ADDED: "Nouvelle mission",
  PLANNING_MISSION_UPDATED: "Mission modifiée",
  ABSENCE_INSTRUMENTIST_RELEASED: "Mission retirée (absence)",
  ABSENCE_SURGEON_MISSION_OPENED: "Mission désormais ouverte (absence)",
  ABSENCE_MISSION_CANCELLED: "Mission annulée (absence)",
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
