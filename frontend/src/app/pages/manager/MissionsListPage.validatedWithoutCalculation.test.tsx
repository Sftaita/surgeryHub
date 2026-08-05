import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import MissionsListPage from "./MissionsListPage";

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn(),
}));

vi.mock("../../features/missions/components/MissionsFiltersBar", () => ({
  MissionsFiltersBar: () => null,
}));

import { fetchMissions } from "../../features/missions/api/missions.api";

beforeEach(() => {
  vi.mocked(fetchMissions).mockReset();
  vi.mocked(fetchMissions).mockResolvedValue({ items: [], total: 0 });
});

function renderPage(initialEntry: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <MissionsListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/**
 * Diagnostic tarifs instrumentistes (2026-08-05) — tuile dashboard "Missions validées
 * sans calcul" (?validatedWithoutCalculation=true). Le backend reste la seule source
 * de vérité du résultat ; ce test vérifie uniquement que le filtre pré-appliqué est
 * transmis et rendu visible, jamais recalculé côté frontend.
 */
describe("MissionsListPage — filtre validatedWithoutCalculation", () => {
  it("transmet validatedWithoutCalculation=true à fetchMissions quand présent dans l'URL", async () => {
    renderPage("/app/m/missions?validatedWithoutCalculation=true");

    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    const [, , filters] = vi.mocked(fetchMissions).mock.calls[0];
    expect(filters).toMatchObject({ validatedWithoutCalculation: true });
  });

  it("affiche un chip « Validées sans calcul » quand le filtre est actif", async () => {
    renderPage("/app/m/missions?validatedWithoutCalculation=true");

    await waitFor(() => expect(screen.getByText("Validées sans calcul")).toBeInTheDocument());
  });

  it("n'ajoute pas le filtre et n'affiche pas le chip sans le paramètre d'URL", async () => {
    renderPage("/app/m/missions");

    await waitFor(() => expect(fetchMissions).toHaveBeenCalled());
    const [, , filters] = vi.mocked(fetchMissions).mock.calls[0];
    expect(filters).not.toHaveProperty("validatedWithoutCalculation");
    expect(screen.queryByText("Validées sans calcul")).not.toBeInTheDocument();
  });
});
