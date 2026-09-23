import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { MissionTrackingDrawer } from "./MissionTrackingDrawer";
import { ToastProvider } from "../../../ui/toast/ToastProvider";
import type { EncodingTrackingItem } from "../api/encodingTracking.api";

const fetchMissionByIdMock = vi.fn();
const getMissionExecutionMock = vi.fn();
vi.mock("../../missions/api/missions.api", () => ({
  fetchMissionById: (...args: unknown[]) => fetchMissionByIdMock(...args),
  getMissionExecution: (...args: unknown[]) => getMissionExecutionMock(...args),
}));

const fetchMissionEncodingMock = vi.fn();
const validateMissionEncodingMock = vi.fn();
const remindMissionEncodingMock = vi.fn();
vi.mock("../../encoding/api/encoding.api", () => ({
  fetchMissionEncoding: (...args: unknown[]) => fetchMissionEncodingMock(...args),
  validateMissionEncoding: (...args: unknown[]) => validateMissionEncodingMock(...args),
  remindMissionEncoding: (...args: unknown[]) => remindMissionEncodingMock(...args),
}));

const fetchMissionAuditMock = vi.fn();
vi.mock("../../planning-v2/api/planningV2.api", () => ({
  fetchMissionAudit: (...args: unknown[]) => fetchMissionAuditMock(...args),
}));

function makeItem(overrides: Partial<EncodingTrackingItem> = {}): EncodingTrackingItem {
  return {
    missionId: 42,
    startAt: "2026-09-10T08:00:00+02:00",
    endAt: "2026-09-10T12:00:00+02:00",
    missionType: "BLOCK",
    missionStatus: "SUBMITTED",
    encodingState: "SUBMITTED",
    encodingStateLabel: "Soumis",
    instrumentist: { id: 5, name: "Salve Decorte" },
    surgeon: { id: 8, name: "Dr Jean Dupont" },
    site: { id: 2, name: "Delta" },
    hours: { plannedMinutes: 240, effectiveMinutes: 312, effectiveSource: "ACTUAL_TIMES", hasRealHours: true },
    encoding: { interventionCount: 2, encodedInterventionCount: 1, materialLineCount: 6, submittedWithoutMaterial: false, hasNoMaterialJustification: false, isStale: false },
    financial: { state: "TO_CALCULATE", label: "À calculer", isBlocking: false },
    ...overrides,
  };
}

function renderDrawer(item: EncodingTrackingItem | null, onClose = () => {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <MemoryRouter>
          <MissionTrackingDrawer item={item} onClose={onClose} />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  fetchMissionByIdMock.mockReset();
  getMissionExecutionMock.mockReset();
  fetchMissionEncodingMock.mockReset();
  fetchMissionAuditMock.mockReset();
  validateMissionEncodingMock.mockReset();
  remindMissionEncodingMock.mockReset();

  getMissionExecutionMock.mockResolvedValue({ hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null, hoursSource: null, effectiveDurationMinutes: 0, effectiveDurationSource: "PLANNED", disputes: [] });
  fetchMissionEncodingMock.mockResolvedValue({ mission: { id: 42, type: "BLOCK", status: "SUBMITTED", allowedActions: [] }, interventions: [], entries: [], interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] });
  fetchMissionAuditMock.mockResolvedValue([]);
  validateMissionEncodingMock.mockResolvedValue(undefined);
  remindMissionEncodingMock.mockResolvedValue(undefined);
});

describe("MissionTrackingDrawer", () => {
  it("ne rend rien quand aucune mission n'est sélectionnée", () => {
    const { container } = renderDrawer(null);
    expect(container).toBeEmptyDOMElement();
  });

  it("affiche l'en-tête depuis l'item déjà chargé, sans attendre une requête", () => {
    fetchMissionByIdMock.mockReturnValue(new Promise(() => {})); // jamais résolu
    renderDrawer(makeItem());

    const heading = screen.getByRole("heading", { name: "Mission #42" });
    expect(heading).toBeInTheDocument();
    expect(within(heading.parentElement!).getByText("Soumis")).toBeInTheDocument();
    expect(screen.getByText("Salve Decorte")).toBeInTheDocument();
    expect(screen.getByText("Dr Jean Dupont")).toBeInTheDocument();
  });

  it("le bouton Fermer appelle onClose", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view", "validate"] });
    renderDrawer(makeItem(), onClose);

    await user.click(screen.getByLabelText("Fermer"));
    expect(onClose).toHaveBeenCalled();
  });

  it("Valider est actif quand allowedActions l'autorise, et appelle validateMissionEncoding", async () => {
    const user = userEvent.setup();
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view", "validate", "reject"] });
    renderDrawer(makeItem());

    const btn = await screen.findByRole("button", { name: "Valider l'encodage" });
    await waitFor(() => expect(btn).toBeEnabled());
    await user.click(btn);

    await waitFor(() => expect(validateMissionEncodingMock).toHaveBeenCalledWith(42));
  });

  it("Relancer n'apparaît que si allowedActions l'autorise", async () => {
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view", "validate"] });
    renderDrawer(makeItem());

    await waitFor(() => expect(screen.getByRole("heading", { name: "Mission #42" })).toBeInTheDocument());
    expect(screen.queryByText("Relancer")).toBeNull();
  });

  it("Relancer appelle remindMissionEncoding quand autorisé", async () => {
    const user = userEvent.setup();
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "IN_PROGRESS", allowedActions: ["view", "remind"] });
    renderDrawer(makeItem({ encodingState: "IN_PROGRESS", encodingStateLabel: "En cours" }));

    const btn = await screen.findByText("Relancer");
    await user.click(btn);

    await waitFor(() => expect(remindMissionEncodingMock).toHaveBeenCalledWith(42));
  });

  it("affiche l'encart d'absence de matériel quand aucune intervention n'est encodée", async () => {
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "IN_PROGRESS", allowedActions: ["view"] });
    fetchMissionEncodingMock.mockResolvedValue({ mission: { id: 42, type: "BLOCK", status: "IN_PROGRESS", allowedActions: [] }, interventions: [], entries: [], interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] });
    renderDrawer(makeItem({ encodingState: "IN_PROGRESS", encoding: { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0, submittedWithoutMaterial: false, hasNoMaterialJustification: false, isStale: false } }));

    await waitFor(() => expect(screen.getByText(/Aucun matériel encodé/)).toBeInTheDocument());
  });

  it("encodage verrouillé (VALIDATED) : jamais de lecture refusée par le backend, un renvoi explicite vers la fiche", async () => {
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "VALIDATED", allowedActions: ["view", "reopen"] });
    renderDrawer(makeItem({ missionStatus: "VALIDATED", encodingState: "VALIDATED", encodingStateLabel: "Validé" }));

    expect(await screen.findByText(/Encodage verrouillé/)).toBeInTheDocument();
    expect(fetchMissionEncodingMock).not.toHaveBeenCalled();
    expect(screen.queryByText(/n'a pas encore ouvert son encodage/)).not.toBeInTheDocument();
  });

  it("affiche « encodées / interventions » du backend — même définition que la page instrumentiste", async () => {
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view"] });
    renderDrawer(makeItem());

    expect(await screen.findByText("1/2 interventions encodées · 6 références")).toBeInTheDocument();
  });

  it("affiche la photo de profil quand le backend en fournit une, sinon les initiales", async () => {
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view"] });
    const { container } = renderDrawer(makeItem({
      instrumentist: { id: 5, name: "Salve Decorte", photoPath: "/uploads/profile-pictures/salve.jpg" },
      surgeon: { id: 8, name: "Dr Jean Dupont", photoPath: null },
    }));

    const img = container.querySelector("img");
    expect(img?.getAttribute("src")).toMatch(/\/uploads\/profile-pictures\/salve\.jpg$/);
    expect(screen.getByText("DJ")).toBeInTheDocument();
  });
});
