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

export interface FirmServiceOffering {
  id: number;
  firmId: number;
  interventionType: { id: number; code: string; label: string };
  label: string | null;
  active: boolean;
  suggestedMaterials: SuggestedMaterialDto[];
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
  body: { label?: string | null; active?: boolean },
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
