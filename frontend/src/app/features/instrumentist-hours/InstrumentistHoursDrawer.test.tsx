import { beforeEach, describe, expect, it, vi, type Mock } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useMediaQuery } from "@mui/material";
import { InstrumentistHoursDrawer } from "./InstrumentistHoursDrawer";
import { fetchInstrumentistHoursDetail } from "./api/instrumentistHours.api";
import type { InstrumentistHoursDetail } from "./api/instrumentistHours.types";
import { detailFixture } from "./testFixtures";

vi.mock("./api/instrumentistHours.api", () => ({ fetchInstrumentistHoursDetail: vi.fn() }));
vi.mock("../missions/drawer/ManagerMissionDrawer", () => ({ ManagerMissionDrawer: () => null }));
vi.mock("@mui/material", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@mui/material")>();
  return { ...actual, useMediaQuery: vi.fn() };
});

const client = () => new QueryClient({ defaultOptions: { queries: { retry: false } } });

function drawer(qc: QueryClient, id: number | null, month = "2026-10", siteId: number | null = null) {
  return (
    <QueryClientProvider client={qc}>
      <InstrumentistHoursDrawer instrumentistId={id} month={month} siteId={siteId} siteName={null} onClose={() => {}} />
    </QueryClientProvider>
  );
}

beforeEach(() => {
  (fetchInstrumentistHoursDetail as Mock).mockReset();
  (useMediaQuery as unknown as Mock).mockReturnValue(true);
});

describe("D-180 — tiroir individuel : jamais de données d'une autre sélection", () => {
  it("passe en chargement quand l'instrumentiste ou le mois change, sans montrer le relevé précédent", async () => {
    (fetchInstrumentistHoursDetail as Mock).mockResolvedValueOnce(detailFixture());
    const qc = client();
    const { rerender } = render(drawer(qc, 41));
    expect(await screen.findByText("Nadia Benali")).toBeInTheDocument();

    let resolveOther!: (d: InstrumentistHoursDetail) => void;
    (fetchInstrumentistHoursDetail as Mock).mockImplementationOnce(() => new Promise((r) => { resolveOther = r; }));
    rerender(drawer(qc, 12));
    expect(screen.queryByText("Nadia Benali")).toBeNull();
    expect(screen.queryByText("17h15")).toBeNull();
    expect(screen.getByText("Détail des heures")).toBeInTheDocument();

    resolveOther({ ...detailFixture(), instrumentist: { id: 12, firstname: "Thomas", lastname: "Leroy", active: true } });
    expect(await screen.findByText("Thomas Leroy")).toBeInTheDocument();

    (fetchInstrumentistHoursDetail as Mock).mockImplementationOnce(() => new Promise(() => {}));
    rerender(drawer(qc, 12, "2026-09"));
    expect(screen.queryByText("Thomas Leroy")).toBeNull();
    expect(fetchInstrumentistHoursDetail).toHaveBeenLastCalledWith(12, "2026-09", null);
  });

  it("ignore une réponse dont l'instrumentiste, le mois ou le site ne correspond pas", async () => {
    (fetchInstrumentistHoursDetail as Mock).mockResolvedValueOnce({ ...detailFixture(), siteId: 3 });
    render(drawer(client(), 41, "2026-10", null));
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByText("Nadia Benali")).toBeNull();
    expect(screen.getByText("Détail des heures")).toBeInTheDocument();
  });
});
