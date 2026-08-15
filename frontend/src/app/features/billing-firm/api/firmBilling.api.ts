import { apiClient } from "../../../api/apiClient";

export interface PricingRule {
  id: number;
  ruleType: "INTERVENTION_FEE" | "MATERIAL_FEE";
  interventionType: { id: number; code: string; label: string } | null;
  materialItem: {
    id: number;
    label: string;
    referenceCode: string | null;
    firm: { id: number; name: string };
  } | null;
  /** Tarification firme conditionnée à un choix obligatoire — null = forfait unique standard. */
  choiceOption: { id: number; label: string } | null;
  unitPrice: string;
  currency: string;
  validFrom: string | null;
  validTo: string | null;
  active: boolean;
}

export interface FirmBillingContact {
  id: number;
  billingEmail: string | null;
  billingEmailCc: string[];
}

export interface SuggestedMaterialDto {
  id: number;
  displayOrder: number;
  materialItem: {
    id: number;
    label: string;
    referenceCode: string | null;
    active: boolean;
  };
}

/**
 * Refonte Catalogue/Prestations (D-092) — politique commerciale "présence d'un délégué"
 * + "forfait attendu", configurée sur cette prestation (Firm × InterventionType). Jamais
 * un montant : uniquement des indicateurs consommés par le backend (voir
 * RepresentativePolicyResolver) pour ajuster un tarif déjà résolu.
 */
export interface ChoiceOptionDto {
  id: number;
  label: string;
  displayOrder: number;
  active: boolean;
  materialItem: { id: number; label: string; referenceCode: string | null } | null;
}

/**
 * Tarification firme conditionnée à un choix obligatoire — question + options, jamais
 * un montant (le forfait par option vit dans PricingRule.choiceOption). Vue complète
 * réservée au manager (BillingVoter::MANAGE) — voir choiceGroup pour la vue filtrée
 * consommée par l'écran instrumentiste.
 */
export interface ChoiceGroupConfigDto {
  id: number;
  question: string;
  active: boolean;
  operational: boolean;
  options: ChoiceOptionDto[];
}

export interface FirmServiceOffering {
  id: number;
  firmId: number;
  interventionType: { id: number; code: string; label: string };
  label: string | null;
  active: boolean;
  representativePresenceRelevant: boolean;
  representativeSuppressesInterventionFee: boolean;
  representativeSuppressesOwnMaterialFees: boolean;
  feeApplicable: boolean;
  suggestedMaterials: SuggestedMaterialDto[];
  choiceGroupConfig: ChoiceGroupConfigDto | null;
}

// ── Pricing rules ────────────────────────────────────────────────────────────

export async function getFirmPricingRules(firmId: number): Promise<PricingRule[]> {
  const res = await apiClient.get(`/api/firms/${firmId}/pricing-rules`);
  return res.data;
}

export async function createPricingRule(
  firmId: number,
  body: {
    ruleType: "INTERVENTION_FEE" | "MATERIAL_FEE";
    unitPrice: number;
    interventionTypeId?: number;
    materialItemId?: number;
    /** Tarification firme conditionnée à un choix obligatoire — facultatif, INTERVENTION_FEE uniquement. */
    choiceOptionId?: number;
    currency?: string;
    validFrom?: string | null;
    validTo?: string | null;
  }
): Promise<PricingRule> {
  const res = await apiClient.post(`/api/firms/${firmId}/pricing-rules`, body);
  return res.data;
}

export async function updatePricingRule(
  firmId: number,
  ruleId: number,
  body: { unitPrice?: number; active?: boolean; currency?: string; validFrom?: string | null; validTo?: string | null }
): Promise<PricingRule> {
  const res = await apiClient.patch(`/api/firms/${firmId}/pricing-rules/${ruleId}`, body);
  return res.data;
}

export async function deletePricingRule(firmId: number, ruleId: number): Promise<void> {
  await apiClient.delete(`/api/firms/${firmId}/pricing-rules/${ruleId}`);
}

/**
 * D-072 §7 — remplace le tarif actuellement en vigueur à partir d'une date : ferme la
 * règle actuelle et en ouvre une nouvelle, atomique. Seul moyen de changer un tarif déjà
 * applicable (PATCH/DELETE sont désormais restreints aux règles futures, 409 sinon).
 */
export async function replacePricingRule(
  firmId: number,
  ruleId: number,
  body: { unitPrice: number; currency?: string; effectiveFrom: string }
): Promise<PricingRule> {
  const res = await apiClient.post(`/api/firms/${firmId}/pricing-rules/${ruleId}/replace`, body);
  return res.data;
}

// ── Billing contact ──────────────────────────────────────────────────────────

export async function updateFirmBillingContact(
  firmId: number,
  body: { billingEmail?: string | null; billingEmailCc?: string[] }
): Promise<FirmBillingContact> {
  const res = await apiClient.patch(`/api/firms/${firmId}/billing-contact`, body);
  return res.data;
}

// ── Prestations (FirmServiceOffering) ───────────────────────────────────────

export async function getFirmServiceOfferings(firmId: number): Promise<FirmServiceOffering[]> {
  const res = await apiClient.get(`/api/firms/${firmId}/service-offerings`);
  return res.data;
}

export async function createFirmServiceOffering(
  firmId: number,
  body: { interventionTypeId: number; label?: string },
): Promise<FirmServiceOffering> {
  const res = await apiClient.post(`/api/firms/${firmId}/service-offerings`, body);
  return res.data;
}

export async function updateFirmServiceOffering(
  firmId: number,
  offeringId: number,
  body: {
    label?: string | null;
    active?: boolean;
    representativePresenceRelevant?: boolean;
    representativeSuppressesInterventionFee?: boolean;
    representativeSuppressesOwnMaterialFees?: boolean;
    feeApplicable?: boolean;
  },
): Promise<FirmServiceOffering> {
  const res = await apiClient.patch(`/api/firms/${firmId}/service-offerings/${offeringId}`, body);
  return res.data;
}

export async function addSuggestedMaterial(
  firmId: number,
  offeringId: number,
  materialItemId: number,
): Promise<SuggestedMaterialDto> {
  const res = await apiClient.post(`/api/firms/${firmId}/service-offerings/${offeringId}/suggested-materials`, { materialItemId });
  return res.data;
}

export async function reorderSuggestedMaterials(
  firmId: number,
  offeringId: number,
  orderedIds: number[],
): Promise<SuggestedMaterialDto[]> {
  const res = await apiClient.patch(`/api/firms/${firmId}/service-offerings/${offeringId}/suggested-materials/reorder`, { orderedIds });
  return res.data;
}

export async function deleteSuggestedMaterial(
  firmId: number,
  offeringId: number,
  suggestionId: number,
): Promise<void> {
  await apiClient.delete(`/api/firms/${firmId}/service-offerings/${offeringId}/suggested-materials/${suggestionId}`);
}

// ── Tarification firme conditionnée à un choix obligatoire ──────────────────

export async function upsertChoiceGroup(
  firmId: number,
  offeringId: number,
  question: string,
): Promise<ChoiceGroupConfigDto> {
  const res = await apiClient.put(`/api/firms/${firmId}/service-offerings/${offeringId}/choice-group`, { question });
  return res.data;
}

export async function deactivateChoiceGroup(firmId: number, offeringId: number): Promise<void> {
  await apiClient.delete(`/api/firms/${firmId}/service-offerings/${offeringId}/choice-group`);
}

export async function createChoiceOption(
  firmId: number,
  offeringId: number,
  body: { label: string; materialItemId?: number },
): Promise<ChoiceOptionDto> {
  const res = await apiClient.post(`/api/firms/${firmId}/service-offerings/${offeringId}/choice-group/options`, body);
  return res.data;
}

export async function updateChoiceOption(
  firmId: number,
  offeringId: number,
  optionId: number,
  body: { label?: string; materialItemId?: number | null; active?: boolean },
): Promise<ChoiceOptionDto> {
  const res = await apiClient.patch(`/api/firms/${firmId}/service-offerings/${offeringId}/choice-group/options/${optionId}`, body);
  return res.data;
}

export async function deleteChoiceOption(firmId: number, offeringId: number, optionId: number): Promise<void> {
  await apiClient.delete(`/api/firms/${firmId}/service-offerings/${offeringId}/choice-group/options/${optionId}`);
}
