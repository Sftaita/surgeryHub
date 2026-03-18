import { apiClient } from "../../../api/apiClient";
import type { InvoiceStatus } from "../../billing-firm/api/firmInvoice.api";

export interface InstrumentistStatement {
  id: number;
  instrumentist: { id: number; displayName: string | null; email: string | null };
  periodYear: number;
  periodMonth: number;
  status: InvoiceStatus;
  totalAmount: string;
  sentAt: string | null;
  paidAt: string | null;
  createdAt: string | null;
  lines?: StatementLine[];
}

export interface StatementLine {
  id: number;
  missionId: number;
  missionDate: string | null;
  lineType: "BLOC" | "CONSULTATION";
  durationMinutesRaw: number | null;
  durationMinutesRounded: number | null;
  rateSnapshot: string;
  quantity: string;
  totalAmount: string;
  surgeonName: string | null;
  siteName: string | null;
}

export interface StatementPreviewLine {
  missionId: number;
  missionDate: string;
  lineType: "BLOC" | "CONSULTATION";
  durationMinutesRaw: number | null;
  durationMinutesRounded: number | null;
  rateSnapshot: number;
  quantity: number;
  totalAmount: number;
  surgeonName: string | null;
  siteName: string | null;
}

export interface StatementPreview {
  instrumentist: {
    id: number;
    displayName: string;
    email: string;
    hourlyRate: string | null;
    consultationFee: string | null;
  };
  period: { year: number; month: number };
  lines: StatementPreviewLine[];
  totalAmount: number;
  alreadyBilledMissionIds: number[];
}

export async function getStatements(params?: {
  instrumentistId?: number;
  status?: InvoiceStatus;
  year?: number;
}): Promise<InstrumentistStatement[]> {
  const res = await apiClient.get("/api/instrumentist-statements", { params });
  return res.data;
}

export async function previewStatement(body: {
  instrumentistId: number;
  year: number;
  month: number;
}): Promise<StatementPreview> {
  const res = await apiClient.post("/api/instrumentist-statements/preview", body);
  return res.data;
}

export async function generateStatement(body: {
  instrumentistId: number;
  year: number;
  month: number;
  selectedMissionIds: number[];
}): Promise<InstrumentistStatement> {
  const res = await apiClient.post("/api/instrumentist-statements", body);
  return res.data;
}

export async function getStatement(id: number): Promise<InstrumentistStatement> {
  const res = await apiClient.get(`/api/instrumentist-statements/${id}`);
  return res.data;
}

export async function sendStatement(
  id: number,
  body: { emailTo: string }
): Promise<InstrumentistStatement> {
  const res = await apiClient.post(`/api/instrumentist-statements/${id}/send`, body);
  return res.data;
}

export async function markStatementPaid(id: number): Promise<InstrumentistStatement> {
  const res = await apiClient.post(`/api/instrumentist-statements/${id}/mark-paid`);
  return res.data;
}

export function getStatementPdfUrl(id: number): string {
  return `${import.meta.env.VITE_API_BASE_URL}/api/instrumentist-statements/${id}/pdf`;
}
