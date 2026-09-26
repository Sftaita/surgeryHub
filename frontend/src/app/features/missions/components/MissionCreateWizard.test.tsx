import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import dayjs from "dayjs";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import MissionCreateWizard from "./MissionCreateWizard";

const SITES = [{ id: 1, name: "Site Delta" }];
const SURGEONS = [{ id: 2, label: "Dr Martin" }];

function renderWizard() {
  return render(
    <MissionCreateWizard
      sites={SITES}
      surgeons={SURGEONS}
      onDone={vi.fn()}
      onCancel={vi.fn()}
    />,
  );
}

describe("MissionCreateWizard — horaires par défaut du formulaire (jamais une règle backend)", () => {
  it("préremplit Début 08:00 et Fin 17:00 le jour même, modifiables librement", async () => {
    const user = userEvent.setup();
    renderWizard();

    await user.click(screen.getByLabelText("Site"));
    await user.click(await screen.findByText("Site Delta"));
    await user.click(screen.getByLabelText("Chirurgien"));
    await user.click(await screen.findByText("Dr Martin"));
    await user.click(screen.getByRole("button", { name: "Continuer" }));

    const today = dayjs().format("YYYY-MM-DD");
    const startField = (await screen.findByLabelText("Début")) as HTMLInputElement;
    const endField = screen.getByLabelText("Fin") as HTMLInputElement;

    expect(startField.value).toBe(`${today}T08:00`);
    expect(endField.value).toBe(`${today}T17:00`);

    // Valeur de formulaire uniquement — librement modifiable, aucune contrainte.
    await user.clear(startField);
    await user.type(startField, `${today}T06:30`);
    expect(startField.value).toBe(`${today}T06:30`);
  });
});

// D-125 — same dispatch choice as every other origin: create (always DRAFT backend-side),
// then "Attribuer directement" to an instrumentist of the chosen site, picked by name.
const postMock = vi.fn();
const getMock = vi.fn();
vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    post: (...args: unknown[]) => postMock(...args),
    get: (...args: unknown[]) => getMock(...args),
  },
}));

describe("MissionCreateWizard — diffusion à la création (D-125)", () => {
  it("attribution directe : crée la mission puis l'attribue à l'instrumentiste choisie par son nom", async () => {
    postMock.mockReset();
    getMock.mockReset();
    postMock.mockImplementation((url: string) =>
      Promise.resolve({ data: url === "/api/missions" ? { id: 555 } : {} }),
    );
    getMock.mockResolvedValue({
      data: { candidates: [{ id: 41, name: "Salve Decorte", email: "salve@x.be", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null }] },
    });
    const onDone = vi.fn();
    const user = userEvent.setup();
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MissionCreateWizard sites={SITES} surgeons={SURGEONS} onDone={onDone} onCancel={vi.fn()} />
      </QueryClientProvider>,
    );

    await user.click(screen.getByLabelText("Site"));
    await user.click(await screen.findByText("Site Delta"));
    await user.click(screen.getByLabelText("Chirurgien"));
    await user.click(await screen.findByText("Dr Martin"));
    await user.click(screen.getByRole("button", { name: "Continuer" }));
    await user.click(await screen.findByRole("button", { name: "Continuer" }));

    await user.click(await screen.findByLabelText(/Attribuer directement/));
    await user.type(screen.getByRole("combobox"), "decorte");
    await user.click(await screen.findByRole("option", { name: /Salve Decorte/ }));
    await user.click(screen.getByRole("button", { name: "Créer et diffuser" }));

    await vi.waitFor(() => expect(onDone).toHaveBeenCalledWith({ missionId: 555, mode: "PUBLISH", dispatchMode: "DIRECT" }));
    expect(postMock.mock.calls.map(([url]) => url)).toEqual(["/api/missions", "/api/missions/555/assign-directly"]);
    expect(postMock).toHaveBeenLastCalledWith("/api/missions/555/assign-directly", { instrumentistId: 41 });
    // Candidates scoped to the chosen site.
    expect(getMock.mock.calls[0][0]).toBe("/api/missions/dispatch-candidates");
    expect(getMock.mock.calls[0][1].params.siteId).toBe(1);
  });
});
