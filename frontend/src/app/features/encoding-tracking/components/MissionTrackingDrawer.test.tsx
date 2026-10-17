import { describe, it, expect, vi, beforeEach } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { MissionTrackingDrawer } from "./MissionTrackingDrawer";
import { ToastProvider } from "../../../ui/toast/ToastProvider";
import type { EncodingTrackingItem } from "../api/encodingTracking.api";

const fetchMissionByIdMock = vi.fn();
const getMissionExecutionMock = vi.fn();
const remindMissionHoursMock = vi.fn();
const updateMissionExecutionMock = vi.fn();
vi.mock("../../missions/api/missions.api", () => ({
  fetchMissionById: (...args: unknown[]) => fetchMissionByIdMock(...args),
  getMissionExecution: (...args: unknown[]) => getMissionExecutionMock(...args),
  remindMissionHours: (...args: unknown[]) => remindMissionHoursMock(...args),
  updateMissionExecution: (...args: unknown[]) => updateMissionExecutionMock(...args),
}));

// D-153 — la section d'encodage réutilisée est déjà couverte par ses propres tests : seul
// son branchement (droits servis par le backend, jamais de soumission) est vérifié ici.
const interventionsSectionProps = vi.fn();
vi.mock("../../encoding/components/InterventionsSection", () => ({
  default: (props: { canEdit: boolean; canSubmit: boolean }) => {
    interventionsSectionProps(props);
    return <div data-testid="interventions-section-stub">{props.canEdit ? "editable" : "read-only"}</div>;
  },
}));

const fetchMissionEncodingMock = vi.fn();
const validateMissionEncodingMock = vi.fn();
const remindMissionEncodingMock = vi.fn();
const rejectMissionEncodingMock = vi.fn();
const reopenMissionEncodingMock = vi.fn();
vi.mock("../../encoding/api/encoding.api", () => ({
  rejectMissionEncoding: (...args: unknown[]) => rejectMissionEncodingMock(...args),
  reopenMissionEncoding: (...args: unknown[]) => reopenMissionEncodingMock(...args),
  fetchMissionEncoding: (...args: unknown[]) => fetchMissionEncodingMock(...args),
  validateMissionEncoding: (...args: unknown[]) => validateMissionEncodingMock(...args),
  remindMissionEncoding: (...args: unknown[]) => remindMissionEncodingMock(...args),
  newActionBatchId: () => "batch-test-0001",
}));

const fetchMissionAuditMock = vi.fn();
vi.mock("../../planning-v2/api/planningV2.api", () => ({
  fetchMissionAudit: (...args: unknown[]) => fetchMissionAuditMock(...args),
}));

const getMissionFinancialAnomaliesMock = vi.fn();
const getMissionEncodingHistoryMock = vi.fn();
vi.mock("../api/encodingTracking.api", () => ({
  getMissionFinancialAnomalies: (...args: unknown[]) => getMissionFinancialAnomaliesMock(...args),
  getMissionEncodingHistory: (...args: unknown[]) => getMissionEncodingHistoryMock(...args),
}));

const calculateMissionMock = vi.fn();
const recalculateFinancialCalculationMock = vi.fn();
vi.mock("../../financial-calculation/api/financialCalculation.api", () => ({
  calculateMission: (...args: unknown[]) => calculateMissionMock(...args),
  recalculateFinancialCalculation: (...args: unknown[]) => recalculateFinancialCalculationMock(...args),
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
    financial: { state: "TO_CALCULATE", label: "À calculer", isBlocking: false, anomalyCount: 0, anomalyReasons: [] },
    ...overrides,
  };
}

function renderDrawer(item: EncodingTrackingItem | null, onClose = () => {}, client = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
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
  rejectMissionEncodingMock.mockReset();
  reopenMissionEncodingMock.mockReset();
  getMissionFinancialAnomaliesMock.mockReset();
  calculateMissionMock.mockReset();
  recalculateFinancialCalculationMock.mockReset();
  updateMissionExecutionMock.mockReset();
  getMissionEncodingHistoryMock.mockReset();
  interventionsSectionProps.mockReset();
  getMissionEncodingHistoryMock.mockResolvedValue([]);

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

    await waitFor(() => expect(remindMissionEncodingMock).toHaveBeenCalledWith(42, expect.any(String)));
  });

  it("D-143 — la relance manuelle annonce le canal réellement utilisé", async () => {
    const user = userEvent.setup();
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "IN_PROGRESS", allowedActions: ["view", "remind"] });
    remindMissionEncodingMock.mockResolvedValue({ lastManualReminderChannel: "email" });
    renderDrawer(makeItem({ encodingState: "IN_PROGRESS", encodingStateLabel: "En cours" }));

    await user.click(await screen.findByText("Relancer"));

    expect(await screen.findByText("Relance envoyée à l'instrumentiste par e-mail.")).toBeInTheDocument();
  });

  it("H5a × D-143 — relance manuelle : e-mail obligatoire + push annoncés ensemble, lot unique par clic", async () => {
    const user = userEvent.setup();
    fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "IN_PROGRESS", allowedActions: ["view", "remind"] });
    remindMissionEncodingMock.mockResolvedValue({ lastManualReminderChannel: "push+email" });
    renderDrawer(makeItem({ encodingState: "IN_PROGRESS", encodingStateLabel: "En cours" }));

    await user.click(await screen.findByText("Relancer"));

    expect(await screen.findByText("Relance envoyée à l'instrumentiste par notification push et par e-mail.")).toBeInTheDocument();
    expect(remindMissionEncodingMock).toHaveBeenCalledWith(42, expect.stringMatching(/^[A-Za-z0-9-]{8,64}$/));
  });

  it("D-143 — l'alerte J+4 et l'historique des relances automatiques restent visibles", async () => {
    fetchMissionByIdMock.mockResolvedValue({
      id: 42, status: "IN_PROGRESS", allowedActions: ["view", "remind"],
      automaticReminders: [
        { stage: "D1", sentAt: "2026-07-15T09:00:00+02:00", channels: ["IN_APP", "PUSH"], managersNotified: null },
        { stage: "D4", sentAt: "2026-07-18T09:00:00+02:00", channels: ["IN_APP", "PUSH", "EMAIL"], managersNotified: 3 },
      ],
    });
    const base = makeItem({ encodingState: "IN_PROGRESS", encodingStateLabel: "En cours" });
    renderDrawer({ ...base, encoding: { ...base.encoding, escalatedAt: "2026-07-18T09:00:00+02:00" } });

    expect(screen.getByTestId("drawer-escalated")).toHaveTextContent("alerte J+4");
    const history = await screen.findByTestId("drawer-reminder-history");
    expect(history).toHaveTextContent("J+1 15/07 (push)");
    expect(history).toHaveTextContent("J+4 18/07 (push + e-mail, managers alertés)");
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
    expect(screen.getAllByText("Heures prestées non renseignées").length).toBeGreaterThan(0);
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
    await waitFor(() => expect(remindMissionEncodingMock).toHaveBeenCalledWith(42, expect.any(String)));
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

  // ── D-138 — anomalies financières ──────────────────────────────────────

  describe("anomalies financières (D-138)", () => {
    const anomalyItem = () => makeItem({
      missionStatus: "VALIDATED", encodingState: "VALIDATED", encodingStateLabel: "Validé",
      financial: { state: "ANOMALY", label: "Anomalie", isBlocking: true, anomalyCount: 2,
        anomalyReasons: [
          { code: "MISSING_FIRM_INTERVENTION_RATE", label: "Tarif d'intervention manquant", count: 1 },
          { code: "MISSING_FIRM_MATERIAL_RATE", label: "Tarif matériel manquant", count: 1 },
        ] },
    });

    const interventionAnomaly = {
      code: "MISSING_FIRM_INTERVENTION_RATE", category: "CONFIGURATION", severity: "BLOCKING",
      title: "Tarif d'intervention manquant",
      explanation: "Aucun tarif applicable n'est configuré pour cette prestation chez Arthrex au 01/10/2026.",
      firm: { id: 5, name: "Arthrex" }, element: { type: "INTERVENTION", label: "Plastie ligamentaire" },
      action: { code: "CONFIGURE_INTERVENTION_RATE", label: "Configurer le tarif" }, resolved: false,
      missionInterventionId: 1, materialLineId: null,
    };
    const materialAnomaly = {
      code: "MISSING_FIRM_MATERIAL_RATE", category: "CONFIGURATION", severity: "BLOCKING",
      title: "Tarif matériel manquant",
      explanation: "Aucun tarif applicable n'est configuré pour ce matériel (Smith & Nephew) au 01/10/2026.",
      firm: { id: 4, name: "Smith & Nephew" }, element: { type: "MATERIAL", label: "Fast-Fix", reference: "FF-360" },
      action: { code: "CONFIGURE_MATERIAL_RATE", label: "Configurer le tarif" }, resolved: false,
      missionInterventionId: 2, materialLineId: 12,
    };
    const detail = (anomalies: unknown[], extra: Record<string, unknown> = {}) => ({
      missionId: 42, state: "ANOMALY", label: "Anomalie", isBlocking: true,
      failedAt: "2026-10-08T06:29:04+02:00", effectiveAt: "2026-10-01",
      anomalies, retry: { kind: "CALCULATE", calculationId: null }, ...extra,
    });

    beforeEach(() => {
      fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "VALIDATED", allowedActions: ["view"] });
      fetchMissionEncodingMock.mockResolvedValue({ mission: { id: 42, type: "BLOCK", status: "VALIDATED", allowedActions: [] }, interventions: [], entries: VALIDATED_ENTRIES, interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] });
    });

    it("une mission en anomalie affiche CHAQUE anomalie expliquée et localisée, avant les interventions", async () => {
      getMissionFinancialAnomaliesMock.mockResolvedValue(detail([interventionAnomaly, materialAnomaly]));
      renderDrawer(anomalyItem());

      const section = await screen.findByTestId("financial-anomalies");
      expect(getMissionFinancialAnomaliesMock).toHaveBeenCalledWith(42);
      expect(within(section).getByText("Anomalies financières — 2 problèmes détectés")).toBeInTheDocument();
      const items = within(section).getAllByTestId("financial-anomaly");
      expect(items).toHaveLength(2);
      expect(within(items[0]).getByText("1. Tarif d'intervention manquant")).toBeInTheDocument();
      expect(within(items[0]).getByText("Arthrex")).toBeInTheDocument();
      expect(within(items[0]).getByText("Plastie ligamentaire (intervention 1/2)")).toBeInTheDocument();
      expect(within(items[0]).getByText(/Aucun tarif applicable n'est configuré pour cette prestation chez Arthrex/)).toBeInTheDocument();
      expect(within(items[1]).getByText("2. Tarif matériel manquant")).toBeInTheDocument();
      expect(within(items[1]).getByText("Fast-Fix · Réf. FF-360")).toBeInTheDocument();
      expect(within(items[1]).getByText("intervention 2/2")).toBeInTheDocument();
      expect(screen.getByTestId("drawer-finance-status")).toHaveTextContent("Finance : anomalie");

      // Placée avant la liste des interventions.
      const interventionsTitle = screen.getByText(/INTERVENTIONS/);
      expect(section.compareDocumentPosition(interventionsTitle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      // Les éléments concernés sont marqués dans l'encodage.
      await waitFor(() => expect(document.querySelectorAll("[data-anomaly]").length).toBeGreaterThan(0));
      expect(Array.from(document.querySelectorAll("[data-anomaly]")).map((el) => el.getAttribute("data-anchor"))).toEqual(["itv-1", "ml-12"]);
    });

    it("règles contradictoires : chaque règle en conflit est identifiée (montant, période), jamais un tarif choisi", async () => {
      getMissionFinancialAnomaliesMock.mockResolvedValue(detail([{
        ...interventionAnomaly,
        code: "CONFLICTING_FIRM_INTERVENTION_RATE",
        title: "Tarifs d'intervention contradictoires",
        explanation: "2 tarifs actifs s'appliquent en même temps à cette prestation chez Arthrex au 01/10/2026. Le calcul ne choisit jamais entre eux : clôturez ou corrigez l'un d'eux.",
        action: { code: "CONFIGURE_INTERVENTION_RATE", label: "Corriger les tarifs" },
        conflictingRules: [
          { id: 12, unitPrice: "300.00", currency: "EUR", validFrom: "2026-01-01", validTo: null },
          { id: 15, unitPrice: "350.00", currency: "EUR", validFrom: "2026-09-01", validTo: "2027-01-01" },
        ],
      }]));
      renderDrawer(anomalyItem());

      const rules = await screen.findByTestId("conflicting-rules");
      expect(within(rules).getByText("Règles en conflit")).toBeInTheDocument();
      const items = within(rules).getAllByRole("listitem");
      expect(items).toHaveLength(2);
      expect(items[0]).toHaveTextContent("Règle #12 — 300,00 EUR · du 01/01/2026, sans date de fin");
      // validTo est exclusif (D-072) : le dernier jour couvert est la veille.
      expect(items[1]).toHaveTextContent("Règle #15 — 350,00 EUR · du 01/09/2026 au 31/12/2026");
      expect(screen.getByText("1. Tarifs d'intervention contradictoires")).toBeInTheDocument();
      expect(screen.getByRole("button", { name: "Corriger les tarifs" })).toBeInTheDocument();
    });

    it("D-141 — « Pas de forfait » posé depuis l'échec : corrigé, nouvelle cause listée à part, pronostic du backend", async () => {
      const corrected = {
        ...interventionAnomaly, resolved: true, referenceDate: "2026-10-01", targetRules: [],
        currentResolution: { kind: "FEE_NOT_APPLICABLE", label: "Aucun forfait prévu pour cette prestation (« Pas de forfait ») : rien ne sera facturé.", rule: null },
      };
      const stillMissing = {
        ...materialAnomaly, referenceDate: "2026-10-01",
        targetRules: [{ id: 12, unitPrice: "20.00", currency: "EUR", validFrom: "2026-01-01", validTo: "2026-09-01", active: true }],
        currentResolution: { kind: "ANOMALY", label: "Toujours bloquant : tarif matériel manquant.", rule: null },
      };
      const appeared = {
        code: "MISSING_REPRESENTATIVE_PRESENCE_ANSWER", category: "ENCODING", severity: "BLOCKING",
        title: "Présence du délégué non renseignée", explanation: "L'encodage doit indiquer si le délégué Arthrex était présent : cela détermine le forfait.",
        firm: { id: 5, name: "Arthrex" }, element: { type: "INTERVENTION", label: "MPFL" }, action: { code: "OPEN_MISSION", label: "Compléter l'encodage" },
        resolved: false, missionInterventionId: 2, materialLineId: null, conflictingRules: [], targetRules: [], referenceDate: "2026-10-01",
        currentResolution: { kind: "ANOMALY", label: "Toujours bloquant : présence du délégué non renseignée.", rule: null }, detectedAfterFailure: true,
      };
      getMissionFinancialAnomaliesMock.mockResolvedValue(detail([corrected, stillMissing], {
        newAnomalies: [appeared], recalculation: { wouldSucceed: false, remainingAnomalyCount: 2, referenceDate: "2026-10-01" },
      }));
      renderDrawer(anomalyItem());

      const section = await screen.findByTestId("financial-anomalies");
      expect(within(section).getByText(/Un recalcul maintenant échouerait encore \(2 anomalies\)/)).toBeInTheDocument();
      const items = within(section).getAllByTestId("financial-anomaly");
      expect(items).toHaveLength(3);
      expect(within(items[0]).getByText("Corrigé — à recalculer")).toBeInTheDocument();
      expect(within(items[0]).getByTestId("financial-anomaly-current")).toHaveTextContent("Pas de forfait");
      expect(within(items[1]).getByTestId("target-rules")).toHaveTextContent("Règle #12");
      expect(within(items[1]).getByTestId("target-rules")).toHaveTextContent("au 31/08/2026");
      expect(within(section).getByTestId("financial-new-anomalies")).toHaveTextContent("1 anomalie qu'un recalcul produirait aussi");
      expect(within(items[2]).getByText(/3\. Présence du délégué non renseignée/)).toBeInTheDocument();
      // Repères « Anomalie » de l'encodage = ce qui bloque ENCORE : l'intervention corrigée (itv-1)
      // n'est plus marquée, celle de la cause apparue depuis l'échec (itv-2) l'est.
      await waitFor(() => expect(document.querySelectorAll("[data-anomaly]").length).toBeGreaterThan(0));
      expect(Array.from(document.querySelectorAll("[data-anomaly]")).map((el) => el.getAttribute("data-anchor"))).toEqual(["itv-2", "ml-12"]);
    });

    it("dépassement horaire sans anomalie : aucune section, aucune requête d'anomalies", async () => {
      renderDrawer(makeItem({ financial: { state: "TO_CALCULATE", label: "À calculer", isBlocking: false, anomalyCount: 0, anomalyReasons: [] } }));
      await screen.findByText("Dépasse le temps planifié", { selector: "[data-testid=drawer-hours-status]" });
      expect(screen.queryByTestId("financial-anomalies")).not.toBeInTheDocument();
      expect(screen.queryByTestId("drawer-finance-status")).not.toBeInTheDocument();
      expect(getMissionFinancialAnomaliesMock).not.toHaveBeenCalled();
    });

    it("échec de chargement : signalé comme tel, jamais transformé en anomalie ni en absence d'anomalie", async () => {
      getMissionFinancialAnomaliesMock.mockRejectedValue(new Error("Network Error"));
      renderDrawer(anomalyItem());

      const error = await screen.findByTestId("financial-anomalies-error");
      expect(error).toHaveTextContent("Impossible de charger le détail des anomalies financières");
      expect(screen.queryByTestId("financial-anomaly")).not.toBeInTheDocument();
    });

    it("« Voir dans l'encodage » mène à la ligne de matériel concernée", async () => {
      const user = userEvent.setup();
      const scrolled: string[] = [];
      const original = HTMLElement.prototype.scrollIntoView;
      HTMLElement.prototype.scrollIntoView = vi.fn(function (this: HTMLElement) { scrolled.push(this.getAttribute("data-anchor") ?? ""); });
      getMissionFinancialAnomaliesMock.mockResolvedValue(detail([interventionAnomaly, materialAnomaly]));
      renderDrawer(anomalyItem());

      const items = await screen.findAllByTestId("financial-anomaly");
      await screen.findAllByTestId("material-row");
      await user.click(within(items[1]).getByRole("button", { name: "Voir dans l'encodage" }));
      expect(scrolled.at(-1)).toBe("ml-12");
      await user.click(within(items[0]).getByRole("button", { name: "Voir dans l'encodage" }));
      expect(scrolled.at(-1)).toBe("itv-1");
      HTMLElement.prototype.scrollIntoView = original;
    });

    it("relance du calcul : un nouvel échec recharge le détail, qui ne garde que les anomalies restantes", async () => {
      const user = userEvent.setup();
      getMissionFinancialAnomaliesMock
        .mockResolvedValueOnce(detail([{ ...interventionAnomaly, resolved: true }, materialAnomaly]))
        .mockResolvedValue(detail([materialAnomaly]));
      calculateMissionMock.mockRejectedValue({ response: { status: 422, data: { error: { code: "FINANCIAL_CALCULATION_ANOMALIES", message: "1 anomalie", violations: [{}] } } } });
      renderDrawer(anomalyItem());

      const section = await screen.findByTestId("financial-anomalies");
      expect(within(section).getByText("Corrigé — à recalculer")).toBeInTheDocument();
      await user.click(within(section).getByRole("button", { name: "Relancer le calcul" }));

      expect(calculateMissionMock).toHaveBeenCalledWith(42);
      expect(await screen.findByText("Le calcul échoue encore : 1 anomalie restante.")).toBeInTheDocument();
      await waitFor(() => expect(screen.getAllByTestId("financial-anomaly")).toHaveLength(1));
      expect(screen.getByText("Anomalies financières — 1 problème détecté")).toBeInTheDocument();
      expect(recalculateFinancialCalculationMock).not.toHaveBeenCalled();
    });

    it("recalcul d'un calcul actif : appelle l'endpoint de recalcul désigné par le backend, puis rafraîchit liste et détail", async () => {
      const user = userEvent.setup();
      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      const invalidate = vi.spyOn(client, "invalidateQueries");
      getMissionFinancialAnomaliesMock.mockResolvedValue(detail([materialAnomaly], { retry: { kind: "RECALCULATE", calculationId: 77 } }));
      recalculateFinancialCalculationMock.mockResolvedValue({ id: 78 });
      renderDrawer(anomalyItem(), () => {}, client);

      await user.click(await screen.findByRole("button", { name: "Relancer le calcul" }));
      expect(recalculateFinancialCalculationMock).toHaveBeenCalledWith(77);
      expect(calculateMissionMock).not.toHaveBeenCalled();
      await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ["encoding-tracking"], exact: false }));
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ["encoding-tracking-financial-anomalies", 42] });
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ["firm-billing-worklist"], exact: false });
    });

    it("tiroir et liste ne se contredisent pas : un détail plus récent recharge la liste", async () => {
      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      const invalidate = vi.spyOn(client, "invalidateQueries");
      getMissionFinancialAnomaliesMock.mockResolvedValue({ ...detail([]), state: "CALCULATED", label: "Calculé", isBlocking: false, retry: null });
      renderDrawer(anomalyItem(), () => {}, client);

      await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ["encoding-tracking"], exact: false }));
      expect(screen.queryByTestId("financial-anomaly")).not.toBeInTheDocument();
    });
  });

  describe("renvoi en correction / réouverture (D-148)", () => {
    const REASON = "Merci de renseigner vos horaires réels de début et de fin.";

    function deferred<T>() {
      let resolve!: (v: T) => void;
      let reject!: (e: unknown) => void;
      const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej; });
      return { promise, resolve, reject };
    }

    function submitted(allowedActions = ["view", "validate", "reject"]) {
      fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions, encodingCorrectionBlockedReason: null });
    }

    async function openReturnDialog(user: ReturnType<typeof userEvent.setup>) {
      await user.click(await screen.findByRole("button", { name: "Renvoyer en correction" }));
      return screen.findByRole("dialog", { name: "Renvoyer en correction" });
    }

    it("SUBMITTED : la fenêtre rappelle mission, instrumentiste et statut ; motif obligatoire ; Annuler ne modifie rien", async () => {
      const user = userEvent.setup();
      submitted();
      renderDrawer(makeItem());

      const dialog = await openReturnDialog(user);
      expect(within(dialog).getByText(/#42 · .* · Delta/)).toBeInTheDocument();
      expect(within(dialog).getByText("Salve Decorte")).toBeInTheDocument();
      expect(within(dialog).getByText("Soumis")).toBeInTheDocument();
      const confirm = within(dialog).getByRole("button", { name: "Confirmer le renvoi" });
      expect(confirm).toBeDisabled();
      await user.type(within(dialog).getByLabelText(/Motif de la correction/), "   ");
      expect(confirm).toBeDisabled();

      await user.click(within(dialog).getByRole("button", { name: "Annuler" }));
      await waitFor(() => expect(screen.queryByRole("dialog", { name: "Renvoyer en correction" })).not.toBeInTheDocument());
      expect(rejectMissionEncodingMock).not.toHaveBeenCalled();
      expect(screen.getByTestId("mission-tracking-drawer")).toBeInTheDocument();
    });

    it("confirmation : fenêtre ouverte pendant l'enregistrement, puis fermée ; statut, liste et compteurs rechargés", async () => {
      const user = userEvent.setup();
      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      const invalidate = vi.spyOn(client, "invalidateQueries");
      submitted();
      const pending = deferred<void>();
      rejectMissionEncodingMock.mockReturnValue(pending.promise);
      renderDrawer(makeItem(), () => {}, client);

      const dialog = await openReturnDialog(user);
      fireEvent.change(within(dialog).getByLabelText(/Motif de la correction/), { target: { value: REASON } });
      await user.click(within(dialog).getByRole("button", { name: "Confirmer le renvoi" }));

      await waitFor(() => expect(rejectMissionEncodingMock).toHaveBeenCalledWith(42, REASON));
      expect(screen.getByRole("dialog", { name: "Renvoyer en correction" })).toBeInTheDocument();
      expect(within(dialog).getByLabelText("Enregistrement en cours")).toBeInTheDocument();
      expect(within(dialog).getByRole("button", { name: "Annuler" })).toBeDisabled();
      expect(invalidate).not.toHaveBeenCalledWith({ queryKey: ["encoding-tracking"], exact: false });

      pending.resolve();
      await waitFor(() => expect(screen.queryByRole("dialog", { name: "Renvoyer en correction" })).not.toBeInTheDocument());
      expect(await screen.findByText("Encodage renvoyé en correction à l'instrumentiste.")).toBeInTheDocument();
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ["encoding-tracking"], exact: false });
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ["mission", 42] });
      expect(invalidate).toHaveBeenCalledWith({ queryKey: ["mission-audit", 42] });
    });

    it("échec serveur : fenêtre ouverte, erreur affichée, motif conservé, nouvelle tentative possible", async () => {
      const user = userEvent.setup();
      submitted();
      rejectMissionEncodingMock.mockRejectedValueOnce({ response: { status: 409, data: { error: { message: "Mission must be SUBMITTED to reject its encoding" } } } });
      renderDrawer(makeItem());

      const dialog = await openReturnDialog(user);
      fireEvent.change(within(dialog).getByLabelText(/Motif de la correction/), { target: { value: REASON } });
      await user.click(within(dialog).getByRole("button", { name: "Confirmer le renvoi" }));

      expect(await within(dialog).findByText("Mission must be SUBMITTED to reject its encoding")).toBeInTheDocument();
      expect(within(dialog).getByLabelText(/Motif de la correction/)).toHaveValue(REASON);

      rejectMissionEncodingMock.mockResolvedValueOnce(undefined);
      await user.click(within(dialog).getByRole("button", { name: "Confirmer le renvoi" }));
      await waitFor(() => expect(screen.queryByRole("dialog", { name: "Renvoyer en correction" })).not.toBeInTheDocument());
      expect(rejectMissionEncodingMock).toHaveBeenCalledTimes(2);
      expect(rejectMissionEncodingMock).toHaveBeenLastCalledWith(42, REASON);
    });

    it("un double clic n'envoie qu'une seule demande", async () => {
      const user = userEvent.setup();
      submitted();
      const pending = deferred<void>();
      rejectMissionEncodingMock.mockReturnValue(pending.promise);
      renderDrawer(makeItem());

      const dialog = await openReturnDialog(user);
      fireEvent.change(within(dialog).getByLabelText(/Motif de la correction/), { target: { value: REASON } });
      const confirm = within(dialog).getByRole("button", { name: "Confirmer le renvoi" });
      fireEvent.click(confirm);
      fireEvent.click(confirm);
      fireEvent.click(confirm);

      await waitFor(() => expect(rejectMissionEncodingMock).toHaveBeenCalledTimes(1));
      pending.resolve();
      await waitFor(() => expect(screen.queryByRole("dialog", { name: "Renvoyer en correction" })).not.toBeInTheDocument());
      expect(rejectMissionEncodingMock).toHaveBeenCalledTimes(1);
    });

    it("Échap dans la fenêtre ne ferme pas le tiroir", async () => {
      const user = userEvent.setup();
      const onClose = vi.fn();
      submitted();
      renderDrawer(makeItem(), onClose);

      await openReturnDialog(user);
      await user.keyboard("{Escape}");
      expect(onClose).not.toHaveBeenCalled();
    });

    it("VALIDATED : « Rouvrir l'encodage » appelle l'endpoint de réouverture avec le motif", async () => {
      const user = userEvent.setup();
      fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "VALIDATED", allowedActions: ["view", "reopen"], encodingCorrectionBlockedReason: null });
      reopenMissionEncodingMock.mockResolvedValue(undefined);
      renderDrawer(validatedItem());

      await user.click(await screen.findByRole("button", { name: "Rouvrir l'encodage" }));
      const dialog = await screen.findByRole("dialog", { name: "Rouvrir l'encodage" });
      expect(within(dialog).getByText("Validé")).toBeInTheDocument();
      fireEvent.change(within(dialog).getByLabelText(/Motif de la correction/), { target: { value: REASON } });
      await user.click(within(dialog).getByRole("button", { name: "Confirmer la réouverture" }));

      await waitFor(() => expect(screen.queryByRole("dialog", { name: "Rouvrir l'encodage" })).not.toBeInTheDocument());
      expect(reopenMissionEncodingMock).toHaveBeenCalledWith(42, REASON);
      expect(rejectMissionEncodingMock).not.toHaveBeenCalled();
    });

    it("verrou financier : aucune action, la raison donnée par le backend est affichée", async () => {
      const reason = "Verrou financier : le calcul de cette mission est intégré à un document financier. Son encodage ne peut plus être rouvert.";
      fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "VALIDATED", allowedActions: ["view"], encodingCorrectionBlockedReason: reason });
      renderDrawer(validatedItem());

      expect(await screen.findByTestId("drawer-correction-blocked")).toHaveTextContent(reason);
      expect(screen.queryByRole("button", { name: "Rouvrir l'encodage" })).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: "Renvoyer en correction" })).not.toBeInTheDocument();
    });

    it("les actions ne sont jamais déduites du statut : sans allowedActions, aucun bouton", async () => {
      submitted(["view", "validate"]);
      renderDrawer(makeItem());

      expect(await screen.findByRole("button", { name: "Valider l'encodage" })).toBeInTheDocument();
      expect(screen.queryByRole("button", { name: "Renvoyer en correction" })).not.toBeInTheDocument();
    });
  });
  describe("D-153 — corrections directes du manager", () => {
    it("aucune action de correction sans edit_hours / edit_encoding servis par le backend (mission validée : rouvrir d'abord)", async () => {
      fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "VALIDATED", allowedActions: ["view", "reopen"] });
      fetchMissionEncodingMock.mockResolvedValue({ mission: { id: 42, type: "BLOCK", status: "VALIDATED", allowedActions: [] }, interventions: [], entries: VALIDATED_ENTRIES, interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [] });
      renderDrawer(validatedItem());

      expect(await screen.findByRole("button", { name: /Rouvrir l'encodage/ })).toBeInTheDocument();
      expect(screen.queryByTestId("drawer-edit-hours")).toBeNull();
      expect(screen.queryByTestId("drawer-edit-encoding")).toBeNull();
    });

    it("« Corriger les heures » réutilise la fenêtre des heures et enregistre comme correction manager", async () => {
      const user = userEvent.setup();
      fetchMissionByIdMock.mockResolvedValue({
        id: 42, status: "SUBMITTED", startAt: "2026-09-10T08:00:00+02:00", endAt: "2026-09-10T12:00:00+02:00",
        allowedActions: ["view", "validate", "reject", "edit_encoding", "edit_hours"],
      });
      updateMissionExecutionMock.mockResolvedValue({ missionId: 42, hasExecutionRecord: true, actualStartAt: "2026-09-10T08:00:00+02:00", actualEndAt: "2026-09-10T12:00:00+02:00", actualDurationMinutes: 240, hoursSource: "MANAGER", effectiveDurationMinutes: 240, effectiveDurationSource: "ACTUAL_TIMES", disputes: [] });
      renderDrawer(makeItem());

      await user.click(await screen.findByTestId("drawer-edit-hours"));
      // Titre de la fenêtre réutilisée + libellé du bouton du tiroir.
      await user.click(await screen.findByText("Enregistrer les heures"));
      expect(screen.getAllByText("Corriger les heures").length).toBeGreaterThanOrEqual(1);

      await waitFor(() => expect(updateMissionExecutionMock).toHaveBeenCalledWith(42, expect.objectContaining({ hoursSource: "MANAGER" })));
      // L'historique est relu après la correction (affichage immédiat).
      await waitFor(() => expect(getMissionEncodingHistoryMock.mock.calls.length).toBeGreaterThanOrEqual(2));
    });

    it("« Corriger l'encodage » ouvre la section d'encodage existante, modifiable, sans soumission", async () => {
      const user = userEvent.setup();
      fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view", "validate", "edit_encoding"] });
      fetchMissionEncodingMock.mockResolvedValue({ mission: { id: 42, type: "BLOCK", status: "SUBMITTED", allowedActions: [] }, interventions: [], entries: VALIDATED_ENTRIES, interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [], catalog: { items: [], firms: [], interventionTypes: [] }, progress: { total: 2, encoded: 2 } });
      renderDrawer(makeItem());

      await user.click(await screen.findByTestId("drawer-edit-encoding"));

      expect(await screen.findByTestId("interventions-section-stub")).toHaveTextContent("editable");
      expect(interventionsSectionProps).toHaveBeenLastCalledWith(expect.objectContaining({ canEdit: true, canSubmit: false, missionId: 42 }));
      // Pas d'heures sans edit_hours.
      expect(screen.queryByTestId("drawer-edit-hours")).toBeNull();
    });

    it("affiche la conséquence financière servie par le backend, jamais déduite", async () => {
      fetchMissionByIdMock.mockResolvedValue({
        id: 42, status: "SUBMITTED", allowedActions: ["view", "edit_encoding"],
        encodingCorrectionFinancialNotice: "Un calcul financier existe pour cette mission.",
      });
      renderDrawer(makeItem());

      expect(await screen.findByTestId("drawer-financial-notice")).toHaveTextContent("Un calcul financier existe pour cette mission.");
    });

    it("affiche l'historique des corrections avec l'auteur, la date et les valeurs avant → après", async () => {
      fetchMissionByIdMock.mockResolvedValue({ id: 42, status: "SUBMITTED", allowedActions: ["view"] });
      getMissionEncodingHistoryMock.mockResolvedValue([
        {
          id: 9, occurredAt: "2026-09-11T10:00:00+00:00", category: "HOURS", eventType: "MISSION_EXECUTION_DURATION_CHANGED",
          actorName: "Marie Manager", summary: "Marie Manager a corrigé les heures réelles de la mission #42 : 08:00–12:00 (240 min) → 08:00–13:00 (300 min)",
          changes: [{ field: "Durée (min)", from: 240, to: 300, redacted: false }],
        },
        {
          id: 8, occurredAt: "2026-09-11T09:00:00+00:00", category: "MATERIAL", eventType: "MISSION_MATERIAL_LINE_UPDATED",
          actorName: "Marie Manager", summary: "Marie Manager a modifié une ligne de matériel",
          changes: [{ field: "Quantité", from: "4.00", to: "2.00", redacted: false }, { field: "Commentaire", from: null, to: null, redacted: true }],
        },
      ]);
      renderDrawer(makeItem());

      const history = await screen.findByTestId("drawer-encoding-history");
      expect(within(history).getByText(/08:00–12:00 \(240 min\) → 08:00–13:00 \(300 min\)/)).toBeInTheDocument();
      expect(within(history).getByText("Durée (min) : 240 → 300")).toBeInTheDocument();
      expect(within(history).getByText("Quantité : 4.00 → 2.00")).toBeInTheDocument();
      expect(within(history).getByText("Commentaire : (masqué) → (masqué)")).toBeInTheDocument();
      expect(within(history).getAllByText(/Marie Manager/).length).toBeGreaterThan(0);
    });
  });
});
