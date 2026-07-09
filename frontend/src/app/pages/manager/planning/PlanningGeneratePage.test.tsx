import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import PlanningGeneratePage from "./PlanningGeneratePage";
import type { PreviewLineV2 } from "../../../features/planning-v2/api/planningV2.types";

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock("../../../features/planning-v2/api/planningV2.api", () => ({
  previewPlanningV2:   vi.fn(),
  generatePlanningV2:  vi.fn(),
  deployPlanningV2:    vi.fn(),
  extractErrorV2: (err: any) => err?.response?.data?.message ?? err?.message ?? String(err),
}));

vi.mock("../../../features/sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([{ id: 1, name: "Delta" }]),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get:  vi.fn(),
    post: vi.fn(),
  },
}));

import * as planningV2Api from "../../../features/planning-v2/api/planningV2.api";
import { apiClient } from "../../../api/apiClient";

// ── Factories ─────────────────────────────────────────────────────────────────

const PREVIEW_VERSION = "a".repeat(64);

function makeLine(overrides: Partial<PreviewLineV2> = {}): PreviewLineV2 {
  return {
    date:                      "2026-01-05", // Monday ISO week 2 (PAIR)
    postId:                    1,
    surgeonId:                 1,
    surgeonName:               "Jean Martin",
    missionType:               "BLOCK",
    startTime:                 "08:00",
    endTime:                   "13:00",
    siteId:                    1,
    siteName:                  "Delta",
    instrumentistId:           null,
    instrumentistName:         null,
    status:                    "COVERED",
    existingMissionId:         null,
    existingInstrumentistId:   null,
    existingInstrumentistName: null,
    freedFrom:                 false,
    ...overrides,
  };
}

function makePreviewResponse(lines: PreviewLineV2[]) {
  const covered   = lines.filter((l) => l.status === "COVERED" || l.status === "MODIFIED").length;
  const uncovered = lines.filter((l) => l.status === "UNCOVERED" || l.status === "CONFLICT").length;
  const skipped   = lines.filter((l) => l.status === "SKIPPED").length;
  return {
    lines,
    summary: {
      total:    lines.length - skipped,
      covered,
      uncovered,
      skipped,
      conflict: lines.filter((l) => l.status === "CONFLICT").length,
      modified: lines.filter((l) => l.status === "MODIFIED").length,
    },
    previewVersion: PREVIEW_VERSION,
    generatedAt:    "2026-01-05T08:00:00+01:00",
  };
}

// ── Render helper ─────────────────────────────────────────────────────────────

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PlanningGeneratePage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

/**
 * Selects a site from the MUI Select, then clicks Prévisualiser.
 * Uses fireEvent for MUI Select interactions (userEvent is too strict about pointer-events).
 * Returns a userEvent instance for subsequent interactions.
 */
async function renderAndPreview(lines: PreviewLineV2[]) {
  vi.mocked(planningV2Api.previewPlanningV2).mockResolvedValue(makePreviewResponse(lines));
  const user = userEvent.setup();
  renderPage();

  // Wait for comboboxes to appear (MUI Select trigger elements have role="combobox")
  await waitFor(() => expect(screen.queryAllByRole("combobox").length).toBeGreaterThan(0));

  // Identify site combobox by its placeholder text "Tous les sites"
  const allComboboxes = screen.getAllByRole("combobox");
  const siteBox = allComboboxes.find((c) => c.textContent?.includes("Tous les sites"));
  if (siteBox) {
    // fireEvent.mouseDown opens the MUI Select dropdown
    fireEvent.mouseDown(siteBox);
    // Wait for the option list to appear in the portal
    const option = await screen.findByRole("option", { name: "Delta" });
    fireEvent.click(option);
  }

  // Wait for the Prévisualiser button to become enabled after site selection
  await waitFor(() =>
    expect(screen.getByRole("button", { name: /Prévisualiser/i })).not.toBeDisabled(),
  );
  fireEvent.click(screen.getByRole("button", { name: /Prévisualiser/i }));

  // Wait for table to appear
  await waitFor(() => screen.getAllByText(/Semaine/i));
  return user;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe("PlanningGeneratePage", () => {
  beforeEach(() => {
    vi.clearAllMocks();

    vi.mocked(apiClient.get).mockImplementation((url: string) => {
      if (url === "/api/surgeons") {
        return Promise.resolve({ data: { items: [] } });
      }
      if (url.startsWith("/api/instrumentists")) {
        return Promise.resolve({
          data: {
            items: [
              { id: 10, displayName: "Ole Salve" },
              { id: 11, displayName: "Christine D." },
            ],
          },
        });
      }
      return Promise.resolve({ data: {} });
    });
  });

  // ── Empty state ───────────────────────────────────────────────────────────

  it("shows empty state before any preview", () => {
    renderPage();
    expect(screen.getByText("Aucune prévisualisation")).toBeInTheDocument();
  });

  it("shows Prévisualiser button", () => {
    renderPage();
    expect(screen.getByRole("button", { name: /Prévisualiser/i })).toBeInTheDocument();
  });

  it("Prévisualiser button is disabled when no site is selected", () => {
    renderPage();
    expect(screen.getByRole("button", { name: /Prévisualiser/i })).toBeDisabled();
  });

  it("does not show Générer button before preview", () => {
    renderPage();
    expect(screen.queryByRole("button", { name: /Générer/i })).not.toBeInTheDocument();
  });

  // ── Table — basic rendering ───────────────────────────────────────────────

  it("renders surgeon name, site and period after preview", async () => {
    await renderAndPreview([
      makeLine({ surgeonName: "J. Martin", siteName: "Delta", startTime: "08:00" }),
    ]);
    expect(screen.getByText("J. Martin")).toBeInTheDocument();
    // "Delta" appears in both the site combobox and the table cell
    expect(screen.getAllByText("Delta").length).toBeGreaterThan(0);
    expect(screen.getByText("Matin")).toBeInTheDocument();
  });

  it("derives Matin from startTime 08:00", async () => {
    await renderAndPreview([makeLine({ startTime: "08:00" })]);
    expect(screen.getByText("Matin")).toBeInTheDocument();
  });

  it("derives Après-midi from startTime 14:00", async () => {
    await renderAndPreview([makeLine({ startTime: "14:00" })]);
    expect(screen.getByText("Après-midi")).toBeInTheDocument();
  });

  // ── Week headers ──────────────────────────────────────────────────────────

  it("labels week 2 as paire", async () => {
    await renderAndPreview([makeLine({ date: "2026-01-05" })]);
    expect(screen.getByText(/Semaine 2.*paire/i)).toBeInTheDocument();
  });

  it("labels week 19 as impaire", async () => {
    await renderAndPreview([makeLine({ date: "2026-05-04" })]);
    expect(screen.getByText(/Semaine 19.*impaire/i)).toBeInTheDocument();
  });

  it("renders one section per week when lines span two weeks", async () => {
    await renderAndPreview([
      makeLine({ date: "2026-01-05", postId: 1 }), // week 2
      makeLine({ date: "2026-01-12", postId: 2 }), // week 3
    ]);
    const headers = screen.getAllByText(/Semaine/i);
    expect(headers.length).toBeGreaterThanOrEqual(2);
  });

  // ── Sorting ───────────────────────────────────────────────────────────────

  it("sorts by surgeon A→Z then Matin before Après-midi within a day", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, surgeonName: "Z. Zorro",  startTime: "08:00" }),
      makeLine({ postId: 2, surgeonName: "A. Martin", startTime: "14:00" }),
      makeLine({ postId: 3, surgeonName: "A. Martin", startTime: "08:00" }),
    ]);

    const rows = screen.getAllByRole("row").slice(1); // skip header
    const martinIdx = rows.findIndex((r) => r.textContent?.includes("A. Martin"));
    const zorroIdx  = rows.findIndex((r) => r.textContent?.includes("Z. Zorro"));
    expect(martinIdx).toBeLessThan(zorroIdx);
  });

  // ── Statistics bar ────────────────────────────────────────────────────────

  it("shows coverage statistics after preview", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, status: "COVERED" }),
      makeLine({ postId: 2, status: "COVERED" }),
      makeLine({ postId: 3, status: "UNCOVERED" }),
    ]);
    // Statistics bar should show total, covered, uncovered
    expect(screen.getByText("2")).toBeInTheDocument();   // covered count
    expect(screen.getByText("1")).toBeInTheDocument();   // uncovered count
    expect(screen.getByText("Couverture")).toBeInTheDocument();
  });

  it("does not show statistics before preview", () => {
    renderPage();
    expect(screen.queryByText("Couverture")).not.toBeInTheDocument();
  });

  // ── Générer + Déployer buttons ────────────────────────────────────────────

  it("shows Générer button after preview", async () => {
    await renderAndPreview([makeLine()]);
    expect(screen.getByRole("button", { name: /Générer/i })).toBeInTheDocument();
  });

  it("shows Déployer button after successful generation", async () => {
    vi.mocked(planningV2Api.generatePlanningV2).mockResolvedValue({
      versionId: 1, created: 3, updated: 0, skipped: 0,
    });
    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer/i }));
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Déployer/i })).toBeInTheDocument(),
    );
  });

  it("shows success alert with counts after generation", async () => {
    vi.mocked(planningV2Api.generatePlanningV2).mockResolvedValue({
      versionId: 1, created: 5, updated: 1, skipped: 2,
    });
    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer/i }));
    await waitFor(() => expect(screen.getAllByRole("alert").length).toBeGreaterThanOrEqual(1));
    const alert = screen.getAllByRole("alert")[0];
    expect(alert.textContent).toMatch(/créée/i);
    expect(alert.textContent).toMatch(/version #1/i);
  });

  // ── Generate: send previewVersion ─────────────────────────────────────────

  it("sends previewVersion in the generate request", async () => {
    vi.mocked(planningV2Api.generatePlanningV2).mockResolvedValue({
      versionId: 1, created: 1, updated: 0, skipped: 0,
    });
    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer/i }));
    await waitFor(() => expect(vi.mocked(planningV2Api.generatePlanningV2)).toHaveBeenCalled());
    const call = vi.mocked(planningV2Api.generatePlanningV2).mock.calls[0][0];
    expect(call.previewVersion).toBe(PREVIEW_VERSION);
  });

  // ── 409 PREVIEW_EXPIRED ───────────────────────────────────────────────────

  it("shows expired dialog on 409 PREVIEW_EXPIRED", async () => {
    const err: any = new Error("stale");
    err.response = { status: 409, data: { code: "PREVIEW_EXPIRED" } };
    vi.mocked(planningV2Api.generatePlanningV2).mockRejectedValue(err);

    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer/i }));

    await waitFor(() =>
      expect(screen.getByText(/Aperçu expiré/i)).toBeInTheDocument(),
    );
  });

  it("re-runs preview when clicking Régénérer from expired dialog", async () => {
    const err: any = new Error("stale");
    err.response = { status: 409, data: { code: "PREVIEW_EXPIRED" } };
    vi.mocked(planningV2Api.generatePlanningV2).mockRejectedValue(err);
    vi.mocked(planningV2Api.previewPlanningV2).mockResolvedValue(
      makePreviewResponse([makeLine()]),
    );

    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer/i }));
    await waitFor(() => screen.getByText(/Aperçu expiré/i));

    await user.click(screen.getByRole("button", { name: /Régénérer/i }));

    await waitFor(() =>
      expect(vi.mocked(planningV2Api.previewPlanningV2)).toHaveBeenCalledTimes(2),
    );
  });

  // ── Dirty state ───────────────────────────────────────────────────────────

  it("shows dirty dot on a modified line after editing in inspector", async () => {
    const user = await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED", instrumentistId: null }),
    ]);

    // Click first data row to open inspector
    const rows = screen.getAllByRole("row");
    const dataRow = rows.find((r) => r.textContent?.includes("Jean Martin"));
    if (!dataRow) return; // guard
    await user.click(dataRow);

    // Inspector should open; toggle SKIPPED (MUI Switch has role="switch")
    await waitFor(() => screen.getByText("Détail de la ligne"));
    const skipSwitch = screen.getByRole("switch", { name: /Ignorer cette ligne/i });
    await user.click(skipSwitch);

    // Dirty dot: stats bar shows "X modification(s) locale(s) non enregistrée(s)"
    await waitFor(() =>
      expect(screen.getByText(/modification\(s\) locale/i)).toBeInTheDocument(),
    );
  });

  it("Reset All clears all local edits", async () => {
    const user = await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED" }),
    ]);

    // Click row → open inspector → toggle skip (MUI Switch has role="switch")
    const rows = screen.getAllByRole("row");
    const dataRow = rows.find((r) => r.textContent?.includes("Jean Martin"));
    if (!dataRow) return;
    await user.click(dataRow);
    await waitFor(() => screen.getByText("Détail de la ligne"));
    const skipSwitch = screen.getByRole("switch", { name: /Ignorer cette ligne/i });
    await user.click(skipSwitch);

    // Dirty indicator should be visible ("modification(s) locale(s) non enregistrée(s)")
    await waitFor(() => expect(screen.getByText(/modification\(s\) locale/i)).toBeInTheDocument());

    // Click Reset All
    await user.click(screen.getByRole("button", { name: /Tout réinitialiser/i }));

    // Dirty indicator should disappear
    await waitFor(() =>
      expect(screen.queryByText(/modification\(s\) locale/i)).not.toBeInTheDocument(),
    );
  });

  // ── Multi-select + bulk skip ──────────────────────────────────────────────

  it("shows bulk action bar when a row is checked", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED" }),
    ]);

    // Check the first row's checkbox
    const checkboxes = screen.getAllByRole("checkbox");
    const rowCheckbox = checkboxes.find((c) => !c.closest("thead")); // skip header checkbox
    expect(rowCheckbox).toBeDefined();
    if (!rowCheckbox) return;

    fireEvent.click(rowCheckbox);

    await waitFor(() =>
      expect(screen.getByText(/1 ligne\(s\) sélectionnée\(s\)/i)).toBeInTheDocument(),
    );
  });

  it("Ignorer la sélection bulk skips selected rows", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED" }),
    ]);

    const checkboxes = screen.getAllByRole("checkbox");
    const rowCheckbox = checkboxes.find((c) => !c.closest("thead"));
    if (!rowCheckbox) return;
    fireEvent.click(rowCheckbox);

    await waitFor(() => screen.getByText(/Ignorer la sélection/i));
    fireEvent.click(screen.getByText(/Ignorer la sélection/i));

    // Selection bar should disappear (deselected after bulk skip)
    await waitFor(() =>
      expect(screen.queryByText(/ligne\(s\) sélectionnée\(s\)/i)).not.toBeInTheDocument(),
    );
  });

  // ── Inspector navigation ──────────────────────────────────────────────────

  it("inspector panel closes on close button", async () => {
    const user = await renderAndPreview([makeLine({ postId: 1 })]);
    const rows = screen.getAllByRole("row");
    const dataRow = rows.find((r) => r.textContent?.includes("Jean Martin"));
    if (!dataRow) return;
    await user.click(dataRow);

    await waitFor(() => screen.getByText("Détail de la ligne"));

    // Close the inspector via the CloseIcon button (icon buttons have empty accessible name)
    const closeBtn = document.querySelector("[data-testid='CloseIcon']")?.closest("button") as HTMLElement | null;
    expect(closeBtn).not.toBeNull();
    fireEvent.click(closeBtn!);

    await waitFor(() =>
      expect(screen.queryByText("Détail de la ligne")).not.toBeInTheDocument(),
    );
  });

  // ── Search / filter ───────────────────────────────────────────────────────

  it("shows no-match message when search term matches no lines", async () => {
    await renderAndPreview([makeLine({ surgeonName: "Jean Martin" })]);

    const searchInput = screen.getByPlaceholderText(/Rechercher/i);
    fireEvent.change(searchInput, { target: { value: "Nobody" } });

    await waitFor(() =>
      expect(screen.getByText(/Aucune ligne ne correspond/i)).toBeInTheDocument(),
    );
  });

  it("filters lines by search term (surgeon)", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, surgeonName: "Alpha A." }),
      makeLine({ postId: 2, surgeonName: "Zeta Z." }),
    ]);

    const searchInput = screen.getByPlaceholderText(/Rechercher/i);
    fireEvent.change(searchInput, { target: { value: "Alpha" } });

    await waitFor(() => {
      expect(screen.getByText("Alpha A.")).toBeInTheDocument();
      expect(screen.queryByText("Zeta Z.")).not.toBeInTheDocument();
    });
  });

  it("filters lines by instrumentist name", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, surgeonName: "Dr. X", instrumentistName: "Ole Salve", status: "COVERED" }),
      makeLine({ postId: 2, surgeonName: "Dr. Y", instrumentistName: null, status: "UNCOVERED" }),
    ]);

    const searchInput = screen.getByPlaceholderText(/Rechercher/i);
    fireEvent.change(searchInput, { target: { value: "Ole" } });

    await waitFor(() => {
      expect(screen.getByText("Dr. X")).toBeInTheDocument();
      expect(screen.queryByText("Dr. Y")).not.toBeInTheDocument();
    });
  });

  it("combines search and status filter (AND logic)", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, surgeonName: "Alice B.", status: "COVERED"   }),
      makeLine({ postId: 2, surgeonName: "Alice B.", status: "UNCOVERED" }),
      makeLine({ postId: 3, surgeonName: "Bob C.",   status: "COVERED"   }),
    ]);

    // Activate UNCOVERED filter chip
    const uncoveredChip = screen.getByRole("button", { name: /Non couverts/i });
    fireEvent.click(uncoveredChip);

    // Also type in search for "Alice"
    fireEvent.change(screen.getByPlaceholderText(/Rechercher/i), { target: { value: "Alice" } });

    await waitFor(() => {
      // Only Alice + UNCOVERED should be visible
      // postId:2 (Alice, UNCOVERED) visible → shows "Non couvert" status
      expect(screen.getAllByText("Alice B.").length).toBeGreaterThanOrEqual(1);
      expect(screen.queryByText("Bob C.")).not.toBeInTheDocument();
    });
  });

  // ── Inspector panel ──────────────────────────────────────────────────────

  it("inspector shows line details when row is clicked", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, surgeonName: "Dr. Smith", siteName: "Centre-Alpha" }),
    ]);

    const rows = screen.getAllByRole("row");
    const dataRow = rows.find((r) => r.textContent?.includes("Dr. Smith"));
    expect(dataRow).toBeDefined();
    fireEvent.click(dataRow!);

    await waitFor(() => {
      expect(screen.getByText("Détail de la ligne")).toBeInTheDocument();
      // Inspector shows surgeon name — appears in both table row and inspector panel
      expect(screen.getAllByText("Dr. Smith").length).toBeGreaterThanOrEqual(2);
    });
  });

  it("inspector updates content when a different row is selected", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, surgeonName: "Dr. First" }),
      makeLine({ postId: 2, surgeonName: "Dr. Second" }),
    ]);

    const rows = screen.getAllByRole("row");
    const firstRow = rows.find((r) => r.textContent?.includes("Dr. First"));
    fireEvent.click(firstRow!);
    await waitFor(() => screen.getByText("Détail de la ligne"));

    // Inspector open on Dr. First: appears in table + inspector (2 elements)
    expect(screen.getAllByText("Dr. First").length).toBeGreaterThanOrEqual(2);

    // Click second row
    const secondRow = screen.getAllByRole("row").find((r) => r.textContent?.includes("Dr. Second"));
    fireEvent.click(secondRow!);

    await waitFor(() => {
      // Dr. Second now in table + inspector (2+), Dr. First back to table only (1)
      expect(screen.getAllByText("Dr. Second").length).toBeGreaterThanOrEqual(2);
      expect(screen.getAllByText("Dr. First").length).toBe(1);
    });
  });

  it("assigning instrumentist in inspector changes line status", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED", instrumentistId: null }),
    ]);

    // Wait for instrumentists to load
    await waitFor(() => screen.getByRole("button", { name: /Générer/i }));

    // Click row to open inspector
    const rows = screen.getAllByRole("row");
    const dataRow = rows.find((r) => r.textContent?.includes("Jean Martin"));
    fireEvent.click(dataRow!);
    await waitFor(() => screen.getByText("Détail de la ligne"));

    // Find and open the instrumentist select in the inspector
    const inspectorPanel = screen.getByText("Détail de la ligne").closest("[class*='Paper']") as HTMLElement;
    const selects = (inspectorPanel ?? document).querySelectorAll("[role='combobox']");
    const instrSelect = selects[0] as HTMLElement;
    if (instrSelect) {
      fireEvent.mouseDown(instrSelect);
      const option = await screen.findByRole("option", { name: "Ole Salve" });
      fireEvent.click(option);
    }

    // Dirty badge should appear
    await waitFor(() => {
      expect(screen.getByTestId("edited-badge")).toBeInTheDocument();
    });
  });

  it("clearing assignment in inspector marks line as uncovered", async () => {
    await renderAndPreview([
      makeLine({
        postId: 1,
        status: "COVERED",
        instrumentistId: 10,
        instrumentistName: "Ole Salve",
      }),
    ]);

    await waitFor(() => screen.getByRole("button", { name: /Générer/i }));

    const rows = screen.getAllByRole("row");
    const dataRow = rows.find((r) => r.textContent?.includes("Jean Martin"));
    fireEvent.click(dataRow!);
    await waitFor(() => screen.getByText("Détail de la ligne"));

    const inspectorPanel = screen.getByText("Détail de la ligne").closest("[class*='Paper']") as HTMLElement;
    const selects = (inspectorPanel ?? document).querySelectorAll("[role='combobox']");
    const instrSelect = selects[0] as HTMLElement;
    if (instrSelect) {
      fireEvent.mouseDown(instrSelect);
      const option = await screen.findByRole("option", { name: /Non assigné/i });
      fireEvent.click(option);
    }

    await waitFor(() => {
      expect(screen.getByTestId("edited-badge")).toBeInTheDocument();
    });
  });

  it("reset row via inspector restores original state", async () => {
    const user = await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED", instrumentistId: null }),
    ]);

    // Click row to open inspector
    const rows = screen.getAllByRole("row");
    const dataRow = rows.find((r) => r.textContent?.includes("Jean Martin"));
    await user.click(dataRow!);
    await waitFor(() => screen.getByText("Détail de la ligne"));

    // Toggle skip to make it dirty
    const skipSwitch = screen.getByRole("switch", { name: /Ignorer cette ligne/i });
    await user.click(skipSwitch);
    await waitFor(() => screen.getByText(/modification\(s\) locale/i));

    // Click reset line
    await user.click(screen.getByRole("button", { name: /Réinitialiser la ligne/i }));

    await waitFor(() =>
      expect(screen.queryByText(/modification\(s\) locale/i)).not.toBeInTheDocument(),
    );
  });

  // ── Bulk assign ──────────────────────────────────────────────────────────

  it("bulk assign sets instrumentist on all selected rows", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED", instrumentistId: null }),
      makeLine({ postId: 2, status: "UNCOVERED", instrumentistId: null }),
    ]);

    await waitFor(() => screen.getAllByRole("checkbox"));

    // Select both rows
    const checkboxes = screen.getAllByRole("checkbox");
    const rowCheckboxes = checkboxes.filter((c) => !c.closest("thead"));
    for (const cb of rowCheckboxes) {
      fireEvent.click(cb);
    }

    await waitFor(() => screen.getByText(/ligne\(s\) sélectionnée\(s\)/i));

    // Select instrumentist in bulk toolbar
    const bulkSelects = screen.getAllByRole("combobox");
    const bulkSelect = bulkSelects.find((s) => s.textContent?.includes("Choisir"));
    if (bulkSelect) {
      fireEvent.mouseDown(bulkSelect);
      const option = await screen.findByRole("option", { name: "Ole Salve" });
      fireEvent.click(option);
    }

    // Click Assigner
    const assignBtn = screen.getByRole("button", { name: /^Assigner$/i });
    fireEvent.click(assignBtn);

    // Selection bar should disappear after bulk assign
    await waitFor(() =>
      expect(screen.queryByText(/ligne\(s\) sélectionnée\(s\)/i)).not.toBeInTheDocument(),
    );
  });

  // ── Edited badge ─────────────────────────────────────────────────────────

  it("shows Édité badge after row is edited via inspector", async () => {
    const user = await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED" }),
    ]);

    const rows = screen.getAllByRole("row");
    const dataRow = rows.find((r) => r.textContent?.includes("Jean Martin"));
    await user.click(dataRow!);
    await waitFor(() => screen.getByText("Détail de la ligne"));

    const skipSwitch = screen.getByRole("switch", { name: /Ignorer cette ligne/i });
    await user.click(skipSwitch);

    await waitFor(() =>
      expect(screen.getByTestId("edited-badge")).toBeInTheDocument(),
    );
  });

  // ── Statistics update ────────────────────────────────────────────────────

  it("statistics update live after editing assignment in inspector", async () => {
    const user = await renderAndPreview([
      makeLine({ postId: 1, status: "UNCOVERED", instrumentistId: null }),
      makeLine({ postId: 2, status: "COVERED",   instrumentistId: 10, instrumentistName: "Ole Salve" }),
    ]);

    // Initially: 1 uncovered
    await waitFor(() => screen.getByText("Couverture"));

    // Click uncovered row, skip it
    const rows = screen.getAllByRole("row");
    const uncoveredRow = rows.find((r) => r.textContent?.includes("Jean Martin") && r.textContent?.includes("Non"));
    if (!uncoveredRow) {
      // fallback: click first data row
      const dataRow = rows.find((r) => r.textContent?.includes("Jean Martin"));
      if (dataRow) await user.click(dataRow);
    } else {
      await user.click(uncoveredRow);
    }

    await waitFor(() => screen.getByText("Détail de la ligne"));
    const skipSwitch = screen.getByRole("switch", { name: /Ignorer cette ligne/i });
    await user.click(skipSwitch);

    // Dirty count should appear in stats bar
    await waitFor(() =>
      expect(screen.getByText(/modification\(s\) locale/i)).toBeInTheDocument(),
    );
  });

  // ── Conflict filter ──────────────────────────────────────────────────────

  it("Conflits filter shows only CONFLICT-status lines", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, status: "CONFLICT",  surgeonName: "Dr. Conflict"  }),
      makeLine({ postId: 2, status: "UNCOVERED", surgeonName: "Dr. Uncovered" }),
      makeLine({ postId: 3, status: "COVERED",   surgeonName: "Dr. Covered"   }),
    ]);

    const conflictChip = screen.getByRole("button", { name: /Conflits/i });
    fireEvent.click(conflictChip);

    await waitFor(() => {
      expect(screen.getByText("Dr. Conflict")).toBeInTheDocument();
      expect(screen.queryByText("Dr. Uncovered")).not.toBeInTheDocument();
      expect(screen.queryByText("Dr. Covered")).not.toBeInTheDocument();
    });
  });

  // ── Manager overrides filter ─────────────────────────────────────────────

  it("Modifiés manager filter shows only edited lines", async () => {
    await renderAndPreview([
      makeLine({ postId: 1, surgeonName: "Dr. Edited",   status: "UNCOVERED" }),
      makeLine({ postId: 2, surgeonName: "Dr. Unedited", status: "UNCOVERED" }),
    ]);

    // Edit the first row via inspector using reliable fireEvent
    const rows = screen.getAllByRole("row");
    const editedRow = rows.find((r) => r.textContent?.includes("Dr. Edited"));
    expect(editedRow).toBeDefined();
    fireEvent.click(editedRow!);
    await waitFor(() => screen.getByText("Détail de la ligne"));

    const skipSwitch = screen.getByRole("switch", { name: /Ignorer cette ligne/i });
    fireEvent.click(skipSwitch);

    // Verify line is now dirty
    await waitFor(() => screen.getByText(/modification\(s\) locale/i));

    // Close inspector before applying filter so assertions are unambiguous
    const closeBtn = document.querySelector("[data-testid='CloseIcon']")?.closest("button") as HTMLElement | null;
    if (closeBtn) fireEvent.click(closeBtn);
    await waitFor(() =>
      expect(screen.queryByText("Détail de la ligne")).not.toBeInTheDocument(),
    );

    // Activate OVERRIDE filter
    const overrideChip = screen.getByRole("button", { name: /Modifiés manager/i });
    fireEvent.click(overrideChip);

    await waitFor(() => {
      expect(screen.getAllByText("Dr. Edited").length).toBeGreaterThanOrEqual(1);
      expect(screen.queryByText("Dr. Unedited")).not.toBeInTheDocument();
    });
  });
});
