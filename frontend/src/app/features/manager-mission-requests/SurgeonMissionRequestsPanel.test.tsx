import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import SurgeonMissionRequestsPanel from "./SurgeonMissionRequestsPanel";
import type { ManagerSurgeonMissionRequest } from "./api/managerSurgeonMissionRequests.types";

const getSurgeonMissionRequestsMock = vi.fn();
const acceptSurgeonMissionRequestMock = vi.fn();
const rejectSurgeonMissionRequestMock = vi.fn();

vi.mock("./api/managerSurgeonMissionRequests.api", () => ({
  getSurgeonMissionRequests: (...args: unknown[]) => getSurgeonMissionRequestsMock(...args),
  acceptSurgeonMissionRequest: (...args: unknown[]) => acceptSurgeonMissionRequestMock(...args),
  rejectSurgeonMissionRequest: (...args: unknown[]) => rejectSurgeonMissionRequestMock(...args),
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

    await waitFor(() => expect(acceptSurgeonMissionRequestMock).toHaveBeenCalledWith(1, undefined));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Demande acceptée. Mission créée."));
  });

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

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Le chirurgien a déjà une autre mission active sur cette période."));
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

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Cette demande a déjà été traitée."));
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
