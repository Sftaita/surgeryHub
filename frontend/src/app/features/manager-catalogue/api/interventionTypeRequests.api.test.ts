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
  it("posts to /resolve with interventionTypeId and firmId (not primaryFirmId) — le backend lit exclusivement `firmId`", async () => {
    apiPost.mockResolvedValueOnce({ data: { requestId: 7, draftId: 2, status: "RESOLVED", draftStatus: "RESOLVED", missionInterventionId: 9 } });

    await resolveInterventionTypeRequest(7, 42, 3);

    expect(apiPost).toHaveBeenCalledWith("/api/intervention-type-requests/7/resolve", {
      interventionTypeId: 42,
      firmId: 3,
    });
  });

  it("sends firmId=undefined when no primary firm is selected", async () => {
    apiPost.mockResolvedValueOnce({ data: { requestId: 7, draftId: 2, status: "RESOLVED", draftStatus: "RESOLVED", missionInterventionId: 9 } });

    await resolveInterventionTypeRequest(7, 42);

    expect(apiPost).toHaveBeenCalledWith("/api/intervention-type-requests/7/resolve", {
      interventionTypeId: 42,
      firmId: undefined,
    });
  });

  it("returns the response payload directly (no .request unwrapping — le backend ne renvoie jamais cette enveloppe)", async () => {
    const payload = { requestId: 7, draftId: 2, status: "RESOLVED", draftStatus: "RESOLVED", missionInterventionId: 9 };
    apiPost.mockResolvedValueOnce({ data: payload });

    const result = await resolveInterventionTypeRequest(7, 42);

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

  it("returns the response payload as-is", async () => {
    const payload = { requestId: 7, draftId: 2, status: "IGNORED", draftStatus: "IGNORED", missionInterventionId: null };
    apiPost.mockResolvedValueOnce({ data: payload });

    const result = await ignoreInterventionTypeRequest({ id: 7, reason: "DUPLICATE", comment: "Demande en doublon." });

    expect(result).toEqual(payload);
  });
});
