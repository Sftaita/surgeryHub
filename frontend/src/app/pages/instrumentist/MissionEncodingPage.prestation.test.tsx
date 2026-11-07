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
      { id: MISSION_A, ordinal: 1, label: `Mission 1/2 · 07h45–13h00 · n° ${MISSION_A}`, roomLabel: null, startAt: "2026-11-04T07:45:00+01:00", endAt: "2026-11-04T13:00:00+01:00", plannedMinutes: 315, status: "IN_PROGRESS", imputedMinutes: null, effectiveMinutes: 315, effectiveSource: "PLANNED", linkedToPresence: false, hasOwnHours: false, interventionCount: 0, activeMaterialLineCount: 0, noMaterialComment: null, isReference: true, carriesContent: false, allowedActions: [] },
      { id: MISSION_B, ordinal: 2, label: `Mission 2/2 · 07h45–18h00 · n° ${MISSION_B}`, roomLabel: null, startAt: "2026-11-04T07:45:00+01:00", endAt: "2026-11-04T18:00:00+01:00", plannedMinutes: 615, status: "IN_PROGRESS", imputedMinutes: null, effectiveMinutes: 615, effectiveSource: "PLANNED", linkedToPresence: false, hasOwnHours: false, interventionCount: 0, activeMaterialLineCount: 0, noMaterialComment: null, isReference: false, carriesContent: false, allowedActions: [] },
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
    referenceMissionId: MISSION_A,
    otherEncodedMissionIds: [],
    encoding: { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0 },
    noMaterialJustificationRequired: true,
    ...overrides,
  };
}

let unifiedEntries: unknown[] = [];
let unifiedProgress = { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0 };

function encoding(id: number, entries: unknown[] = [], progress = { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0 }) {
  return {
    mission: { id, type: "BLOCK", status: "IN_PROGRESS", allowedActions: ["edit_encoding", "submit"] },
    interventions: [],
    entries,
    interventionTypeRequests: [],
    catalog: { firms: [{ id: 10, name: "Arthrex", active: true }], items: [], interventionTypes: [{ id: 1, code: "GEN01", label: "Coiffe des rotateurs" }] },
    coherenceSummary: { hasNoInterventions: true, hasInterventionsWithNoMaterial: false, hasUnusedSuggestions: false, hasMaterialFromOtherFirm: false, hasMissingPrimaryFirm: false },
    encodingComments: [],
    progress,
  };
}

function mockApi(view = prestation(), anchor = MISSION_A) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${anchor}`) return Promise.resolve({ data: mission(anchor) });
    if (url === `/api/missions/${anchor}/prestation`) return Promise.resolve({ data: view });
    if (url === `/api/missions/${anchor}/execution`) {
      return Promise.resolve({ data: { missionId: anchor, hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null, hoursSource: null, effectiveDurationMinutes: 315, effectiveDurationSource: "PLANNED", disputes: [] } });
    }
    // Complément D-156 — prestation : une seule liste, servie pour toute la prestation.
    if (url === `/api/missions/${anchor}/prestation/encoding`) {
      return Promise.resolve({ data: encoding(view.referenceMissionId as number, unifiedEntries, unifiedProgress) });
    }
    const enc = url.match(/^\/api\/missions\/(\d+)\/encoding$/);
    if (enc) return Promise.resolve({ data: encoding(Number(enc[1])) });
    if (url.includes("/encoding-context")) {
      return Promise.resolve({ data: { interventionType: { id: 1, code: "GEN01", label: "Coiffe des rotateurs" }, suggestedPrimaryFirm: null, offerings: [] } });
    }
    if (url.includes("/service-offerings") || url.includes("recent")) return Promise.resolve({ data: [] });
    return Promise.resolve({ data: {} });
  });
}

function renderPage(anchor = MISSION_A) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[`/app/i/missions/${anchor}/encoding`]}>
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
  unifiedEntries = [];
  unifiedProgress = { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0 };
});

const encodingUrls = () => apiGetMock.mock.calls.map(([url]) => url as string).filter((u) => u.endsWith("/encoding"));

describe("MissionEncodingPage — prestation multi-salles (D-156, encodage unique)", () => {
  it("affiche une seule prestation « Multi-salles · 2 missions », une seule saisie d'heures et aucun choix de salle", async () => {
    mockApi();
    renderPage();

    expect(await screen.findByTestId("prestation-panel")).toBeInTheDocument();
    expect(screen.getByTestId("prestation-kicker")).toHaveTextContent("Multi-salles · 2 missions");
    expect(screen.getByText(/Dr Arnaud Deltour — 07h45–18h00/)).toBeInTheDocument();
    expect(screen.getByText(/Temps planifié global : 10h15/)).toBeInTheDocument();
    expect(screen.getByText(/Temps de travail · 1 saisie/)).toBeInTheDocument();
    expect(screen.queryByText(/Choisissez la mission/)).not.toBeInTheDocument();
    expect(screen.queryByRole("radiogroup")).not.toBeInTheDocument();
    expect(screen.queryByText(/Mission 1\/2|Mission 2\/2/)).not.toBeInTheDocument();
    // Une seule liste d'interventions, un seul bouton d'ajout.
    expect(await screen.findAllByRole("button", { name: "Nouvelle intervention" })).toHaveLength(1);
  });

  it("encode toujours sur la mission de référence désignée par le serveur, même ouverte depuis l'autre salle", async () => {
    mockApi(prestation(), MISSION_B);
    renderPage(MISSION_B);

    await screen.findByTestId("prestation-panel");
    await waitFor(() => expect(encodingUrls()).toContain(`/api/missions/${MISSION_B}/prestation/encoding`));
    // Jamais l'encodage d'une seule salle.
    expect(encodingUrls()).not.toContain(`/api/missions/${MISSION_A}/encoding`);
    expect(encodingUrls()).not.toContain(`/api/missions/${MISSION_B}/encoding`);
  });

  it("parcours complet sans sélection de salle : intervention, heures, soumission", async () => {
    mockApi(prestation(), MISSION_B);
    apiPostMock.mockImplementation((url: string) => {
      if (url.endsWith("/interventions")) return Promise.resolve({ data: { id: 501, orderIndex: 0, representativePresent: null, selectedChoiceOptionId: null } });
      return Promise.resolve({ data: prestation({ statuses: ["SUBMITTED"], globalStatus: "SUBMITTED" }) });
    });
    apiPutMock.mockResolvedValue({ data: prestation({ presence: { id: 9, revision: 1, actualStartAt: "2026-11-04T07:45:00+01:00", actualEndAt: "2026-11-04T18:00:00+01:00", totalMinutes: 615, missionIds: [MISSION_A, MISSION_B], allocationRule: "EARLIEST_END_FIRST" } }) });
    const user = userEvent.setup();
    renderPage(MISSION_B);

    await screen.findByTestId("prestation-panel");
    // 1. Intervention : firme puis type, aucune salle demandée ; enregistrée sur la référence.
    await user.click(await screen.findByRole("button", { name: "Nouvelle intervention" }));
    await user.click(await screen.findByText("Arthrex"));
    await user.click(await screen.findByText(/Coiffe des rotateurs/));
    await user.click(await screen.findByRole("button", { name: "Ajouter" }));
    await waitFor(() => expect(apiPostMock.mock.calls.map(([u]) => u)).toContain(`/api/missions/${MISSION_A}/interventions`));
    expect(screen.queryByText(/Choisissez la mission/)).not.toBeInTheDocument();
    expect(screen.queryByRole("radiogroup")).not.toBeInTheDocument();

    // 2. Heures : une seule saisie pour la journée.
    await user.click(screen.getByRole("button", { name: "Renseigner" }));
    await user.click(await screen.findByRole("button", { name: "Enregistrer les heures" }));
    await waitFor(() => expect(apiPutMock).toHaveBeenCalledWith(`/api/missions/${MISSION_B}/prestation/presence`, expect.objectContaining({ expectedMissionIds: [MISSION_A, MISSION_B] })));

    // 3. Une soumission pour toute la prestation.
    await user.click((await screen.findAllByRole("button", { name: "Soumettre la prestation" }))[0]);
    await user.type(await screen.findByLabelText(/Aucun matériel encodé pour la journée/), "Aucun implant.");
    await user.click((await screen.findAllByRole("button", { name: "Soumettre la prestation" })).at(-1)!);
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(`/api/missions/${MISSION_B}/prestation/submit`, { fingerprint: "fp-1", noMaterialComment: "Aucun implant." }));
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

  it("soumet la prestation en une fois, avec l'empreinte et une seule justification « sans matériel » pour la journée", async () => {
    mockApi();
    apiPostMock.mockResolvedValue({ data: prestation({ statuses: ["SUBMITTED"], globalStatus: "SUBMITTED" }) });
    const user = userEvent.setup();
    renderPage();

    await screen.findByTestId("prestation-panel");
    expect(await screen.findByText("Terminer la prestation", { selector: "div" })).toBeInTheDocument();
    expect(screen.getByText(/une seule soumission pour la journée/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Soumettre la prestation" }));

    const dialogSubmit = (await screen.findAllByRole("button", { name: "Soumettre la prestation" })).at(-1)!;
    expect(dialogSubmit).toBeDisabled();
    expect(screen.getByTestId("prestation-submit-summary")).toHaveTextContent("0 intervention(s) · 0 ligne(s) de matériel");
    expect(screen.queryByText(/Mission 1\/2|Mission 2\/2/)).not.toBeInTheDocument();

    await user.type(screen.getByLabelText(/Aucun matériel encodé pour la journée/), "Aucun implant posé.");
    await user.click(dialogSubmit);

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(
      `/api/missions/${MISSION_A}/prestation/submit`,
      { fingerprint: "fp-1", noMaterialComment: "Aucun implant posé." },
    ));
    expect(apiPostMock.mock.calls.map(([u]) => u)).not.toContain(`/api/missions/${MISSION_A}/encoding/complete`);
  });

  it("aucune justification demandée quand la prestation porte du matériel", async () => {
    mockApi(prestation({ noMaterialJustificationRequired: false, encoding: { interventionCount: 1, encodedInterventionCount: 1, materialLineCount: 2 } }));
    apiPostMock.mockResolvedValue({ data: prestation({ statuses: ["SUBMITTED"], globalStatus: "SUBMITTED" }) });
    const user = userEvent.setup();
    renderPage();

    await screen.findByTestId("prestation-panel");
    await user.click(await screen.findByRole("button", { name: "Soumettre la prestation" }));
    const dialogSubmit = (await screen.findAllByRole("button", { name: "Soumettre la prestation" })).at(-1)!;
    expect(screen.queryByLabelText(/Aucun matériel encodé/)).not.toBeInTheDocument();
    expect(dialogSubmit).toBeEnabled();
    await user.click(dialogSubmit);

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(`/api/missions/${MISSION_A}/prestation/submit`, { fingerprint: "fp-1" }));
  });

  it("explique pourquoi les heures communes ne sont plus modifiables, sans parler de salles", async () => {
    const base = prestation();
    mockApi(prestation({
      allowedActions: ["submit"],
      hoursLockedByMissionIds: [MISSION_B],
      statuses: ["ENCODING_IN_PROGRESS", "SUBMITTED"],
      globalStatus: "MIXED",
      missions: [base.missions[0], { ...base.missions[1], status: "SUBMITTED" }],
    }));
    renderPage();

    await screen.findByTestId("prestation-panel");
    expect(screen.queryByRole("button", { name: /Renseigner|Modifier/ })).not.toBeInTheDocument();
    expect(screen.getByTestId("prestation-hours-locked")).toHaveTextContent(/renvoyer la prestation en correction/);
    expect(screen.getByTestId("prestation-hours-locked")).not.toHaveTextContent(/Mission 2\/2/);
  });

  it("une seule liste pour toute la journée, historique compris, compteur = interventions affichées", async () => {
    const itv = (id: number, missionId: number, label: string) => ({
      kind: "INTERVENTION", id, requestId: null, orderIndex: 0, label, interventionType: { id: 1, code: "GEN01", label },
      firm: { id: 10, name: "Arthrex" }, requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false,
      materialLines: [], materialItemRequests: [], version: 1, missionId,
    });
    unifiedEntries = [itv(31, MISSION_B, "Intervention encodée sur l'autre salle"), itv(32, MISSION_A, "Intervention du jour")];
    unifiedProgress = { interventionCount: 2, encodedInterventionCount: 0, materialLineCount: 0 };
    mockApi(prestation({ otherEncodedMissionIds: [MISSION_B], encoding: unifiedProgress }));
    renderPage();

    expect(await screen.findByText("Intervention encodée sur l'autre salle")).toBeInTheDocument();
    expect(screen.getByText("Intervention du jour")).toBeInTheDocument();
    expect(screen.getByText(/2 interventions · 0 matériel/)).toBeInTheDocument();
    expect(screen.queryByText(/Encodage antérieur/)).not.toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: "Nouvelle intervention" })).toHaveLength(1);
    expect(screen.getAllByRole("button", { name: "Soumettre la prestation" })).toHaveLength(1);
    expect(encodingUrls()).toEqual([`/api/missions/${MISSION_A}/prestation/encoding`]);
  });

  it("une mission seule garde l'encodage habituel (aucun panneau de prestation)", async () => {
    mockApi(prestation({ size: 1, missionIds: [MISSION_A], missions: [prestation().missions[0]] }) as any);
    renderPage();

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledWith(`/api/missions/${MISSION_A}/encoding`));
    expect(screen.queryByTestId("prestation-panel")).not.toBeInTheDocument();
  });
});
