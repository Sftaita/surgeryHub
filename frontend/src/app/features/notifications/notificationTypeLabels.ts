export const NOTIFICATION_TYPE_LABELS: Record<string, string> = {
  PLANNING_ALERT: "Alertes planning",
  PLANNING_DEPLOYED_INSTRUMENTIST: "Planning déployé",
  PLANNING_DEPLOYED_SURGEON: "Planning déployé",
  PLANNING_DEPLOYED_MANAGER: "Planning déployé",
  OPEN_MISSION_AVAILABLE: "Nouvelles missions disponibles",
  SURGEON_POST_COVERED: "Poste couvert",
  SURGEON_POST_UNCOVERED: "Poste non couvert",
  PLANNING_MISSION_REASSIGNED: "Mission réassignée",
  PLANNING_MISSION_CANCELLED: "Mission annulée",
  // H2 (D-127) — retrait par un manager d'une mission publiée.
  PLANNING_MISSION_RELEASED: "Retrait d'une mission de votre planning",
  // H3 (D-129) — récapitulatif d'une modification du planning publié.
  PLANNING_CHANGE_SUMMARY: "Récapitulatif des modifications de votre planning",
  PLANNING_MISSION_ADDED: "Mission ajoutée",
  PLANNING_MISSION_UPDATED: "Mission modifiée",
  // D-125 — demande nominative / attribution directe / refus.
  MISSION_OFFERED: "Mission proposée personnellement",
  MISSION_ASSIGNED_DIRECTLY: "Mission attribuée directement (confirmation)",
  MISSION_OFFER_DECLINED: "Demande de mission refusée",
  // D-151 — managers : origine réelle d'une désaffectation, demande d'intervention.
  ASSIGNMENT_REFUSED_BY_SURGEON: "Refus d'affectation par un chirurgien",
  ASSIGNMENT_REVIEW_REQUESTED: "Intervention demandée par un chirurgien sur une affectation",
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
  ROOM_TAKEOVER_MISSION_OFFER: "Salle reprise — mission disponible pour vous",
  // H4 (D-130) — absences avant publication (créneau habituel / planning en préparation).
  ABSENCE_OCCURRENCE_CANCELLED: "Absence du chirurgien sur un créneau prévu ou habituel",
  ABSENCE_OCCURRENCE_UNCOVERED: "Absence de votre instrumentiste sur un créneau prévu ou habituel",
  // H5a — flux email désormais tracés par le dispatcher (historique des envois).
  MISSION_UNCOVERED_ESCALATION: "Instrumentiste toujours recherchée (J-14)",
  SURGEON_MISSION_OPEN_PUBLISHED: "Mission ouverte publiée pour vous",
  ENCODING_REMINDER_D1: "Rappel d'encodage",
  ABSENCE_REQUEST_MISSING: "Demande des congés à venir",
  ABSENCE_ENCODED_RECAP: "Récapitulatif des congés encodés",
  ACCOUNT_INVITATION: "Invitation au compte",
  ACCOUNT_EMAIL_CHANGED: "Changement d'adresse email",
  // H5b (D-132) — anciennes notifications in-app directes, désormais via le dispatcher
  // (donc réglables dans les préférences).
  MISSION_DECLARED: "Mission déclarée par une instrumentiste",
  MISSION_DECLARED_APPROVED: "Votre mission déclarée a été validée",
  MISSION_DECLARED_REJECTED: "Votre mission déclarée a été refusée",
  ENCODING_ANOMALY_REPORTED: "Anomalie d'encodage signalée",
  SURGEON_MISSION_REQUEST_CREATED: "Nouvelle demande de mission d'un chirurgien",
  // D-143 — les relances d'encodage elles-mêmes ne sont pas configurables (absentes de la liste).
  ENCODING_SUBMITTED: "Encodage de vos missions disponible (chirurgien)",
  ENCODING_ANOMALY_INSTRUMENTIST: "Point signalé sur votre encodage",
  ENCODING_CORRECTION_REQUESTED: "Correction de votre encodage demandée",
  ENCODING_UPDATED_BY_SURGEON: "Encodage complété par le chirurgien",
};

export function notificationTypeLabel(type: string): string {
  return NOTIFICATION_TYPE_LABELS[type] ?? type;
}
