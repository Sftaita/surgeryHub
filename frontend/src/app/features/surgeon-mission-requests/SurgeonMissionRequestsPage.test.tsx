import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import SurgeonMissionRequestsPage from "./SurgeonMissionRequestsPage";
import type { SurgeonMissionRequest } from "./api/surgeonMissionRequests.types";

const fetchMyMissionRequestsMock = vi.fn();
const navigateMock = vi.fn();

vi.mock("./api/surgeonMissionRequests.api", () => ({
  fetchMyMissionRequests: (...args: unknown[]) => fetchMyMissionRequestsMock(...args),
}));

vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => navigateMock };
});

function makeRequest(overrides: Partial<SurgeonMissionRequest> = {}): SurgeonMissionRequest {
  return {
    id: 1,
    site: { id: 1, name: "CHIREC - Hôpital Delta" },
    type: "BLOCK",
    startAt: "2026-09-10T08:00:00+02:00",
    endAt: "2026-09-10T13:00:00+02:00",
    comment: "Bloc supplémentaire",
    status: "PENDING",
    createdAt: "2026-09-01T10:00:00+02:00",
    reviewedAt: null,
    reviewComment: null,
    createdMissionId: null,
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={["/app/s/requests"]}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/app/s/requests" element={<SurgeonMissionRequestsPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchMyMissionRequestsMock.mockReset();
  navigateMock.mockClear();
});

describe("SurgeonMissionRequestsPage — chargement", () => {
  it("affiche un indicateur de chargement", async () => {
    fetchMyMissionRequestsMock.mockReturnValue(new Promise(() => {}));
    renderPage();
    expect(screen.getByRole("progressbar")).toBeInTheDocument();
  });

  it("erreur réseau → message explicite", async () => {
    fetchMyMissionRequestsMock.mockRejectedValue(new Error("network error"));
    renderPage();
    expect(await screen.findByText("Impossible de charger vos demandes.")).toBeInTheDocument();
  });
});

describe("SurgeonMissionRequestsPage — état vide", () => {
  it("aucune demande → empty state avec CTA", async () => {
    fetchMyMissionRequestsMock.mockResolvedValue([]);
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText("Aucune demande de mission")).toBeInTheDocument();
    await user.click(screen.getByText("Nouvelle demande"));
    expect(navigateMock).toHaveBeenCalledWith("/app/s/mission-requests/new");
  });
});

describe("SurgeonMissionRequestsPage — groupes", () => {
  it("PENDING apparaît sous EN ATTENTE avec le statut En attente", async () => {
    fetchMyMissionRequestsMock.mockResolvedValue([makeRequest({ status: "PENDING" })]);
    renderPage();

    expect(await screen.findByText("EN ATTENTE")).toBeInTheDocument();
    expect(screen.getByText("En attente")).toBeInTheDocument();
    expect(screen.queryByText("TRAITÉES")).not.toBeInTheDocument();
  });

  it("ACCEPTED apparaît sous TRAITÉES avec un lien Voir la mission", async () => {
    fetchMyMissionRequestsMock.mockResolvedValue([
      makeRequest({ id: 2, status: "ACCEPTED", createdMissionId: 999 }),
    ]);
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText("TRAITÉES")).toBeInTheDocument();
    expect(screen.getByText("Acceptée")).toBeInTheDocument();
    await user.click(screen.getByText(/Voir la mission/));
    expect(navigateMock).toHaveBeenCalledWith("/app/s/missions/999");
  });

  it("REJECTED apparaît sous TRAITÉES avec le motif de refus", async () => {
    fetchMyMissionRequestsMock.mockResolvedValue([
      makeRequest({ id: 3, status: "REJECTED", reviewComment: "Bloc déjà complet à cette date" }),
    ]);
    renderPage();

    expect(await screen.findByText("Refusée")).toBeInTheDocument();
    expect(screen.getByText(/Bloc déjà complet à cette date/)).toBeInTheDocument();
  });

  it("mélange PENDING + traitées → les deux groupes sont affichés", async () => {
    fetchMyMissionRequestsMock.mockResolvedValue([
      makeRequest({ id: 1, status: "PENDING" }),
      makeRequest({ id: 2, status: "ACCEPTED", createdMissionId: 999 }),
    ]);
    renderPage();

    expect(await screen.findByText("EN ATTENTE")).toBeInTheDocument();
    expect(screen.getByText("TRAITÉES")).toBeInTheDocument();
  });

  it("une demande PENDING n'affiche jamais de bouton d'édition/annulation (§19, lecture seule)", async () => {
    fetchMyMissionRequestsMock.mockResolvedValue([makeRequest({ status: "PENDING" })]);
    renderPage();

    await screen.findByText("EN ATTENTE");
    expect(screen.queryByText("Modifier")).not.toBeInTheDocument();
    expect(screen.queryByText("Annuler")).not.toBeInTheDocument();
    expect(screen.queryByText("Supprimer")).not.toBeInTheDocument();
  });
});

describe("SurgeonMissionRequestsPage — CTA header", () => {
  it("le bouton + Nouvelle demande navigue vers le formulaire", async () => {
    fetchMyMissionRequestsMock.mockResolvedValue([makeRequest()]);
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByText("+ Nouvelle demande"));
    expect(navigateMock).toHaveBeenCalledWith("/app/s/mission-requests/new");
  });
});
