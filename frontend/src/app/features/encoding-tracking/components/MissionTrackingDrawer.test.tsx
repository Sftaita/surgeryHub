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
const remindMissionHoursMock = vi.fn();
vi.mock("../../missions/api/missions.api", () => ({
  fetchMissionById: (...args: unknown[]) => fetchMissionByIdMock(...args),
  getMissionExecution: (...args: unknown[]) => getMissionExecutionMock(...args),
  remindMissionHours: (...args: unknown[]) => remindMissionHoursMock(...args),
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
    hours: { plannedMinutes: 240, effectiveMinutes: 312, effectiveSource: "ACTUAL_TIMES", hasRealHours: true, comparison: "OVER_PLAN" },
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
  remindMissionHoursMock.mockReset();

  getMissionExecutionMock.mockResolvedValue({ hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null, hoursSource: null, effectiveDurationMinutes: 0, effectiveDurationSource: "PLANNED", disputes: [] });
  fetchMissionEncodingMock.mockResolvedValue({ mission: { id: 42, type: "BLOCK", status: "SUBMITTED", allowedActions: [] }, interventions: [], entries: [], interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] });
  fetchMissionAuditMock.mockResolvedValue([]);
  validateMissionEncodingMock.mockResolvedValue(undefined);
  remindMissionEncodingMock.mockResolvedValue(undefined);
  remindMissionHoursMock.mockResolvedValue(undefined);
});

const VALIDATED_ENTRIES = [
  {
    kind: "INTERVENTION", id: 1, requestId: null, orderIndex: 0, label: "Suture d'un ménisque de genou", interventionType: null,
    firm: { id: 10, name: "Smith & Nephew" }, requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false, materialItemRequests: [],
    materialLines: [{ id: 11, missionInterventionId: 1, quantity: "4.00", comment: "", item: { id: 101, label: "Fast-Fix", referenceCode: "FF-360", unit: "1", isImplant: false, firm: { id: 10, name: "Smith & Nephew" } } }],
  },
  {
    kind: "INTERVENTION", id: 2, requestId: null, orderIndex: 1, label: "Suture d'un ménisque de genou", interventionType: null,
    firm: { id: 10, name: "Smith & Nephew" }, requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false, materialItemRequests: [],
    materialLines: [{ id: 12, missionInterventionId: 2, quantity: "7.00", comment: "", item: { id: 101, label: "Fast-Fix", referenceCode: "FF-360", unit: "1", isImplant: false, firm: { id: 10, name: "Smith & Nephew" } } }],
  },
];

function validatedItem(): EncodingTrackingItem {
  return makeItem({ missionStatus: "VALIDATED", encodingState: "VALIDATED", encodingStateLabel: "Validé" });
}

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

  it("encodage VALIDATED : le détail complet reste consultable dans le tiroir, en lecture seule", async () => {
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "VALIDATED", allowedActions: ["view", "reopen"] });
    fetchMissionEncodingMock.mockResolvedValue({ mission: { id: 42, type: "BLOCK", status: "VALIDATED", allowedActions: ["view", "reopen"] }, interventions: [], entries: VALIDATED_ENTRIES, interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] });
    renderDrawer(validatedItem());

    // GET .../encoding est bien appelé (lecture), le contenu s'affiche.
    await waitFor(() => expect(fetchMissionEncodingMock).toHaveBeenCalledWith(42));
    expect(await screen.findAllByTestId("intervention-block")).toHaveLength(2);
    expect(screen.getAllByText("Smith & Nephew · Réf. FF-360")).toHaveLength(2);
    expect(screen.getByText("Qté 4")).toBeInTheDocument();
    expect(screen.getByText("Qté 7")).toBeInTheDocument();
    expect(screen.getByText("Lecture seule")).toBeInTheDocument();
    expect(screen.queryByText(/Encodage verrouillé/)).toBeNull();
    expect(screen.queryByText(/n'a pas encore ouvert son encodage/)).not.toBeInTheDocument();

    // États conservés : badge Validé, bouton « Encodage validé » non cliquable, lien fiche complète.
    expect(within(screen.getByRole("heading", { name: "Mission #42" }).parentElement!).getByText("Validé")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Encodage validé" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Voir la fiche complète" })).toBeInTheDocument();
  });

  it("encodage VALIDATED : les trois modes restent disponibles et aucune mutation n'est possible", async () => {
    const user = userEvent.setup();
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "VALIDATED", allowedActions: ["view", "reopen"] });
    fetchMissionEncodingMock.mockResolvedValue({ mission: { id: 42, type: "BLOCK", status: "VALIDATED", allowedActions: ["view", "reopen"] }, interventions: [], entries: VALIDATED_ENTRIES, interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] });
    renderDrawer(validatedItem());
    await screen.findAllByTestId("intervention-block");

    await user.click(screen.getByRole("button", { name: "Matériel" }));
    expect(screen.getAllByTestId("material-row")).toHaveLength(2);
    await user.click(screen.getByRole("button", { name: "Interventions" }));
    expect(screen.getAllByTestId("intervention-row")).toHaveLength(2);
    await user.click(screen.getByRole("button", { name: "Encodage validé" }));

    // Aucune action d'écriture exposée ni déclenchée.
    expect(screen.queryByText("Relancer")).toBeNull();
    expect(screen.queryByText("Rappeler les heures")).toBeNull();
    expect(screen.queryByRole("button", { name: /supprimer|modifier|ajouter/i })).toBeNull();
    expect(validateMissionEncodingMock).not.toHaveBeenCalled();
    expect(remindMissionEncodingMock).not.toHaveBeenCalled();
    expect(remindMissionHoursMock).not.toHaveBeenCalled();
  });

  it("heures : réel ≤ planifié → vert, réel > planifié → orange, absent → neutre explicite", async () => {
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view"] });

    const withinPlan = renderDrawer(makeItem({ hours: { plannedMinutes: 240, effectiveMinutes: 230, effectiveSource: "ACTUAL_TIMES", hasRealHours: true, comparison: "WITHIN_PLAN" } }));
    expect(screen.getByTestId("drawer-hours-status")).toHaveAttribute("data-hours-comparison", "WITHIN_PLAN");
    expect(screen.getByTestId("drawer-hours-status")).toHaveTextContent("Dans le temps planifié");
    withinPlan.unmount();

    const over = renderDrawer(makeItem({ hours: { plannedMinutes: 240, effectiveMinutes: 312, effectiveSource: "ACTUAL_TIMES", hasRealHours: true, comparison: "OVER_PLAN" } }));
    expect(screen.getByTestId("drawer-hours-status")).toHaveAttribute("data-hours-comparison", "OVER_PLAN");
    expect(screen.getByTestId("drawer-hours-status")).toHaveTextContent("Dépasse le temps planifié");
    over.unmount();

    renderDrawer(makeItem({ hours: { plannedMinutes: 240, effectiveMinutes: 240, effectiveSource: "PLANNED", hasRealHours: false, comparison: "NO_REAL_HOURS" } }));
    expect(screen.getByTestId("drawer-hours-status")).toHaveAttribute("data-hours-comparison", "NO_REAL_HOURS");
    expect(screen.getAllByText("Heures réelles non renseignées").length).toBeGreaterThan(0);
    // Le planifié de repli n'est jamais affiché comme total réel : « 4 h » n'apparaît que sur
    // la ligne Planifié, la ligne Réel affiche « — ».
    expect(screen.getAllByText("4 h")).toHaveLength(1);
    expect(within(screen.getByText("Réel").parentElement!).getByText("—")).toBeInTheDocument();
  });

  it("« Rappeler les heures » n'apparaît que si allowedActions contient remind_hours", async () => {
    const noRealHours = { plannedMinutes: 240, effectiveMinutes: 240, effectiveSource: "PLANNED" as const, hasRealHours: false, comparison: "NO_REAL_HOURS" as const };
    // Heures réelles manquantes mais backend ne l'autorise pas → jamais inventé côté frontend.
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "ASSIGNED", allowedActions: ["view", "remind"] });
    renderDrawer(makeItem({ encodingState: "IN_PROGRESS", hours: noRealHours }));

    await screen.findByText("Relancer");
    expect(screen.queryByText("Rappeler les heures")).toBeNull();
  });

  it("« Rappeler les heures » appelle l'endpoint dédié, distinct de la relance d'encodage", async () => {
    const user = userEvent.setup();
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "ASSIGNED", allowedActions: ["view", "remind", "remind_hours"] });
    renderDrawer(makeItem({ encodingState: "IN_PROGRESS", hours: { plannedMinutes: 240, effectiveMinutes: 240, effectiveSource: "PLANNED", hasRealHours: false, comparison: "NO_REAL_HOURS" } }));

    await user.click(await screen.findByText("Rappeler les heures"));

    await waitFor(() => expect(remindMissionHoursMock).toHaveBeenCalledWith(42));
    expect(remindMissionEncodingMock).not.toHaveBeenCalled();
    // La relance d'encodage classique reste disponible et indépendante.
    await user.click(screen.getByText("Relancer"));
    await waitFor(() => expect(remindMissionEncodingMock).toHaveBeenCalledWith(42));
    expect(remindMissionHoursMock).toHaveBeenCalledTimes(1);
  });

  it("Échap ferme le tiroir", async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view"] });
    renderDrawer(makeItem(), onClose);

    await user.keyboard("{Escape}");
    expect(onClose).toHaveBeenCalledTimes(1);
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
