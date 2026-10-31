import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { MissionTrackingDrawer } from "./MissionTrackingDrawer";
import { ToastProvider } from "../../../ui/toast/ToastProvider";
import type { EncodingTrackingItem } from "../api/encodingTracking.api";

/**
 * D-166 — parcours manager réel « Suivi des encodages → Corriger l'encodage → + Intervention /
 * matériel » (mission #1079) : la fenêtre d'ajout s'ouvre AU-DESSUS de la fenêtre de
 * correction (seule fenêtre interactive), garde le focus, Échap ne ferme qu'elle, et la
 * correction de la mission reste ouverte et rafraîchie. La section d'encodage n'est PAS
 * bouchonnée ici (contrairement à MissionTrackingDrawer.test.tsx) : ce sont les vrais
 * composants (InterventionsSection, AddInterventionDialog, MaterialWizard, SheetModal).
 */

const MISSION_ID = 1079;

const fetchMissionByIdMock = vi.fn();
vi.mock("../../missions/api/missions.api", () => ({
  fetchMissionById: (...args: unknown[]) => fetchMissionByIdMock(...args),
  getMissionExecution: () => Promise.resolve({ hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null, hoursSource: null, effectiveDurationMinutes: 0, effectiveDurationSource: "PLANNED", disputes: [] }),
  remindMissionHours: vi.fn(),
  updateMissionExecution: vi.fn(),
}));
vi.mock("../../planning-v2/api/planningV2.api", () => ({ fetchMissionAudit: () => Promise.resolve([]) }));
vi.mock("../api/encodingTracking.api", () => ({
  getMissionFinancialAnomalies: () => Promise.resolve(null),
  getMissionEncodingHistory: () => Promise.resolve([]),
}));
vi.mock("../../financial-calculation/api/financialCalculation.api", () => ({ calculateMission: vi.fn(), recalculateFinancialCalculation: vi.fn() }));

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();
const apiPatchMock = vi.fn();
const apiDeleteMock = vi.fn();
vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
    patch: (...args: unknown[]) => apiPatchMock(...args),
    delete: (...args: unknown[]) => apiDeleteMock(...args),
  },
}));

const CATALOG = {
  firms: [{ id: 10, name: "Arthrex", active: true }],
  items: [{ id: 100, label: "FiberWire n°2", referenceCode: "FW-2", unit: "unité", isImplant: false, firm: { id: 10, name: "Arthrex" } }],
  interventionTypes: [{ id: 1, code: "GEN01", label: "Réparation coiffe des rotateurs" }],
};

// Petit « serveur » en mémoire : les mutations le modifient, GET .../encoding le relit.
let serverEntries: any[] = [];
let nextId = 900;
function interventionEntry(id: number, label: string, materialLines: any[] = []) {
  return {
    kind: "INTERVENTION", id, requestId: null, orderIndex: 0, label,
    interventionType: CATALOG.interventionTypes[0], firm: { id: 10, name: "Arthrex" }, requestedFirmNameSnapshot: null,
    status: "CATALOGUED", readOnly: false, materialLines, materialItemRequests: [],
  };
}
function line(id: number, interventionId: number) {
  return { id, missionInterventionId: interventionId, quantity: "2.00", comment: "", version: 1, item: { ...CATALOG.items[0] } };
}

function installServer(initial: any[]) {
  serverEntries = initial;
  nextId = 900;
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${MISSION_ID}/encoding`) {
      const real = serverEntries.filter((e) => e.kind === "INTERVENTION");
      return Promise.resolve({
        data: {
          mission: { id: MISSION_ID, type: "BLOCK", status: "SUBMITTED", allowedActions: [] },
          entries: serverEntries,
          interventions: [],
          interventionTypeRequests: [],
          catalog: CATALOG,
          progress: {
            interventionCount: real.length,
            encodedInterventionCount: real.filter((e) => e.materialLines.length > 0).length,
            materialLineCount: real.reduce((n, e) => n + e.materialLines.length, 0),
          },
          coherenceSummary: {},
          encodingComments: [],
        },
      });
    }
    if (url.includes("/service-offerings")) return Promise.resolve({ data: [] });
    return Promise.resolve({ data: {} });
  });
  apiPostMock.mockImplementation((url: string, body: any) => {
    if (url === `/api/missions/${MISSION_ID}/interventions`) {
      const id = ++nextId;
      serverEntries = [...serverEntries, { ...interventionEntry(id, "Réparation coiffe des rotateurs"), orderIndex: body.orderIndex }];
      return Promise.resolve({ data: { id } });
    }
    if (url === `/api/missions/${MISSION_ID}/material-lines`) {
      const created = line(++nextId, body.missionInterventionId);
      serverEntries = serverEntries.map((e) => (e.id === body.missionInterventionId ? { ...e, materialLines: [...e.materialLines, created] } : e));
      return Promise.resolve({ data: created });
    }
    return Promise.resolve({ data: {} });
  });
  apiPatchMock.mockResolvedValue({ data: {} });
  apiDeleteMock.mockResolvedValue({ data: undefined });
}

function makeItem(): EncodingTrackingItem {
  return {
    missionId: MISSION_ID,
    startAt: "2026-10-20T08:00:00+02:00",
    endAt: "2026-10-20T12:00:00+02:00",
    missionType: "BLOCK",
    missionStatus: "SUBMITTED",
    encodingState: "SUBMITTED",
    encodingStateLabel: "Soumis",
    instrumentist: { id: 5, name: "Salve Decorte" },
    surgeon: { id: 8, name: "Dr Jean Dupont" },
    site: { id: 2, name: "Delta" },
    hours: { plannedMinutes: 240, effectiveMinutes: 240, effectiveSource: "ACTUAL_TIMES", hasRealHours: true, comparison: "WITHIN_PLAN" },
    encoding: { interventionCount: 1, encodedInterventionCount: 0, materialLineCount: 0, submittedWithoutMaterial: false, hasNoMaterialJustification: false, isStale: false },
    financial: { state: "TO_CALCULATE", label: "À calculer", isBlocking: false, anomalyCount: 0, anomalyReasons: [] },
  } as EncodingTrackingItem;
}

function renderDrawer() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <MemoryRouter>
          <MissionTrackingDrawer item={makeItem()} onClose={() => {}} />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  );
}

const CORRECTION = /Corriger l'encodage — mission #1079/;

async function openCorrection(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByTestId("drawer-edit-encoding"));
  return screen.findByRole("dialog", { name: CORRECTION });
}

/** La fenêtre du dessus : accessible (non masquée), après la correction dans le DOM, focus dedans. */
async function expectTopMost(sheet: HTMLElement) {
  // La fenêtre de correction est sortie de l'arbre d'accessibilité (aria-hidden posé par le
  // gestionnaire de fenêtres) : elle reste montée, mais seule la fenêtre du dessus est utilisable.
  expect(screen.queryByRole("dialog", { name: CORRECTION })).toBeNull();
  const correction = screen.getByRole("dialog", { name: CORRECTION, hidden: true });
  expect(correction.compareDocumentPosition(sheet) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  await waitFor(() => expect(sheet.contains(document.activeElement), `focus: ${document.activeElement?.outerHTML.slice(0, 160)}`).toBe(true));
}

beforeEach(() => {
  fetchMissionByIdMock.mockReset();
  fetchMissionByIdMock.mockResolvedValue({ id: MISSION_ID, status: "SUBMITTED", allowedActions: ["view", "validate", "reject", "edit_encoding"] });
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  apiPatchMock.mockReset();
  apiDeleteMock.mockReset();
});

describe("D-166 — fenêtres d'ajout depuis « Corriger l'encodage » (manager)", () => {
  it("« + Intervention » s'ouvre au-dessus, est utilisable (firme puis intervention) et revient sur l'encodage rafraîchi", async () => {
    const user = userEvent.setup();
    installServer([]);
    renderDrawer();
    const correction = await openCorrection(user);
    await within(correction).findByText("Aucune intervention encodée");

    const opener = within(correction).getByRole("button", { name: /nouvelle intervention/i });
    await user.click(opener);
    const sheet = await screen.findByRole("dialog", { name: "Ajouter une intervention" });
    await expectTopMost(sheet);

    await user.click(within(sheet).getByRole("option", { name: "Arthrex" }));
    await within(sheet).findByText("Étape 2/2 – Choisir le type d'intervention");
    await user.click(within(sheet).getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("GEN01 — Réparation coiffe des rotateurs"));
    await user.click(within(sheet).getByRole("button", { name: "Ajouter" }));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(`/api/missions/${MISSION_ID}/interventions`, expect.objectContaining({ interventionTypeId: 1 })));
    // Retour sur l'encodage de la mission, toujours ouvert, rafraîchi depuis le serveur.
    const back = await screen.findByRole("dialog", { name: CORRECTION });
    await waitFor(() => expect(within(back).getByText("1 intervention · 0 matériel")).toBeInTheDocument());
    expect(within(back).getByText("Réparation coiffe des rotateurs")).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Ajouter une intervention", hidden: true })).toBeNull());
    expect(screen.getByTestId("mission-tracking-drawer")).toBeInTheDocument();
  });

  it("Échap ne ferme que la fenêtre d'ajout : la correction reste ouverte, intacte, et reprend le focus", async () => {
    const user = userEvent.setup();
    installServer([interventionEntry(1, "Suture méniscale")]);
    renderDrawer();
    const correction = await openCorrection(user);
    await within(correction).findByText("Suture méniscale");

    const opener = within(correction).getByRole("button", { name: /nouvelle intervention/i });
    await user.click(opener);
    const sheet = await screen.findByRole("dialog", { name: "Ajouter une intervention" });
    await expectTopMost(sheet);
    await user.click(within(sheet).getByRole("option", { name: "Arthrex" }));
    // Le bouton cliqué disparaît avec l'étape (focus sur <body>, que le piège à focus MUI corrige
    // en navigateur toutes les 50 ms — inactif sous jsdom) : un clic dans la fenêtre y remet le focus.
    await user.click(await within(sheet).findByText("Étape 2/2 – Choisir le type d'intervention"));
    await waitFor(() => expect(sheet.contains(document.activeElement)).toBe(true));

    await user.keyboard("{Escape}");

    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Ajouter une intervention", hidden: true })).toBeNull());
    const back = screen.getByRole("dialog", { name: CORRECTION });
    expect(within(back).getByText("Suture méniscale")).toBeInTheDocument();
    expect(document.activeElement).toBe(opener);
    expect(apiPostMock).not.toHaveBeenCalled();

    // Un second Échap ferme alors la correction, jamais le tiroir de la mission.
    await user.keyboard("{Escape}");
    await waitFor(() => expect(screen.queryByRole("dialog", { name: CORRECTION, hidden: true })).toBeNull());
    expect(screen.getByTestId("mission-tracking-drawer")).toBeInTheDocument();
  });

  it("ajout de matériel : le wizard s'ouvre au-dessus, Échap ne ferme que lui", async () => {
    const user = userEvent.setup();
    installServer([interventionEntry(1, "Suture méniscale")]);
    renderDrawer();
    const correction = await openCorrection(user);
    await within(correction).findByText("Suture méniscale");

    await user.click(within(correction).getByRole("button", { name: "Ajouter du matériel" }));
    const wizard = await screen.findByRole("dialog", { name: "Ajouter du matériel" });
    await expectTopMost(wizard);
    await user.click(within(wizard).getAllByRole("button", { name: "Arthrex" })[0]);
    await user.click(await within(wizard).findByText("Étape 2/3 – Rechercher un matériel"));
    await waitFor(() => expect(wizard.contains(document.activeElement)).toBe(true));

    await user.keyboard("{Escape}");
    await waitFor(() => expect(screen.queryByRole("dialog", { name: "Ajouter du matériel", hidden: true })).toBeNull());
    expect(screen.getByRole("dialog", { name: CORRECTION })).toBeInTheDocument();
  });

  it("modifier puis supprimer une ligne de matériel : chaque fenêtre est au-dessus, la correction reste ouverte et rafraîchie", async () => {
    const user = userEvent.setup();
    installServer([interventionEntry(1, "Suture méniscale", [line(501, 1)])]);
    renderDrawer();
    const correction = await openCorrection(user);

    await user.click(await within(correction).findByRole("button", { name: /modifier fiberwire/i }));
    const edit = await screen.findByRole("dialog", { name: "Modifier la ligne matériel" });
    await expectTopMost(edit);
    await user.click(within(edit).getByRole("button", { name: "Supprimer" }));

    const confirm = await screen.findByRole("dialog", { name: "Supprimer le matériel ?" });
    await expectTopMost(confirm);
    serverEntries = [interventionEntry(1, "Suture méniscale")];
    await user.click(within(confirm).getByRole("button", { name: "Supprimer" }));

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledWith(`/api/missions/${MISSION_ID}/material-lines/501`, expect.anything()));
    const back = await screen.findByRole("dialog", { name: CORRECTION });
    await waitFor(() => expect(within(back).queryByRole("button", { name: /modifier fiberwire/i })).toBeNull());
    expect(within(back).getByText("Suture méniscale")).toBeInTheDocument();
  });
});
