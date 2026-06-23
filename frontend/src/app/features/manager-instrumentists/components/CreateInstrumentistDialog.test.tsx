import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CreateInstrumentistDialog } from "./CreateInstrumentistDialog";

vi.mock("../api/instrumentists.api", () => ({
  createInstrumentist: vi.fn().mockResolvedValue({ instrumentist: {}, warnings: [] }),
}));

vi.mock("../../sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([
    { id: 1, name: "Delta" },
    { id: 2, name: "Saint-Jean" },
  ]),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

import { createInstrumentist } from "../api/instrumentists.api";

beforeEach(() => {
  vi.mocked(createInstrumentist).mockClear();
});

function renderDialog() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <CreateInstrumentistDialog open onClose={() => {}} />
    </QueryClientProvider>
  );
}

describe("CreateInstrumentistDialog — règle métier : au moins un site requis", () => {
  it("rejette la création sans site sélectionné", async () => {
    const user = userEvent.setup();
    renderDialog();
    await waitFor(() => expect(screen.queryByRole("progressbar")).not.toBeInTheDocument());

    await user.type(screen.getByLabelText(/^Email/i), "instrumentiste@example.com");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    expect(
      await screen.findByText("Au moins un site est requis pour un instrumentiste.")
    ).toBeInTheDocument();
    expect(createInstrumentist).not.toHaveBeenCalled();
  });

  it("autorise la création avec plusieurs sites sélectionnés", async () => {
    const user = userEvent.setup();
    renderDialog();
    await waitFor(() => expect(screen.queryByRole("progressbar")).not.toBeInTheDocument());

    await user.type(screen.getByLabelText(/^Email/i), "instrumentiste@example.com");

    const sitesInput = screen.getByLabelText(/Sites d.activité/i);
    await user.click(sitesInput);
    await user.click(await screen.findByText("Delta"));
    await user.click(sitesInput);
    await user.click(await screen.findByText("Saint-Jean"));

    await user.click(screen.getByRole("button", { name: "Créer" }));

    await waitFor(() => expect(createInstrumentist).toHaveBeenCalledTimes(1));
    expect(createInstrumentist).toHaveBeenCalledWith(
      expect.objectContaining({ email: "instrumentiste@example.com", siteIds: [1, 2] })
    );
  });
});
