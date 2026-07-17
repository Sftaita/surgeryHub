import { apiClient } from "../../../api/apiClient";

export type InvoiceStatus = "DRAFT" | "GENERATED" | "SENT" | "PAID";

export interface FirmInvoice {
  id: number;
  number: string | null;
  firm: { id: number; name: string };
  status: InvoiceStatus;
  periodStart: string;
  periodEnd: string;
  totalAmount: string;
  billingEmailTo?: string | null;
  billingEmailCc?: string[];
  generatedAt: string | null;
  sentAt: string | null;
  paidAt: string | null;
  createdAt: string | null;
  lines?: FirmInvoiceLine[];
}

export interface FirmInvoiceLine {
  id: number;
  missionId: number;
  missionDate: string;
  interventionId: number | null;
  materialLineId: number | null;
  lineType: "INTERVENTION_FEE" | "MATERIAL_FEE";
  descriptionSnapshot: string;
  firmNameSnapshot: string;
  unitPrice: string;
  quantity: string;
  totalAmount: string;
}

export interface PreviewLine {
  missionId: number;
  missionDate: string;
  interventionId: number | null;
  materialLineId: number | null;
  lineType: "INTERVENTION_FEE" | "MATERIAL_FEE";
  descriptionSnapshot: string;
  firmNameSnapshot: string;
  unitPrice: number;
  quantity: number;
  totalAmount: number;
}

export interface FirmInvoicePreview {
  firm: { id: number; name: string };
  period: { start: string; end: string };
  lines: PreviewLine[];
  totalAmount: number;
}

export async function getFirmInvoices(params?: {
  firmId?: number;
  status?: InvoiceStatus;
  year?: number;
}): Promise<FirmInvoice[]> {
  const res = await apiClient.get("/api/firm-invoices", { params });
  return res.data;
}

export async function previewFirmInvoice(body: {
  firmId: number;
  periodStart: string;
  periodEnd: string;
}): Promise<FirmInvoicePreview> {
  const res = await apiClient.post("/api/firm-invoices/preview", body);
  return res.data;
}

export async function generateFirmInvoice(body: {
  firmId: number;
  periodStart: string;
  periodEnd: string;
  selectedInterventionIds: number[];
  selectedMaterialLineIds: number[];
}): Promise<FirmInvoice> {
  const res = await apiClient.post("/api/firm-invoices", body);
  return res.data;
}

export async function getFirmInvoice(id: number): Promise<FirmInvoice> {
  const res = await apiClient.get(`/api/firm-invoices/${id}`);
  return res.data;
}

export async function sendFirmInvoice(
  id: number,
  body: { emailTo: string; emailCc?: string[] }
): Promise<FirmInvoice> {
  const res = await apiClient.post(`/api/firm-invoices/${id}/send`, body);
  return res.data;
}

export async function markFirmInvoicePaid(id: number): Promise<FirmInvoice> {
  const res = await apiClient.post(`/api/firm-invoices/${id}/mark-paid`);
  return res.data;
}

export function getFirmInvoicePdfUrl(id: number): string {
  return `${import.meta.env.VITE_API_BASE_URL}/api/firm-invoices/${id}/pdf`;
}
