import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import AbsencesPage from "./AbsencesPage";
import type { Absence } from "../../../features/planning-manager/api/planning.api";

vi.mock("../../../features/planning-manager/api/planning.api", () => ({
  getAbsences: vi.fn().mockResolvedValue([]),
  createAbsence: vi.fn(),
  createIsolatedDayAbsences: vi.fn(),
  deleteAbsence: vi.fn(),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

vi.mock("../../../api/apiClient", () => ({
  apiClient: { get: vi.fn() },
}));

import * as planningApi from "../../../features/planning-manager/api/planning.api";
import { apiClient } from "../../../api/apiClient";

function makeAbsence(overrides: Partial<Absence> = {}): Absence {
  return {
    id: 1,
    user: { id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin" },
    dateStart: "2026-07-01",
    dateEnd: "2026-07-15",
    reason: null,
    createdAt: "2026-06-24T00:00:00Z",
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AbsencesPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
});

async function openCreateDialog(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole("button", { name: /Nouvelle absence/i }));
  await user.click(screen.getByText("Sélectionner une personne"));
  await user.click(await screen.findByText("Jean Martin (Chirurgien)"));
}

describe("AbsencesPage — non-régression mode Période (Cas 1)", () => {
  it("garde le comportement Du/Au existant : un seul appel createAbsence", async () => {
    vi.mocked(apiClient.get).mockImplementation(async (url: string) => {
      if (url === "/api/instrumentists") return { data: { items: [] } };
      if (url === "/api/surgeons") return { data: { items: [{ id: 1, firstname: "Jean", lastname: "Martin", email: "martin@test.com" }] } };
      return { data: {} };
    });
    vi.mocked(planningApi.createAbsence).mockResolvedValue(makeAbsence());

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);

    // "Période" is the default mode — no extra interaction needed.
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalledTimes(1));
    expect(planningApi.createIsolatedDayAbsences).not.toHaveBeenCalled();
    expect(planningApi.createAbsence).toHaveBeenCalledWith(
      expect.objectContaining({ userId: 1, dateStart: expect.any(String), dateEnd: expect.any(String) }),
    );
  });
});

describe("AbsencesPage — mode Jours isolés (Cas 3)", () => {
  it("désactive Enregistrer tant qu'aucun jour n'est ajouté, puis envoie tous les jours en un seul appel groupé", async () => {
    vi.mocked(apiClient.get).mockImplementation(async (url: string) => {
      if (url === "/api/instrumentists") return { data: { items: [] } };
      if (url === "/api/surgeons") return { data: { items: [{ id: 1, firstname: "Jean", lastname: "Martin", email: "martin@test.com" }] } };
      return { data: {} };
    });
    vi.mocked(planningApi.createIsolatedDayAbsences).mockResolvedValue([
      makeAbsence({ id: 1, dateStart: "2026-07-04", dateEnd: "2026-07-04" }),
      makeAbsence({ id: 2, dateStart: "2026-07-09", dateEnd: "2026-07-09" }),
      makeAbsence({ id: 3, dateStart: "2026-07-18", dateEnd: "2026-07-18" }),
    ]);

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);

    await user.click(screen.getByRole("button", { name: "Jours isolés" }));

    const enregistrer = screen.getByRole("button", { name: "Enregistrer" });
    expect(enregistrer).toBeDisabled();

    const dateInput = screen.getByLabelText("Ajouter une date");
    for (const date of ["2026-07-04", "2026-07-09", "2026-07-18"]) {
      await user.clear(dateInput);
      await user.type(dateInput, date);
      await user.click(screen.getByRole("button", { name: "Ajouter" }));
    }

    expect(screen.getByText("04/07/2026")).toBeInTheDocument();
    expect(screen.getByText("09/07/2026")).toBeInTheDocument();
    expect(screen.getByText("18/07/2026")).toBeInTheDocument();
    expect(enregistrer).toBeEnabled();

    await user.click(enregistrer);

    await waitFor(() => expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledTimes(1));
    expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledWith({
      userId: 1, dates: ["2026-07-04", "2026-07-09", "2026-07-18"], reason: undefined,
    });
    expect(planningApi.createAbsence).not.toHaveBeenCalled();
  });

  it("permet de retirer un jour isolé avant validation (Cas 4)", async () => {
    vi.mocked(apiClient.get).mockImplementation(async (url: string) => {
      if (url === "/api/instrumentists") return { data: { items: [] } };
      if (url === "/api/surgeons") return { data: { items: [{ id: 1, firstname: "Jean", lastname: "Martin", email: "martin@test.com" }] } };
      return { data: {} };
    });

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Jours isolés" }));

    const dateInput = screen.getByLabelText("Ajouter une date");
    for (const date of ["2026-07-04", "2026-07-09", "2026-07-18"]) {
      await user.clear(dateInput);
      await user.type(dateInput, date);
      await user.click(screen.getByRole("button", { name: "Ajouter" }));
    }

    const chip09 = screen.getByText("09/07/2026").closest(".MuiChip-root")!;
    await user.click(within(chip09 as HTMLElement).getByTestId("CloseIcon"));

    expect(screen.queryByText("09/07/2026")).not.toBeInTheDocument();
    expect(screen.getByText("04/07/2026")).toBeInTheDocument();
    expect(screen.getByText("18/07/2026")).toBeInTheDocument();
  });
});
