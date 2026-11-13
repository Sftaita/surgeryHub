import { apiClient } from "../../../api/apiClient";
import type { InstrumentistHoursDetail, InstrumentistHoursList, InstrumentistHoursQuery } from "./instrumentistHours.types";

/** D-180 — liste, totaux et classement du mois (filtres appliqués par le serveur). */
export async function fetchInstrumentistHours(query: InstrumentistHoursQuery): Promise<InstrumentistHoursList> {
  const params: Record<string, string | number> = { month: query.month };
  if (query.siteId != null) params.siteId = query.siteId;
  if (query.q) params.q = query.q;
  if (query.sort) params.sort = query.sort;
  if (query.direction) params.direction = query.direction;
  if (query.limit != null) params.limit = query.limit;
  const { data } = await apiClient.get<InstrumentistHoursList>("/api/manager/instrumentist-hours", { params });
  return data;
}

/** D-180 — détail individuel : relevé « Mes heures » de l'instrumentiste + comparaisons. */
export async function fetchInstrumentistHoursDetail(instrumentistId: number, month: string, siteId: number | null): Promise<InstrumentistHoursDetail> {
  const { data } = await apiClient.get<InstrumentistHoursDetail>(`/api/manager/instrumentists/${instrumentistId}/hours`, {
    params: siteId === null ? { month } : { month, siteId },
  });
  return data;
}

export interface PdfFile {
  blob: Blob;
  filename: string;
}

/** D-189 — synthèse PDF de la page : mêmes filtres et même tri que la liste, toutes les lignes. */
export async function exportInstrumentistHoursPdf(query: Omit<InstrumentistHoursQuery, "limit">): Promise<PdfFile> {
  const params: Record<string, string | number> = { month: query.month };
  if (query.siteId != null) params.siteId = query.siteId;
  if (query.q) params.q = query.q;
  if (query.sort) params.sort = query.sort;
  if (query.direction) params.direction = query.direction;
  return downloadPdf("/api/manager/instrumentist-hours/export", params, `heures-instrumentistes-${query.month}.pdf`);
}

/** D-189 — décompte de prestations et d'honoraires (prestations validées, une ligne par prestation). */
export async function exportInstrumentistStatementPdf(instrumentistId: number, month: string, siteId: number | null): Promise<PdfFile> {
  return downloadPdf(`/api/manager/instrumentists/${instrumentistId}/hours/export`, siteId === null ? { month } : { month, siteId }, `decompte-${month}.pdf`);
}

async function downloadPdf(url: string, params: Record<string, string | number>, fallbackName: string): Promise<PdfFile> {
  const res = await apiClient.get(url, { params, responseType: "blob", timeout: 60_000 });
  const match = /filename="([^"]+)"/.exec(String(res.headers?.["content-disposition"] ?? ""));
  return { blob: res.data as Blob, filename: match?.[1] ?? fallbackName };
}
