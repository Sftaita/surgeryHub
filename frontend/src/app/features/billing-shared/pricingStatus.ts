/**
 * D-138 — deux états tarifaires jamais confondus dans les écrans du catalogue :
 * - « Tarif non configuré » : aucune règle active à la date du jour ;
 * - « Conflit tarifaire » : plusieurs règles actives couvrent la même cible
 *   (`pricingConflictRuleIds`, résolu par le backend — le frontend ne détecte rien).
 * Le moteur financier ne choisit jamais entre des règles en conflit : le calcul est bloqué.
 */
export const PRICING_NOT_CONFIGURED_LABEL = "Tarif non configuré";
export const PRICING_CONFLICT_LABEL = "Conflit tarifaire";

export function hasPricingConflict(ids: number[] | null | undefined): ids is number[] {
  return Array.isArray(ids) && ids.length > 0;
}

/** Libellé du forfait d'une prestation (Firme × intervention) tel que résolu par le backend. */
export function formatOfferingForfait(row: {
  feeApplicable: boolean;
  forfait: { amount: string; currency: string } | null;
  pricingConflictRuleIds?: number[] | null;
}): string {
  if (!row.feeApplicable) return "Pas de forfait";
  if (hasPricingConflict(row.pricingConflictRuleIds)) return PRICING_CONFLICT_LABEL;
  if (row.forfait) return `${Number(row.forfait.amount).toFixed(2)} ${row.forfait.currency} HTVA`;
  return PRICING_NOT_CONFIGURED_LABEL;
}

export function pricingConflictTooltip(ids: number[]): string {
  return `Règles ${ids.map((id) => `#${id}`).join(", ")} actives en même temps — le calcul financier est bloqué tant qu'une seule n'est pas conservée.`;
}
