import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import MissionsListPage from "./MissionsListPage";
import type { Mission } from "../../features/missions/api/missions.types";

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn(),
  fetchSites: vi.fn().mockResolvedValue([{ id: 1, name: "CHIREC Delta" }, { id: 2, name: "Clinique Nord" }]),
  fetchMissionById: vi.fn(),
  getMissionExecution: vi.fn().mockResolvedValue({
    missionId: 42, hasExecutionRecord: false, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null,
    hoursSource: null, effectiveDurationMinutes: 240, effectiveDurationSource: "PLANNED_FALLBACK", disputes: [],
  }),
  approveDeclaredMission: vi.fn(),
  cancelMission: vi.fn(),
  cancelExecutedWithoutInstrumentist: vi.fn(),
  declareExecutedWithoutInstrumentist: vi.fn(),
  rejectDeclaredMission: vi.fn(),
}));

vi.mock("../../features/encoding/api/encoding.api", () => ({
  fetchMissionEncoding: vi.fn().mockResolvedValue({ entries: [] }),
  rejectMissionEncoding: vi.fn(),
  reopenMissionEncoding: vi.fn(),
  validateMissionEncoding: vi.fn(),
}));

vi.mock("../../features/planning-v2/api/planningV2.api", () => ({
  fetchMissionAudit: vi.fn().mockResolvedValue([]),
}));

vi.mock("../../features/encoding-anomaly-reports/components/AnomalyReportsManagerPanel", () => ({
  AnomalyReportsManagerPanel: () => null,
}));

vi.mock("../../features/missions/components/EditMissionDialog", () => ({ default: () => null }));
vi.mock("../../features/missions/components/PublishMissionDialog", () => ({ default: () => null }));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}));

vi.mock("../../features/manager-mission-requests/api/managerSurgeonMissionRequests.api", () => ({
  getSurgeonMissionRequestCounts: vi.fn().mockResolvedValue({ PENDING: 3, ACCEPTED: 0, REJECTED: 0 }),
  getSurgeonMissionRequests: vi.fn().mockResolvedValue({ items: [], total: 0 }),
  acceptSurgeonMissionRequest: vi.fn(),
  rejectSurgeonMissionRequest: vi.fn(),
}));

import { fetchMissionById, fetchMissions } from "../../features/missions/api/missions.api";

function mission(id: number, startAt: string, overrides: Partial<Mission> = {}): Mission {
  return {
    id,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startAt,
    endAt: startAt.replace(/T(\d\d)/, (_, h) => `T${String(Number(h) + 4).padStart(2, "0")}`),
    site: { id: 1, name: "CHIREC Delta" },
    status: "ASSIGNED",
    surgeon: { id: 5, firstname: "Arnaud", lastname: "Deltour", email: "a@x.be" },
    instrumentist: { id: 9, firstname: "Salve", lastname: "Decorte", email: "s@x.be" },
    ...overrides,
  } as Mission;
}

let lastSearch = "";
function LocationProbe() {
  lastSearch = useLocation().search;
  return null;
}

function renderPage(initialEntry = "/app/m/missions") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <Routes>
          {["/app/m/missions", "/app/m/missions/to-validate", "/app/m/missions/requests"].map((path) => (
            <Route key={path} path={path} element={<><MissionsListPage /><LocationProbe /></>} />
          ))}
          <Route path="/app/m/missions/:id" element={<div>detail</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function lastFilters() {
  const calls = vi.mocked(fetchMissions).mock.calls;
  return calls[calls.length - 1][2]!;
}

beforeEach(() => {
  vi.mocked(fetchMissions).mockReset();
  vi.mocked(fetchMissions).mockResolvedValue({ items: [], total: 0 } as never);
});

describe("MissionsListPage — vue manager (D-147)", () => {
  it("par défaut : missions à venir (from = aujourd'hui), les plus proches d'abord", async () => {
    renderPage();

    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    const filters = lastFilters();
    expect(filters.order).toBe("asc");
    const today = new Date();
    expect(new Date(filters.from!).getTime()).toBe(new Date(today.getFullYear(), today.getMonth(), today.getDate()).getTime());
    expect(filters).not.toHaveProperty("to");
  });

  it("regroupe les missions par jour avec un en-tête et un compteur", async () => {
    vi.mocked(fetchMissions).mockResolvedValue({
      items: [
        mission(1, "2026-10-14T08:00:00+02:00"),
        mission(2, "2026-10-14T13:00:00+02:00", { instrumentist: null, status: "OPEN" }),
        mission(3, "2026-10-15T08:00:00+02:00"),
      ],
      total: 3,
    } as never);
    renderPage();

    const day1 = await screen.findByRole("region", { name: "Mercredi 14 octobre 2026" });
    expect(within(day1).getByText("2 missions")).toBeInTheDocument();
    expect(within(day1).getAllByTestId("mission-row")).toHaveLength(2);
    expect(within(day1).getByText("Aucune instrumentiste")).toBeInTheDocument();
    expect(within(day1).getByText("08:00 – 12:00")).toBeInTheDocument();

    const day2 = screen.getByRole("region", { name: "Jeudi 15 octobre 2026" });
    expect(within(day2).getByText("1 mission")).toBeInTheDocument();
    expect(screen.getByTestId("missions-total")).toHaveTextContent("3 missions");
  });

  it("recherche dynamique : la saisie part au serveur (q) après une courte pause et va dans l'URL", async () => {
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());

    await user.type(screen.getByPlaceholderText(/Chirurgien, instrumentiste/), "deltour");

    await waitFor(() => expect(lastFilters().q).toBe("deltour"));
    // Debounced: no request per keystroke.
    expect(vi.mocked(fetchMissions).mock.calls.filter(([, , f]) => f?.q && f.q !== "deltour")).toHaveLength(0);
    await waitFor(() => expect(lastSearch).toContain("q=deltour"));
  });

  it("filtres période / statut / site transmis au serveur", async () => {
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());

    await user.click(screen.getByRole("button", { name: "Passées" }));
    await waitFor(() => expect(lastFilters().order).toBe("desc"));
    expect(lastFilters()).not.toHaveProperty("from");

    await user.click(screen.getByLabelText("Statut"));
    await user.click(await screen.findByRole("option", { name: "Publiée" }));
    await waitFor(() => expect(lastFilters().status).toBe("OPEN"));

    await user.click(screen.getByLabelText("Site"));
    await user.click(await screen.findByRole("option", { name: "Clinique Nord" }));
    await waitFor(() => expect(lastFilters().siteId).toBe(2));
    expect(lastSearch).toContain("period=past");
  });

  it("filtre Statut : mêmes mots que les pastilles, statut backend envoyé tel quel", async () => {
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());

    // Une mission SUBMITTED s'affiche « À valider » : le filtre du même nom envoie SUBMITTED.
    await user.click(screen.getByLabelText("Statut"));
    await user.click(await screen.findByRole("option", { name: "À valider" }));
    await waitFor(() => expect(lastFilters().status).toBe("SUBMITTED"));

    await user.click(screen.getByLabelText("Statut"));
    await user.click(await screen.findByRole("option", { name: "Encodage en cours" }));
    await waitFor(() => expect(lastFilters().status).toBe("ENCODING_IN_PROGRESS"));

    await user.click(screen.getByLabelText("Statut"));
    await user.click(await screen.findByRole("option", { name: "Déclarée" }));
    await waitFor(() => expect(lastFilters().status).toBe("DECLARED"));
    expect(screen.queryByRole("option", { name: "Soumise" })).toBeNull();
  });

  it("les filtres de l'URL sont repris au chargement", async () => {
    renderPage("/app/m/missions?q=nord&period=all&status=OPEN&siteId=2");

    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    expect(lastFilters()).toMatchObject({ q: "nord", status: "OPEN", siteId: 2, order: "desc" });
    expect(screen.getByPlaceholderText(/Chirurgien, instrumentiste/)).toHaveValue("nord");
  });

  it("onglet « À approuver » : statut DECLARED imposé, toutes dates ; « À valider » reste réservé aux encodages soumis", async () => {
    renderPage("/app/m/missions/to-validate");

    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    expect(screen.getByRole("tab", { name: "À approuver" })).toHaveAttribute("aria-selected", "true");
    expect(screen.queryByRole("tab", { name: "À valider" })).toBeNull();
    expect(lastFilters()).toMatchObject({ status: "DECLARED", order: "desc" });
    expect(lastFilters()).not.toHaveProperty("from");
    expect(screen.queryByLabelText("Statut")).not.toBeInTheDocument();
  });

  it("cliquer une mission ouvre son détail en tiroir, la liste reste montée et la ligne est surlignée", async () => {
    const m = mission(42, "2026-10-14T08:00:00+02:00", { allowedActions: ["cancel"] });
    vi.mocked(fetchMissions).mockResolvedValue({ items: [m], total: 1 } as never);
    vi.mocked(fetchMissionById).mockResolvedValue(m);
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByTestId("mission-row"));

    const drawer = await screen.findByRole("dialog", { name: "Mission #42" });
    expect(drawer).toHaveClass("is-open");
    await waitFor(() => expect(lastSearch).toContain("mission=42"));
    expect(screen.queryByText("detail")).not.toBeInTheDocument();
    expect(screen.getByTestId("mission-row")).toHaveAttribute("aria-current", "true");
    // Actions servies par allowedActions uniquement.
    expect(await within(drawer).findByRole("button", { name: "Annuler la mission" })).toBeInTheDocument();
    expect(within(drawer).queryByRole("button", { name: /Approuver/ })).not.toBeInTheDocument();
  });

  it("Échap ferme le tiroir et retire la mission de l'URL", async () => {
    const m = mission(42, "2026-10-14T08:00:00+02:00");
    vi.mocked(fetchMissions).mockResolvedValue({ items: [m], total: 1 } as never);
    vi.mocked(fetchMissionById).mockResolvedValue(m);
    const user = userEvent.setup();
    renderPage("/app/m/missions?mission=42");

    const drawer = await screen.findByRole("dialog", { name: "Mission #42", hidden: true });
    expect(drawer).toHaveClass("is-open");
    await user.keyboard("{Escape}");
    await waitFor(() => expect(lastSearch).not.toContain("mission="));
    expect(drawer).not.toHaveClass("is-open");
  });

  it("le badge « Demandes chirurgien » lit les compteurs partagés", async () => {
    renderPage();
    expect(await screen.findByText("3")).toBeInTheDocument();
  });
});

describe("MissionsListPage — annulées, état d'encodage et verrous (D-172)", () => {
  it("annulées masquées par défaut côté serveur, compteur explicite, interrupteur pour les afficher", async () => {
    const user = userEvent.setup();
    vi.mocked(fetchMissions).mockResolvedValue({ items: [mission(1, "2026-10-22T07:45:00+02:00")], total: 1, cancelledExcluded: 3 } as never);
    renderPage();

    await waitFor(() => expect(lastFilters().excludeCancelled).toBe(true));
    expect(await screen.findByTestId("missions-total")).toHaveTextContent("1 mission · annulées exclues (3 masquées)");

    vi.mocked(fetchMissions).mockResolvedValue({
      items: [mission(1, "2026-10-22T07:45:00+02:00"), mission(2, "2026-10-22T13:00:00+02:00", { status: "CANCELLED" })], total: 2, cancelledExcluded: null,
    } as never);
    await user.click(screen.getByLabelText("Afficher les missions annulées"));
    await waitFor(() => expect(lastFilters()).not.toHaveProperty("excludeCancelled"));
    expect(lastSearch).toContain("cancelled=1");
    expect(await screen.findByText("Annulée")).toBeInTheDocument();
    expect(screen.getByTestId("missions-total")).toHaveTextContent("2 missions · annulées incluses");
  });

  it("un statut choisi explicitement prime sur le masquage", async () => {
    renderPage("/app/m/missions?status=CANCELLED");
    await waitFor(() => expect(lastFilters().status).toBe("CANCELLED"));
    expect(lastFilters()).not.toHaveProperty("excludeCancelled");
    expect(screen.queryByTestId("filter-show-cancelled")).toBeNull();
  });

  it("filtre « Sans instrumentiste » transmis au serveur, combiné aux autres", async () => {
    const user = userEvent.setup();
    renderPage("/app/m/missions?period=past&siteId=1");
    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    await user.click(screen.getByLabelText("Sans instrumentiste"));
    await waitFor(() => expect(lastFilters().withoutInstrumentist).toBe(true));
    expect(lastFilters().siteId).toBe(1);
    expect(lastFilters().excludeCancelled).toBe(true);
  });

  it("une seule pastille par mission, ligne grise, aucun cadenas (maquette Statut missions § 2a)", async () => {
    vi.mocked(fetchMissions).mockResolvedValue({
      items: [
        mission(1079, "2026-10-22T07:45:00+02:00", { status: "OPEN", instrumentist: null, encoding: { state: "NOT_DECLARED", stateLabel: "Aucun encodage", finalizedBy: null, validatedBy: null, locks: [] } }),
        mission(1080, "2026-10-22T13:00:00+02:00", { status: "VALIDATED", encoding: {
          state: "VALIDATED", stateLabel: "Validé", finalizedBy: { name: "Sophie Manager", role: "MANAGER", at: "2026-10-23T08:00:00+02:00" }, validatedBy: null,
          locks: [{ level: "ENCODING", label: "Encodage verrouillé", reason: "Encodage validé le 23/10/2026 : …" }, { level: "FINANCIAL_CALCULATION", label: "Calcul financier verrouillé", reason: "Calcul financier verrouillé le 24/10/2026 : ses montants sont définitifs." }],
        } }),
      ],
      total: 2,
      cancelledExcluded: 0,
    } as never);
    renderPage();

    const rows = await screen.findAllByTestId("mission-row");
    const open = rows.find((r) => r.getAttribute("data-mission-id") === "1079")!;
    // Passée, sans instrumentiste, rien de déclaré : « Non couverte », jamais « Publiée ».
    expect(within(open).getByText("Non couverte")).toBeInTheDocument();
    expect(within(open).queryByText("Publiée")).toBeNull();
    expect(within(open).getByText("Aucun instrumentiste assigné")).toBeInTheDocument();
    expect(within(open).getByTestId("mission-status")).toHaveAttribute("data-status-key", "noncouverte");
    expect(within(open).queryByText(/^Encodage :/)).toBeNull();

    const validated = rows.find((r) => r.getAttribute("data-mission-id") === "1080")!;
    expect(within(validated).getByText("Validée")).toBeInTheDocument();
    // Verrou réel servi, le plus restrictif, avec son libellé backend : écrit dans la ligne grise,
    // plus de second badge ni de cadenas ; jamais confondu avec le verrou d'encodage.
    expect(within(validated).getByText("Calcul financier verrouillé")).toBeInTheDocument();
    expect(within(validated).queryByText(/^Encodage verrouillé/)).toBeNull();
    expect(within(validated).queryByText(/^Encodage :/)).toBeNull();
    expect(within(validated).queryByTestId("mission-lock")).toBeNull();
    expect(within(validated).getAllByTestId("mission-status")).toHaveLength(1);
  });
});

describe("MissionsListPage — activité sans instrumentiste encodée par le chirurgien (D-172)", () => {
  const surgeonActor = { name: "Dr Arnaud Deltour", role: "SURGEON" as const, at: "2026-10-23T09:00:00+02:00" };
  it("B en cours, C soumise par le chirurgien (« À valider »), C validée", async () => {
    vi.mocked(fetchMissions).mockResolvedValue({
      items: [
        mission(1, "2026-10-22T07:45:00+02:00", { status: "ENCODING_IN_PROGRESS", instrumentist: null, encoding: { state: "IN_PROGRESS", stateLabel: "En cours", executedWithoutInstrumentist: true, interventionCount: 2, materialLineCount: 2, finalizedBy: null, validatedBy: null, locks: [] } }),
        mission(2, "2026-10-21T07:45:00+02:00", { status: "SUBMITTED", instrumentist: null, encoding: { state: "SUBMITTED", stateLabel: "Soumis", executedWithoutInstrumentist: true, interventionCount: 2, materialLineCount: 2, finalizedBy: surgeonActor, validatedBy: null, locks: [] } }),
        mission(3, "2026-10-20T07:45:00+02:00", { status: "VALIDATED", instrumentist: null, encoding: { state: "VALIDATED", stateLabel: "Validé", executedWithoutInstrumentist: true, interventionCount: 1, materialLineCount: 1, finalizedBy: surgeonActor, validatedBy: null, locks: [{ level: "ENCODING", label: "Encodage verrouillé", reason: "Encodage validé." }] } }),
      ],
      total: 3,
      cancelledExcluded: 0,
    } as never);
    renderPage();
    const rows = await screen.findAllByTestId("mission-row");
    const byId = (id: number) => rows.find((r) => r.getAttribute("data-mission-id") === String(id))!;

    expect(within(byId(1)).getByText("Encodage en cours")).toBeInTheDocument();
    expect(within(byId(1)).queryByText(/par le chirurgien/)).toBeNull();

    expect(within(byId(2)).getByText("À valider")).toBeInTheDocument();
    expect(within(byId(2)).getByText("Soumise par le chirurgien le 23/10 à 09h00")).toBeInTheDocument();

    expect(within(byId(3)).getByText("Validée")).toBeInTheDocument();
    expect(within(byId(3)).getByText("Encodage verrouillé")).toBeInTheDocument();
    // D-172 — la finalisation par le chirurgien reste dite une fois la mission validée.
    expect(within(byId(3)).getByText("Encodage terminé par le chirurgien")).toBeInTheDocument();
    expect(within(byId(3)).getAllByTestId("mission-status")).toHaveLength(1);
  });
});
