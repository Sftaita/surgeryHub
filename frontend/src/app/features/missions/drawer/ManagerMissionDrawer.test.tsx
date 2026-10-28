import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ManagerMissionDrawer } from "./ManagerMissionDrawer";
import type { FinancialCalculation } from "../../financial-calculation/api/financialCalculation.api";

/**
 * Tiroir mission manager branché sur l'API (apiClient mocké) — reprend la couverture de
 * l'ancienne fiche pleine page manager et de sa carte de calcul financier, qu'il remplace.
 */

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

const toastSuccess = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
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

const NO_EXECUTION = {
  missionId: 42, hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null,
  hoursSource: null, effectiveDurationMinutes: 0, effectiveDurationSource: "PLANNED", disputes: [],
};
const EMPTY_ENCODING = {
  mission: {}, interventions: [], entries: [], interventionTypeRequests: [], coherenceSummary: {}, encodingComments: [],
  progress: { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0 },
};

function renderDrawer(
  mission: ReturnType<typeof baseMission>,
  responses: { execution?: object; encoding?: object; anomalies?: object[]; calculations?: FinancialCalculation[] } = {},
) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}`) return Promise.resolve({ data: mission });
    if (url.endsWith("/execution")) return Promise.resolve({ data: responses.execution ?? NO_EXECUTION });
    if (url.endsWith("/encoding")) return Promise.resolve({ data: responses.encoding ?? EMPTY_ENCODING });
    if (url.endsWith("/encoding-anomaly-reports")) return Promise.resolve({ data: responses.anomalies ?? [] });
    if (url.endsWith("/financial-calculations")) return Promise.resolve({ data: responses.calculations ?? [] });
    if (url.endsWith("/audit")) return Promise.resolve({ data: [] });
    return Promise.resolve({ data: {} });
  });
  apiPostMock.mockResolvedValue({ data: { ...mission, status: "CANCELLED" } });

  const onClose = vi.fn();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const utils = render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <ManagerMissionDrawer missionId={mission.id} onClose={onClose} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return { ...utils, onClose };
}

/** Le corps n'est rendu qu'une fois la mission chargée. */
const loaded = () => screen.findByTestId(/^drawer-hours/);

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  toastSuccess.mockReset();
});

describe("ManagerMissionDrawer — annulation de mission (Point 5)", () => {
  it("affiche 'Annuler la mission' quand allowedActions le permet (OPEN)", async () => {
    renderDrawer(baseMission({ status: "OPEN" }));
    expect(await screen.findByRole("button", { name: "Annuler la mission" })).toBeInTheDocument();
  });

  it("masque l'action quand allowedActions ne contient pas 'cancel' (SUBMITTED)", async () => {
    renderDrawer(baseMission({ status: "SUBMITTED", allowedActions: ["view", "validate", "reject"] }));
    await loaded();
    expect(screen.queryByRole("button", { name: "Annuler la mission" })).not.toBeInTheDocument();
  });

  it("affiche un message d'effet différent pour un brouillon (DRAFT)", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({ status: "DRAFT", allowedActions: ["view", "edit", "publish", "cancel"] }));
    await user.click(await screen.findByRole("button", { name: "Annuler la mission" }));
    expect(screen.getByText(/n'a jamais été publié/i)).toBeInTheDocument();
  });

  it("affiche un message d'effet différent pour une mission assignée (ASSIGNED)", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({
      status: "ASSIGNED",
      instrumentist: { id: 9, firstname: "Sophie", lastname: "Martin" },
      allowedActions: ["view", "cancel", "reassign", "view_claim"],
    }));
    await user.click(await screen.findByRole("button", { name: "Annuler la mission" }));
    expect(screen.getByText(/instrumentiste est assigné/i)).toBeInTheDocument();
  });

  it("confirmer l'annulation appelle POST /cancel avec le motif, affiche un succès et ferme le tiroir", async () => {
    const user = userEvent.setup();
    const { onClose } = renderDrawer(baseMission({ status: "OPEN" }));

    await user.click(await screen.findByRole("button", { name: "Annuler la mission" }));
    await user.type(screen.getByLabelText(/motif/i), "Créneau finalement inutile");
    const dialog = screen.getAllByRole("dialog").find((d) => d.classList.contains("MuiDialog-paper"))!;
    await user.click(within(dialog).getByRole("button", { name: "Annuler la mission" }));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/missions/42/cancel", { reason: "Créneau finalement inutile" }));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Mission annulée."));
    expect(onClose).toHaveBeenCalled();
  });

  it("le bouton 'Retour' du dialogue n'appelle jamais l'API", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({ status: "OPEN" }));
    await user.click(await screen.findByRole("button", { name: "Annuler la mission" }));
    await user.click(screen.getByRole("button", { name: "Retour" }));
    expect(apiPostMock).not.toHaveBeenCalled();
  });
});

describe("ManagerMissionDrawer — anomalies d'encodage signalées (Lot 6, D-100)", () => {
  it("n'affiche aucune section quand aucune anomalie n'est signalée", async () => {
    renderDrawer(baseMission({ status: "ASSIGNED" }));
    await loaded();
    expect(screen.queryByText("Anomalies signalées par le chirurgien")).not.toBeInTheDocument();
  });

  it("affiche le signalement quand il en existe un pour cette mission", async () => {
    renderDrawer(baseMission({ status: "ASSIGNED" }), {
      anomalies: [{
        id: 1, missionId: 42, reporter: { id: 2, displayName: "A B" },
        type: "MATERIAL_INCORRECT", comment: "Matériel manquant.", status: "OPEN",
        createdAt: "2026-08-01T10:00:00Z", resolvedBy: null, resolvedAt: null, resolutionComment: null,
      }],
    });
    expect(await screen.findByText("Anomalies signalées par le chirurgien")).toBeInTheDocument();
    expect(screen.getByText("Matériel incorrect")).toBeInTheDocument();
    expect(screen.getByText("Marquer comme traité")).toBeInTheDocument();
  });
});

describe("ManagerMissionDrawer — encodage (bug mission #447, Lot 7 D-070)", () => {
  it("affiche les interventions, le matériel encodé et les signaux de cohérence", async () => {
    renderDrawer(baseMission({ status: "SUBMITTED", allowedActions: ["view", "validate", "reject"] }), {
      encoding: {
        ...EMPTY_ENCODING,
        coherenceSummary: { hasInterventionsWithNoMaterial: true },
        entries: [{
          kind: "INTERVENTION", id: 1, requestId: null, orderIndex: 0,
          label: "Prothèse de hanche", interventionType: { id: 1, code: "HIP", label: "Prothèse de hanche" },
          firm: { id: 1, name: "Firme Alpha" }, requestedFirmNameSnapshot: null,
          status: "CATALOGUED", readOnly: false, materialLines: [], materialItemRequests: [],
        }],
      },
    });
    expect(await screen.findByText("Prothèse de hanche")).toBeInTheDocument();
    expect(screen.getByText("Aucun matériel encodé pour cette intervention.")).toBeInTheDocument();
    const review = screen.getByTestId("drawer-encoding-review");
    expect(review).toHaveTextContent("n’empêchent pas la validation");
    expect(within(review).getByText("Au moins une intervention sans matériel")).toBeInTheDocument();
  });

  it("affiche l'historique des commentaires manager, le plus récent d'abord", async () => {
    renderDrawer(baseMission({ status: "ENCODING_IN_PROGRESS", allowedActions: ["view"] }), {
      encoding: {
        ...EMPTY_ENCODING,
        encodingComments: [
          { id: 1, comment: "Il manque la vis.", authorDisplayName: "Manager Un", createdAt: "2026-08-10T18:00:00+02:00" },
          { id: 2, comment: "Merci de vérifier la firme.", authorDisplayName: "Manager Deux", createdAt: "2026-08-11T09:00:00+02:00" },
        ],
      },
    });
    const review = await screen.findByTestId("drawer-encoding-review");
    const texts = Array.from(review.querySelectorAll(".mdw-comment__text")).map((n) => n.textContent);
    expect(texts).toEqual(["Merci de vérifier la firme.", "Il manque la vis."]);
    expect(review).toHaveTextContent("Manager Deux · 11/08 09:00");
  });

  it("sans signal ni commentaire, aucun bloc de revue", async () => {
    renderDrawer(baseMission({ status: "ASSIGNED" }));
    await loaded();
    expect(await screen.findByText("L’encodage s’ouvrira après la mission pour l’instrumentiste.")).toBeInTheDocument();
    expect(screen.queryByTestId("drawer-encoding-review")).toBeNull();
  });
});

describe("ManagerMissionDrawer — heures et contestations", () => {
  it("détaille chaque contestation : motif, statut, auteur, commentaire et réponse", async () => {
    renderDrawer(baseMission({ status: "SUBMITTED", allowedActions: ["view"] }), {
      execution: {
        ...NO_EXECUTION, hasExecutionRecord: true, actualStartAt: "2026-08-10T08:00:00+02:00", actualEndAt: "2026-08-10T18:00:00+02:00",
        actualDurationMinutes: 600, effectiveDurationMinutes: 600, effectiveDurationSource: "ACTUAL_TIMES",
        disputes: [
          { id: 1, reasonCode: "DURATION_INCOHERENT", comment: "La salle a fermé à 17h.", status: "OPEN", resolutionComment: null, raisedByDisplayName: "Dr B", createdAt: "2026-08-11T08:00:00+02:00" },
          { id: 2, reasonCode: "OTHER", comment: null, status: "RESOLVED", resolutionComment: "Heure corrigée.", raisedByDisplayName: "Manager", createdAt: "2026-08-11T09:00:00+02:00" },
        ],
      },
    });
    const disputes = await screen.findByTestId("drawer-disputes");
    expect(disputes).toHaveTextContent("Durée incohérente");
    expect(disputes).toHaveTextContent("ouverte");
    expect(disputes).toHaveTextContent("Dr B · 11/08 08:00");
    expect(disputes).toHaveTextContent("La salle a fermé à 17h.");
    expect(disputes).toHaveTextContent("Autre motif");
    expect(disputes).toHaveTextContent("Réponse : Heure corrigée.");
    expect(screen.getByTestId("drawer-hours")).toHaveTextContent("1 contestation ouverte");
  });

  it("mission classique sans heures déclarées : frise vide, aucune contestation", async () => {
    renderDrawer(baseMission({ status: "SUBMITTED", allowedActions: ["view", "validate", "reject"] }));
    expect(await screen.findByText("Pas encore d’heures déclarées")).toBeInTheDocument();
    expect(screen.queryByTestId("drawer-disputes")).toBeNull();
    expect(screen.queryByTestId("drawer-hours-without-instrumentist")).toBeNull();
  });
});

describe("ManagerMissionDrawer — activité réalisée sans instrumentiste (D-155)", () => {
  const declared = { declaredAt: "2026-08-10T12:00:00+02:00", declaredByName: "Jean Dupont" };

  it("propose la déclaration quand le backend la sert, et l'envoie après confirmation", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({ status: "OPEN", allowedActions: ["view", "cancel", "declare_executed_without_instrumentist"] }));
    await user.click(await screen.findByTestId("manager-declare-without-instrumentist"));
    await user.click(screen.getByRole("button", { name: "Confirmer la déclaration" }));
    expect(apiPostMock).toHaveBeenCalledWith("/api/missions/42/declare-executed-without-instrumentist", {});
  });

  it("annule une déclaration erronée avec un motif obligatoire", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({
      status: "ENCODING_IN_PROGRESS", allowedActions: ["view", "edit_encoding", "submit", "cancel_executed_without_instrumentist"],
      executedWithoutInstrumentist: declared,
    }));
    expect(await screen.findByTestId("manager-without-instrumentist")).toHaveTextContent("déclarée par Jean Dupont");
    await user.click(screen.getByTestId("manager-cancel-declaration"));
    const confirm = screen.getAllByRole("button", { name: "Annuler la déclaration" }).at(-1)!;
    expect(confirm).toBeDisabled();
    await user.type(screen.getByLabelText(/Motif de l'annulation/), "Une instrumentiste était présente.");
    await user.click(confirm);
    expect(apiPostMock).toHaveBeenCalledWith("/api/missions/42/cancel-executed-without-instrumentist", { reason: "Une instrumentiste était présente." });
  });

  it("sans droit servi par le backend, aucune de ces actions", async () => {
    renderDrawer(baseMission({ status: "OPEN", allowedActions: ["view", "cancel"] }));
    expect(await screen.findByRole("button", { name: "Annuler la mission" })).toBeInTheDocument();
    expect(screen.queryByTestId("manager-declare-without-instrumentist")).toBeNull();
    expect(screen.queryByTestId("manager-cancel-declaration")).toBeNull();
  });

  it("aucune frise horaire : information explicite à la place", async () => {
    renderDrawer(baseMission({ status: "SUBMITTED", allowedActions: ["view", "validate", "reject"], executedWithoutInstrumentist: declared }));
    expect(await screen.findByTestId("drawer-hours-without-instrumentist")).toHaveTextContent("Activité réalisée sans instrumentiste — aucune prestation horaire attendue");
    expect(screen.queryByTestId("drawer-hours")).toBeNull();
  });

  it("« Rejeter l'encodage » annonce le renvoi au chirurgien, avec des exemples de contenu", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({ status: "SUBMITTED", allowedActions: ["view", "validate", "reject"], executedWithoutInstrumentist: declared }));
    await user.click(await screen.findByRole("button", { name: "Rejeter l'encodage" }));
    const intro = await screen.findByTestId("reject-encoding-intro");
    expect(intro).toHaveTextContent("renvoyé au chirurgien");
    expect(intro).toHaveTextContent("intervention manquante");
    expect(intro).not.toHaveTextContent("instrumentiste pour correction");
  });

  it("mission classique : la fenêtre de rejet renvoie à l'instrumentiste", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({ status: "SUBMITTED", allowedActions: ["view", "validate", "reject"] }));
    await user.click(await screen.findByRole("button", { name: "Rejeter l'encodage" }));
    expect(await screen.findByTestId("reject-encoding-intro")).toHaveTextContent("L'encodage repart chez l'instrumentiste pour correction");
  });
});

describe("ManagerMissionDrawer — corrections (reassign, edit_hours, edit_encoding)", () => {
  const assigned = baseMission({
    status: "ASSIGNED", instrumentist: { id: 9, firstname: "Sophie", lastname: "Martin" },
    allowedActions: ["view", "cancel", "reassign", "edit_hours", "edit_encoding"],
  });

  it("chaque correction est posée à côté de ce qu'elle modifie, jamais dans le pied", async () => {
    renderDrawer(assigned);
    await loaded();
    const people = document.querySelector(".mdw-people") as HTMLElement;
    expect(within(people).getByTestId("manager-reassign")).toHaveTextContent("Changer");
    expect(within(screen.getByTestId("drawer-hours")).getByTestId("manager-edit-hours")).toHaveTextContent("Corriger les heures");
    expect(within(screen.getByTestId("drawer-encoding")).getByTestId("manager-edit-encoding")).toHaveTextContent("Corriger l'encodage");
    const footer = document.querySelector(".mdw-foot") as HTMLElement;
    expect(within(footer).queryByText(/Changer|Corriger/)).toBeNull();
  });

  it("sans droit servi par le backend, aucune correction", async () => {
    renderDrawer(baseMission({ status: "ASSIGNED", allowedActions: ["view", "cancel"] }));
    await loaded();
    expect(screen.queryByTestId("manager-reassign")).toBeNull();
    expect(screen.queryByTestId("manager-edit-hours")).toBeNull();
    expect(screen.queryByTestId("manager-edit-encoding")).toBeNull();
  });

  it("« Changer » l'instrumentiste : liste des éligibles du backend, puis POST /reassign", async () => {
    const user = userEvent.setup();
    renderDrawer(assigned);
    const base = apiGetMock.getMockImplementation()!;
    apiGetMock.mockImplementation((url: string) =>
      url.endsWith("/eligible-instrumentists")
        ? Promise.resolve({ data: { missionId: 42, candidates: [{ id: 15, name: "Julie Lambert", selectable: true, eligible: true, reasons: [] }] } })
        : base(url));
    apiPostMock.mockResolvedValue({ data: {} });

    await user.click(await screen.findByTestId("manager-reassign"));
    await user.click(await screen.findByText("Julie Lambert"));
    const dialog = screen.getAllByRole("dialog").find((d) => d.classList.contains("MuiDialog-paper"))!;
    await user.click(within(dialog).getByRole("button", { name: /Réassigner|Confirmer/ }));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/missions/42/reassign", { instrumentistId: 15 }));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Instrumentiste changé."));
  });

  it("« Corriger les heures » ouvre la saisie manager des heures prestées", async () => {
    const user = userEvent.setup();
    renderDrawer(assigned);
    await user.click(await screen.findByTestId("manager-edit-hours"));
    // Le bouton + le titre de la fenêtre de saisie.
    await waitFor(() => expect(screen.getAllByText("Corriger les heures")).toHaveLength(2));
  });

  it("« Corriger l'encodage » ouvre l'édition des interventions", async () => {
    const user = userEvent.setup();
    renderDrawer(assigned);
    await user.click(await screen.findByTestId("manager-edit-encoding"));
    expect(await screen.findByText("Corriger l'encodage — mission #42")).toBeInTheDocument();
  });
});

/* ---------- Calcul financier (D-092 / D-153) ---------- */

function line(overrides: Partial<FinancialCalculation["lines"][number]> = {}): FinancialCalculation["lines"][number] {
  return {
    id: 1, beneficiaryType: "FIRM", beneficiaryFirmId: 10, beneficiaryInstrumentistId: null,
    lineType: "FIRM_INTERVENTION_FEE", sourceType: "MISSION_INTERVENTION",
    descriptionSnapshot: "[LCA] Ligament croisé antérieur", quantity: "1.0000", durationMinutes: null,
    unitAmount: "150.00", grossAmount: "150.00", adjustmentAmount: "0.00", totalAmount: "150.00",
    currency: "EUR", effectiveAt: "2026-06-01", snapshot: { firmNameSnapshot: "ConMed" }, warnings: [],
    ...overrides,
  };
}

function calculation(lines: FinancialCalculation["lines"], overrides: Partial<FinancialCalculation> = {}): FinancialCalculation {
  return {
    id: 900, missionId: 42, version: 2, status: "CALCULATED", effectiveAt: "2026-06-01", currencyPolicy: "PER_CURRENCY",
    calculatedAt: "2026-06-01T10:00:00+02:00", calculatedBy: 1, approvedAt: null, approvedBy: null, lockedAt: null,
    cancelledAt: null, cancelledBy: null, supersededAt: null, supersededByCalculationId: null,
    totalsByCurrency: { EUR: { FIRM: "150.00" } }, lines,
    ...overrides,
  };
}

const validated = baseMission({ status: "VALIDATED", instrumentist: { id: 9, firstname: "Sophie", lastname: "Martin" }, allowedActions: ["view", "reopen_encoding"] });

describe("ManagerMissionDrawer — calcul financier", () => {
  it("ligne neutralisée : brut, ajustement, final, délégué présent et règle appliquée", async () => {
    renderDrawer(validated, {
      calculations: [calculation([line({
        adjustmentAmount: "-150.00", totalAmount: "0.00",
        snapshot: { firmNameSnapshot: "ConMed", representativePresentSnapshot: true, adjustmentReasonSnapshot: "Le forfait de cette prestation est neutralisé en présence du délégué ConMed." },
      })])],
    });
    const detail = await screen.findByTestId("drawer-finance-detail-1");
    expect(detail).toHaveTextContent("Brut 150,00 € · ajustement − 150,00 € · final 0,00 € · délégué présent");
    expect(detail).toHaveTextContent("Règle appliquée : Le forfait de cette prestation est neutralisé en présence du délégué ConMed.");
    expect(screen.getByText("ajustée")).toBeInTheDocument();
  });

  it("une ligne non ajustée et sans avertissement n'a pas de détail", async () => {
    renderDrawer(validated, { calculations: [calculation([line()])] });
    await screen.findByText("Ligament croisé antérieur", { exact: false });
    expect(screen.queryByTestId("drawer-finance-detail-1")).toBeNull();
    expect(screen.queryByText("ajustée")).toBeNull();
  });

  it("affiche l'avertissement tarif/politique porté par une ligne", async () => {
    renderDrawer(validated, {
      calculations: [calculation([line({ warnings: [{ code: "STALE_REPRESENTATIVE_PRESENCE_ANSWER", message: "Le délégué a été signalé présent mais cette politique n'affecte plus la facturation de cette prestation." }] })])],
    });
    const detail = await screen.findByTestId("drawer-finance-detail-1");
    expect(detail).toHaveTextContent("n'affecte plus la facturation");
    expect(detail).not.toHaveTextContent("Brut");
  });

  it("calcul obsolète : signalé, seul « Recalculer » est proposé", async () => {
    renderDrawer(validated, { calculations: [calculation([line()], { status: "APPROVED", obsolete: true, encodingReopenedAt: "2026-06-02T09:00:00+02:00" })] });
    expect(await screen.findByTestId("calculation-obsolete")).toHaveTextContent("Calcul obsolète");
    expect(screen.getByTestId("drawer-recalculate")).toBeInTheDocument();
    expect(screen.queryByTestId("drawer-lock-calculation")).toBeNull();
    expect(screen.queryByTestId("drawer-approve-calculation")).toBeNull();
  });

  it("D-172 — tarif modifié depuis le calcul : raison servie affichée, seul « Recalculer » est proposé", async () => {
    const reason = "Le tarif instrumentiste applicable au 01/10/2026 a changé depuis ce calcul (40.00 EUR → 65.00 EUR) : il doit être recalculé avant approbation ou verrouillage.";
    renderDrawer(validated, { calculations: [calculation([line()], { status: "CALCULATED", obsolete: false, tariffStaleReason: reason })] });
    expect(await screen.findByTestId("calculation-tariff-stale")).toHaveTextContent("40.00 EUR → 65.00 EUR");
    expect(screen.getByTestId("drawer-recalculate")).toBeInTheDocument();
    expect(screen.queryByTestId("drawer-approve-calculation")).toBeNull();
    expect(screen.queryByTestId("drawer-lock-calculation")).toBeNull();
  });

  it("calcul approuvé à jour : « Verrouiller le calcul » appelle POST /lock", async () => {
    const user = userEvent.setup();
    renderDrawer(validated, { calculations: [calculation([line()], { status: "APPROVED", obsolete: false })] });
    await user.click(await screen.findByTestId("drawer-lock-calculation"));
    expect(apiPostMock).toHaveBeenCalledWith("/api/financial-calculations/900/lock");
    expect(screen.queryByTestId("calculation-obsolete")).toBeNull();
  });

  it("D-171 — calcul sans heures : prestations firme facturables, prestation instrumentiste en attente", async () => {
    renderDrawer(validated, { calculations: [calculation([line()], { status: "LOCKED", instrumentistHoursPending: true, instrumentistSupplementAvailable: false })] });
    expect(await screen.findByTestId("drawer-instrumentist-hours-pending")).toHaveTextContent("les prestations firme sont facturables");
    expect(screen.queryByTestId("drawer-instrumentist-supplement")).toBeNull();
  });

  it("D-171 — heures déclarées après la facture : « Valoriser la prestation instrumentiste » appelle l'endpoint dédié", async () => {
    const user = userEvent.setup();
    renderDrawer(validated, { calculations: [calculation([line()], { status: "LOCKED", instrumentistHoursPending: true, instrumentistSupplementAvailable: true })] });
    await user.click(await screen.findByTestId("drawer-instrumentist-supplement"));
    expect(apiPostMock).toHaveBeenCalledWith("/api/missions/42/financial-calculations/instrumentist-supplement");
  });

  it("D-171 — complément calculé : affiché à part, à approuver, jamais confondu avec le calcul principal", async () => {
    const user = userEvent.setup();
    const main = calculation([line()], { status: "LOCKED", instrumentistHoursPending: true, instrumentistSupplementAvailable: false });
    const supplement = calculation([line()], { id: 901, version: 2, status: "CALCULATED", supplementOfCalculationId: main.id });
    renderDrawer(validated, { calculations: [main, supplement] });
    expect(await screen.findByTestId("drawer-instrumentist-supplement-status")).toHaveTextContent("complément v2");
    expect(screen.queryByTestId("drawer-instrumentist-hours-pending")).toBeNull();
    await user.click(screen.getByTestId("drawer-approve-supplement"));
    expect(apiPostMock).toHaveBeenCalledWith("/api/financial-calculations/901/approve");
  });

  it("aucun calcul : « Calculer la valorisation »", async () => {
    renderDrawer(validated, { calculations: [] });
    expect(await screen.findByTestId("drawer-calculate")).toHaveTextContent("Calculer la valorisation");
  });

  it("versions précédentes repliées, dépliables", async () => {
    const user = userEvent.setup();
    renderDrawer(validated, {
      calculations: [
        calculation([line()]),
        calculation([line(), line({ id: 2 })], { id: 899, version: 1, status: "SUPERSEDED", calculatedAt: "2026-05-30T08:00:00+02:00" }),
      ],
    });
    const toggle = await screen.findByRole("button", { name: /VERSIONS PRÉCÉDENTES/ });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByText("Remplacé", { exact: false })).toBeNull();
    await user.click(toggle);
    expect(screen.getByText("30/05 08:00")).toBeInTheDocument();
    expect(screen.getByText("Remplacé", { exact: false })).toHaveTextContent("v1 — Remplacé · 2 lignes");
  });
});

describe("ManagerMissionDrawer — clavier", () => {
  it("Échap ferme le tiroir sans remonter au conteneur (Drawer MUI de la fiche instrumentiste/chirurgien)", async () => {
    const user = userEvent.setup();
    const parentKeyDown = vi.fn();
    apiGetMock.mockImplementation((url: string) =>
      Promise.resolve({ data: url === "/api/missions/42" ? baseMission() : url.endsWith("/execution") ? NO_EXECUTION : [] }));
    const onClose = vi.fn();
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <div onKeyDown={parentKeyDown}>
            <button type="button">Élément de la fiche</button>
            <ManagerMissionDrawer missionId={42} onClose={onClose} />
          </div>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await loaded();
    await user.keyboard("{Escape}");
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(parentKeyDown).not.toHaveBeenCalled();
  });

  it("Échap ne remonte pas au conteneur même quand le focus est retombé hors du tiroir (bouton d'action disparu)", async () => {
    const user = userEvent.setup();
    const parentKeyDown = vi.fn();
    apiGetMock.mockImplementation((url: string) =>
      Promise.resolve({ data: url === "/api/missions/42" ? baseMission() : url.endsWith("/execution") ? NO_EXECUTION : [] }));
    const onClose = vi.fn();
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <div onKeyDown={parentKeyDown}>
            <button type="button">Élément de la fiche</button>
            <ManagerMissionDrawer missionId={42} onClose={onClose} />
          </div>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await loaded();
    screen.getByRole("button", { name: "Élément de la fiche" }).focus();
    await user.keyboard("{Escape}");
    expect(onClose).toHaveBeenCalledTimes(1);
    expect(parentKeyDown).not.toHaveBeenCalled();
  });
});

describe("ManagerMissionDrawer — terminer et valider (D-171)", () => {
  it("propose « Terminer et valider » quand le backend le sert, et appelle l'endpoint dédié", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({
      status: "ENCODING_IN_PROGRESS", allowedActions: ["view", "edit_encoding", "submit", "complete_and_validate"],
      executedWithoutInstrumentist: { declaredAt: "2026-08-10T12:00:00+02:00", declaredByName: "Jean Dupont" },
      encodingRevision: "rev-3", encodingTakeover: null,
    }));
    await user.click(await screen.findByTestId("manager-complete-and-validate"));
    await user.click(screen.getByTestId("confirm-complete-and-validate"));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/missions/42/encoding/complete-and-validate", { expectedRevision: "rev-3", takeoverConfirmed: false }));
  });

  it("reprise : la confirmation explicite est envoyée au backend", async () => {
    const user = userEvent.setup();
    renderDrawer(baseMission({
      status: "ENCODING_IN_PROGRESS", allowedActions: ["view", "edit_encoding", "complete_and_validate"],
      encodingRevision: "rev-4", encodingTakeover: { instrumentistName: "Marie Dupont", hoursMissing: false },
    }));
    await user.click(await screen.findByTestId("manager-complete-and-validate"));
    expect(screen.getByTestId("confirm-complete-and-validate")).toBeDisabled();
    await user.click(screen.getByTestId("complete-and-validate-takeover-confirm"));
    await user.click(screen.getByTestId("confirm-complete-and-validate"));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/missions/42/encoding/complete-and-validate", { expectedRevision: "rev-4", takeoverConfirmed: true }));
  });

  it("jamais sans l'action servie par le backend", async () => {
    renderDrawer(baseMission({ status: "ENCODING_IN_PROGRESS", allowedActions: ["view", "edit_encoding"] }));
    expect(await screen.findByTestId("manager-edit-encoding")).toBeInTheDocument();
    expect(screen.queryByTestId("manager-complete-and-validate")).toBeNull();
  });
});

describe("ManagerMissionDrawer — état d'encodage, auteur et verrous (D-172)", () => {
  it("affiche qui a finalisé l'encodage et la raison de chaque verrou servi", async () => {
    renderDrawer(baseMission({
      status: "VALIDATED",
      instrumentist: null,
      allowedActions: ["view"],
      encoding: {
        state: "VALIDATED", stateLabel: "Validé",
        finalizedBy: { name: "Sophie Manager", role: "MANAGER", at: "2026-10-23T08:00:00+02:00" },
        validatedBy: { name: "Sophie Manager", role: "MANAGER", at: "2026-10-23T08:00:00+02:00" },
        locks: [{ level: "ENCODING", label: "Encodage verrouillé", reason: "Encodage validé le 23/10/2026 : interventions, matériel et heures ne sont plus modifiables." }],
      },
    }), { calculations: [] });
    const notice = await screen.findByTestId("drawer-encoding-overview");
    expect(notice).toHaveTextContent("Encodage : validé");
    expect(notice).toHaveTextContent("Encodage finalisé par Sophie Manager (manager).");
    expect(screen.getByTestId("drawer-lock-ENCODING")).toHaveTextContent("ne sont plus modifiables");
    expect(screen.queryByTestId("drawer-lock-FINANCIAL_CALCULATION")).toBeNull();
  });

  it("mission passée sans instrumentiste non déclarée : « Aucun encodage », jamais présentée comme réalisée", async () => {
    renderDrawer(baseMission({
      status: "OPEN", instrumentist: null, allowedActions: ["view"],
      encoding: { state: "NOT_DECLARED", stateLabel: "Aucun encodage", finalizedBy: null, validatedBy: null, locks: [] },
    }), { calculations: [] });
    const notice = await screen.findByTestId("drawer-encoding-overview");
    expect(notice).toHaveTextContent("Encodage : aucun encodage");
    expect(notice).toHaveTextContent("aucune activité n'a été déclarée réalisée");
    expect(screen.getAllByText("Non couverte").length).toBeGreaterThan(0);
    expect(screen.queryByText("Publiée")).toBeNull();
  });
});
