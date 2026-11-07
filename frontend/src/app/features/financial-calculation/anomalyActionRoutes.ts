/**
 * Où mène chaque action d'anomalie du moteur financier (navigation uniquement — le code
 * d'action est décidé par le backend, FinancialCalculationAnomalyExplainer, D-138).
 * Partagé par « Facturation firmes » et le tiroir du « Suivi des encodages ».
 * OPEN_MISSION n'est pas ici : sa route dépend de la mission concernée.
 */
export const ANOMALY_ACTION_ROUTES: Partial<Record<string, string>> = {
  CONFIGURE_INTERVENTION_RATE: "/app/m/catalogue/prestations",
  CONFIGURE_MATERIAL_RATE: "/app/m/catalogue/prestations",
  CONFIGURE_INSTRUMENTIST_RATE: "/app/m/instrumentists",
};
