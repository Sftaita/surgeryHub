import type { ChipProps } from "@mui/material";
import type { EncodingFinancialState, EncodingState, EffectiveDurationSource, MissionType } from "./api/encodingTracking.api";
import type { StatusBadgeConfig } from "../../ui/StatusBadge";

/**
 * Suivi des encodages (D-118) — mapping PUREMENT présentationnel des 7 EncodingState et
 * des 6 EncodingFinancialState backend.
 *
 * Ce fichier ne doit JAMAIS contenir de logique de dérivation (aucune comparaison de
 * date, aucun calcul de statut) : la seule chose autorisée ici est libellé/couleur/icône
 * pour une valeur déjà résolue par le backend (EncodingStateResolver /
 * EncodingFinancialStateResolver, D-118). Si un jour l'ajout d'un état ici semble
 * nécessiter une règle de calcul, c'est que la règle appartient au backend, pas ici.
 */
export const ENCODING_STATE_CONFIG: Record<EncodingState, StatusBadgeConfig> = {
  UPCOMING: { label: "À venir", color: "default" },
  TO_ENCODE: { label: "À encoder", color: "error" },
  IN_PROGRESS: { label: "En cours", color: "warning" },
  SUBMITTED: { label: "Soumis", color: "info" },
  VALIDATED: { label: "Validé", color: "success" },
  LOCKED: { label: "Verrouillé", color: "success", variant: "outlined" },
  NOT_APPLICABLE: { label: "Sans objet", color: "default", variant: "outlined" },
};

export const FINANCIAL_STATE_CONFIG: Record<EncodingFinancialState, StatusBadgeConfig> = {
  NOT_CALCULABLE: { label: "Pas encore calculable", color: "default" },
  TO_CALCULATE: { label: "À calculer", color: "default", variant: "outlined" },
  CALCULATED: { label: "Calculé", color: "info" },
  DOCUMENTED: { label: "Facturé / Décompté", color: "info", variant: "outlined" },
  PAID: { label: "Payé", color: "success" },
  ANOMALY: { label: "Anomalie", color: "error" },
};

export const EFFECTIVE_SOURCE_LABEL: Record<EffectiveDurationSource, string> = {
  PLANNED: "Planifié",
  ACTUAL_TIMES: "Heures réelles",
  ACTUAL_EXPLICIT: "Heures explicites",
};

export const MISSION_TYPE_LABEL: Record<MissionType, string> = {
  BLOCK: "Bloc opératoire",
  CONSULTATION: "Consultation",
};

export const ENCODING_STATE_ORDER: EncodingState[] = [
  "UPCOMING", "TO_ENCODE", "IN_PROGRESS", "SUBMITTED", "VALIDATED", "LOCKED", "NOT_APPLICABLE",
];

export function formatMinutes(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m} min`;
  if (m === 0) return `${h} h`;
  return `${h} h ${String(m).padStart(2, "0")}`;
}

export const chipColorForFinancial = (state: EncodingFinancialState): ChipProps["color"] => FINANCIAL_STATE_CONFIG[state].color;
