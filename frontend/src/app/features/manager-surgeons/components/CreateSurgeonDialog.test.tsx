import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CreateSurgeonDialog } from "./CreateSurgeonDialog";

vi.mock("../api/surgeons.api", () => ({
  createSurgeon: vi.fn().mockResolvedValue({ surgeon: {}, warnings: [] }),
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

import { createSurgeon } from "../api/surgeons.api";

beforeEach(() => {
  vi.mocked(createSurgeon).mockClear();
});

function renderDialog() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <CreateSurgeonDialog open onClose={() => {}} />
    </QueryClientProvider>
  );
}

describe("CreateSurgeonDialog — règle métier : au moins un site requis", () => {
  it("rejette la création sans site sélectionné", async () => {
    const user = userEvent.setup();
    renderDialog();
    await waitFor(() => expect(screen.queryByRole("progressbar")).not.toBeInTheDocument());

    await user.type(screen.getByLabelText(/^Email/i), "chirurgien@example.com");
    await user.click(screen.getByRole("button", { name: "Créer" }));

    expect(
      await screen.findByText("Au moins un site est requis pour un chirurgien.")
    ).toBeInTheDocument();
    expect(createSurgeon).not.toHaveBeenCalled();
  });

  it("autorise la création avec plusieurs sites sélectionnés", async () => {
    const user = userEvent.setup();
    renderDialog();
    await waitFor(() => expect(screen.queryByRole("progressbar")).not.toBeInTheDocument());

    await user.type(screen.getByLabelText(/^Email/i), "chirurgien@example.com");

    const sitesInput = screen.getByLabelText(/Sites d'activité/i);
    await user.click(sitesInput);
    await user.click(await screen.findByText("Delta"));
    await user.click(sitesInput);
    await user.click(await screen.findByText("Saint-Jean"));

    await user.click(screen.getByRole("button", { name: "Créer" }));

    await waitFor(() => expect(createSurgeon).toHaveBeenCalledTimes(1));
    expect(createSurgeon).toHaveBeenCalledWith(
      expect.objectContaining({ email: "chirurgien@example.com", siteIds: [1, 2] })
    );
  });
});
