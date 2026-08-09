import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MissionDetailContent } from "./MissionDetailPage";

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

const toastSuccess = vi.fn();
vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: vi.fn(), warning: vi.fn() }),
}));

function baseMission(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 42,
    type: "BLOCK",
    status: "OPEN",
    site: { id: 1, name: "Site Delta" },
    surgeon: { id: 2, firstname: "A", lastname: "B" },
    instrumentist: null,
    startAt: "2026-08-10T08:00:00+02:00",
    endAt: "2026-08-10T17:00:00+02:00",
    schedulePrecision: "EXACT",
    allowedActions: ["view", "view_publications", "cancel"],
    ...overrides,
  };
}

function renderDetail(mission: ReturnType<typeof baseMission>) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}`) return Promise.resolve({ data: mission });
    if (url.endsWith("/execution")) return Promise.resolve({ data: { missionId: mission.id, hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null, hoursSource: null, effectiveDurationMinutes: 0, effectiveDurationSource: "PLANNED", disputes: [] } });
    if (url.endsWith("/encoding")) return Promise.resolve({ data: { mission: {}, interventions: [], entries: [], interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] } });
    if (url.endsWith("/encoding-anomaly-reports")) return Promise.resolve({ data: [] });
    return Promise.resolve({ data: {} });
  });
  apiPostMock.mockResolvedValue({ data: { ...mission, status: "CANCELLED" } });

  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <MissionDetailContent missionId={mission.id} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  toastSuccess.mockReset();
});

describe("MissionDetailPage — annulation de mission (Point 5)", () => {
  it("affiche 'Annuler la mission' quand allowedActions le permet (OPEN)", async () => {
    renderDetail(baseMission({ status: "OPEN" }));
    expect(await screen.findByRole("button", { name: "Annuler la mission" })).toBeInTheDocument();
  });

  it("masque l'action quand allowedActions ne contient pas 'cancel' (SUBMITTED)", async () => {
    renderDetail(baseMission({ status: "SUBMITTED", allowedActions: ["view", "validate", "reject"] }));
    await screen.findByText("Mission #42");
    expect(screen.queryByRole("button", { name: "Annuler la mission" })).not.toBeInTheDocument();
  });

  it("affiche un message d'effet différent pour un brouillon (DRAFT)", async () => {
    const user = userEvent.setup();
    renderDetail(baseMission({ status: "DRAFT", allowedActions: ["view", "edit", "publish", "cancel"] }));

    await user.click(await screen.findByRole("button", { name: "Annuler la mission" }));
    expect(screen.getByText(/n'a jamais été publié/i)).toBeInTheDocument();
  });

  it("affiche un message d'effet différent pour une mission assignée (ASSIGNED)", async () => {
    const user = userEvent.setup();
    renderDetail(baseMission({
      status: "ASSIGNED",
      instrumentist: { id: 9, firstname: "Sophie", lastname: "Martin" },
      allowedActions: ["view", "cancel", "reassign", "view_claim"],
    }));

    await user.click(await screen.findByRole("button", { name: "Annuler la mission" }));
    expect(screen.getByText(/instrumentiste est assigné/i)).toBeInTheDocument();
  });

  it("confirmer l'annulation appelle POST /cancel avec le motif saisi et affiche un succès", async () => {
    const user = userEvent.setup();
    const mission = baseMission({ status: "OPEN" });
    renderDetail(mission);

    await user.click(await screen.findByRole("button", { name: "Annuler la mission" }));
    await user.type(screen.getByLabelText(/motif/i), "Créneau finalement inutile");

    const dialog = screen.getByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Annuler la mission" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        `/api/missions/${mission.id}/cancel`,
        { reason: "Créneau finalement inutile" },
      );
    });
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Mission annulée."));
  });

  it("le bouton 'Retour' du dialogue n'appelle jamais l'API", async () => {
    const user = userEvent.setup();
    renderDetail(baseMission({ status: "OPEN" }));

    await user.click(await screen.findByRole("button", { name: "Annuler la mission" }));
    await user.click(screen.getByRole("button", { name: "Retour" }));

    expect(apiPostMock).not.toHaveBeenCalled();
  });
});

/**
 * Lot 6 (D-100) — AnomalyReportsManagerPanel intégré au détail Mission manager
 * existant (§15 : jamais une nouvelle page de listing top-level). Le comportement
 * détaillé du panneau (résolution, 409, etc.) est couvert par
 * AnomalyReportsManagerPanel.test.tsx ; ici on vérifie uniquement le branchement.
 */
describe("MissionDetailPage (manager) — anomalies d'encodage signalées (Lot 6, D-100)", () => {
  it("n'affiche aucune section quand aucune anomalie n'est signalée", async () => {
    renderDetail(baseMission({ status: "ASSIGNED" }));
    await screen.findByText("Mission #42");
    expect(screen.queryByText("Anomalies signalées par le chirurgien")).not.toBeInTheDocument();
  });

  it("affiche le signalement quand il en existe un pour cette mission", async () => {
    const mission = baseMission({ status: "ASSIGNED" });
    apiGetMock.mockImplementation((url: string) => {
      if (url === `/api/missions/${mission.id}`) return Promise.resolve({ data: mission });
      if (url.endsWith("/execution")) return Promise.resolve({ data: { missionId: mission.id, hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null, hoursSource: null, effectiveDurationMinutes: 0, effectiveDurationSource: "PLANNED", disputes: [] } });
      if (url.endsWith("/encoding")) return Promise.resolve({ data: { mission: {}, interventions: [], entries: [], interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] } });
      if (url.endsWith("/encoding-anomaly-reports")) {
        return Promise.resolve({
          data: [{
            id: 1, missionId: mission.id,
            reporter: { id: 2, displayName: "A B" },
            type: "MATERIAL_INCORRECT", comment: "Matériel manquant.", status: "OPEN",
            createdAt: "2026-08-01T10:00:00Z", resolvedBy: null, resolvedAt: null, resolutionComment: null,
          }],
        });
      }
      return Promise.resolve({ data: {} });
    });

    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <MissionDetailContent missionId={mission.id} />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText("Anomalies signalées par le chirurgien")).toBeInTheDocument();
    expect(screen.getByText("Matériel incorrect")).toBeInTheDocument();
    expect(screen.getByText("Marquer comme traité")).toBeInTheDocument();
  });
});
