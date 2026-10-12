import { describe, it, expect, vi } from "vitest";

vi.mock("../../../api/apiClient", () => ({
  apiClient: { get: vi.fn(), post: vi.fn() },
}));

import {
  getInterventionTypeRequests,
  resolveInterventionTypeRequest,
  ignoreInterventionTypeRequest,
} from "./interventionTypeRequests.api";
import { apiClient } from "../../../api/apiClient";

const apiGet = apiClient.get as unknown as ReturnType<typeof vi.fn>;
const apiPost = apiClient.post as unknown as ReturnType<typeof vi.fn>;

describe("getInterventionTypeRequests()", () => {
  it("calls GET /api/intervention-type-requests with the status filter", async () => {
    apiGet.mockResolvedValueOnce({ data: { items: [], total: 0 } });

    await getInterventionTypeRequests({ status: "PENDING" });

    expect(apiGet).toHaveBeenCalledWith("/api/intervention-type-requests", {
      params: { status: "PENDING" },
    });
  });

  it("calls without params when no filter is given", async () => {
    apiGet.mockResolvedValueOnce({ data: { items: [], total: 0 } });

    await getInterventionTypeRequests();

    expect(apiGet).toHaveBeenCalledWith("/api/intervention-type-requests", { params: undefined });
  });

  it("returns the response payload as-is (items + total)", async () => {
    const payload = { items: [{ id: 1 }], total: 1 };
    apiGet.mockResolvedValueOnce({ data: payload });

    const result = await getInterventionTypeRequests();

    expect(result).toEqual(payload);
  });
});

describe("resolveInterventionTypeRequest()", () => {
  // D-146 bis — le corps est transmis tel quel : une seule requête atomique (firme,
  // intervention, décision de prestation, réponses obligatoires).
  it("posts the resolution body as-is to /resolve", async () => {
    apiPost.mockResolvedValueOnce({ data: { requestId: 7, draftId: 2, status: "RESOLVED", draftStatus: "CONVERTED", missionInterventionId: 9 } });

    await resolveInterventionTypeRequest(7, { interventionTypeId: 42, firmId: 3, representativePresent: false });

    expect(apiPost).toHaveBeenCalledWith("/api/intervention-type-requests/7/resolve", {
      interventionTypeId: 42,
      firmId: 3,
      representativePresent: false,
    });
  });

  it("supports creating the firm, the intervention and deciding the missing offering in the same call", async () => {
    apiPost.mockResolvedValueOnce({ data: { requestId: 7, draftId: 2, status: "RESOLVED", draftStatus: "CONVERTED", missionInterventionId: 9 } });

    await resolveInterventionTypeRequest(7, {
      newInterventionType: { code: "PTE-INV", label: "Prothèse épaule inversée" },
      newFirmName: "Medacta",
      offeringDecision: "LEAVE_UNCONFIGURED",
    });

    expect(apiPost).toHaveBeenCalledWith("/api/intervention-type-requests/7/resolve", {
      newInterventionType: { code: "PTE-INV", label: "Prothèse épaule inversée" },
      newFirmName: "Medacta",
      offeringDecision: "LEAVE_UNCONFIGURED",
    });
  });

  it("returns the response payload directly (no .request unwrapping — le backend ne renvoie jamais cette enveloppe)", async () => {
    const payload = { requestId: 7, draftId: 2, status: "RESOLVED", draftStatus: "CONVERTED", missionInterventionId: 9 };
    apiPost.mockResolvedValueOnce({ data: payload });

    const result = await resolveInterventionTypeRequest(7, { interventionTypeId: 42, firmId: 3 });

    expect(result).toEqual(payload);
  });
});

describe("ignoreInterventionTypeRequest()", () => {
  // Correctif workflow Demandes Catalogue (D-113) — reason/comment toujours
  // obligatoires, transmis dans le body au lieu du POST sans body d'avant ce lot.
  it("posts to /ignore with reason and comment", async () => {
    apiPost.mockResolvedValueOnce({ data: { requestId: 7, draftId: 2, status: "IGNORED", draftStatus: "IGNORED", missionInterventionId: null } });

    await ignoreInterventionTypeRequest({ id: 7, reason: "DUPLICATE", comment: "Demande en doublon." });

    expect(apiPost).toHaveBeenCalledWith("/api/intervention-type-requests/7/ignore", {
      reason: "DUPLICATE",
      comment: "Demande en doublon.",
    });
  });

  it("sends the explicit material strategy and the reassignment target when provided (D-146 bis)", async () => {
    apiPost.mockResolvedValueOnce({ data: { requestId: 7, draftId: 2, status: "IGNORED", draftStatus: "MATERIAL_REASSIGNED", missionInterventionId: 5 } });

    await ignoreInterventionTypeRequest({ id: 7, reason: "DUPLICATE", comment: "Déjà encodée.", strategy: "REASSIGN", missionInterventionId: 5 });

    expect(apiPost).toHaveBeenCalledWith("/api/intervention-type-requests/7/ignore", {
      reason: "DUPLICATE",
      comment: "Déjà encodée.",
      strategy: "REASSIGN",
      missionInterventionId: 5,
    });
  });

  it("returns the response payload as-is", async () => {
    const payload = { requestId: 7, draftId: 2, status: "IGNORED", draftStatus: "IGNORED", missionInterventionId: null };
    apiPost.mockResolvedValueOnce({ data: payload });

    const result = await ignoreInterventionTypeRequest({ id: 7, reason: "DUPLICATE", comment: "Demande en doublon." });

    expect(result).toEqual(payload);
  });
});
