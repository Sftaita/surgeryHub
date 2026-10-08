import type {
  CatalogueRequestIgnoreReason,
  MaterialRequestDTO,
  MaterialRequestStatus,
} from "./api/catalogue.types";
import type { InterventionTypeRequestDTO } from "./api/interventionTypeRequests.api";

/** Demande catalogue affichée dans Manager > Demandes — matériel ou intervention, distinguées par `kind`. */
export type CatalogueRequestRow =
  | { kind: "material"; request: MaterialRequestDTO }
  | { kind: "intervention"; request: InterventionTypeRequestDTO };

export function statusLabel(status: MaterialRequestStatus): string {
  switch (status) {
    case "PENDING": return "En attente";
    case "RESOLVED": return "Résolu";
    case "IGNORED": return "Ignoré";
  }
}

export function statusColor(status: MaterialRequestStatus): "warning" | "success" | "default" {
  switch (status) {
    case "PENDING": return "warning";
    case "RESOLVED": return "success";
    case "IGNORED": return "default";
  }
}

/**
 * Correctif workflow Demandes Catalogue (D-113) — wording FR local à l'affichage
 * (historique Résolues/Ignorées, Select de la modal), miroir de
 * CatalogueRequestIgnoreReason::label() côté backend. Le backend, lui, ne transporte
 * jamais ce libellé (CatalogueRequestProcessedMessage porte le code brut) — voir
 * docs/decisions.md D-113.
 */
export const IGNORE_REASON_LABELS: Record<CatalogueRequestIgnoreReason, string> = {
  ALREADY_EXISTS: "Intervention déjà existante",
  MATERIAL_ALREADY_EXISTS: "Matériel déjà existant",
  DUPLICATE: "Demande en doublon",
  INVALID_REQUEST: "Demande incorrecte",
  OTHER: "Autre",
};

const DATE_TIME_FORMAT = new Intl.DateTimeFormat("fr-BE", {
  dateStyle: "short",
  timeStyle: "short",
  timeZone: "Europe/Brussels",
});

export function formatRequestDateTime(iso: string | null): string | null {
  return iso ? DATE_TIME_FORMAT.format(new Date(iso)) : null;
}
