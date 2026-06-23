import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AdminCreateUserModal } from "./AdminCreateUserModal";

vi.mock("../api/admin.api", () => ({
  createAdminUser: vi.fn().mockResolvedValue({ user: {}, warnings: [] }),
}));

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: vi.fn().mockResolvedValue({
      data: [
        { id: 1, name: "Delta" },
        { id: 2, name: "Saint-Jean" },
      ],
    }),
  },
}));

import { createAdminUser } from "../api/admin.api";

beforeEach(() => {
  vi.mocked(createAdminUser).mockClear();
});

function renderModal() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AdminCreateUserModal open onClose={() => {}} />
    </QueryClientProvider>
  );
}

async function fillRequiredFieldsExceptSite(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/Prénom/i), "Jean");
  await user.type(screen.getByLabelText(/^Nom/i), "Martin");
  await user.type(screen.getByLabelText(/^Email/i), "jean.martin@example.com");
  // PhoneInputField auto-fills "+32" from its default country on mount, which
  // fails phone validation unless cleared — unrelated to the site rules under test.
  const phoneInput = screen.getByLabelText(/Téléphone/i);
  await user.clear(phoneInput);
}

describe("AdminCreateUserModal — règles d'affiliation aux sites", () => {
  it("rejette la création d'un instrumentiste sans site", async () => {
    const user = userEvent.setup();
    renderModal();
    await waitFor(() => screen.getByText("Delta"));

    await fillRequiredFieldsExceptSite(user);
    await user.click(screen.getByRole("button", { name: "Créer" }));

    expect(await screen.findByText("Au moins un site requis")).toBeInTheDocument();
    expect(createAdminUser).not.toHaveBeenCalled();
  }, 10000);

  it("rejette la création d'un chirurgien sans site", async () => {
    const user = userEvent.setup();
    renderModal();
    await waitFor(() => screen.getByText("Delta"));

    await fillRequiredFieldsExceptSite(user);
    await user.click(screen.getByRole("radio", { name: "Chirurgien" }));
    await user.click(screen.getByRole("button", { name: "Créer" }));

    expect(await screen.findByText("Au moins un site requis")).toBeInTheDocument();
    expect(createAdminUser).not.toHaveBeenCalled();
  }, 10000);

  it("autorise la création d'un manager sans site", async () => {
    const user = userEvent.setup();
    renderModal();
    await waitFor(() => screen.getByText("Delta"));

    await fillRequiredFieldsExceptSite(user);
    await user.click(screen.getByRole("radio", { name: "Manager" }));
    await user.click(screen.getByRole("button", { name: "Créer" }));

    await waitFor(() => expect(createAdminUser).toHaveBeenCalledTimes(1));
    expect(createAdminUser).toHaveBeenCalledWith(
      expect.objectContaining({ role: "ROLE_MANAGER", siteIds: [] }),
      expect.anything()
    );
  });

  it("autorise la création d'un manager avec plusieurs sites", async () => {
    const user = userEvent.setup();
    renderModal();
    await waitFor(() => screen.getByText("Delta"));

    await fillRequiredFieldsExceptSite(user);
    await user.click(screen.getByRole("radio", { name: "Manager" }));
    await user.click(screen.getByRole("checkbox", { name: "Delta" }));
    await user.click(screen.getByRole("checkbox", { name: "Saint-Jean" }));
    await user.click(screen.getByRole("button", { name: "Créer" }));

    await waitFor(() => expect(createAdminUser).toHaveBeenCalledTimes(1));
    expect(createAdminUser).toHaveBeenCalledWith(
      expect.objectContaining({ role: "ROLE_MANAGER", siteIds: [1, 2] }),
      expect.anything()
    );
  });

  it("autorise la création d'un chirurgien avec plusieurs sites", async () => {
    const user = userEvent.setup();
    renderModal();
    await waitFor(() => screen.getByText("Delta"));

    await fillRequiredFieldsExceptSite(user);
    await user.click(screen.getByRole("radio", { name: "Chirurgien" }));
    await user.click(screen.getByRole("checkbox", { name: "Delta" }));
    await user.click(screen.getByRole("checkbox", { name: "Saint-Jean" }));
    await user.click(screen.getByRole("button", { name: "Créer" }));

    await waitFor(() => expect(createAdminUser).toHaveBeenCalledTimes(1));
    expect(createAdminUser).toHaveBeenCalledWith(
      expect.objectContaining({ role: "ROLE_SURGEON", siteIds: [1, 2] }),
      expect.anything()
    );
  });
});
