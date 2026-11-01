import { describe, it, expect, vi, beforeEach, type Mock } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useMediaQuery } from "@mui/material";

import MyHoursPage from "./MyHoursPage";
import { fetchMyHours } from "./api/myHours.api";
import type { MyHoursItem, MyHoursStatement } from "./api/myHours.types";
import { currentMonth, shiftMonth } from "./format";

const { mockNavigate } = vi.hoisted(() => ({ mockNavigate: vi.fn() }));

vi.mock("./api/myHours.api", () => ({ fetchMyHours: vi.fn() }));
vi.mock("@mui/material", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@mui/material")>();
  return { ...actual, useMediaQuery: vi.fn() };
});
vi.mock("@mui/material/useMediaQuery", () => ({ default: (...args: unknown[]) => (useMediaQuery as unknown as (...a: unknown[]) => boolean)(...args) }));
vi.mock("react-router-dom", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react-router-dom")>();
  return { ...actual, useNavigate: () => mockNavigate };
});

const MONTH = currentMonth();

function item(p: Partial<MyHoursItem> & Pick<MyHoursItem, "missionId">): MyHoursItem {
  return {
    date: `${MONTH}-29`,
    start: `${MONTH}-29T07:30:00+01:00`,
    end: `${MONTH}-29T16:00:00+01:00`,
    endsNextDay: false,
    minutes: 510,
    durationSource: "ACTUAL_TIMES",
    missionType: "BLOCK",
    status: "pending",
    surgeonName: "Dr Martin Dubois",
    site: { id: 1, name: "CHU Brugmann" },
    estimate: { rateType: "HOURLY_RATE", unitAmount: "42.00", amount: "357.00", currency: "EUR" },
    ...p,
  };
}

const SITES = [
  { id: 1, name: "CHU Brugmann", count: 2 },
  { id: 2, name: "Hôpital Erasme", count: 2 },
];

const ALL: MyHoursStatement = {
  month: MONTH,
  siteId: null,
  sites: SITES,
  summary: { count: 4, encodedMinutes: 4950, validatedMinutes: 3495, pendingMinutes: 1455, validatedPct: 71, missingHoursCount: 1 },
  estimate: {
    currency: "EUR",
    encodedAmount: "3482.75",
    validatedAmount: "2445.50",
    hourlyRateMin: "42.00",
    hourlyRateMax: "42.00",
    unratedCount: 0,
    lines: [{ siteName: "CHU Brugmann", rateType: "HOURLY_RATE", unitAmount: "42.00", minutes: 1545, count: 3, amount: "1081.50" }],
  },
  items: [
    item({ missionId: 11 }),
    item({ missionId: 12, date: `${MONTH}-20`, start: `${MONTH}-20T22:00:00+01:00`, end: `${MONTH}-21T03:00:00+01:00`, endsNextDay: true, minutes: 300, status: "validated", site: { id: 2, name: "Hôpital Erasme" } }),
    item({ missionId: 13, date: `${MONTH}-12`, start: null, end: null, minutes: 510, durationSource: "ACTUAL_EXPLICIT", status: "validated" }),
    item({ missionId: 14, date: `${MONTH}-05`, start: null, end: null, minutes: null, durationSource: null, status: "validated", estimate: null, site: { id: 2, name: "Hôpital Erasme" } }),
  ],
};

const BRUGMANN: MyHoursStatement = {
  ...ALL,
  siteId: 1,
  summary: { count: 2, encodedMinutes: 1545, validatedMinutes: 1035, pendingMinutes: 510, validatedPct: 67, missingHoursCount: 0 },
  estimate: { ...ALL.estimate!, encodedAmount: "1081.50", validatedAmount: "724.50" },
  items: [ALL.items[0], ALL.items[2]],
};

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <MyHoursPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("MyHoursPage (D-179)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (useMediaQuery as unknown as Mock).mockReturnValue(false);
    (fetchMyHours as Mock).mockImplementation(async (_m: string, siteId: number | null) => (siteId === 1 ? BRUGMANN : ALL));
  });

  it("charge le mois courant et affiche les deux compteurs et l'estimation tels que fournis par le serveur", async () => {
    renderPage();
    expect(await screen.findByText("82h30")).toBeInTheDocument();
    expect(fetchMyHours).toHaveBeenCalledWith(MONTH, null);
    expect(screen.getByText("Heures encodées")).toBeInTheDocument();
    expect(screen.getByText("Heures validées")).toBeInTheDocument();
    expect(screen.getByText("58h15")).toBeInTheDocument();
    expect(screen.getByText("24h15")).toBeInTheDocument();
    expect(screen.getByText("ESTIMATION INDICATIVE")).toBeInTheDocument();
    expect(screen.getByText("≈ 3 482,75 €")).toBeInTheDocument();
    expect(screen.getByText("≈ 2 445,50 €")).toBeInTheDocument();
    expect(screen.getByText(/ni un salaire net, ni une facture, ni un paiement/)).toBeInTheDocument();
  });

  it("chaque ligne se lit DÉBUT | DURÉE TOTALE | FIN, avec +1j après minuit, — pour une durée seule et un état sans heures", async () => {
    renderPage();
    const night = await screen.findByRole("button", { name: /5h00, de 22h00 à 03h00 le lendemain/ });
    expect(within(night).getByText("22h00")).toBeInTheDocument();
    expect(within(night).getByText("+1j")).toBeInTheDocument();

    const durationOnly = screen.getByRole("button", { name: /12 .*, 8h30$/ });
    expect(within(durationOnly).getAllByText("—")).toHaveLength(2);
    expect(within(durationOnly).getByText("8h30")).toBeInTheDocument();

    const missing = screen.getByRole("button", { name: /heures non renseignées/ });
    expect(within(missing).getByText("Heures non renseignées")).toBeInTheDocument();

    expect(screen.getByText("En attente de validation")).toBeInTheDocument();
    expect(screen.getAllByText("Validé")).toHaveLength(3);
  });

  it("le filtre par site redemande le relevé au serveur : liste, heures ET montants changent ; les puces restent", async () => {
    renderPage();
    await screen.findByText("82h30");
    const chips = screen.getByRole("group", { name: "Filtrer par site" });
    await userEvent.click(within(chips).getByRole("button", { name: /CHU Brugmann/ }));

    await waitFor(() => expect(fetchMyHours).toHaveBeenLastCalledWith(MONTH, 1));
    expect(await screen.findByText("25h45")).toBeInTheDocument();
    expect(screen.getByText("17h15")).toBeInTheDocument();
    expect(screen.getByText("≈ 1 081,50 €")).toBeInTheDocument();
    expect(screen.getByText("≈ 724,50 €")).toBeInTheDocument();
    expect(screen.getByText("2 prestations · CHU Brugmann")).toBeInTheDocument();
    expect(within(chips).getByRole("button", { name: /CHU Brugmann/ })).toHaveAttribute("aria-pressed", "true");
    expect(within(chips).getAllByRole("button")).toHaveLength(3);
  });

  it("sans tarif applicable : aucune section financière, aucun montant", async () => {
    (fetchMyHours as Mock).mockResolvedValue({ ...ALL, estimate: null, items: ALL.items.map((i) => ({ ...i, estimate: undefined })) });
    renderPage();
    await screen.findByText("82h30");
    expect(screen.queryByText("ESTIMATION INDICATIVE")).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain("€");
  });

  it("mois courant : flèche suivante désactivée ; mois précédent vide → message et retour au mois en cours ; filtre réinitialisé", async () => {
    renderPage();
    await screen.findByText("82h30");
    expect(screen.getByRole("button", { name: "Mois suivant" })).toBeDisabled();
    await userEvent.click(within(screen.getByRole("group", { name: "Filtrer par site" })).getByRole("button", { name: /Erasme/ }));

    (fetchMyHours as Mock).mockResolvedValue({ month: shiftMonth(MONTH, -1), siteId: null, sites: [], summary: { count: 0, encodedMinutes: 0, validatedMinutes: 0, pendingMinutes: 0, validatedPct: 0, missingHoursCount: 0 }, estimate: null, items: [] });
    await userEvent.click(screen.getByRole("button", { name: "Mois précédent" }));

    await waitFor(() => expect(fetchMyHours).toHaveBeenLastCalledWith(shiftMonth(MONTH, -1), null));
    expect(await screen.findByText(/Aucune prestation encodée en/)).toBeInTheDocument();
    expect(screen.getAllByText("0h00")).toHaveLength(2);
    expect(screen.queryByRole("group", { name: "Filtrer par site" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Mois suivant" })).toBeEnabled();
    expect(screen.getByRole("button", { name: "Revenir au mois en cours" })).toBeInTheDocument();
  });

  it("une ligne ouvre le détail mission existant", async () => {
    renderPage();
    await userEvent.click(await screen.findByRole("button", { name: /5h00, de 22h00/ }));
    expect(mockNavigate).toHaveBeenCalledWith("/app/i/missions/12");
  });

  it("desktop : tableau avec colonnes Tarif / Estimation, et jamais de pause ni d'heure planifiée", async () => {
    (useMediaQuery as unknown as Mock).mockReturnValue(true);
    renderPage();
    expect(await screen.findByText("DURÉE TOTALE")).toBeInTheDocument();
    expect(screen.getByText("Tarif")).toBeInTheDocument();
    expect(screen.getByText("Estimation")).toBeInTheDocument();
    expect(screen.getAllByText("42,00 €/h").length).toBeGreaterThan(0);
    const text = document.body.textContent ?? "";
    expect(text).not.toMatch(/pause/i);
    expect(text).not.toMatch(/planifi/i);
  });

  it("desktop étroit (900–1279 px) : en-tête desktop mais liste, jamais un tableau à colonne écrasée", async () => {
    (useMediaQuery as unknown as Mock).mockImplementation((q: string) => !q.includes("1280"));
    renderPage();
    expect(await screen.findByText(/Heures encodées et validées · 4 prestations/)).toBeInTheDocument();
    expect(screen.queryByText("Tarif")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: /5h00, de 22h00 à 03h00 le lendemain/ })).toBeInTheDocument();
  });

  it("consultation au forfait : libellé « Forfait consultation », distinct d'une estimation horaire, aucune heure fictive", async () => {
    const consultation = item({ missionId: 21, date: `${MONTH}-08`, start: null, end: null, minutes: null, durationSource: null, missionType: "CONSULTATION", status: "validated", estimate: { rateType: "CONSULTATION_FEE", unitAmount: "120.00", amount: "120.00", currency: "EUR" } });
    const statement: MyHoursStatement = {
      ...ALL,
      sites: [{ id: 1, name: "CHU Brugmann", count: 2 }],
      summary: { count: 2, encodedMinutes: 510, validatedMinutes: 0, pendingMinutes: 510, validatedPct: 0, missingHoursCount: 1 },
      items: [item({ missionId: 20 }), consultation],
    };
    (fetchMyHours as Mock).mockResolvedValue(statement);
    const { unmount } = renderPage();

    const row = await screen.findByRole("button", { name: /heures non renseignées, forfait consultation$/ });
    expect(within(row).getByText("Forfait consultation")).toBeInTheDocument();
    expect(within(row).getByText("Heures non renseignées")).toBeInTheDocument();
    expect(within(screen.getByRole("button", { name: /8h30, de 07h30 à 16h00$/ })).queryByText("Forfait consultation")).not.toBeInTheDocument();
    expect(screen.getAllByText("8h30").length).toBeGreaterThan(0);
    unmount();

    (useMediaQuery as unknown as Mock).mockReturnValue(true);
    renderPage();
    const tableRow = await screen.findByRole("button", { name: /forfait consultation$/ });
    expect(within(tableRow).getByText("Forfait consultation")).toBeInTheDocument();
    expect(within(tableRow).getByText("120,00 €")).toBeInTheDocument();
    expect(within(screen.getByRole("button", { name: /8h30, de 07h30 à 16h00$/ })).getByText("42,00 €/h")).toBeInTheDocument();
  });

  it("erreur de chargement : message et nouvel essai", async () => {
    (fetchMyHours as Mock).mockRejectedValueOnce(new Error("network")).mockResolvedValue(ALL);
    renderPage();
    await userEvent.click(await screen.findByRole("button", { name: "Réessayer" }));
    expect(await screen.findByText("82h30")).toBeInTheDocument();
  });
});
