import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import EncodingTrackingPage from "./EncodingTrackingPage";
import type { EncodingTrackingItem, EncodingTrackingResponse, EncodingTrackingSummary } from "../../../features/encoding-tracking/api/encodingTracking.api";

const navigateMock = vi.fn();
vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => navigateMock };
});

const getEncodingTrackingMock = vi.fn();
const getEncodingTrackingSummaryMock = vi.fn();
vi.mock("../../../features/encoding-tracking/api/encodingTracking.api", async () => {
  const actual = await vi.importActual<typeof import("../../../features/encoding-tracking/api/encodingTracking.api")>(
    "../../../features/encoding-tracking/api/encodingTracking.api",
  );
  return {
    ...actual,
    getEncodingTracking: (...args: unknown[]) => getEncodingTrackingMock(...args),
    getEncodingTrackingSummary: (...args: unknown[]) => getEncodingTrackingSummaryMock(...args),
  };
});

const apiClientGetMock = vi.fn();
vi.mock("../../../api/apiClient", () => ({
  apiClient: { get: (...args: unknown[]) => apiClientGetMock(...args) },
}));

vi.mock("../../../features/manager-surgeons/api/surgeons.api", () => ({
  getSurgeons: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));

function emptySummary(overrides: Partial<EncodingTrackingSummary> = {}): EncodingTrackingSummary {
  return {
    totalMissions: 0, encodingExpected: 0, upcoming: 0, toEncode: 0, inProgress: 0,
    submitted: 0, validated: 0, locked: 0, notApplicable: 0, staleInProgress: 0,
    financialAnomalies: 0, encoded: 0, missingEncoding: 0, toTreat: 0,
    hasFinanciallyEligibleMissions: false,
    ...overrides,
  };
}

function makeItem(overrides: Partial<EncodingTrackingItem> = {}): EncodingTrackingItem {
  return {
    missionId: 1,
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
    encoding: { interventionCount: 2, materialLineCount: 6, submittedWithoutMaterial: false, hasNoMaterialJustification: false, isStale: false },
    financial: { state: "NOT_CALCULABLE", label: "Pas encore calculable", isBlocking: false },
    ...overrides,
  };
}

function makeResponse(items: EncodingTrackingItem[], summary: EncodingTrackingSummary, total?: number): EncodingTrackingResponse {
  return {
    period: { from: "2026-09-10T00:00:00+02:00", to: "2026-09-11T00:00:00+02:00" },
    summary,
    items,
    total: total ?? items.length,
    page: 1,
    limit: 50,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <EncodingTrackingPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  navigateMock.mockReset();
  getEncodingTrackingMock.mockReset();
  getEncodingTrackingSummaryMock.mockReset();
  apiClientGetMock.mockReset();
  apiClientGetMock.mockResolvedValue({ data: [] });
});

describe("EncodingTrackingPage — rendu de base", () => {
  it("affiche le titre de la page et les KPI depuis /summary, sans les recalculer", async () => {
    const summary = emptySummary({
      totalMissions: 10, encodingExpected: 10, upcoming: 3, toEncode: 1, inProgress: 2,
      submitted: 4, validated: 1, locked: 0, staleInProgress: 0, financialAnomalies: 5,
      encoded: 5, missingEncoding: 1, toTreat: 10, hasFinanciallyEligibleMissions: true,
    });
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([makeItem()], summary));

    renderPage();

    expect(screen.getByText("Suivi des encodages")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText("10")).toBeInTheDocument()); // Missions (encodingExpected)
    expect(screen.getByText("7")).toBeInTheDocument(); // Terminées (encodingExpected - upcoming)
    expect(screen.getByText("5")).toBeInTheDocument(); // Anomalies (financialAnomalies)
    expect(screen.getByText("4")).toBeInTheDocument(); // Soumises (submitted)

    // Les valeurs proviennent de getEncodingTrackingSummaryMock — jamais recalculées à
    // partir de `items` (un seul appel au résumé, indépendant du nombre d'items reçus).
    expect(getEncodingTrackingSummaryMock).toHaveBeenCalled();
  });

  it("affiche un état de chargement avant résolution des requêtes", () => {
    getEncodingTrackingSummaryMock.mockReturnValue(new Promise(() => {}));
    getEncodingTrackingMock.mockReturnValue(new Promise(() => {}));

    renderPage();

    expect(screen.getAllByText("—").length).toBeGreaterThan(0);
  });

  it("affiche un état d'erreur explicite si l'API échoue, sans planter la page", async () => {
    getEncodingTrackingSummaryMock.mockRejectedValue(new Error("network error"));
    getEncodingTrackingMock.mockRejectedValue(new Error("network error"));

    renderPage();

    expect(screen.getByText("Suivi des encodages")).toBeInTheDocument();
    await waitFor(() => expect(screen.getByText("Impossible de charger le suivi des encodages")).toBeInTheDocument());
  });
});

describe("EncodingTrackingPage — empty states distincts", () => {
  it("« aucune mission sur la période » quand total = 0", async () => {
    const summary = emptySummary();
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([], summary, 0));

    renderPage();

    await waitFor(() => expect(screen.getByText("Aucune mission sur cette période")).toBeInTheDocument());
  });

  it("« aucune mission ne correspond aux filtres » quand des missions existent mais la page est vide", async () => {
    const summary = emptySummary({ totalMissions: 5, encodingExpected: 5 });
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([], summary, 5));

    renderPage();

    await waitFor(() => expect(screen.getByText("Aucune mission ne correspond aux filtres actuels")).toBeInTheDocument());
  });
});

describe("EncodingTrackingPage — états d'encodage & finance", () => {
  it("affiche le badge d'état d'encodage et l'état financier synthétique tels que fournis par le backend", async () => {
    const summary = emptySummary({ totalMissions: 1, encodingExpected: 1, submitted: 1 });
    const item = makeItem({ encodingState: "SUBMITTED", financial: { state: "ANOMALY", label: "Anomalie", isBlocking: true } });
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([item], summary));

    renderPage();

    await waitFor(() => expect(screen.getByText("Soumis")).toBeInTheDocument());
    expect(screen.getByText("Anomalie")).toBeInTheDocument();
  });

  it("affiche planifié + effectif + source quand des heures réelles existent", async () => {
    const summary = emptySummary({ totalMissions: 1, encodingExpected: 1 });
    const item = makeItem({ hours: { plannedMinutes: 300, effectiveMinutes: 312, effectiveSource: "ACTUAL_TIMES", hasRealHours: true } });
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([item], summary));

    renderPage();

    await waitFor(() => expect(screen.getByText(/5 h planifiées · 5 h 12 effectives/)).toBeInTheDocument());
    expect(screen.getByText("Heures réelles")).toBeInTheDocument();
  });

  it("n'affiche jamais d'heures « effectives » quand la source est PLANNED (pas de champ encodedMinutes fantôme)", async () => {
    const summary = emptySummary({ totalMissions: 1, encodingExpected: 1 });
    const item = makeItem({ hours: { plannedMinutes: 180, effectiveMinutes: 180, effectiveSource: "PLANNED", hasRealHours: false } });
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([item], summary));

    renderPage();

    await waitFor(() => expect(screen.getByText(/3 h planifiées/)).toBeInTheDocument());
    expect(screen.queryByText(/effectives/)).toBeNull();
    expect(screen.getByText("Planifié")).toBeInTheDocument();
  });

  it("ne masque jamais une heure réelle au motif que la mission n'est pas SUBMITTED", async () => {
    const summary = emptySummary({ totalMissions: 1, encodingExpected: 1, inProgress: 1 });
    const item = makeItem({
      missionStatus: "ENCODING_IN_PROGRESS",
      encodingState: "IN_PROGRESS",
      hours: { plannedMinutes: 120, effectiveMinutes: 145, effectiveSource: "ACTUAL_EXPLICIT", hasRealHours: true },
    });
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([item], summary));

    renderPage();

    await waitFor(() => expect(screen.getByText(/2 h planifiées · 2 h 25 effectives/)).toBeInTheDocument());
  });
});

describe("EncodingTrackingPage — ouverture mission", () => {
  it("navigue vers la fiche mission existante au clic sur une ligne", async () => {
    const user = userEvent.setup();
    const summary = emptySummary({ totalMissions: 1, encodingExpected: 1 });
    const item = makeItem({ missionId: 42 });
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([item], summary));

    renderPage();

    await waitFor(() => expect(screen.getByText("Ouvrir")).toBeInTheDocument());
    await user.click(screen.getByText("Ouvrir"));

    expect(navigateMock).toHaveBeenCalledWith("/app/m/missions/42");
  });
});

describe("EncodingTrackingPage — navigation temporelle", () => {
  it("Aujourd'hui / Hier / 7 jours / Ce mois redéclenchent une requête avec une nouvelle période", async () => {
    const user = userEvent.setup();
    const summary = emptySummary();
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([], summary, 0));

    renderPage();
    await waitFor(() => expect(getEncodingTrackingSummaryMock).toHaveBeenCalledTimes(1));

    await user.click(screen.getByText("Hier"));
    await waitFor(() => expect(getEncodingTrackingSummaryMock).toHaveBeenCalledTimes(2));

    await user.click(screen.getByText("7 jours"));
    await waitFor(() => expect(getEncodingTrackingSummaryMock).toHaveBeenCalledTimes(3));

    await user.click(screen.getByText("Ce mois"));
    await waitFor(() => expect(getEncodingTrackingSummaryMock).toHaveBeenCalledTimes(4));

    // Pas de bouton "Appliquer" : chaque clic déclenche directement le refetch.
    expect(screen.queryByText("Appliquer")).toBeNull();
  });
});

describe("EncodingTrackingPage — vue « À traiter »", () => {
  it("regroupe encodages manquants, en cours en retard, à valider et anomalies sans recomposer la règle métier", async () => {
    const user = userEvent.setup();
    const summary = emptySummary({ totalMissions: 4, encodingExpected: 4, toTreat: 4 });
    const toEncode = makeItem({ missionId: 1, encodingState: "TO_ENCODE" });
    const stale = makeItem({ missionId: 2, encodingState: "IN_PROGRESS", encoding: { ...makeItem().encoding, isStale: true } });
    const toValidate = makeItem({ missionId: 3, encodingState: "SUBMITTED" });
    const anomaly = makeItem({ missionId: 4, encodingState: "VALIDATED", financial: { state: "ANOMALY", label: "Anomalie", isBlocking: true } });

    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([toEncode, stale, toValidate, anomaly], summary));

    renderPage();
    await waitFor(() => expect(screen.getByText("À traiter")).toBeInTheDocument());
    await user.click(screen.getByText("À traiter"));

    await waitFor(() => expect(screen.getByText("Encodages manquants — 1")).toBeInTheDocument());
    expect(screen.getByText("En cours anormalement longtemps — 1")).toBeInTheDocument();
    expect(screen.getByText("À valider — 1")).toBeInTheDocument();
    expect(screen.getByText("Anomalies financières — 1")).toBeInTheDocument();
  });

  it("affiche un état « rien à traiter » quand aucune catégorie n'est non vide", async () => {
    const user = userEvent.setup();
    const summary = emptySummary({ totalMissions: 1, encodingExpected: 1, validated: 1 });
    const validated = makeItem({ encodingState: "VALIDATED" });
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([validated], summary));

    renderPage();
    await user.click(screen.getByText("À traiter"));

    await waitFor(() => expect(screen.getByText("Rien à traiter sur cette période")).toBeInTheDocument());
  });
});

describe("EncodingTrackingPage — vue « Par instrumentiste »", () => {
  it("regroupe les missions par instrumentiste avec des compteurs et un temps effectif total", async () => {
    const user = userEvent.setup();
    const summary = emptySummary({ totalMissions: 2, encodingExpected: 2, submitted: 2 });
    const items = [
      makeItem({ missionId: 1, instrumentist: { id: 5, name: "Salve Decorte" }, hours: { plannedMinutes: 60, effectiveMinutes: 60, effectiveSource: "ACTUAL_TIMES", hasRealHours: true } }),
      makeItem({ missionId: 2, instrumentist: { id: 5, name: "Salve Decorte" }, hours: { plannedMinutes: 60, effectiveMinutes: 90, effectiveSource: "ACTUAL_TIMES", hasRealHours: true } }),
    ];
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse(items, summary));

    renderPage();
    await user.click(screen.getByText("Par instrumentiste"));

    await waitFor(() => expect(screen.getByText("Salve Decorte")).toBeInTheDocument());
    const card = screen.getByText("Salve Decorte").closest("div")!.parentElement!;
    expect(within(card).getByText("2 h 30")).toBeInTheDocument(); // 60+90 minutes = 150 min = 2h30
  });
});

describe("EncodingTrackingPage — filtres", () => {
  it("répercute le changement de filtre instrumentiste dans une nouvelle requête", async () => {
    apiClientGetMock.mockImplementation((url: string) => {
      if (url === "/api/instrumentists") return Promise.resolve({ data: { items: [{ id: 9, displayName: "Perrine Pineux" }] } });
      return Promise.resolve({ data: [] });
    });

    const summary = emptySummary();
    getEncodingTrackingSummaryMock.mockResolvedValue({ period: { from: "", to: "" }, summary });
    getEncodingTrackingMock.mockResolvedValue(makeResponse([], summary, 0));

    renderPage();
    await waitFor(() => expect(getEncodingTrackingSummaryMock).toHaveBeenCalledTimes(1));

    const user = userEvent.setup();
    await user.click(screen.getByText("Tous les instrumentistes"));
    await user.click(await screen.findByText("Perrine Pineux"));

    await waitFor(() => expect(getEncodingTrackingSummaryMock).toHaveBeenCalledTimes(2));
    const lastCallFilter = getEncodingTrackingSummaryMock.mock.calls[1][0];
    expect(lastCallFilter.instrumentistId).toBe(9);
  });
});
