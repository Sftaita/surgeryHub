import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { InstrumentistsTable } from "./InstrumentistsTable";
import type { InstrumentistListItemDTO } from "../api/instrumentists.types";

vi.mock("../api/instrumentists.api", () => ({
  getInstrumentists: vi.fn(),
}));

import { getInstrumentists } from "../api/instrumentists.api";

beforeEach(() => {
  vi.mocked(getInstrumentists).mockReset();
  vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
});

function baseItem(overrides: Partial<InstrumentistListItemDTO> = {}): InstrumentistListItemDTO {
  return {
    id: 1,
    email: "jane@example.com",
    firstname: "Jane",
    lastname: "Doe",
    active: true,
    employmentType: null,
    defaultCurrency: "EUR",
    displayName: "Jane Doe",
    profilePicturePath: null,
    ...overrides,
  };
}

function renderTable() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <InstrumentistsTable onOpenInstrumentist={() => {}} />
    </QueryClientProvider>,
  );
}

describe("InstrumentistsTable — avatar", () => {
  it("affiche la photo de profil quand profilePicturePath est renseigné", async () => {
    vi.mocked(getInstrumentists).mockResolvedValue({
      items: [baseItem({ profilePicturePath: "/uploads/profile-pictures/jane.jpg" })],
      total: 1,
    });

    renderTable();

    const avatarImg = await screen.findByRole("img", { name: "Jane Doe" });
    expect(avatarImg).toHaveAttribute(
      "src",
      "https://api.surgicalhub.test/uploads/profile-pictures/jane.jpg",
    );
  });

  it("affiche les initiales en repli quand aucune photo n'est présente", async () => {
    vi.mocked(getInstrumentists).mockResolvedValue({
      items: [baseItem({ profilePicturePath: null })],
      total: 1,
    });

    renderTable();

    await waitFor(() => expect(screen.getByText("Jane Doe")).toBeInTheDocument());
    expect(screen.queryByRole("img", { name: "Jane Doe" })).not.toBeInTheDocument();
    expect(screen.getByText("JD")).toBeInTheDocument();
  });

  it("affiche l'email sous le nom dans la même cellule", async () => {
    vi.mocked(getInstrumentists).mockResolvedValue({
      items: [baseItem()],
      total: 1,
    });

    renderTable();

    await waitFor(() => expect(screen.getByText("jane@example.com")).toBeInTheDocument());
  });
});
