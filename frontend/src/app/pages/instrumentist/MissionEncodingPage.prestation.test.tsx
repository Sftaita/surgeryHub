import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import MissionEncodingPage from "./MissionEncodingPage";

// D-156 — encodage commun d'une prestation multi-salles.

vi.mock("../../layouts/scrollRestoration", () => ({
  getEncodingBackTarget: vi.fn(() => "/app/i/today"),
}));

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();
const apiPutMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
    put: (...args: unknown[]) => apiPutMock(...args),
    patch: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn().mockResolvedValue({ data: undefined }),
  },
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

const MISSION_A = 674;
const MISSION_B = 675;

function mission(id: number) {
  return {
    id,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startAt: "2026-11-04T07:45:00+01:00",
    endAt: id === MISSION_A ? "2026-11-04T13:00:00+01:00" : "2026-11-04T18:00:00+01:00",
    status: "IN_PROGRESS",
    site: { id: 1, name: "CHIREC Delta" },
    surgeon: { id: 2, firstname: "Arnaud", lastname: "Deltour", email: "a@x.test", specialties: [] },
    instrumentist: { id: 3, firstname: "Sophie", lastname: "Colette", email: "s@x.test" },
    allowedActions: ["edit_encoding", "submit", "edit_hours"],
  };
}

function prestation(overrides: Record<string, unknown> = {}) {
  return {
    key: `p${MISSION_A}-${MISSION_B}`,
    size: 2,
    missionIds: [MISSION_A, MISSION_B],
    missions: [
      { id: MISSION_A, ordinal: 1, label: `Mission 1/2 · 07h45–13h00 · n° ${MISSION_A}`, roomLabel: null, startAt: "2026-11-04T07:45:00+01:00", endAt: "2026-11-04T13:00:00+01:00", plannedMinutes: 315, status: "IN_PROGRESS", imputedMinutes: null, effectiveMinutes: 315, effectiveSource: "PLANNED", linkedToPresence: false, hasOwnHours: false, interventionCount: 0, activeMaterialLineCount: 0, noMaterialComment: null, allowedActions: [] },
      { id: MISSION_B, ordinal: 2, label: `Mission 2/2 · 07h45–18h00 · n° ${MISSION_B}`, roomLabel: null, startAt: "2026-11-04T07:45:00+01:00", endAt: "2026-11-04T18:00:00+01:00", plannedMinutes: 615, status: "IN_PROGRESS", imputedMinutes: null, effectiveMinutes: 615, effectiveSource: "PLANNED", linkedToPresence: false, hasOwnHours: false, interventionCount: 0, activeMaterialLineCount: 0, noMaterialComment: null, allowedActions: [] },
    ],
    date: "2026-11-04",
    instrumentist: { id: 3, name: "Sophie Colette" },
    surgeon: { id: 2, name: "Dr Arnaud Deltour" },
    site: { id: 1, name: "CHIREC Delta" },
    planned: { startAt: "2026-11-04T07:45:00+01:00", endAt: "2026-11-04T18:00:00+01:00", unionMinutes: 615 },
    presence: null,
    unlinkedMissionIds: [],
    ownHoursMissionIds: [],
    statuses: ["IN_PROGRESS"],
    globalStatus: "IN_PROGRESS",
    fingerprint: "fp-1",
    allowedActions: ["edit_presence", "submit"],
    ...overrides,
  };
}

function encoding(id: number) {
  return {
    mission: { id, type: "BLOCK", status: "IN_PROGRESS", allowedActions: ["edit_encoding", "submit"] },
    interventions: [],
    entries: [],
    interventionTypeRequests: [],
    catalog: { firms: [], items: [] },
    coherenceSummary: { hasNoInterventions: true, hasInterventionsWithNoMaterial: false, hasUnusedSuggestions: false, hasMaterialFromOtherFirm: false, hasMissingPrimaryFirm: false },
    encodingComments: [],
    progress: { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0 },
  };
}

function mockApi(view = prestation()) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${MISSION_A}`) return Promise.resolve({ data: mission(MISSION_A) });
    if (url === `/api/missions/${MISSION_A}/prestation`) return Promise.resolve({ data: view });
    if (url === `/api/missions/${MISSION_A}/execution`) {
      return Promise.resolve({ data: { missionId: MISSION_A, hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null, hoursSource: null, effectiveDurationMinutes: 315, effectiveDurationSource: "PLANNED", disputes: [] } });
    }
    const enc = url.match(/^\/api\/missions\/(\d+)\/encoding$/);
    if (enc) return Promise.resolve({ data: encoding(Number(enc[1])) });
    return Promise.resolve({ data: {} });
  });
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[`/app/i/missions/${MISSION_A}/encoding`]}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/app/i/missions/:id/encoding" element={<MissionEncodingPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  apiPutMock.mockReset();
});

describe("MissionEncodingPage — prestation multi-salles (D-156)", () => {
  it("affiche une seule prestation avec ses deux missions distinctes et une seule saisie d'heures", async () => {
    mockApi();
    renderPage();

    expect(await screen.findByTestId("prestation-panel")).toBeInTheDocument();
    expect(screen.getByText(/Dr Arnaud Deltour — 2 missions — 07h45–18h00/)).toBeInTheDocument();
    expect(screen.getByText(/Temps planifié global : 10h15/)).toBeInTheDocument();
    expect(screen.getByTestId(`prestation-mission-${MISSION_A}`)).toHaveTextContent("Mission 1/2 · 07h45–13h00");
    expect(screen.getByTestId(`prestation-mission-${MISSION_B}`)).toHaveTextContent("Mission 2/2 · 07h45–18h00");
    expect(screen.getByText(/1 saisie pour la prestation/)).toBeInTheDocument();
  });

  it("n'attribue jamais les interventions à une mission par défaut : le choix est explicite", async () => {
    mockApi();
    const user = userEvent.setup();
    renderPage();

    await screen.findByTestId("prestation-panel");
    expect(screen.getByText(/Choisissez la mission \(salle\)/)).toBeInTheDocument();
    expect(apiGetMock.mock.calls.map(([url]) => url)).not.toContain(`/api/missions/${MISSION_A}/encoding`);
    expect(apiGetMock.mock.calls.map(([url]) => url)).not.toContain(`/api/missions/${MISSION_B}/encoding`);

    await user.click(screen.getByTestId(`prestation-mission-${MISSION_B}`));

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledWith(`/api/missions/${MISSION_B}/encoding`));
    expect(screen.getByTestId(`prestation-mission-${MISSION_B}`)).toHaveAttribute("aria-checked", "true");
    expect(apiGetMock.mock.calls.map(([url]) => url)).not.toContain(`/api/missions/${MISSION_A}/encoding`);
  });

  it("enregistre la présence une seule fois pour toute la prestation (composition attendue)", async () => {
    mockApi();
    apiPutMock.mockResolvedValue({ data: prestation({ presence: { id: 9, revision: 1, actualStartAt: "2026-11-04T07:45:00+01:00", actualEndAt: "2026-11-04T18:00:00+01:00", totalMinutes: 615, missionIds: [MISSION_A, MISSION_B], allocationRule: "EARLIEST_END_FIRST" } }) });
    const user = userEvent.setup();
    renderPage();

    await screen.findByTestId("prestation-panel");
    await user.click(screen.getByRole("button", { name: "Renseigner" }));
    await user.click(await screen.findByRole("button", { name: "Enregistrer les heures" }));

    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1));
    const [url, body] = apiPutMock.mock.calls[0];
    expect(url).toBe(`/api/missions/${MISSION_A}/prestation/presence`);
    expect(body.expectedMissionIds).toEqual([MISSION_A, MISSION_B]);
    expect(body.actualStartAt).toMatch(/^2026-11-04T07:45/);
    expect(body.actualEndAt).toMatch(/^2026-11-04T18:00/);
    // Never the per-mission hours endpoint.
    expect(apiPostMock).not.toHaveBeenCalled();
  });

  it("soumet la prestation en une fois, avec l'empreinte et une seule justification sans matériel", async () => {
    mockApi();
    apiPostMock.mockResolvedValue({ data: prestation({ statuses: ["SUBMITTED"], globalStatus: "SUBMITTED" }) });
    const user = userEvent.setup();
    renderPage();

    await screen.findByTestId("prestation-panel");
    await user.click(screen.getByTestId("prestation-submit-open"));
    const submit = await screen.findByRole("button", { name: "Soumettre la prestation" });
    expect(submit).toBeDisabled();

    await user.type(screen.getByLabelText(/Aucun matériel encodé/), "Aucun implant posé.");
    await user.click(submit);

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(
      `/api/missions/${MISSION_A}/prestation/submit`,
      { fingerprint: "fp-1", noMaterialComment: "Aucun implant posé." },
    ));
    expect(apiPostMock.mock.calls.map(([u]) => u)).not.toContain(`/api/missions/${MISSION_A}/encoding/complete`);
  });

  it("une mission seule garde l'encodage habituel (aucun panneau de prestation)", async () => {
    mockApi(prestation({ size: 1, missionIds: [MISSION_A], missions: [prestation().missions[0]] }) as any);
    renderPage();

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledWith(`/api/missions/${MISSION_A}/encoding`));
    expect(screen.queryByTestId("prestation-panel")).not.toBeInTheDocument();
  });
});
