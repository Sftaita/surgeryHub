import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import PlanningPage, {
  buildMonthGridCells,
  formatDateToYmd,
  classifyPlanningMission,
} from "./PlanningPage";

describe("buildMonthGridCells (risque identifié dans le plan — grille mensuelle calculée à la main)", () => {
  it("commence toujours un lundi", () => {
    const cells = buildMonthGridCells("2026-07-05");
    const firstDay = new Date(cells[0].dateYmd + "T00:00:00");
    expect(firstDay.getDay()).toBe(1); // 1 = lundi
  });

  it("couvre des semaines complètes (multiple de 7 cellules)", () => {
    const cells = buildMonthGridCells("2026-07-05");
    expect(cells.length % 7).toBe(0);
  });

  it("marque correctement les jours du mois courant vs hors-mois — juillet 2026 (1er = mercredi)", () => {
    const cells = buildMonthGridCells("2026-07-15");
    // Juillet 2026 : le 1er tombe un mercredi → lun 29 et mar 30 juin sont hors-mois en tête de grille.
    expect(cells[0]).toEqual({ dateYmd: "2026-06-29", dayNumber: 29, inCurrentMonth: false });
    expect(cells[1]).toEqual({ dateYmd: "2026-06-30", dayNumber: 30, inCurrentMonth: false });
    expect(cells[2]).toEqual({ dateYmd: "2026-07-01", dayNumber: 1, inCurrentMonth: true });
    const last = cells[cells.length - 1];
    expect(new Date(last.dateYmd + "T00:00:00").getDay()).toBe(0); // se termine un dimanche
  });

  it("couvre bien tous les jours du mois, y compris le dernier", () => {
    const cells = buildMonthGridCells("2026-02-10"); // février 2026 = 28 jours
    const inMonth = cells.filter((c) => c.inCurrentMonth);
    expect(inMonth).toHaveLength(28);
    expect(inMonth[0].dateYmd).toBe("2026-02-01");
    expect(inMonth[27].dateYmd).toBe("2026-02-28");
  });

  it("gère le changement d'année (décembre → janvier)", () => {
    const cells = buildMonthGridCells("2026-12-15");
    const inMonth = cells.filter((c) => c.inCurrentMonth);
    expect(inMonth).toHaveLength(31);
    expect(inMonth[0].dateYmd).toBe("2026-12-01");
    expect(inMonth[30].dateYmd).toBe("2026-12-31");
  });
});

describe("formatDateToYmd", () => {
  it("formate en YYYY-MM-DD avec zéros de tête", () => {
    expect(formatDateToYmd(new Date(2026, 0, 5))).toBe("2026-01-05");
  });
});

/**
 * Navigation planning (commit dédié) — la seule source fiable pour "cette mission attend
 * un encodage" est allowedActions (déjà calculé côté backend par MissionEncodingGuard sur
 * startAt, jamais actualStartAt qui n'existe que sur MissionExecution). classifyPlanningMission
 * est testée directement ici : logique pure, indépendante du rendu, couvre la frontière
 * exacte autour de l'heure courante sans dépendre d'un montage de composant.
 */
describe("classifyPlanningMission", () => {
  const NOW = new Date("2026-07-24T12:00:00+02:00").getTime();

  function mission(overrides: Partial<{ startAt: string; allowedActions: string[] }> = {}) {
    return { startAt: "2026-07-24T12:00:00+02:00", allowedActions: [], ...overrides } as any;
  }

  it("mission passée avec droit d'encodage (edit_encoding) → toEncode", () => {
    const m = mission({ startAt: "2026-07-24T08:00:00+02:00", allowedActions: ["edit_encoding", "submit"] });
    expect(classifyPlanningMission(m, NOW)).toBe("toEncode");
  });

  it("mission passée avec droit d'encodage (encoding, ex. DECLARED) → toEncode", () => {
    const m = mission({ startAt: "2026-07-24T08:00:00+02:00", allowedActions: ["encoding"] });
    expect(classifyPlanningMission(m, NOW)).toBe("toEncode");
  });

  it("mission passée déjà soumise (aucun droit d'encodage) → other, jamais upcoming", () => {
    const m = mission({ startAt: "2026-07-24T08:00:00+02:00", allowedActions: ["view"] });
    expect(classifyPlanningMission(m, NOW)).toBe("other");
  });

  it("mission future sans droit d'encodage → upcoming", () => {
    const m = mission({ startAt: "2026-07-25T08:00:00+02:00", allowedActions: [] });
    expect(classifyPlanningMission(m, NOW)).toBe("upcoming");
  });

  it("mission future avec droit d'encodage (cas normalement impossible côté backend) → toEncode prime quand même", () => {
    const m = mission({ startAt: "2026-07-25T08:00:00+02:00", allowedActions: ["edit_encoding"] });
    expect(classifyPlanningMission(m, NOW)).toBe("toEncode");
  });

  it("mission non assignée / annulée (aucun droit, hors période) → other", () => {
    const m = mission({ startAt: "2026-07-20T08:00:00+02:00", allowedActions: [] });
    expect(classifyPlanningMission(m, NOW)).toBe("other");
  });

  it("frontière exacte : startAt strictement égal à maintenant, sans droit d'encodage → other (pas upcoming)", () => {
    const m = mission({ startAt: "2026-07-24T12:00:00+02:00", allowedActions: [] });
    expect(classifyPlanningMission(m, NOW)).toBe("other");
  });

  it("frontière exacte : 1ms dans le futur, sans droit d'encodage → upcoming", () => {
    const m = mission({ startAt: "2026-07-24T12:00:00.001+02:00", allowedActions: [] });
    expect(classifyPlanningMission(m, NOW)).toBe("upcoming");
  });

  it("frontière exacte : 1ms dans le passé, sans droit d'encodage → other", () => {
    const m = mission({ startAt: "2026-07-24T11:59:59.999+02:00", allowedActions: [] });
    expect(classifyPlanningMission(m, NOW)).toBe("other");
  });

  it("respecte la timezone métier (offset explicite dans startAt, pas une comparaison de date locale naïve)", () => {
    // 2026-07-24T11:00:00Z = 2026-07-24T13:00:00+02:00, donc dans le futur par rapport à NOW (12:00 +02:00).
    const m = mission({ startAt: "2026-07-24T11:00:00Z", allowedActions: [] });
    expect(classifyPlanningMission(m, NOW)).toBe("upcoming");
  });
});

const fetchMissionsMock = vi.fn();
const mockNavigate = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: (...args: unknown[]) => fetchMissionsMock(...args),
}));

vi.mock("./MissionDetailPage", () => ({
  MissionDetailContent: () => <div>détail mission</div>,
}));

vi.mock("react-router-dom", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react-router-dom")>();
  return { ...actual, useNavigate: () => mockNavigate };
});

function makeMission(overrides: Partial<any> = {}) {
  return {
    id: 1,
    type: "BLOCK",
    status: "ASSIGNED",
    startAt: "2026-07-25T07:30:00+02:00",
    endAt: "2026-07-25T15:30:00+02:00",
    site: { id: 1, name: "CHU Brugmann" },
    allowedActions: [],
    ...overrides,
  };
}

function renderPage(initialEntry = "/planning?view=week&date=2026-07-24") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <QueryClientProvider client={client}>
        <PlanningPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchMissionsMock.mockReset();
  mockNavigate.mockReset();
  // Ne fige que Date (classifyPlanningMission dépend de Date.now()) — figer aussi
  // setTimeout/setInterval bloquerait indéfiniment waitFor/findBy* et les retries
  // internes de React Query, qui reposent sur de vrais timers.
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-07-24T12:00:00+02:00"));
});

afterEach(() => {
  vi.useRealTimers();
});

describe("PlanningPage", () => {
  it("affiche le contrôle segmenté et bascule entre semaine et mois", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    const user = userEvent.setup();
    renderPage();

    await waitFor(() => expect(fetchMissionsMock).toHaveBeenCalled());
    expect(screen.getByRole("button", { name: "Semaine" })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Mois" }));
    // Bascule vers la grille mensuelle : les en-têtes de colonnes L M M J V S D apparaissent.
    await waitFor(() => expect(screen.getAllByText("L").length).toBeGreaterThan(0));
  });

  it("mission passée non soumise (droit d'encodage) → section À ENCODER uniquement, jamais À VENIR", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [makeMission({ startAt: "2026-07-24T08:00:00+02:00", allowedActions: ["edit_encoding", "submit"] })],
    });
    renderPage();

    expect(await screen.findByText("À ENCODER")).toBeInTheDocument();
    expect(await screen.findByText("CHU Brugmann")).toBeInTheDocument();
    expect(screen.getByText("Aucune mission à venir. Acceptez des offres pour compléter votre planning.")).toBeInTheDocument();
  });

  it("mission passée déjà soumise (aucun droit d'encodage) → absente d'À ENCODER et d'À VENIR", async () => {
    fetchMissionsMock.mockResolvedValue({
      items: [makeMission({ status: "SUBMITTED", startAt: "2026-07-24T08:00:00+02:00", allowedActions: ["view"] })],
    });
    renderPage();

    expect(await screen.findByText("Aucune mission à encoder")).toBeInTheDocument();
    expect(screen.getByText("Aucune mission à venir. Acceptez des offres pour compléter votre planning.")).toBeInTheDocument();
    expect(screen.queryByText("CHU Brugmann")).not.toBeInTheDocument();
  });

  it("mission future sans droit d'encodage → section À VENIR uniquement", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission()] });
    renderPage();

    expect(await screen.findByText("À VENIR")).toBeInTheDocument();
    expect(await screen.findByText("CHU Brugmann")).toBeInTheDocument();
    expect(screen.getByText("Aucune mission à encoder")).toBeInTheDocument();
  });

  it("aucune mission dans la période → les deux états vides s'affichent, avec le CTA offres toujours présent", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    renderPage();

    expect(await screen.findByText("Aucune mission à encoder")).toBeInTheDocument();
    expect(await screen.findByText(/Aucune mission à venir/)).toBeInTheDocument();
    expect(screen.getByText(/Acceptez des offres/)).toBeInTheDocument();
  });

  it("clic sur une mission À ENCODER navigue directement vers l'écran d'encodage, sans ouvrir de dialogue", async () => {
    const m = makeMission({ id: 42, startAt: "2026-07-24T08:00:00+02:00", allowedActions: ["edit_encoding"] });
    fetchMissionsMock.mockResolvedValue({ items: [m] });
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByText("CHU Brugmann"));

    expect(mockNavigate).toHaveBeenCalledWith("/app/i/missions/42/encoding");
    expect(screen.queryByText("détail mission")).not.toBeInTheDocument();
    expect(screen.queryByText("Détail mission")).not.toBeInTheDocument();
  });

  it("clic sur une mission À VENIR ouvre toujours le dialogue de détail (comportement inchangé)", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [makeMission()] });
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByText("CHU Brugmann"));
    expect(await screen.findByText("détail mission")).toBeInTheDocument();
    expect(mockNavigate).not.toHaveBeenCalled();
  });

  it("aucun doublon : une mission à encoder n'apparaît jamais aussi dans À VENIR", async () => {
    const toEncode = makeMission({ id: 1, startAt: "2026-07-24T08:00:00+02:00", allowedActions: ["edit_encoding"], site: { id: 1, name: "CHU Brugmann" } });
    const upcoming = makeMission({ id: 2, startAt: "2026-07-25T08:00:00+02:00", allowedActions: [], site: { id: 2, name: "Clinique Saint-Luc" } });
    fetchMissionsMock.mockResolvedValue({ items: [toEncode, upcoming] });
    renderPage();

    expect(await screen.findAllByText("CHU Brugmann")).toHaveLength(1);
    expect(await screen.findAllByText("Clinique Saint-Luc")).toHaveLength(1);
  });
});
