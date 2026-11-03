import { beforeEach, describe, expect, it, vi, type Mock } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useMediaQuery } from "@mui/material";
import InstrumentistHoursPage from "./InstrumentistHoursPage";
import { fetchInstrumentistHours, fetchInstrumentistHoursDetail } from "../../../features/instrumentist-hours/api/instrumentistHours.api";
import { detailFixture, listFixture } from "../../../features/instrumentist-hours/testFixtures";

vi.mock("../../../features/instrumentist-hours/api/instrumentistHours.api", () => ({
  fetchInstrumentistHours: vi.fn(),
  fetchInstrumentistHoursDetail: vi.fn(),
}));
vi.mock("../../../features/missions/drawer/ManagerMissionDrawer", () => ({
  ManagerMissionDrawer: ({ missionId }: { missionId: number | null }) => (missionId === null ? null : <div data-testid="mission-drawer">mission {missionId}</div>),
}));
vi.mock("../../../features/help/HelpButton", () => ({ HelpButton: () => null }));
vi.mock("@mui/material", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@mui/material")>();
  return { ...actual, useMediaQuery: vi.fn() };
});

function LocationProbe() {
  const l = useLocation();
  return <div data-testid="location">{l.search}</div>;
}

function renderPage(url = "/app/m/billing/hours?month=2026-10") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[url]}>
        <Routes><Route path="/app/m/billing/hours" element={<><InstrumentistHoursPage /><LocationProbe /></>} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  (fetchInstrumentistHours as Mock).mockReset().mockResolvedValue(listFixture());
  (fetchInstrumentistHoursDetail as Mock).mockReset().mockResolvedValue(detailFixture());
  (useMediaQuery as unknown as Mock).mockReturnValue(true);
});

describe("D-180 — page Heures instrumentistes", () => {
  it("affiche indicateurs, lignes et total tels que calculés par le serveur", async () => {
    renderPage();
    expect(screen.getByTestId("hours-page-loading")).toBeInTheDocument();
    await screen.findByText("Claire Dubois");
    expect(fetchInstrumentistHours).toHaveBeenCalledWith({ month: "2026-10", siteId: null, q: null, sort: "validatedMinutes", direction: "desc" });

    expect(screen.getAllByText("538h00")).toHaveLength(2); // indicateur + total
    expect(screen.getAllByText("476h00")).toHaveLength(2);
    expect(screen.getAllByText("≈ 18 000,00 €")).toHaveLength(2); // indicateur + total
    expect(screen.getByText("1 instrumentiste sans tarif")).toBeInTheDocument();

    const table = screen.getByRole("table");
    const marc = within(table).getByText("Marc Janssens").closest("tr")!;
    expect(within(marc).getAllByText("Tarif manquant")).toHaveLength(2);
    const paul = within(table).getByText("Paul Renard").closest("tr")!;
    expect(within(paul).getByText("Inactive")).toBeInTheDocument();
    expect(within(paul).getByText("sur 4 mois")).toBeInTheDocument();
    const laura = within(table).getByText("Laura Gillet").closest("tr")!;
    expect(within(laura).getByText("aucune prestation")).toBeInTheDocument();
    const elise = within(table).getByText("Elise Wouters").closest("tr")!;
    expect(within(elise).getByText("historique depuis oct.")).toBeInTheDocument();
    const nadia = within(table).getByText("Nadia Benali").closest("tr")!;
    expect(within(nadia).getByText("dont 3 forfaits consultation")).toBeInTheDocument();

    // Total serveur, sans évolution globale.
    const total = within(table).getByText(/^Total · 7 instrumentistes/).closest("tr")!;
    expect(within(total).getByText("≈ 20 000,00 €")).toBeInTheDocument();
    expect(within(total).queryByText(/%/)).toBeNull();
  });

  it("transmet site, recherche et tri au serveur et garde l'état dans l'URL", async () => {
    renderPage();
    await screen.findByText("Claire Dubois");

    await userEvent.click(screen.getByRole("button", { name: "Heures encodées" }));
    await waitFor(() => expect(fetchInstrumentistHours).toHaveBeenLastCalledWith(expect.objectContaining({ sort: "encodedMinutes", direction: "desc" })));
    expect(screen.getByTestId("location").textContent).toContain("sort=encodedMinutes%3Adesc");

    await userEvent.type(screen.getByRole("textbox", { name: "Rechercher un instrumentiste" }), "élo");
    await waitFor(() => expect(fetchInstrumentistHours).toHaveBeenLastCalledWith(expect.objectContaining({ q: "élo" })));

    await userEvent.click(screen.getByRole("combobox", { name: "Site" }));
    await userEvent.click(await screen.findByRole("option", { name: "CHU Brugmann" }));
    await waitFor(() => expect(fetchInstrumentistHours).toHaveBeenLastCalledWith(expect.objectContaining({ siteId: 3, q: "élo" })));

    await userEvent.click(screen.getByRole("button", { name: "Réinitialiser les filtres" }));
    await waitFor(() => expect(fetchInstrumentistHours).toHaveBeenLastCalledWith(expect.objectContaining({ siteId: null, q: null })));
  }, 20000);

  it("ouvre le tiroir : relevé, comparaisons, tarifs par site, parts multi-salles, puis la mission existante", async () => {
    renderPage();
    await userEvent.click(await screen.findByText("Nadia Benali"));

    const dialog = await screen.findByRole("dialog");
    expect(await within(dialog).findAllByText("17h15")).toHaveLength(2); // encodées = validées
    expect(fetchInstrumentistHoursDetail).toHaveBeenCalledWith(41, "2026-10", null);
    expect(screen.getByTestId("location").textContent).toContain("instrumentist=41");

    expect(within(dialog).getByText("−6 %")).toBeInTheDocument();
    expect(within(dialog).getByText(/66h00 \/ mois · juin–sept\. · sur 4 mois/)).toBeInTheDocument();
    expect(within(dialog).getByText(/première prestation connue dans SurgicalHub, pas une date d'entrée en activité/)).toBeInTheDocument();
    expect(within(dialog).getByText("CHU Brugmann", { selector: "p" })).toBeInTheDocument();
    expect(within(dialog).getAllByText("Forfait consultation").length).toBeGreaterThan(0);
    expect(within(dialog).getByText("Multi-salles · part imputée")).toBeInTheDocument();
    expect(within(dialog).getByText("Heures non renseignées")).toBeInTheDocument();
    expect(within(dialog).getByText("+1j")).toBeInTheDocument();
    expect(within(dialog).getAllByText("DÉBUT").length).toBeGreaterThan(0);

    await userEvent.click(within(dialog).getByRole("button", { name: /Dr Hélène Claes/ }));
    expect(await screen.findByTestId("mission-drawer")).toHaveTextContent("mission 5087");
  });

  it("affiche le bandeau provisoire, le mois vide et l'erreur", async () => {
    (fetchInstrumentistHours as Mock).mockResolvedValueOnce({ ...listFixture(), provisional: true, asOfDay: 3 });
    const { unmount } = renderPage();
    expect(await screen.findByText(/provisoire au 3/)).toBeInTheDocument();
    unmount();

    const empty = listFixture();
    empty.totals = { ...empty.totals, count: 0, encodedMinutes: 0, validatedMinutes: 0, pendingMinutes: 0, encodedAmount: null, validatedAmount: null, currency: null };
    (fetchInstrumentistHours as Mock).mockResolvedValueOnce(empty);
    const second = renderPage("/app/m/billing/hours?month=2026-08");
    expect(await screen.findByText(/Aucune prestation encodée en août 2026/)).toBeInTheDocument();
    expect(screen.getByText("Les instrumentistes actives restent listées à 0h00.")).toBeInTheDocument();
    second.unmount();

    (fetchInstrumentistHours as Mock).mockRejectedValueOnce(new Error("boom"));
    renderPage();
    expect(await screen.findByText("Impossible de charger le suivi des heures.")).toBeInTheDocument();
  });

  it("présente une liste de cartes sur mobile, avec tri par sélection", async () => {
    (useMediaQuery as unknown as Mock).mockReturnValue(false);
    renderPage();
    const card = (await screen.findByRole("button", { name: /Claire Dubois, 104h00 validées/ }));
    expect(within(card).getByText("≈ 4 680,00 €")).toBeInTheDocument();
    expect(within(card).getByText("+8 %")).toBeInTheDocument();
    expect(screen.queryByRole("table")).toBeNull();
    expect(screen.getByRole("combobox", { name: "Trier par" })).toBeInTheDocument();
  });
});
