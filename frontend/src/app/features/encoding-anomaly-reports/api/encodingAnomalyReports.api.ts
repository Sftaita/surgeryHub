import { apiClient } from "../../../api/apiClient";
import type {
  CreateEncodingAnomalyReportBody,
  EncodingAnomalyReport,
  ResolveEncodingAnomalyReportBody,
} from "./encodingAnomalyReports.types";

export async function fetchEncodingAnomalyReports(missionId: number): Promise<EncodingAnomalyReport[]> {
  const { data } = await apiClient.get<EncodingAnomalyReport[]>(
    `/api/missions/${missionId}/encoding-anomaly-reports`,
  );
  return data;
}

export async function createEncodingAnomalyReport(
  missionId: number,
  body: CreateEncodingAnomalyReportBody,
): Promise<EncodingAnomalyReport> {
  const { data } = await apiClient.post<EncodingAnomalyReport>(
    `/api/missions/${missionId}/encoding-anomaly-reports`,
    body,
  );
  return data;
}

export async function resolveEncodingAnomalyReport(
  reportId: number,
  body: ResolveEncodingAnomalyReportBody,
): Promise<EncodingAnomalyReport> {
  const { data } = await apiClient.post<EncodingAnomalyReport>(
    `/api/encoding-anomaly-reports/${reportId}/resolve`,
    body,
  );
  return data;
}
