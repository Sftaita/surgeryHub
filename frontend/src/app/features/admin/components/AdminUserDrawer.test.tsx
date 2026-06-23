import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AdminUserDrawer } from "./AdminUserDrawer";
import type { AdminUserDetail } from "../api/admin.types";

vi.mock("../api/admin.api", () => ({
  getAdminUser: vi.fn(),
  resendAdminInvitation: vi.fn(),
  addAdminSiteMembership: vi.fn(),
  removeAdminSiteMembership: vi.fn(),
}));

vi.mock("../../sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([
    { id: 1, name: "Delta" },
    { id: 2, name: "Saint-Jean" },
    { id: 3, name: "Parc Léopold" },
  ]),
}));

import {
  getAdminUser,
  addAdminSiteMembership,
  removeAdminSiteMembership,
} from "../api/admin.api";

function buildUser(overrides: Partial<AdminUserDetail> = {}): AdminUserDetail {
  return {
    id: 1,
    email: "dr.x@example.com",
    firstname: "X",
    lastname: "Surgeon",
    phone: null,
    displayName: "Dr X",
    role: "SURGEON",
    active: true,
    invitationStatus: "used",
    invitationExpiresAt: null,
    invitationLastSentAt: null,
    siteMemberships: [
      { id: 10, site: { id: 1, name: "Delta" }, siteRole: "SURGEON" },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  vi.mocked(getAdminUser).mockReset();
  vi.mocked(addAdminSiteMembership).mockReset();
  vi.mocked(removeAdminSiteMembership).mockReset();
});

function renderDrawer(userId: number | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AdminUserDrawer userId={userId} onClose={() => {}} />
    </QueryClientProvider>
  );
}

describe("AdminUserDrawer — gestion des affiliations aux sites", () => {
  it("permet d'ajouter un nouveau site à un chirurgien multi-sites", async () => {
    vi.mocked(getAdminUser).mockResolvedValue(buildUser());
    vi.mocked(addAdminSiteMembership).mockResolvedValue({
      id: 11,
      site: { id: 2, name: "Saint-Jean" },
      siteRole: "SURGEON",
    });

    const user = userEvent.setup();
    renderDrawer(1);

    await waitFor(() => screen.getByText("Dr X"));
    expect(screen.getByText("Delta")).toBeInTheDocument();

    const addSiteInput = screen.getByPlaceholderText("Ajouter un site");
    await user.click(addSiteInput);
    await user.click(await screen.findByText("Saint-Jean"));
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    await waitFor(() =>
      expect(addAdminSiteMembership).toHaveBeenCalledWith(1, 2)
    );
  });

  it("permet de retirer un site existant d'un chirurgien multi-sites", async () => {
    vi.mocked(getAdminUser).mockResolvedValue(
      buildUser({
        siteMemberships: [
          { id: 10, site: { id: 1, name: "Delta" }, siteRole: "SURGEON" },
          { id: 11, site: { id: 2, name: "Saint-Jean" }, siteRole: "SURGEON" },
        ],
      })
    );
    vi.mocked(removeAdminSiteMembership).mockResolvedValue({ id: 11, deleted: true });

    const user = userEvent.setup();
    renderDrawer(1);

    await waitFor(() => screen.getByText("Dr X"));
    expect(screen.getByText("Saint-Jean")).toBeInTheDocument();
    const removeButtons = screen.getAllByRole("button", { name: "Retirer ce site" });

    await user.click(removeButtons[1]);

    await waitFor(() =>
      expect(removeAdminSiteMembership).toHaveBeenCalledWith(1, 11)
    );
  });
});
