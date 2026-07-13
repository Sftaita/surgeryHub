import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { SurgeonsTable } from "./SurgeonsTable";
import type { SurgeonListItemDTO } from "../api/surgeons.types";

vi.mock("../api/surgeons.api", () => ({
  getSurgeons: vi.fn(),
}));

import { getSurgeons } from "../api/surgeons.api";

beforeEach(() => {
  vi.mocked(getSurgeons).mockReset();
  vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
});

function baseItem(overrides: Partial<SurgeonListItemDTO> = {}): SurgeonListItemDTO {
  return {
    id: 1,
    email: "jean.martin@example.com",
    firstname: "Jean",
    lastname: "Martin",
    displayName: "Jean Martin",
    active: true,
    profilePicturePath: null,
    ...overrides,
  };
}

function renderTable() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SurgeonsTable onOpenSurgeon={() => {}} />
    </QueryClientProvider>,
  );
}

describe("SurgeonsTable — avatar", () => {
  it("affiche la photo de profil quand profilePicturePath est renseigné", async () => {
    vi.mocked(getSurgeons).mockResolvedValue({
      items: [baseItem({ profilePicturePath: "/uploads/profile-pictures/jean.jpg" })],
      total: 1,
    });

    renderTable();

    const avatarImg = await screen.findByRole("img", { name: "Jean Martin" });
    expect(avatarImg).toHaveAttribute(
      "src",
      "https://api.surgicalhub.test/uploads/profile-pictures/jean.jpg",
    );
  });

  it("affiche les initiales en repli quand aucune photo n'est présente", async () => {
    vi.mocked(getSurgeons).mockResolvedValue({
      items: [baseItem({ profilePicturePath: null })],
      total: 1,
    });

    renderTable();

    await waitFor(() => expect(screen.getByText("Jean Martin")).toBeInTheDocument());
    expect(screen.queryByRole("img", { name: "Jean Martin" })).not.toBeInTheDocument();
    expect(screen.getByText("JM")).toBeInTheDocument();
  });

  it("affiche l'email sous le nom dans la même cellule", async () => {
    vi.mocked(getSurgeons).mockResolvedValue({
      items: [baseItem()],
      total: 1,
    });

    renderTable();

    await waitFor(() => expect(screen.getByText("jean.martin@example.com")).toBeInTheDocument());
  });
});
