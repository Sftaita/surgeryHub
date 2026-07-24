import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { MissionDetailContent } from "./MissionDetailPage";

const apiGetMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: vi.fn().mockResolvedValue({ data: {} }),
    patch: vi.fn().mockResolvedValue({ data: {} }),
  },
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

const MISSION_ID = 690;

function baseMission(overrides: Record<string, any> = {}) {
  return {
    id: MISSION_ID,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startAt: "2026-07-20T08:00:00Z",
    endAt: "2026-07-20T11:00:00Z",
    status: "ASSIGNED",
    site: { id: 1, name: "CHU Test" },
    surgeon: { id: 2, firstname: "Jean", lastname: "Dupont", email: "jd@test.be" },
    allowedActions: ["encoding", "edit_hours"],
    ...overrides,
  };
}

function executionInfo(overrides: Record<string, any> = {}) {
  return {
    missionId: MISSION_ID,
    hasExecutionRecord: false,
    actualStartAt: null,
    actualEndAt: null,
    actualDurationMinutes: null,
    hoursSource: null,
    effectiveDurationMinutes: 180,
    effectiveDurationSource: "PLANNED",
    disputes: [],
    ...overrides,
  };
}

function mockRoutes(mission: any, execution: any) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}/execution`) return Promise.resolve({ data: execution });
    if (url === `/api/missions/${mission.id}`) return Promise.resolve({ data: mission });
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <MissionDetailContent missionId={MISSION_ID} />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
});

/**
 * Anomalie écran d'encodage (commit dédié) — cette page lisait aussi
 * mission.service?.hours (toujours undefined depuis D-071). Corrigée pour lire
 * ["mission-execution", missionId], la même source que MissionEncodingPage.tsx et
 * SubmitDialog.tsx (formatage centralisé, formatExecutionHours).
 */
describe("MissionDetailPage (instrumentiste) — heures prestées (source de vérité mission-execution)", () => {
  it("affiche 'Non renseigné' quand aucune saisie n'existe réellement", async () => {
    mockRoutes(baseMission(), executionInfo({ hasExecutionRecord: false }));
    renderPage();

    expect(await screen.findByText("Non renseigné")).toBeInTheDocument();
  });

  it("affiche les heures réellement enregistrées, au même format que l'écran d'encodage", async () => {
    mockRoutes(baseMission(), executionInfo({ hasExecutionRecord: true, actualDurationMinutes: 270 }));
    renderPage();

    expect(await screen.findByText("4.5 h")).toBeInTheDocument();
    expect(screen.queryByText("Non renseigné")).not.toBeInTheDocument();
  });
});
