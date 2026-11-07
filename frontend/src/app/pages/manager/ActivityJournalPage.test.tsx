import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import ActivityJournalPage from "./ActivityJournalPage";

const fetchAuditJournalMock = vi.fn();
vi.mock("../../features/audit-journal/api/auditJournal.api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../../features/audit-journal/api/auditJournal.api")>()),
  fetchAuditJournal: (...args: unknown[]) => fetchAuditJournalMock(...args),
}));

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <ActivityJournalPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

function lastFilters() {
  const calls = fetchAuditJournalMock.mock.calls;
  return calls[calls.length - 1][0];
}

beforeEach(() => {
  fetchAuditJournalMock.mockReset();
  fetchAuditJournalMock.mockResolvedValue({
    total: 1, page: 1, limit: 50,
    items: [{
      id: 1, eventType: "MISSION_ASSIGNED_DIRECTLY", category: "MISSION",
      occurredAt: "2026-10-12T09:00:00+02:00", actor: { id: 3, displayName: "Gérard Manager" },
      mission: {
        id: 77, status: "ASSIGNED", startAt: "2026-10-14T08:00:00+02:00", endAt: "2026-10-14T12:00:00+02:00",
        site: { id: 1, name: "CHIREC Delta" }, surgeon: { id: 5, displayName: "Arnaud Deltour" }, instrumentist: { id: 9, displayName: "Salve Decorte" },
      },
      surgeonMissionRequest: null,
      payload: {},
    }],
  });
});

describe("ActivityJournalPage (D-147)", () => {
  it("affiche chaque action avec son auteur et un lien vers la mission", async () => {
    renderPage();

    expect(await screen.findByText("Attribuée directement")).toBeInTheDocument();
    expect(screen.getByText(/par Gérard Manager/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Mission #77" })).toHaveAttribute("href", "/app/m/missions/77");
    // Default: last 7 days, all categories.
    expect(lastFilters().from).toBeDefined();
    expect(lastFilters()).not.toHaveProperty("category");
  });

  it("filtre par catégorie, période et recherche", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Attribuée directement");

    await user.click(screen.getByRole("button", { name: "Demandes chirurgien" }));
    await waitFor(() => expect(lastFilters().category).toBe("SURGEON_REQUEST"));

    await user.click(within(screen.getByRole("group", { name: "Période" })).getByRole("button", { name: "Tout" }));
    await waitFor(() => expect(lastFilters()).not.toHaveProperty("from"));

    await user.type(screen.getByPlaceholderText(/Auteur, chirurgien/), "deltour");
    await waitFor(() => expect(lastFilters().q).toBe("deltour"));
  });
});
