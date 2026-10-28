/**
 * D-147 — libellés FR des AuditEventType (backend App\Enum\AuditEventType), pour le
 * journal d'activité manager et l'historique des demandes chirurgien. Purement
 * cosmétique : un type inconnu (ajouté côté backend après ce fichier) s'affiche par son
 * code brut, jamais masqué.
 */
export const AUDIT_EVENT_LABELS: Record<string, string> = {
  // Missions
  MISSION_CREATED_MANUALLY: "Mission créée",
  MISSION_DRAFT_UPDATED: "Brouillon modifié",
  MISSION_DRAFT_INSTRUMENTIST_CHANGED: "Instrumentiste du brouillon modifié",
  MISSION_PUBLISHED_TO_POOL: "Proposée au pool",
  MISSION_OFFERED_TO_INSTRUMENTIST: "Proposée à une instrumentiste",
  MISSION_ASSIGNED_DIRECTLY: "Attribuée directement",
  MISSION_OFFER_DECLINED: "Proposition refusée",
  MISSION_CLAIMED_FROM_POOL: "Prise en charge depuis le pool",
  MISSION_RELEASED_TO_POOL: "Remise au pool",
  MISSION_CANCELLED_POST_DEPLOY: "Mission annulée",
  MISSION_REASSIGNED_POST_DEPLOY: "Mission réassignée",
  MISSION_TIME_CHANGED_POST_DEPLOY: "Horaire modifié",
  MISSION_ADDED_POST_DEPLOY: "Mission ajoutée au planning",
  MISSION_UNCOVERED_ESCALATION_SENT: "Alerte « non couverte » envoyée",
  MISSION_RESTORED_AFTER_SURGEON_ABSENCE: "Mission rétablie (fin d'absence chirurgien)",
  MISSION_ASSIGNMENT_RESTORED_AFTER_INSTRUMENTIST_ABSENCE: "Attribution rétablie (fin d'absence instrumentiste)",
  ABSENCE_DAY_REMOVED: "Jour d'absence retiré",

  // Demandes chirurgien
  SURGEON_MISSION_REQUEST_CREATED: "Demande de mission envoyée",
  SURGEON_MISSION_REQUEST_ACCEPTED: "Demande acceptée",
  SURGEON_MISSION_REQUEST_REJECTED: "Demande refusée",

  // Planning
  PLANNING_GENERATED: "Planning généré",
  PLANNING_UNASSIGNED_HANDLED: "Créneaux non couverts traités",
  PLANNING_DEPLOYED: "Planning déployé",
  PLANNING_ARCHIVED: "Planning archivé",
  MISSION_CREATED_FROM_PLANNING: "Mission créée par le planning",
  MISSION_OPENED_FROM_PLANNING: "Mission ouverte par le planning",
  MISSION_ASSIGNED_FROM_PLANNING: "Mission attribuée par le planning",
  PLANNING_ALERT_ACKNOWLEDGED: "Alerte planning vue",
  PLANNING_ALERT_RESOLVED: "Alerte planning résolue",
  PLANNING_ALERT_IGNORED: "Alerte planning ignorée",
  PLANNING_ALERT_REASSIGNED: "Alerte planning : réassignation",
  PLANNING_ALERT_OPENED_AS_AVAILABLE: "Alerte planning : mise au pool",
  PLANNING_OCCURRENCE_CANCELLED_DUE_TO_SURGEON_ABSENCE: "Occurrence annulée (absence chirurgien)",
  PLANNING_OCCURRENCE_INSTRUMENTIST_ABSENCE_NOTICE: "Occurrence impactée (absence instrumentiste)",
  PLANNING_OCCURRENCE_RESTORED_AFTER_ABSENCE: "Occurrence rétablie après absence",
  ROOM_SLOT_MADE_AVAILABLE: "Salle libérée",
  ROOM_SLOT_TAKEN_OVER: "Salle reprise",
  ROOM_SLOT_REOPENED: "Salle à nouveau disponible",

  // Encodage / exécution
  MISSION_DECLARED: "Mission déclarée",
  MISSION_DECLARED_APPROVED: "Déclaration approuvée",
  MISSION_DECLARED_REJECTED: "Déclaration refusée",
  MISSION_STARTED: "Mission démarrée",
  MISSION_ENCODING_STARTED: "Encodage commencé",
  MISSION_ENCODING_COMPLETED: "Encodage soumis",
  MISSION_ENCODING_REOPENED: "Encodage rouvert",
  MISSION_ENCODING_VALIDATED: "Encodage validé",
  MISSION_ENCODING_TAKEN_OVER: "Encodage repris par un manager",
  MISSION_ENCODING_REJECTED: "Encodage renvoyé",
  MISSION_ENCODING_MANUAL_REMINDER_SENT: "Relance d'encodage envoyée",
  MISSION_HOURS_MANUAL_REMINDER_SENT: "Relance des heures envoyée",
  MISSION_ENCODING_AUTO_REMINDER_SENT: "Relance automatique d'encodage",
  MISSION_EXECUTION_CREATED: "Prestation créée",
  MISSION_EXECUTION_UPDATED: "Prestation modifiée",
  MISSION_EXECUTION_DURATION_CHANGED: "Durée de prestation modifiée",
  MISSION_EXECUTION_DISPUTE_OPENED: "Contestation ouverte",
  MISSION_EXECUTION_DISPUTE_RESOLVED: "Contestation résolue",
  MISSION_EXECUTION_DISPUTE_REJECTED: "Contestation rejetée",
  MISSION_INTERVENTION_DRAFT_CREATED: "Intervention à compléter créée",
  MISSION_INTERVENTION_DRAFT_RESOLVED: "Intervention à compléter résolue",
  MISSION_INTERVENTION_DRAFT_IGNORED_AS_HISTORY: "Intervention à compléter classée",
  MISSION_INTERVENTION_DRAFT_MATERIAL_REASSIGNED: "Matériel réaffecté",
  ENCODING_ANOMALY_REPORTED: "Anomalie d'encodage signalée",
  ENCODING_ANOMALY_RESOLVED: "Anomalie d'encodage résolue",

  // Tarifs & facturation
  PRICING_RULE_CREATED: "Tarif créé",
  PRICING_RULE_SCHEDULED: "Tarif programmé",
  PRICING_RULE_REPLACED: "Tarif remplacé",
  PRICING_RULE_FUTURE_UPDATED: "Tarif futur modifié",
  PRICING_RULE_FUTURE_CANCELLED: "Tarif futur annulé",
  INSTRUMENTIST_RATE_CREATED: "Taux instrumentiste créé",
  INSTRUMENTIST_RATE_SCHEDULED: "Taux instrumentiste programmé",
  INSTRUMENTIST_RATE_REPLACED: "Taux instrumentiste remplacé",
  INSTRUMENTIST_RATE_FUTURE_UPDATED: "Taux instrumentiste futur modifié",
  INSTRUMENTIST_RATE_FUTURE_CANCELLED: "Taux instrumentiste futur annulé",
  FINANCIAL_CALCULATION_CREATED: "Calcul financier créé",
  FINANCIAL_CALCULATION_RECALCULATED: "Calcul financier recalculé",
  FINANCIAL_CALCULATION_APPROVED: "Calcul financier approuvé",
  FINANCIAL_CALCULATION_LOCKED: "Calcul financier verrouillé",
  FINANCIAL_CALCULATION_SUPERSEDED: "Calcul financier remplacé",
  FINANCIAL_CALCULATION_CANCELLED: "Calcul financier annulé",
  FINANCIAL_CALCULATION_FAILED: "Calcul financier en échec",
  FIRM_INVOICE_CREATED_FROM_CALCULATION: "Facture firme créée",
  FIRM_INVOICE_ISSUED: "Facture firme émise",
  FIRM_INVOICE_CANCELLED: "Facture firme annulée",
  INSTRUMENTIST_STATEMENT_CREATED_FROM_CALCULATION: "Décompte créé",
  INSTRUMENTIST_STATEMENT_ISSUED: "Décompte émis",
  INSTRUMENTIST_STATEMENT_CANCELLED: "Décompte annulé",
  DOCUMENT_PAYMENT_RECORDED: "Paiement enregistré",
  DOCUMENT_PARTIALLY_PAID: "Document partiellement payé",
  DOCUMENT_FULLY_PAID: "Document payé",
  CREDIT_NOTE_CREATED: "Note de crédit créée",
  DEBIT_NOTE_CREATED: "Note de débit créée",
  FINANCIAL_CORRECTION_ISSUED: "Correction financière émise",
  REFUND_RECORDED: "Remboursement enregistré",
  DOCUMENT_NET_BALANCE_CHANGED: "Solde du document modifié",

  // Catalogue
  INTERVENTION_TYPE_MERGED: "Types d'intervention fusionnés",
  MATERIAL_ITEM_REQUEST_RESOLVED: "Demande de matériel résolue",
  MATERIAL_ITEM_REQUEST_IGNORED: "Demande de matériel ignorée",
};

export function formatAuditEventType(type: string | null | undefined): string {
  if (!type) return "—";
  return AUDIT_EVENT_LABELS[type] ?? type;
}

/** Catégories exposées par le backend (AuditEventType::CATEGORIES). */
export const AUDIT_CATEGORIES = [
  { value: "MISSION", label: "Missions" },
  { value: "SURGEON_REQUEST", label: "Demandes chirurgien" },
  { value: "PLANNING", label: "Planning" },
  { value: "ENCODING", label: "Encodage" },
  { value: "FINANCE", label: "Tarifs & facturation" },
  { value: "CATALOGUE", label: "Catalogue" },
] as const;

export type AuditCategory = (typeof AUDIT_CATEGORIES)[number]["value"];

export function formatAuditCategory(category: string | null | undefined): string {
  return AUDIT_CATEGORIES.find((c) => c.value === category)?.label ?? category ?? "—";
}
