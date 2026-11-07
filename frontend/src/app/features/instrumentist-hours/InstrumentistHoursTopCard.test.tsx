import { beforeEach, describe, expect, it, vi, type Mock } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useMediaQuery } from "@mui/material";
import { InstrumentistHoursTopCard } from "./InstrumentistHoursTopCard";
import { fetchInstrumentistHours } from "./api/instrumentistHours.api";
import { listFixture } from "./testFixtures";
import { currentMonth, shiftMonth } from "../hours-shared/format";

const { mockNavigate } = vi.hoisted(() => ({ mockNavigate: vi.fn() }));
vi.mock("./api/instrumentistHours.api", () => ({ fetchInstrumentistHours: vi.fn() }));
vi.mock("@mui/material", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@mui/material")>();
  return { ...actual, useMediaQuery: vi.fn() };
});
vi.mock("react-router-dom", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react-router-dom")>();
  return { ...actual, useNavigate: () => mockNavigate };
});

const MONTH = currentMonth();
const PREV = shiftMonth(MONTH, -1);

function top5(month = MONTH) {
  const data = listFixture(month, shiftMonth(month, -1));
  return { ...data, items: data.items.slice(0, 5) };
}

function renderCard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter><InstrumentistHoursTopCard /></MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  mockNavigate.mockReset();
  (fetchInstrumentistHours as Mock).mockReset();
  (useMediaQuery as unknown as Mock).mockReturnValue(true);
});

describe("D-180 — carte Top 5 des instrumentistes", () => {
  it("demande le classement du mois courant au serveur et affiche ses valeurs telles quelles", async () => {
    (fetchInstrumentistHours as Mock).mockResolvedValue(top5());
    renderCard();

    expect(screen.getByTestId("hours-top5-loading")).toBeInTheDocument();
    await screen.findByText("Claire Dubois");
    expect(fetchInstrumentistHours).toHaveBeenCalledWith({ month: MONTH, limit: 5 });

    const rows = within(screen.getByRole("list")).getAllByRole("listitem");
    expect(rows).toHaveLength(5);
    expect(within(rows[0]).getByText("104h00")).toBeInTheDocument();
    expect(within(rows[0]).getByText("≈ 4 680,00 €")).toBeInTheDocument();
    expect(within(rows[0]).getByText("+8 %")).toBeInTheDocument();
    expect(within(rows[3]).getByText("Tarif manquant")).toBeInTheDocument();
    expect(within(rows[3]).getByText("−14 %")).toBeInTheDocument();
    // Comparaison indisponible : « — » expliqué, jamais un pourcentage.
    expect(within(rows[4]).getAllByText("—")).toHaveLength(2);
    expect(within(rows[4]).getAllByLabelText(/Pas d'historique/)).toHaveLength(2);
  });

  it("navigue vers la page complète sur le mois affiché et ne va jamais dans le futur", async () => {
    (fetchInstrumentistHours as Mock).mockImplementation(({ month }: { month: string }) => Promise.resolve(top5(month)));
    renderCard();
    await screen.findByText("Claire Dubois");

    expect(screen.getByRole("button", { name: "Mois suivant" })).toBeDisabled();
    await userEvent.click(screen.getByRole("button", { name: "Mois précédent" }));
    await waitFor(() => expect(fetchInstrumentistHours).toHaveBeenLastCalledWith({ month: PREV, limit: 5 }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Mois suivant" })).toBeEnabled());

    await userEvent.click(screen.getByRole("button", { name: /En savoir plus/ }));
    expect(mockNavigate).toHaveBeenCalledWith(`/app/m/billing/hours?month=${PREV}`);
  });

  it("marque le mois en cours comme provisoire, à date équivalente", async () => {
    (fetchInstrumentistHours as Mock).mockResolvedValue({ ...top5(), provisional: true, asOfDay: 3 });
    renderCard();
    expect(await screen.findByText(/Provisoire · au 3/)).toBeInTheDocument();
    expect(screen.getByText(/^vs 1–3 /)).toBeInTheDocument();
  });

  it("affiche un mois sans heure validée et une erreur réessayable", async () => {
    const empty = { ...top5(), items: [], totals: { ...top5().totals, pendingMinutes: 1080 } };
    (fetchInstrumentistHours as Mock).mockResolvedValueOnce(empty);
    renderCard();
    expect(await screen.findByText(/Aucune heure validée en/)).toBeInTheDocument();
    expect(screen.getByText("18h00 encodées en attente de validation.")).toBeInTheDocument();
  });

  it("propose de réessayer en cas d'erreur", async () => {
    (fetchInstrumentistHours as Mock).mockRejectedValueOnce(new Error("boom")).mockResolvedValueOnce(top5());
    renderCard();
    await userEvent.click(await screen.findByRole("button", { name: "Réessayer" }));
    expect(await screen.findByText("Claire Dubois")).toBeInTheDocument();
  });

  it("empile la ligne sur mobile, avec ses deux évolutions", async () => {
    (useMediaQuery as unknown as Mock).mockReturnValue(false);
    (fetchInstrumentistHours as Mock).mockResolvedValue(top5());
    renderCard();
    const first = (await screen.findByText("Claire Dubois")).closest("li")!;
    expect(within(first).getByText("+8 %")).toBeInTheDocument();
    expect(within(first).getByText("+6 %")).toBeInTheDocument();
    expect(within(first).getByText("vs moy. 6 m", { exact: false })).toBeInTheDocument();
  });
});
