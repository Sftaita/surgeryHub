import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import PlanningTemplateEditorPage from "./PlanningTemplateEditorPage";

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock("../../../features/planning-manager/api/planning.api", () => ({
  getTemplate:    vi.fn(),
  addSlot:        vi.fn(),
  updateSlot:     vi.fn(),
  deleteSlot:     vi.fn(),
  renameTemplate: vi.fn(),
  DAY_LABELS: ["", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"],
}));

vi.mock("../../../api/apiClient", () => ({
  apiClient: { get: vi.fn() },
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

import * as planningApi from "../../../features/planning-manager/api/planning.api";
import { apiClient } from "../../../api/apiClient";

// ── Fixtures ──────────────────────────────────────────────────────────────────

const SURGEONS = [
  { id: 1, firstname: "Jean",  lastname: "Martin",  email: "jean.martin@test.com" },
  { id: 2, firstname: "Alice", lastname: "Bernard", email: "alice.bernard@test.com" },
  { id: 3, firstname: "Paul",  lastname: "Leroy",   email: "paul.leroy@test.com" },
];

const INSTRUMENTISTS = [
  { id: 10, firstname: "Ole",       lastname: "Salve",    email: "ole@test.com" },
  { id: 11, firstname: "Christine", lastname: "Decorte",  email: "christine@test.com" },
];

const TEMPLATE = {
  id: 3,
  type: "PAIR" as const,
  label: "Bloc genou",
  site: { id: 1, name: "Delta" },
  slots: [],
  createdAt: "2026-01-01T10:00:00+01:00",
};

// ── Render helper ─────────────────────────────────────────────────────────────

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={["/app/m/planning/templates/3"]}>
        <Routes>
          <Route path="/app/m/planning/templates/:id" element={<PlanningTemplateEditorPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  );
}

/** Open the "Ajouter un créneau" dialog for Lundi. */
async function openAddDialog() {
  const user = userEvent.setup();
  // Click the + button on the Lundi accordion header
  const addBtn = screen.getAllByRole("button", { name: /Ajouter un créneau/i })[0];
  await user.click(addBtn);
  await waitFor(() => screen.getByRole("dialog"));
  return user;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe("PlanningTemplateEditorPage — SlotDialog Autocomplete", () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(planningApi.getTemplate).mockResolvedValue(TEMPLATE);

    vi.mocked(apiClient.get).mockImplementation((url: string) => {
      if (url === "/api/surgeons") {
        return Promise.resolve({ data: { items: SURGEONS } });
      }
      if (url.startsWith("/api/instrumentists")) {
        return Promise.resolve({ data: { items: INSTRUMENTISTS } });
      }
      return Promise.resolve({ data: {} });
    });
  });

  // ── Rendu de base ─────────────────────────────────────────────────────────

  it("renders template label in header", async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText("Bloc genou")).toBeInTheDocument());
  });

  it("shows accordion for each weekday", async () => {
    renderPage();
    await waitFor(() => {
      expect(screen.getByText("Lundi")).toBeInTheDocument();
      expect(screen.getByText("Vendredi")).toBeInTheDocument();
    });
  });

  // ── Dialog add ────────────────────────────────────────────────────────────

  it("opens add dialog when + button is clicked", async () => {
    renderPage();
    await waitFor(() => screen.getAllByRole("button", { name: /Ajouter un créneau/i }));
    await openAddDialog();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(screen.getByText(/Ajouter un créneau/i)).toBeInTheDocument();
  });

  it("dialog contains Chirurgien and Instrumentiste autocomplete fields", async () => {
    renderPage();
    await waitFor(() => screen.getAllByRole("button", { name: /Ajouter un créneau/i }));
    await openAddDialog();

    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByLabelText(/Chirurgien/i)).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/Instrumentiste/i)).toBeInTheDocument();
  });

  // ── Autocomplete chirurgien ───────────────────────────────────────────────

  it("surgeon autocomplete filters list when typing", async () => {
    renderPage();
    await waitFor(() => screen.getAllByRole("button", { name: /Ajouter un créneau/i }));
    const user = await openAddDialog();

    const dialog = screen.getByRole("dialog");
    const surgeonInput = within(dialog).getByLabelText(/Chirurgien/i);

    await user.type(surgeonInput, "Jean");

    await waitFor(() => {
      expect(screen.getByRole("option", { name: /Jean Martin/i })).toBeInTheDocument();
      expect(screen.queryByRole("option", { name: /Alice Bernard/i })).not.toBeInTheDocument();
    });
  });

  it("surgeon autocomplete shows all options when field is focused empty", async () => {
    renderPage();
    await waitFor(() => screen.getAllByRole("button", { name: /Ajouter un créneau/i }));
    const user = await openAddDialog();

    const dialog  = screen.getByRole("dialog");
    const surgeonInput = within(dialog).getByLabelText(/Chirurgien/i);
    await user.click(surgeonInput);

    await waitFor(() => {
      expect(screen.getByRole("option", { name: /Jean Martin/i })).toBeInTheDocument();
      expect(screen.getByRole("option", { name: /Alice Bernard/i })).toBeInTheDocument();
      expect(screen.getByRole("option", { name: /Paul Leroy/i })).toBeInTheDocument();
    });
  });

  it("selecting a surgeon from the list closes the dropdown", async () => {
    renderPage();
    await waitFor(() => screen.getAllByRole("button", { name: /Ajouter un créneau/i }));
    const user = await openAddDialog();

    const dialog  = screen.getByRole("dialog");
    const surgeonInput = within(dialog).getByLabelText(/Chirurgien/i);
    await user.click(surgeonInput);
    await waitFor(() => screen.getByRole("option", { name: /Jean Martin/i }));
    await user.click(screen.getByRole("option", { name: /Jean Martin/i }));

    await waitFor(() =>
      expect(screen.queryByRole("option", { name: /Jean Martin/i })).not.toBeInTheDocument(),
    );
    expect(surgeonInput).toHaveValue("Jean Martin");
  });

  it("surgeon autocomplete shows 'Aucun résultat' when no match", async () => {
    renderPage();
    await waitFor(() => screen.getAllByRole("button", { name: /Ajouter un créneau/i }));
    const user = await openAddDialog();

    const dialog  = screen.getByRole("dialog");
    const surgeonInput = within(dialog).getByLabelText(/Chirurgien/i);
    await user.type(surgeonInput, "zzzznotfound");

    await waitFor(() =>
      expect(screen.getByText("Aucun résultat")).toBeInTheDocument(),
    );
  });

  // ── Autocomplete instrumentiste ───────────────────────────────────────────

  it("instrumentist autocomplete filters list when typing", async () => {
    renderPage();
    await waitFor(() => screen.getAllByRole("button", { name: /Ajouter un créneau/i }));
    const user = await openAddDialog();

    const dialog  = screen.getByRole("dialog");
    const instInput = within(dialog).getByLabelText(/Instrumentiste/i);
    await user.type(instInput, "Ole");

    await waitFor(() => {
      expect(screen.getByRole("option", { name: /Ole Salve/i })).toBeInTheDocument();
      expect(screen.queryByRole("option", { name: /Christine/i })).not.toBeInTheDocument();
    });
  });

  it("instrumentist field is optional — submit button enabled after surgeon selected", async () => {
    renderPage();
    await waitFor(() => screen.getAllByRole("button", { name: /Ajouter un créneau/i }));
    const user = await openAddDialog();

    const dialog = screen.getByRole("dialog");

    // Submit disabled initially (no surgeon)
    expect(within(dialog).getByRole("button", { name: /Ajouter/i })).toBeDisabled();

    // Select a surgeon
    const surgeonInput = within(dialog).getByLabelText(/Chirurgien/i);
    await user.click(surgeonInput);
    await waitFor(() => screen.getByRole("option", { name: /Jean Martin/i }));
    await user.click(screen.getByRole("option", { name: /Jean Martin/i }));

    // Submit now enabled (instrumentist not required)
    await waitFor(() =>
      expect(within(dialog).getByRole("button", { name: /Ajouter/i })).not.toBeDisabled(),
    );
  });
});
