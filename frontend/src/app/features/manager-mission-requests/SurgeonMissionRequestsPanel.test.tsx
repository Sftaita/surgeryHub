import { describe, it, expect, vi, beforeEach } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import SurgeonMissionRequestsPanel from "./SurgeonMissionRequestsPanel";
import type { ManagerSurgeonMissionRequest } from "./api/managerSurgeonMissionRequests.types";

const getSurgeonMissionRequestsMock = vi.fn();
const acceptSurgeonMissionRequestMock = vi.fn();
const rejectSurgeonMissionRequestMock = vi.fn();
const getCountsMock = vi.fn();

vi.mock("./api/managerSurgeonMissionRequests.api", () => ({
  getSurgeonMissionRequests: (...args: unknown[]) => getSurgeonMissionRequestsMock(...args),
  acceptSurgeonMissionRequest: (...args: unknown[]) => acceptSurgeonMissionRequestMock(...args),
  rejectSurgeonMissionRequest: (...args: unknown[]) => rejectSurgeonMissionRequestMock(...args),
  getSurgeonMissionRequestCounts: (...args: unknown[]) => getCountsMock(...args),
}));

const fetchHistoryMock = vi.fn();
vi.mock("../audit-journal/api/auditJournal.api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../audit-journal/api/auditJournal.api")>()),
  fetchSurgeonMissionRequestHistory: (...args: unknown[]) => fetchHistoryMock(...args),
}));

// D-125 — the dispatch picker loads the site's instrumentists (backend eligibility).
const apiGetMock = vi.fn();
vi.mock("../../api/apiClient", () => ({
  apiClient: { get: (...args: unknown[]) => apiGetMock(...args), post: vi.fn() },
}));

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

function makeRequest(overrides: Partial<ManagerSurgeonMissionRequest> = {}): ManagerSurgeonMissionRequest {
  return {
    id: 1,
    surgeon: { id: 5, displayName: "Arnaud Deltour" },
    site: { id: 1, name: "CHIREC - Hôpital Delta" },
    type: "BLOCK",
    startAt: "2026-09-10T08:00:00+02:00",
    endAt: "2026-09-10T13:00:00+02:00",
    comment: "Bloc supplémentaire",
    status: "PENDING",
    createdAt: "2026-09-01T10:00:00+02:00",
    reviewedBy: null,
    reviewedAt: null,
    reviewComment: null,
    createdMissionId: null,
    ...overrides,
  };
}

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SurgeonMissionRequestsPanel />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  getSurgeonMissionRequestsMock.mockReset();
  acceptSurgeonMissionRequestMock.mockReset();
  rejectSurgeonMissionRequestMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
  apiGetMock.mockReset();
  getCountsMock.mockReset();
  getCountsMock.mockResolvedValue({ PENDING: 1, ACCEPTED: 4, REJECTED: 2 });
  fetchHistoryMock.mockReset();
  apiGetMock.mockResolvedValue({
    data: {
      candidates: [
        { id: 41, name: "Salve Decorte", email: "salve@x.be", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
        { id: 42, name: "Anne Absente", email: "anne@x.be", eligible: false, selectable: false, reasons: ["ABSENT"], unavailability: { type: "ABSENCE", dateStart: "2026-09-10", dateEnd: "2026-09-10" }, conflict: null },
      ],
    },
  });
});

describe("SurgeonMissionRequestsPanel — liste", () => {
  it("affiche les demandes PENDING par défaut", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    renderPanel();

    expect(await screen.findByText("Dr Arnaud Deltour")).toBeInTheDocument();
    expect(screen.getByText("CHIREC - Hôpital Delta")).toBeInTheDocument();
    expect(getSurgeonMissionRequestsMock).toHaveBeenCalledWith("PENDING");
  });

  it("aucune demande → empty state", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [], total: 0 });
    renderPanel();

    expect(await screen.findByText("Aucune demande.")).toBeInTheDocument();
  });

  it("bascule sur l'onglet Refusées relance la requête avec le bon statut", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [], total: 0 });
    const user = userEvent.setup();
    renderPanel();

    await screen.findByText("Aucune demande.");
    await user.click(screen.getByText("Refusées"));
    await waitFor(() => expect(getSurgeonMissionRequestsMock).toHaveBeenCalledWith("REJECTED"));
  });
});

describe("SurgeonMissionRequestsPanel — détail et acceptation", () => {
  it("le détail de la demande (site, horaires, type, commentaire) est visible avant d'accepter", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByText("Accepter"));
    expect(await screen.findByText("Demande du Dr Arnaud Deltour")).toBeInTheDocument();
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/Bloc supplémentaire/)).toBeInTheDocument();
  });

  it("accepter crée la mission et invalide la liste", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    acceptSurgeonMissionRequestMock.mockResolvedValue(makeRequest({ status: "ACCEPTED", createdMissionId: 999 }));
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByText("Accepter"));
    await screen.findByText("Demande du Dr Arnaud Deltour");
    await user.click(screen.getByText("Accepter et créer"));

    // D-125 — default choice: proposer au pool.
    await waitFor(() => expect(acceptSurgeonMissionRequestMock).toHaveBeenCalledWith(1, undefined, { mode: "POOL", instrumentistId: null }));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Demande acceptée. Mission créée et proposée au pool."));
  });

  it("D-125 — accepter en attribuant directement : instrumentiste du site choisie par son nom", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    acceptSurgeonMissionRequestMock.mockResolvedValue(makeRequest({ status: "ACCEPTED", createdMissionId: 999 }));
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByText("Accepter"));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByLabelText(/Attribuer directement/));

    // Not submittable until someone is chosen.
    expect(within(dialog).getByRole("button", { name: "Accepter et créer" })).toBeDisabled();

    await user.type(within(dialog).getByRole("combobox"), "decorte sal");
    await user.click(await screen.findByRole("option", { name: /Salve Decorte/ }));
    await user.click(within(dialog).getByRole("button", { name: "Accepter et créer" }));

    await waitFor(() => expect(acceptSurgeonMissionRequestMock).toHaveBeenCalledWith(1, undefined, { mode: "DIRECT", instrumentistId: 41 }));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Demande acceptée. Mission créée et attribuée."));
    // Candidates are scoped to the request's site and slot — never the whole catalogue.
    expect(apiGetMock).toHaveBeenCalledWith("/api/missions/dispatch-candidates", {
      params: { siteId: 1, startAt: "2026-09-10T08:00:00+02:00", endAt: "2026-09-10T13:00:00+02:00" },
    });
    // Typing into the Autocomplete alone takes ~4–5 s under jsdom — above the 5 s default.
  }, 15_000);

  it("conflit planning à l'acceptation → message d'erreur explicite", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    acceptSurgeonMissionRequestMock.mockRejectedValue({
      response: { data: { error: { code: "SURGEON_MISSION_REQUEST_CONFLICT" } } },
    });
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByText("Accepter"));
    await screen.findByText("Demande du Dr Arnaud Deltour");
    await user.click(screen.getByText("Accepter et créer"));

    expect(await within(screen.getByRole("dialog")).findByText("Le chirurgien a déjà une autre mission active sur cette période.")).toBeInTheDocument();
    expect(toastError).not.toHaveBeenCalled();
  });

  /** §22 — une demande déjà traitée par un autre manager entre-temps (revue concurrente). */
  it("demande déjà traitée par un autre manager (concurrence) → message d'erreur explicite", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    acceptSurgeonMissionRequestMock.mockRejectedValue({
      response: { data: { error: { code: "SURGEON_MISSION_REQUEST_ALREADY_REVIEWED", message: "Cette demande a déjà été traitée." } } },
    });
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByText("Accepter"));
    await screen.findByText("Demande du Dr Arnaud Deltour");
    await user.click(screen.getByText("Accepter et créer"));

    expect(await within(screen.getByRole("dialog")).findByText("Cette demande a déjà été traitée.")).toBeInTheDocument();
  });
});

describe("SurgeonMissionRequestsPanel — refus", () => {
  it("le bouton Refuser est désactivé tant qu'aucun motif n'est saisi", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByText("Refuser"));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByRole("button", { name: "Refuser" })).toBeDisabled();
  });

  it("refuser avec un motif transmet le reviewComment", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    rejectSurgeonMissionRequestMock.mockResolvedValue(makeRequest({ status: "REJECTED", reviewComment: "Bloc déjà complet" }));
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByText("Refuser"));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText(/Motif du refus/), "Bloc déjà complet");
    await user.click(within(dialog).getByRole("button", { name: "Refuser" }));

    await waitFor(() => expect(rejectSurgeonMissionRequestMock).toHaveBeenCalledWith(1, "Bloc déjà complet"));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Demande refusée."));
  });
});

describe("SurgeonMissionRequestsPanel — compteurs, optimisme, historique (D-147)", () => {
  function deferred<T>() {
    let resolve!: (v: T) => void;
    let reject!: (e: unknown) => void;
    const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
  }

  function card(label: string): HTMLElement {
    return screen.getByText(label, { selector: "span" }).closest(".MuiPaper-root") as HTMLElement;
  }

  it("affiche les compteurs par statut au-dessus des demandes et dans les onglets", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    renderPanel();

    expect(await screen.findByRole("tab", { name: "En attente (1)" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Acceptées (4)" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Refusées (2)" })).toBeInTheDocument();
    expect(within(card("Acceptées")).getByText("4")).toBeInTheDocument();
  });

  it("accepter : la fenêtre reste ouverte pendant l'enregistrement, se ferme et met à jour les compteurs une fois confirmé", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    const pending = deferred<ManagerSurgeonMissionRequest>();
    acceptSurgeonMissionRequestMock.mockReturnValue(pending.promise);
    const user = userEvent.setup();
    renderPanel();

    await screen.findByRole("tab", { name: "En attente (1)" });
    await user.click(await screen.findByText("Accepter"));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Commentaire (optionnel)"), "OK pour le bloc");
    await user.click(within(dialog).getByRole("button", { name: "Accepter et créer" }));

    // Serveur pas encore revenu : fenêtre ouverte, chargement, rien n'a bougé.
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(within(dialog).getByLabelText("Enregistrement en cours")).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Annuler" })).toBeDisabled();
    expect(screen.getByRole("tab", { name: "En attente (1)", hidden: true })).toBeInTheDocument();
    expect(toastSuccess).not.toHaveBeenCalled();

    getCountsMock.mockResolvedValue({ PENDING: 0, ACCEPTED: 5, REJECTED: 2 });
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [], total: 0 });
    pending.resolve(makeRequest({ status: "ACCEPTED", createdMissionId: 999 }));

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(toastSuccess).toHaveBeenCalledWith("Demande acceptée. Mission créée et proposée au pool.");
    expect(await screen.findByRole("tab", { name: "Acceptées (5)" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "En attente (0)" })).toBeInTheDocument();
    expect(acceptSurgeonMissionRequestMock).toHaveBeenCalledWith(1, "OK pour le bloc", { mode: "POOL", instrumentistId: null });
  });

  it("accepter : échec serveur → fenêtre ouverte, erreur affichée, commentaire conservé, nouvelle tentative possible", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    acceptSurgeonMissionRequestMock.mockRejectedValueOnce({
      response: { data: { error: { code: "SURGEON_MISSION_REQUEST_CONFLICT" } } },
    });
    const user = userEvent.setup();
    renderPanel();

    await screen.findByRole("tab", { name: "En attente (1)" });
    await user.click(await screen.findByText("Accepter"));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Commentaire (optionnel)"), "OK pour le bloc");
    await user.click(within(dialog).getByRole("button", { name: "Accepter et créer" }));

    expect(await within(dialog).findByText("Le chirurgien a déjà une autre mission active sur cette période.")).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(within(dialog).getByLabelText("Commentaire (optionnel)")).toHaveValue("OK pour le bloc");
    expect(screen.getByRole("tab", { name: "En attente (1)", hidden: true })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Acceptées (4)", hidden: true })).toBeInTheDocument();

    // Nouvelle tentative avec la même saisie : réussit, la fenêtre se ferme.
    acceptSurgeonMissionRequestMock.mockResolvedValueOnce(makeRequest({ status: "ACCEPTED", createdMissionId: 999 }));
    await user.click(within(dialog).getByRole("button", { name: "Accepter et créer" }));

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(acceptSurgeonMissionRequestMock).toHaveBeenCalledTimes(2);
    expect(acceptSurgeonMissionRequestMock).toHaveBeenLastCalledWith(1, "OK pour le bloc", { mode: "POOL", instrumentistId: null });
    expect(toastSuccess).toHaveBeenCalledWith("Demande acceptée. Mission créée et proposée au pool.");
  });

  it("accepter : un double clic n'envoie qu'une seule demande", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    const pending = deferred<ManagerSurgeonMissionRequest>();
    acceptSurgeonMissionRequestMock.mockReturnValue(pending.promise);
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByText("Accepter"));
    const button = within(await screen.findByRole("dialog")).getByRole("button", { name: "Accepter et créer" });
    // Clics synchrones, avant tout rendu : seul le verrou synchrone peut les écarter.
    fireEvent.click(button);
    fireEvent.click(button);
    fireEvent.click(button);

    await waitFor(() => expect(acceptSurgeonMissionRequestMock).toHaveBeenCalledTimes(1));
    expect(within(screen.getByRole("dialog")).getByLabelText("Enregistrement en cours").closest("button")).toBeDisabled();
    pending.resolve(makeRequest({ status: "ACCEPTED", createdMissionId: 999 }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(acceptSurgeonMissionRequestMock).toHaveBeenCalledTimes(1);
  });

  it("refuser : échec serveur → fenêtre ouverte, erreur affichée, motif conservé", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [makeRequest()], total: 1 });
    rejectSurgeonMissionRequestMock.mockRejectedValueOnce({
      response: { data: { error: { code: "SURGEON_MISSION_REQUEST_ALREADY_REVIEWED", message: "Cette demande a déjà été traitée." } } },
    });
    const user = userEvent.setup();
    renderPanel();

    await screen.findByRole("tab", { name: "En attente (1)" });
    await user.click(await screen.findByText("Refuser"));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText(/Motif du refus/), "Bloc complet");
    await user.click(within(dialog).getByRole("button", { name: "Refuser" }));

    expect(await within(dialog).findByText("Cette demande a déjà été traitée.")).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/Motif du refus/)).toHaveValue("Bloc complet");
    expect(screen.getByRole("tab", { name: "Refusées (2)", hidden: true })).toBeInTheDocument();
    expect(toastSuccess).not.toHaveBeenCalled();
  });

  it("l'onglet Historique liste toutes les actions avec leur auteur", async () => {
    getSurgeonMissionRequestsMock.mockResolvedValue({ items: [], total: 0 });
    fetchHistoryMock.mockResolvedValue({
      total: 2, page: 1, limit: 50,
      items: [
        {
          id: 11, eventType: "SURGEON_MISSION_REQUEST_REJECTED", category: "SURGEON_REQUEST",
          occurredAt: "2026-09-02T09:15:00+02:00", actor: { id: 3, displayName: "Gérard Manager" },
          mission: null,
          surgeonMissionRequest: { id: 1, status: "REJECTED", startAt: "2026-09-10T08:00:00+02:00", endAt: "2026-09-10T13:00:00+02:00", site: { id: 1, name: "CHIREC - Hôpital Delta" }, surgeon: { id: 5, displayName: "Arnaud Deltour" } },
          payload: { reviewComment: "Bloc complet" },
        },
        {
          id: 10, eventType: "SURGEON_MISSION_REQUEST_CREATED", category: "SURGEON_REQUEST",
          occurredAt: "2026-09-01T10:00:00+02:00", actor: { id: 5, displayName: "Arnaud Deltour" },
          mission: null,
          surgeonMissionRequest: { id: 1, status: "REJECTED", startAt: "2026-09-10T08:00:00+02:00", endAt: "2026-09-10T13:00:00+02:00", site: { id: 1, name: "CHIREC - Hôpital Delta" }, surgeon: { id: 5, displayName: "Arnaud Deltour" } },
          payload: { type: "BLOCK" },
        },
      ],
    });
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByRole("tab", { name: "Historique" }));

    expect(await screen.findByText("Demande refusée")).toBeInTheDocument();
    expect(screen.getByText(/par Gérard Manager/)).toBeInTheDocument();
    expect(screen.getByText("« Bloc complet »")).toBeInTheDocument();
    expect(screen.getByText("Demande de mission envoyée")).toBeInTheDocument();
    expect(screen.getAllByTestId("audit-entry")).toHaveLength(2);
    expect(fetchHistoryMock).toHaveBeenCalledWith(1);
  });
});
