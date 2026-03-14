import { apiClient } from "../../../api/apiClient";
import {
  InstrumentistsListResponseDTO,
  InstrumentistDetailDTO,
  CreateInstrumentistResponseDTO,
  InstrumentistRatesDTO,
  InstrumentistActiveStateDTO,
  SiteMembershipDTO,
  DeleteMembershipResponseDTO,
  InstrumentistPlanningEventDTO,
} from "./instrumentists.types";

import {
  InstrumentistsListQuery,
  CreateInstrumentistRequest,
  UpdateInstrumentistRatesRequest,
  AddSiteMembershipRequest,
  InstrumentistPlanningQuery,
} from "./instrumentists.requests";

/**
 * GET /api/instrumentists
 */
export const getInstrumentists = async (
  query?: InstrumentistsListQuery,
): Promise<InstrumentistsListResponseDTO> => {
  const res = await apiClient.get("/api/instrumentists", { params: query });
  return res.data;
};

/**
 * GET /api/instrumentists/{id}
 */
export const getInstrumentist = async (
  id: number,
): Promise<InstrumentistDetailDTO> => {
  const res = await apiClient.get(`/api/instrumentists/${id}`);
  return res.data;
};

/**
 * POST /api/instrumentists
 */
export const createInstrumentist = async (
  body: CreateInstrumentistRequest,
): Promise<CreateInstrumentistResponseDTO> => {
  const res = await apiClient.post("/api/instrumentists", body);
  return res.data;
};

/**
 * PATCH /api/instrumentists/{id}/rates
 */
export const updateInstrumentistRates = async (
  id: number,
  body: UpdateInstrumentistRatesRequest,
): Promise<InstrumentistRatesDTO> => {
  const res = await apiClient.patch(`/api/instrumentists/${id}/rates`, body);
  return res.data;
};

/**
 * POST /api/instrumentists/{id}/suspend
 */
export const suspendInstrumentist = async (
  id: number,
): Promise<InstrumentistActiveStateDTO> => {
  const res = await apiClient.post(`/api/instrumentists/${id}/suspend`);
  return res.data;
};

/**
 * POST /api/instrumentists/{id}/activate
 */
export const activateInstrumentist = async (
  id: number,
): Promise<InstrumentistActiveStateDTO> => {
  const res = await apiClient.post(`/api/instrumentists/${id}/activate`);
  return res.data;
};

/**
 * POST /api/instrumentists/{id}/site-memberships
 */
export const addSiteMembership = async (
  id: number,
  body: AddSiteMembershipRequest,
): Promise<SiteMembershipDTO> => {
  const res = await apiClient.post(
    `/api/instrumentists/${id}/site-memberships`,
    body,
  );
  return res.data;
};

/**
 * DELETE /api/instrumentists/{id}/site-memberships/{membershipId}
 */
export const deleteSiteMembership = async (
  id: number,
  membershipId: number,
): Promise<DeleteMembershipResponseDTO> => {
  const res = await apiClient.delete(
    `/api/instrumentists/${id}/site-memberships/${membershipId}`,
  );
  return res.data;
};

/**
 * GET /api/instrumentists/{id}/planning
 */
export const getInstrumentistPlanning = async (
  id: number,
  query: InstrumentistPlanningQuery,
): Promise<InstrumentistPlanningEventDTO[]> => {
  const res = await apiClient.get(`/api/instrumentists/${id}/planning`, {
    params: query,
  });
  return res.data;
};
