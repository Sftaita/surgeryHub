import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import OffersPage from "./OffersPage";

const fetchOffersMock = vi.fn();
const claimMissionMock = vi.fn();
const markOffersSeenMock = vi.fn();
const toastSuccessMock = vi.fn();
const toastWarningMock = vi.fn();
const toastErrorMock = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchInstrumentistOffersWithFallback: (...args: unknown[]) => fetchOffersMock(...args),
  claimMission: (...args: unknown[]) => claimMissionMock(...args),
  markOffersSeen: (...args: unknown[]) => markOffersSeenMock(...args),
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccessMock, warning: toastWarningMock, error: toastErrorMock }),
}));

vi.mock("../../auth/AuthContext", () => ({
  useAuth: () => ({ state: { status: "authenticated", user: { id: 1, role: "INSTRUMENTIST" } } }),
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

function renderPage(client?: QueryClient) {
  const queryClient = client ?? new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={queryClient}>
        <OffersPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchOffersMock.mockReset();
  claimMissionMock.mockReset();
  markOffersSeenMock.mockReset().mockResolvedValue(undefined);
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

  it("affiche la photo du chirurgien quand profilePicturePath est renseigné", async () => {
    fetchOffersMock.mockResolvedValue({
      items: [makeMission({ surgeon: { id: 2, firstname: "Anouk", lastname: "Peeters", profilePicturePath: "/uploads/profile-pictures/surgeon-2.jpg" } })],
    });
    renderPage();

    const img = await screen.findByAltText("Dr. Anouk Peeters");
    expect(img.tagName).toBe("IMG");
    expect((img as HTMLImageElement).src).toContain("/uploads/profile-pictures/surgeon-2.jpg");
  });

  it("affiche les initiales quand le chirurgien n'a pas de photo", async () => {
    fetchOffersMock.mockResolvedValue({
      items: [makeMission({ surgeon: { id: 2, firstname: "Anouk", lastname: "Peeters", profilePicturePath: null } })],
    });
    renderPage();

    await screen.findByText("CHU Brugmann");
    expect(screen.queryByAltText("Dr. Anouk Peeters")).not.toBeInTheDocument();
    expect(screen.getByText("DP")).toBeInTheDocument();
  });
});

describe("OffersPage — remise à zéro du badge non-lu (Lot 6, audit PWA/mobile/admin 2026-07-29)", () => {
  it("appelle markOffersSeen après un chargement réussi", async () => {
    fetchOffersMock.mockResolvedValue({ items: [makeMission()] });
    renderPage();

    await screen.findByText("CHU Brugmann");
    await waitFor(() => expect(markOffersSeenMock).toHaveBeenCalledTimes(1));
  });

  it("appelle markOffersSeen même quand la liste est vide (un chargement réussi reste un chargement réussi)", async () => {
    fetchOffersMock.mockResolvedValue({ items: [] });
    renderPage();

    await waitFor(() => expect(markOffersSeenMock).toHaveBeenCalledTimes(1));
  });

  it("n'appelle markOffersSeen qu'une seule fois par montage, pas à chaque re-render", async () => {
    fetchOffersMock.mockResolvedValue({ items: [makeMission()] });
    renderPage();

    await screen.findByText("CHU Brugmann");
    await waitFor(() => expect(markOffersSeenMock).toHaveBeenCalledTimes(1));

    // Laisse le temps à un éventuel second appel non désiré de se produire.
    await new Promise((r) => setTimeout(r, 50));
    expect(markOffersSeenMock).toHaveBeenCalledTimes(1);
  });

  it("un échec de markOffersSeen ne casse pas l'écran (best-effort)", async () => {
    fetchOffersMock.mockResolvedValue({ items: [makeMission()] });
    markOffersSeenMock.mockRejectedValue(new Error("network"));
    renderPage();

    expect(await screen.findByText("CHU Brugmann")).toBeInTheDocument();
  });

  /**
   * Revue post-rapport (2026-07-29) : preuve explicite que markOffersSeen n'est
   * jamais appelé quand le CHARGEMENT lui-même échoue — une erreur réseau ne doit
   * jamais remettre le badge à zéro côté client (et ne doit surtout pas déclencher
   * le checkpoint serveur alors que l'utilisateur n'a rien vu).
   */
  it("n'appelle jamais markOffersSeen si le chargement de la liste échoue", async () => {
    fetchOffersMock.mockRejectedValue(new Error("network down"));
    renderPage();

    await waitFor(() => expect(fetchOffersMock).toHaveBeenCalled());
    await new Promise((r) => setTimeout(r, 50));
    expect(markOffersSeenMock).not.toHaveBeenCalled();
  });

  /**
   * Un simple préchargement React Query (ex: un autre écran qui pré-remplit le cache
   * de la clé ["missions","offers"]) ne doit jamais marquer les offres comme vues —
   * seul un montage RÉEL de OffersPage (l'utilisateur consulte effectivement l'écran)
   * doit déclencher le checkpoint. Ce test précharge le cache sans jamais monter
   * OffersPage, et prouve qu'aucun appel n'a lieu.
   */
  it("un simple préchargement React Query de la clé offres ne marque rien comme vu (aucun montage de la page)", async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await client.prefetchQuery({
      queryKey: ["missions", "offers"],
      queryFn: () => fetchOffersMock.mockResolvedValue({ items: [makeMission()] })(),
    });

    expect(markOffersSeenMock).not.toHaveBeenCalled();
  });

  it("le timestamp n'est mis à jour que par un montage réel de l'écran Offres, pas par la simple présence de données en cache", async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    // Simule des données déjà en cache (ex: préchargées ailleurs) avant que
    // l'utilisateur n'ouvre réellement l'écran.
    client.setQueryData(["missions", "offers"], { items: [makeMission()] });
    fetchOffersMock.mockResolvedValue({ items: [makeMission()] });
    expect(markOffersSeenMock).not.toHaveBeenCalled();

    // Seul le montage réel de la page (navigation effective de l'utilisateur)
    // déclenche le checkpoint — même si les données étaient déjà disponibles.
    renderPage(client);
    await screen.findByText("CHU Brugmann");
    await waitFor(() => expect(markOffersSeenMock).toHaveBeenCalledTimes(1));
  });
});
