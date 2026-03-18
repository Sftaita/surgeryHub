import { apiClient } from "../../../api/apiClient";

export interface PricingRule {
  id: number;
  ruleType: "INTERVENTION_FEE" | "IMPLANT_FEE";
  interventionCode: string | null;
  materialItem: {
    id: number;
    label: string;
    referenceCode: string | null;
    firm: { id: number; name: string };
  } | null;
  unitPrice: string;
  active: boolean;
}

export interface FirmBillingContact {
  id: number;
  billingEmail: string | null;
  billingEmailCc: string[];
}

export async function getFirmPricingRules(firmId: number): Promise<PricingRule[]> {
  const res = await apiClient.get(`/api/firms/${firmId}/pricing-rules`);
  return res.data;
}

export async function createPricingRule(
  firmId: number,
  body: {
    ruleType: "INTERVENTION_FEE" | "IMPLANT_FEE";
    unitPrice: number;
    interventionCode?: string;
    materialItemId?: number;
  }
): Promise<PricingRule> {
  const res = await apiClient.post(`/api/firms/${firmId}/pricing-rules`, body);
  return res.data;
}

export async function updatePricingRule(
  firmId: number,
  ruleId: number,
  body: { unitPrice?: number; active?: boolean }
): Promise<PricingRule> {
  const res = await apiClient.patch(`/api/firms/${firmId}/pricing-rules/${ruleId}`, body);
  return res.data;
}

export async function deletePricingRule(firmId: number, ruleId: number): Promise<void> {
  await apiClient.delete(`/api/firms/${firmId}/pricing-rules/${ruleId}`);
}

export async function updateFirmBillingContact(
  firmId: number,
  body: { billingEmail?: string | null; billingEmailCc?: string[] }
): Promise<FirmBillingContact> {
  const res = await apiClient.patch(`/api/firms/${firmId}/billing-contact`, body);
  return res.data;
}
