import { describe, it, expect, beforeEach } from "vitest";
import { QueryClient } from "@tanstack/react-query";
import { applyMissionSyncToCache } from "./applyMissionSync";
import type { Mission, MissionSyncResponse, PaginatedResponse } from "../api/missions.types";

function paginated(items: Mission[]): PaginatedResponse<Mission> {
  return { items, total: items.length, page: 1, limit: 100 };
}

function mission(overrides: Partial<Mission>): Mission {
  return {
    id: 1,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startAt: "2026-06-12T08:00:00+00:00",
    endAt: "2026-06-12T12:00:00+00:00",
    status: "OPEN",
    allowedActions: [],
    ...overrides,
  };
}

function syncResponse(overrides: Partial<MissionSyncResponse>): MissionSyncResponse {
  return {
    serverTime: "2026-06-12T10:00:00+00:00",
    changed: true,
    missions: [],
    removedMissionIds: [],
    ...overrides,
  };
}

describe("applyMissionSyncToCache", () => {
  let queryClient: QueryClient;

  beforeEach(() => {
    queryClient = new QueryClient();
  });

  it("ajoute une nouvelle mission OPEN claimable aux Offres", () => {
    queryClient.setQueryData(["missions", "offers"], paginated([]));

    const newOffer = mission({ id: 42, status: "OPEN", allowedActions: ["claim"] });
    const result = applyMissionSyncToCache(
      queryClient,
      syncResponse({ missions: [newOffer] }),
      /* currentUserId */ 7,
    );

    expect(result.newOpenOffers).toEqual([newOffer]);

    const offers = queryClient.getQueryData<PaginatedResponse<Mission>>(["missions", "offers"]);
    expect(offers?.items.map((m) => m.id)).toEqual([42]);
  });

  it("retire une mission de toutes les listes en cache via removedMissionIds", () => {
    const claimed = mission({ id: 99, status: "ASSIGNED" });
    queryClient.setQueryData(["missions", "offers"], paginated([claimed]));
    queryClient.setQueryData(
      ["missions", "planning", { view: "week", date: "2026-06-12" }],
      paginated([claimed]),
    );

    const result = applyMissionSyncToCache(
      queryClient,
      syncResponse({ removedMissionIds: [99] }),
      7,
    );

    expect(result.removedCount).toBe(1);

    const offers = queryClient.getQueryData<PaginatedResponse<Mission>>(["missions", "offers"]);
    expect(offers?.items).toEqual([]);

    const planning = queryClient.getQueryData<PaginatedResponse<Mission>>([
      "missions",
      "planning",
      { view: "week", date: "2026-06-12" },
    ]);
    expect(planning?.items).toEqual([]);
  });

  it("met à jour en place une mission déjà présente dans une liste mise en cache", () => {
    const original = mission({ id: 5, status: "OPEN", allowedActions: ["claim"] });
    queryClient.setQueryData(["missions", "offers"], paginated([original]));

    const updated = mission({ id: 5, status: "ASSIGNED", allowedActions: ["submit"] });
    applyMissionSyncToCache(queryClient, syncResponse({ missions: [updated] }), 7);

    const offers = queryClient.getQueryData<PaginatedResponse<Mission>>(["missions", "offers"]);
    expect(offers?.items[0]?.status).toBe("ASSIGNED");
    expect(offers?.items[0]?.allowedActions).toEqual(["submit"]);
  });

  it("ajoute à Mes missions une mission nouvellement assignée à l'utilisateur courant", () => {
    queryClient.setQueryData(["missions", "my-missions"], paginated([]));

    const assignedToMe = mission({
      id: 12,
      status: "ASSIGNED",
      instrumentist: { id: 7, email: "me@example.com" },
      allowedActions: ["submit"],
    });

    applyMissionSyncToCache(queryClient, syncResponse({ missions: [assignedToMe] }), 7);

    const mine = queryClient.getQueryData<PaginatedResponse<Mission>>(["missions", "my-missions"]);
    expect(mine?.items.map((m) => m.id)).toEqual([12]);
  });

  it("n'infère aucun droit : allowedActions est répercuté tel quel depuis le serveur", () => {
    queryClient.setQueryData(["missions", "offers"], paginated([]));

    const noClaimOpen = mission({ id: 8, status: "OPEN", allowedActions: [] });
    const result = applyMissionSyncToCache(
      queryClient,
      syncResponse({ missions: [noClaimOpen] }),
      7,
    );

    // Sans "claim" dans allowedActions, ce n'est pas une "nouvelle offre"
    expect(result.newOpenOffers).toEqual([]);
  });
});
