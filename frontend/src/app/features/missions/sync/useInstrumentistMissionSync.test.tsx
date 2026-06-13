import * as React from "react";
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, waitFor, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { Mission, MissionSyncResponse, PaginatedResponse } from "../api/missions.types";

const fetchInstrumentistMissionSync = vi.fn();
const toast = {
  show: vi.fn(),
  success: vi.fn(),
  info: vi.fn(),
  warning: vi.fn(),
  error: vi.fn(),
};

let authState: { status: string; user?: { id: number; role: string } } = {
  status: "authenticated",
  user: { id: 7, role: "INSTRUMENTIST" },
};

vi.mock("../api/missions.api", () => ({
  fetchInstrumentistMissionSync: (...args: unknown[]) => fetchInstrumentistMissionSync(...args),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => toast,
}));

vi.mock("../../../auth/AuthContext", () => ({
  useAuth: () => ({ state: authState }),
}));

import { useInstrumentistMissionSync } from "./useInstrumentistMissionSync";

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

function paginated(items: Mission[]): PaginatedResponse<Mission> {
  return { items, total: items.length, page: 1, limit: 100 };
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

function setVisibility(state: DocumentVisibilityState) {
  Object.defineProperty(document, "visibilityState", {
    configurable: true,
    get: () => state,
  });
}

function setOnline(value: boolean) {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    get: () => value,
  });
}

function Harness() {
  useInstrumentistMissionSync();
  return null;
}

function renderHarness(queryClient: QueryClient) {
  return render(
    <QueryClientProvider client={queryClient}>
      <Harness />
    </QueryClientProvider>,
  );
}

describe("useInstrumentistMissionSync", () => {
  beforeEach(() => {
    fetchInstrumentistMissionSync.mockReset();
    fetchInstrumentistMissionSync.mockResolvedValue(syncResponse({ changed: false }));
    toast.info.mockReset();
    toast.success.mockReset();
    authState = { status: "authenticated", user: { id: 7, role: "INSTRUMENTIST" } };
    setVisibility("visible");
    setOnline(true);
    window.localStorage.clear();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("démarre le polling quand l'app est visible et online", async () => {
    const queryClient = new QueryClient();
    renderHarness(queryClient);

    await waitFor(() => {
      expect(fetchInstrumentistMissionSync).toHaveBeenCalled();
    });
  });

  it("ne déclenche pas de sync si l'onglet est caché", async () => {
    setVisibility("hidden");
    const queryClient = new QueryClient();
    renderHarness(queryClient);

    await act(async () => {
      await Promise.resolve();
    });

    expect(fetchInstrumentistMissionSync).not.toHaveBeenCalled();
  });

  it("ne déclenche pas de sync si l'utilisateur n'est pas INSTRUMENTIST", async () => {
    authState = { status: "authenticated", user: { id: 7, role: "MANAGER" } };
    const queryClient = new QueryClient();
    renderHarness(queryClient);

    await act(async () => {
      await Promise.resolve();
    });

    expect(fetchInstrumentistMissionSync).not.toHaveBeenCalled();
  });

  it("déclenche un refresh immédiat au retour du focus", async () => {
    const queryClient = new QueryClient();
    renderHarness(queryClient);

    await waitFor(() => {
      expect(fetchInstrumentistMissionSync).toHaveBeenCalledTimes(1);
    });

    await act(async () => {
      window.dispatchEvent(new Event("focus"));
      await Promise.resolve();
    });

    await waitFor(() => {
      expect(fetchInstrumentistMissionSync).toHaveBeenCalledTimes(2);
    });
  });

  it("ajoute une nouvelle mission OPEN au cache des Offres et affiche un toast singulier", async () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(["missions", "offers"], paginated([]));

    const newOffer = mission({ id: 100, status: "OPEN", allowedActions: ["claim"] });
    fetchInstrumentistMissionSync.mockResolvedValue(
      syncResponse({ missions: [newOffer] }),
    );

    renderHarness(queryClient);

    await waitFor(() => {
      const offers = queryClient.getQueryData<PaginatedResponse<Mission>>(["missions", "offers"]);
      expect(offers?.items.map((m) => m.id)).toEqual([100]);
    });

    expect(toast.info).toHaveBeenCalledWith("Nouvelle mission disponible");
  });

  it("affiche un toast groupé si plusieurs nouvelles missions sont disponibles", async () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(["missions", "offers"], paginated([]));

    const offerA = mission({ id: 101, status: "OPEN", allowedActions: ["claim"] });
    const offerB = mission({ id: 102, status: "OPEN", allowedActions: ["claim"] });
    fetchInstrumentistMissionSync.mockResolvedValue(
      syncResponse({ missions: [offerA, offerB] }),
    );

    renderHarness(queryClient);

    await waitFor(() => {
      expect(toast.info).toHaveBeenCalledWith("2 nouvelles missions disponibles");
    });
  });

  it("retire une mission des Offres via removedMissionIds", async () => {
    const queryClient = new QueryClient();
    const claimedByOther = mission({ id: 200, status: "OPEN", allowedActions: ["claim"] });
    queryClient.setQueryData(["missions", "offers"], paginated([claimedByOther]));

    fetchInstrumentistMissionSync.mockResolvedValue(
      syncResponse({ removedMissionIds: [200] }),
    );

    renderHarness(queryClient);

    await waitFor(() => {
      const offers = queryClient.getQueryData<PaginatedResponse<Mission>>(["missions", "offers"]);
      expect(offers?.items).toEqual([]);
    });
  });

  it("ne calcule aucun droit côté frontend : allowedActions vient tel quel du serveur", async () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(["missions", "offers"], paginated([]));

    const assigned = mission({ id: 300, status: "ASSIGNED", allowedActions: ["submit"] });
    fetchInstrumentistMissionSync.mockResolvedValue(
      syncResponse({ missions: [assigned] }),
    );

    renderHarness(queryClient);

    await waitFor(() => {
      expect(fetchInstrumentistMissionSync).toHaveBeenCalled();
    });

    // Pas de mutation d'allowedActions : le hook ne fait que répercuter la réponse serveur.
    expect(assigned.allowedActions).toEqual(["submit"]);
  });
});
