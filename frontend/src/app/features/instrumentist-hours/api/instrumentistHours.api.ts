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
