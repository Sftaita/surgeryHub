export const NOTIFICATION_TYPE_LABELS: Record<string, string> = {
  PLANNING_ALERT: "Alertes planning",
  PLANNING_DEPLOYED_INSTRUMENTIST: "Planning déployé",
  PLANNING_DEPLOYED_SURGEON: "Planning déployé",
  PLANNING_DEPLOYED_MANAGER: "Planning déployé",
  OPEN_MISSION_AVAILABLE: "Nouvelles offres disponibles",
  SURGEON_POST_COVERED: "Poste couvert",
  SURGEON_POST_UNCOVERED: "Poste non couvert",
  PLANNING_MISSION_REASSIGNED: "Mission réassignée",
  PLANNING_MISSION_CANCELLED: "Mission annulée",
  PLANNING_MISSION_ADDED: "Mission ajoutée",
  PLANNING_MISSION_UPDATED: "Mission modifiée",
  ABSENCE_INSTRUMENTIST_RELEASED: "Mission libérée suite à une absence",
  ABSENCE_SURGEON_MISSION_OPENED: "Mission réouverte suite à une absence",
  ABSENCE_MISSION_CANCELLED: "Mission annulée suite à une absence",
  PLANNING_RESENT_MANUAL: "Planning renvoyé par un manager",
  // Correctif workflow Demandes Catalogue (D-113) — CATALOGUE_REQUEST_CREATED gagne un
  // canal email désormais activé par défaut : sans libellé lisible ici, un manager ne
  // pouvait pas retrouver ce type dans ses préférences pour le désactiver.
  CATALOGUE_REQUEST_CREATED: "Nouvelle demande Catalogue à traiter",
  CATALOGUE_REQUEST_RESOLVED: "Votre proposition Catalogue acceptée",
  CATALOGUE_REQUEST_IGNORED: "Votre proposition Catalogue non retenue",
};

export function notificationTypeLabel(type: string): string {
  return NOTIFICATION_TYPE_LABELS[type] ?? type;
}
