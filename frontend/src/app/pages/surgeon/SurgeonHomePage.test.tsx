import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import SurgeonHomePage from "./SurgeonHomePage";

/**
 * Home chirurgien (Lot 2, D-095, §1/§15) — deux requêtes self-scoped distinctes
 * ("à venir" pour la prochaine mission, "ce mois-ci" pour le résumé), jamais
 * assignedToMe:true (voir SurgeonHomePage.tsx, ce paramètre est hardcodé sur
 * m.instrumentist côté backend et viderait systématiquement les résultats pour un
 * chirurgien). CANCELLED est exclu de tous les comptages (isLive()), OPEN seul compte
 * comme "à couvrir" (isUncovered()). "Résumé du mois" doit refléter exactement
 * mission.covered — jamais une redéfinition frontend de la couverture.
 */

const fetchMissionsMock = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: (...args: unknown[]) => fetchMissionsMock(...args),
}));

vi.mock("../instrumentist/MissionDetailPage", () => ({
  MissionDetailContent: ({ missionId }: { missionId: number }) => <div>détail mission #{missionId}</div>,
}));

function makeMission(overrides: Partial<any> = {}) {
  return {
    id: 1,
    type: "BLOCK",
    status: "ASSIGNED",
    startAt: "2026-08-10T08:00:00+02:00",
    endAt: "2026-08-10T15:30:00+02:00",
    site: { id: 1, name: "CHU Brugmann" },
    instrumentist: { id: 2, firstname: "Salve", lastname: "Decorte", email: "sd@test.be" },
    covered: true,
    allowedActions: [],
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={["/app/s"]}>
      <QueryClientProvider client={client}>
        <SurgeonHomePage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchMissionsMock.mockReset();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-08-06T12:00:00+02:00"));
});

afterEach(() => {
  vi.useRealTimers();
});

describe("SurgeonHomePage — prochaine mission", () => {
  it("aucune mission à venir → message simple, pas de carte dramatique", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    renderPage();

    expect(await screen.findByText("Aucune mission planifiée prochainement")).toBeInTheDocument();
  });

  it("mission future couverte → affiche site/horaire/instrumentiste et le bouton d'ouverture du détail", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [makeMission({ id: 7, startAt: "2026-08-10T08:00:00+02:00" })],
    });
    renderPage();

    expect(await screen.findByText(/CHU Brugmann/)).toBeInTheDocument();
    expect(screen.getByText("Salve Decorte")).toBeInTheDocument();
    expect(screen.getByText("Voir la mission")).toBeInTheDocument();
  });

  it("mission future non couverte → affiche 'À couvrir' au lieu d'un nom d'instrumentiste", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [makeMission({ id: 8, status: "OPEN", instrumentist: null, covered: false })],
    });
    renderPage();

    expect(await screen.findByText("À couvrir")).toBeInTheDocument();
  });

  it("une mission CANCELLED n'est jamais retenue comme prochaine mission", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [
        makeMission({ id: 9, status: "CANCELLED", startAt: "2026-08-07T08:00:00+02:00", site: { id: 9, name: "Site Annulé" } }),
        makeMission({ id: 10, startAt: "2026-08-15T08:00:00+02:00", site: { id: 10, name: "Site Valide" } }),
      ],
    });
    renderPage();

    expect(await screen.findByText(/Site Valide/)).toBeInTheDocument();
    expect(screen.queryByText(/Site Annulé/)).not.toBeInTheDocument();
  });

  it("choisit la mission future la plus proche, jamais une mission passée", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [
        makeMission({ id: 11, startAt: "2026-08-01T08:00:00+02:00", site: { id: 11, name: "Site Passé" } }),
        makeMission({ id: 12, startAt: "2026-08-20T08:00:00+02:00", site: { id: 12, name: "Site Futur Loin" } }),
        makeMission({ id: 13, startAt: "2026-08-09T08:00:00+02:00", site: { id: 13, name: "Site Futur Proche" } }),
      ],
    });
    renderPage();

    expect(await screen.findByText(/Site Futur Proche/)).toBeInTheDocument();
    expect(screen.queryByText(/Site Passé/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Site Futur Loin/)).not.toBeInTheDocument();
  });

  it("clic sur 'Voir la mission' ouvre le dialogue de détail (MissionDetailContent partagé, jamais un composant dédié)", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [makeMission({ id: 21, startAt: "2026-08-10T08:00:00+02:00" })],
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await user.click(await screen.findByText("Voir la mission"));
    expect(await screen.findByText("détail mission #21")).toBeInTheDocument();
  });
});

describe("SurgeonHomePage — résumé du mois", () => {
  it("compte missions/couvertes/à couvrir en excluant CANCELLED, sans redéfinir la couverture côté frontend", async () => {
    fetchMissionsMock.mockImplementation((_page: number, limit: number) => {
      // Requête "à venir" (limit 50) vs requête "ce mois-ci" (limit 200) — voir SurgeonHomePage.tsx.
      const isMonthQuery = limit === 200;
      if (isMonthQuery) {
        return Promise.resolve({
          items: [
            makeMission({ id: 1, covered: true }),
            makeMission({ id: 2, covered: true }),
            makeMission({ id: 3, status: "OPEN", instrumentist: null, covered: false }),
            makeMission({ id: 4, status: "CANCELLED", covered: false }),
          ],
        });
      }
      return Promise.resolve({ items: [] });
    });
    renderPage();

    await waitFor(() => expect(screen.getByText("3")).toBeInTheDocument());
    expect(screen.getByText("2")).toBeInTheDocument();
    // "0" et "1" partagés visuellement (couvertes vs à couvrir) : on vérifie via le texte voisin.
    expect(screen.getByText("missions")).toBeInTheDocument();
    expect(screen.getByText(/couverte/)).toBeInTheDocument();
    expect(screen.getByText("à couvrir")).toBeInTheDocument();
  });
});

describe("SurgeonHomePage — à suivre", () => {
  it("aucune mission à couvrir à venir → pas de carte 'à suivre'", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission({ covered: true })] });
    renderPage();

    await screen.findByText(/CHU Brugmann/);
    expect(screen.queryByText(/à couvrir prochainement/)).not.toBeInTheDocument();
  });

  it("des missions à couvrir à venir → carte 'à suivre' affichée avec le bon compte", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [
        makeMission({ id: 31, status: "OPEN", instrumentist: null, covered: false, startAt: "2026-08-09T08:00:00+02:00" }),
        makeMission({ id: 32, status: "OPEN", instrumentist: null, covered: false, startAt: "2026-08-11T08:00:00+02:00" }),
      ],
    });
    renderPage();

    expect(await screen.findByText("2 missions à couvrir prochainement")).toBeInTheDocument();
  });
});

describe("SurgeonHomePage — podium activité", () => {
  it("aucun podium n'est rendu (aucun faux chiffre en Lot 2, en attendant le vrai calcul backend)", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission()] });
    renderPage();

    await screen.findByText(/CHU Brugmann/);
    expect(screen.queryByText(/podium/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/classement/i)).not.toBeInTheDocument();
  });
});

describe("SurgeonHomePage — états UX", () => {
  it("erreur réseau → message d'erreur explicite", async () => {
    fetchMissionsMock.mockRejectedValue(new Error("network error"));
    renderPage();

    expect(await screen.findByText("Impossible de charger votre accueil.")).toBeInTheDocument();
  });
});
