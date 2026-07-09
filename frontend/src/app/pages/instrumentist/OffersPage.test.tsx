import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import OffersPage from "./OffersPage";

const fetchOffersMock = vi.fn();
const claimMissionMock = vi.fn();
const toastSuccessMock = vi.fn();
const toastWarningMock = vi.fn();
const toastErrorMock = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchInstrumentistOffersWithFallback: (...args: unknown[]) => fetchOffersMock(...args),
  claimMission: (...args: unknown[]) => claimMissionMock(...args),
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccessMock, warning: toastWarningMock, error: toastErrorMock }),
}));

vi.mock("../../auth/AuthContext", () => ({
  useAuth: () => ({ state: { status: "authenticated", user: { id: 1, role: "INSTRUMENTIST" } } }),
}));

vi.mock("../../features/push/useNotifications", () => ({
  useNotifications: () => ({ addNotification: vi.fn() }),
}));

vi.mock("../../features/missions/sync/missionSyncBus", () => ({
  requestMissionSync: vi.fn(),
}));

function makeMission(overrides: Partial<any> = {}) {
  return {
    id: 1,
    type: "BLOCK",
    startAt: "2026-07-05T07:30:00Z",
    endAt: "2026-07-05T15:30:00Z",
    site: { id: 1, name: "CHU Brugmann", address: "Site Victor Horta" },
    surgeon: { id: 2, firstname: "Anouk", lastname: "Peeters" },
    allowedActions: ["claim"],
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <OffersPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchOffersMock.mockReset();
  claimMissionMock.mockReset();
  toastSuccessMock.mockClear();
  toastWarningMock.mockClear();
  toastErrorMock.mockClear();
});

describe("OffersPage", () => {
  it("affiche les 3 chips de filtre avec des libellés correspondant au modèle réel (pas 'Stérilisation')", async () => {
    fetchOffersMock.mockResolvedValue({ items: [] });
    renderPage();

    expect(await screen.findByRole("button", { name: "Toutes" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Bloc opératoire" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Consultation" })).toBeInTheDocument();
    expect(screen.queryByText("Stérilisation")).not.toBeInTheDocument();
  });

  it("filtre les offres par type au clic sur un chip", async () => {
    fetchOffersMock.mockResolvedValue({
      items: [
        makeMission({ id: 1, type: "BLOCK", site: { id: 1, name: "Site Bloc" } }),
        makeMission({ id: 2, type: "CONSULTATION", site: { id: 2, name: "Site Consult" } }),
      ],
    });
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText("Site Bloc")).toBeInTheDocument();
    expect(screen.getByText("Site Consult")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Consultation" }));

    expect(screen.queryByText("Site Bloc")).not.toBeInTheDocument();
    expect(screen.getByText("Site Consult")).toBeInTheDocument();
  });

  it("ne propose pas de bouton Refuser (aucun endpoint de refus n'existe)", async () => {
    fetchOffersMock.mockResolvedValue({ items: [makeMission()] });
    renderPage();

    await screen.findByText("CHU Brugmann");
    expect(screen.queryByRole("button", { name: /refuser/i })).not.toBeInTheDocument();
  });

  it("après prise, affiche la confirmation au lieu de faire disparaître la carte", async () => {
    const mission = makeMission();
    fetchOffersMock.mockResolvedValue({ items: [mission] });
    claimMissionMock.mockResolvedValue(mission);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("CHU Brugmann");
    await user.click(screen.getByRole("button", { name: "Prendre la mission" }));

    await waitFor(() => expect(claimMissionMock).toHaveBeenCalledWith(1));
    expect(await screen.findByText("Ajoutée à votre planning")).toBeInTheDocument();
    expect(screen.getByText("CHU Brugmann")).toBeInTheDocument();
    expect(screen.getByText("Attribuée")).toBeInTheDocument();
  });

  it("affiche l'état vide quand aucune offre n'est disponible", async () => {
    fetchOffersMock.mockResolvedValue({ items: [] });
    renderPage();

    expect(await screen.findByText("Aucune offre disponible")).toBeInTheDocument();
  });
});
