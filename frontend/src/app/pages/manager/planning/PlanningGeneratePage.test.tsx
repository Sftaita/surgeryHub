import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import PlanningGeneratePage from "./PlanningGeneratePage";
import type { PreviewLine } from "../../../features/planning-manager/api/planning.api";

// ── Mocks ─────────────────────────────────────────────────────────────────────

vi.mock("../../../features/planning-manager/api/planning.api", () => ({
  previewPlanning:            vi.fn(),
  generatePlanning:           vi.fn(),
  deployPlanning:             vi.fn(),
  getPlanningVersion:         vi.fn().mockResolvedValue(null),
  getVersionDiff:             vi.fn().mockResolvedValue({ added: [], removed: [], modified: [] }),
  getSuggestedInstrumentists: vi.fn(),
  assignInstrumentist:        vi.fn(),
  createMission:              vi.fn(),
  publishMission:             vi.fn(),
}));

vi.mock("../../../features/planning-manager/components/DeployModal", () => ({
  DeployModal: ({ open, onDeploy }: { open: boolean; onDeploy?: (ids: number[], flag: boolean) => void }) =>
    open ? (
      <div data-testid="deploy-modal">
        <button onClick={() => onDeploy?.([101], false)}>Confirmer déploiement</button>
      </div>
    ) : null,
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

import * as planningApi from "../../../features/planning-manager/api/planning.api";
import { apiClient } from "../../../api/apiClient";

// ── Factories ─────────────────────────────────────────────────────────────────

function makeLine(overrides: Partial<PreviewLine> = {}): PreviewLine {
  return {
    date:                      "2026-01-05", // Monday ISO week 2 (PAIR)
    slotId:                    1,
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

// ── Render helper ─────────────────────────────────────────────────────────────

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PlanningGeneratePage />
      </MemoryRouter>
    </QueryClientProvider>
  );
}

/** Render, click Prévisualiser, wait for the table to appear. Returns userEvent instance. */
async function renderAndPreview(lines: PreviewLine[]) {
  vi.mocked(planningApi.previewPlanning).mockResolvedValue(lines);
  const user = userEvent.setup();
  renderPage();
  await user.click(screen.getByRole("button", { name: /Prévisualiser/i }));
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

  // ── État vide ────────────────────────────────────────────────────────────

  it("shows empty state before any preview", () => {
    renderPage();
    expect(screen.getByText("Aucune prévisualisation")).toBeInTheDocument();
  });

  it("shows date inputs and Prévisualiser button", () => {
    renderPage();
    expect(screen.getByLabelText(/Du/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Au/i)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Prévisualiser/i })).toBeInTheDocument();
  });

  it("does not show Générer button before preview", () => {
    renderPage();
    expect(screen.queryByRole("button", { name: /Générer le planning/i })).not.toBeInTheDocument();
  });

  // ── Tableau — rendu de base ───────────────────────────────────────────────

  it("renders surgeon name, site and period after preview", async () => {
    await renderAndPreview([
      makeLine({ surgeonName: "J. Martin", siteName: "Delta", startTime: "08:00" }),
    ]);
    expect(screen.getByText("J. Martin")).toBeInTheDocument();
    expect(screen.getByText("Delta")).toBeInTheDocument();
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

  // ── En-têtes de semaine ───────────────────────────────────────────────────

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
      makeLine({ date: "2026-01-05", slotId: 1 }), // week 2
      makeLine({ date: "2026-01-12", slotId: 2 }), // week 3
    ]);
    const headers = screen.getAllByText(/Semaine/i);
    expect(headers).toHaveLength(2);
  });

  // ── Rowspan jour + date ───────────────────────────────────────────────────

  it("renders Jour cell once for two lines on the same day (rowspan)", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, date: "2026-01-05", surgeonName: "A. Alpha" }),
      makeLine({ slotId: 2, date: "2026-01-05", surgeonName: "B. Bêta" }),
    ]);
    // Both surgeons visible
    expect(screen.getByText("A. Alpha")).toBeInTheDocument();
    expect(screen.getByText("B. Bêta")).toBeInTheDocument();
    // Day name appears only once (rowSpan=2 in DOM)
    expect(screen.getAllByText(/Lundi/i)).toHaveLength(1);
  });

  // ── Tri ───────────────────────────────────────────────────────────────────

  it("sorts by surgeon A→Z then Matin before Après-midi within a day", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, surgeonName: "Z. Zorro",   startTime: "08:00" }),
      makeLine({ slotId: 2, surgeonName: "A. Martin",  startTime: "14:00" }),
      makeLine({ slotId: 3, surgeonName: "A. Martin",  startTime: "08:00" }),
    ]);

    const rows = screen.getAllByRole("row").slice(1); // skip header

    const martinFirstIdx = rows.findIndex((r) => r.textContent?.includes("A. Martin"));
    const zorroIdx       = rows.findIndex((r) => r.textContent?.includes("Z. Zorro"));
    expect(martinFirstIdx).toBeLessThan(zorroIdx);

    // A. Martin 08:00 before A. Martin 14:00
    const allRows   = screen.getAllByRole("row").slice(1);
    const matinRow  = allRows.find((r) => r.textContent?.includes("A. Martin") && r.textContent?.includes("Matin"));
    const apmRow    = allRows.find((r) => r.textContent?.includes("A. Martin") && r.textContent?.includes("Après-midi"));
    if (matinRow && apmRow) {
      expect(allRows.indexOf(matinRow)).toBeLessThan(allRows.indexOf(apmRow));
    }
  });

  // ── Chips récapitulatifs ──────────────────────────────────────────────────

  it("shows summary chips with correct counts after preview", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, status: "COVERED"   }),
      makeLine({ slotId: 2, status: "COVERED"   }),
      makeLine({ slotId: 3, status: "UNCOVERED" }),
      makeLine({ slotId: 4, status: "CONFLICT"  }),
    ]);
    expect(screen.getByText("2 Couvert")).toBeInTheDocument();
    expect(screen.getByText("1 Non couvert")).toBeInTheDocument();
    expect(screen.getByText("1 Conflit")).toBeInTheDocument();
  });

  it("shows 'sans instrumentiste' chip when covered lines have no instrumentist", async () => {
    await renderAndPreview([
      // COVERED with instrumentist → not counted
      makeLine({ slotId: 1, status: "COVERED", instrumentistId: 10, instrumentistName: "Ole Salve" }),
      // COVERED without instrumentist → counted (ex: Yorick Berger case)
      makeLine({ slotId: 2, status: "COVERED", instrumentistId: null, instrumentistName: null }),
      makeLine({ slotId: 3, status: "COVERED", instrumentistId: null, instrumentistName: null }),
      // UNCOVERED → also counted (no instrumentist)
      makeLine({ slotId: 4, status: "UNCOVERED", instrumentistId: null }),
    ]);
    // 2 COVERED+no-instr + 1 UNCOVERED = 3 sans instrumentiste
    expect(screen.getByText("3 sans instrumentiste")).toBeInTheDocument();
  });

  it("does not show 'sans instrumentiste' chip when all covered lines have an instrumentist", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, status: "COVERED", instrumentistId: 10, instrumentistName: "Ole Salve" }),
      makeLine({ slotId: 2, status: "COVERED", instrumentistId: 11, instrumentistName: "Christine D." }),
    ]);
    expect(screen.queryByText(/sans instrumentiste/)).not.toBeInTheDocument();
  });

  it("does not count SKIPPED lines in sans instrumentiste chip", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, status: "COVERED", instrumentistId: 10, instrumentistName: "Ole" }),
      // SKIPPED with no instrumentist — should NOT be counted (surgeon absent, slot ignored)
      makeLine({ slotId: 2, status: "SKIPPED", instrumentistId: null }),
    ]);
    expect(screen.queryByText(/sans instrumentiste/)).not.toBeInTheDocument();
  });

  // Cas limites supplémentaires pour verrouiller noInstrCount

  it("counts only 1 sans instrumentiste for pure UNCOVERED with no instrumentist", async () => {
    // UNCOVERED = no existing mission, no instrumentist in template → needs attribution
    await renderAndPreview([
      makeLine({ slotId: 1, status: "UNCOVERED", instrumentistId: null }),
    ]);
    expect(screen.getByText("1 Non couvert")).toBeInTheDocument();
    expect(screen.getByText("1 sans instrumentiste")).toBeInTheDocument();
  });

  it("MODIFIED line with instrumentist is NOT counted in sans instrumentiste", async () => {
    // MODIFIED = existing mission with different instrumentist than template.
    // The template HAS an instrumentist (instrumentistId != null) → not sans instrumentiste.
    await renderAndPreview([
      makeLine({ slotId: 1, status: "MODIFIED", instrumentistId: 10, instrumentistName: "Ole" }),
    ]);
    expect(screen.queryByText(/sans instrumentiste/)).not.toBeInTheDocument();
  });

  it("SKIPPED lines with freed instrumentist badge are NOT counted in sans instrumentiste", async () => {
    // SKIPPED line shows the freed instrumentist's name (e.g. "Françoise Libéré")
    // but the line itself is SKIPPED — it must NOT contribute to the sans instrumentiste count.
    await renderAndPreview([
      makeLine({ slotId: 1, status: "COVERED", instrumentistId: 10, instrumentistName: "Ole" }),
      makeLine({ slotId: 2, status: "SKIPPED", instrumentistId: 10, instrumentistName: "Ole" }), // freed
    ]);
    expect(screen.queryByText(/sans instrumentiste/)).not.toBeInTheDocument();
  });

  it("freedFrom lines with auto-assigned instrumentist are NOT counted", async () => {
    // freedFrom=true means an instrumentist was auto-assigned from a SKIPPED slot.
    // instrumentistId is set → should NOT appear in sans instrumentiste count.
    await renderAndPreview([
      makeLine({ slotId: 1, status: "COVERED", instrumentistId: 10, instrumentistName: "Ole", freedFrom: true }),
      makeLine({ slotId: 2, status: "COVERED", instrumentistId: 11, instrumentistName: "Christine", freedFrom: false }),
    ]);
    expect(screen.queryByText(/sans instrumentiste/)).not.toBeInTheDocument();
  });

  it("shows exact count combining UNCOVERED and COVERED-no-instr, excluding SKIPPED", async () => {
    // Scenario: 1 COVERED+instr, 2 COVERED+no-instr, 1 UNCOVERED, 1 SKIPPED+no-instr, 1 CONFLICT+instr
    // Expected count: 2 (COVERED+no-instr) + 1 (UNCOVERED) = 3
    await renderAndPreview([
      makeLine({ slotId: 1, status: "COVERED",   instrumentistId: 10, instrumentistName: "Ole" }),
      makeLine({ slotId: 2, status: "COVERED",   instrumentistId: null }),
      makeLine({ slotId: 3, status: "COVERED",   instrumentistId: null }),
      makeLine({ slotId: 4, status: "UNCOVERED", instrumentistId: null }),
      makeLine({ slotId: 5, status: "SKIPPED",   instrumentistId: null }),  // excluded
      makeLine({ slotId: 6, status: "CONFLICT",  instrumentistId: 11, instrumentistName: "C." }),
    ]);
    expect(screen.getByText("3 sans instrumentiste")).toBeInTheDocument();
  });

  it("does not show summary chips before preview", () => {
    renderPage();
    expect(screen.queryByText(/Couvert/)).not.toBeInTheDocument();
  });

  // ── Boutons Générer + Déployer ────────────────────────────────────────────

  it("shows Générer button after preview", async () => {
    await renderAndPreview([makeLine()]);
    expect(screen.getByRole("button", { name: /Générer le planning/i })).toBeInTheDocument();
  });

  it("shows Déployer button after generation", async () => {
    vi.mocked(planningApi.generatePlanning).mockResolvedValue({
      versionId: 1, created: 3, updated: 0, skipped: 0,
    });
    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer le planning/i }));
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Déployer/i })).toBeInTheDocument(),
    );
  });

  it("shows success alert with new mission count when missions were created", async () => {
    vi.mocked(planningApi.generatePlanning).mockResolvedValue({
      versionId: 1, created: 5, updated: 1, skipped: 2,
    });
    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer le planning/i }));
    await waitFor(() => expect(screen.getAllByRole("alert").length).toBeGreaterThanOrEqual(1));
    const successAlert = screen.getAllByRole("alert").find((el) =>
      el.textContent?.includes("créé"),
    )!;
    expect(within(successAlert).getByText("5")).toBeInTheDocument();
    expect(within(successAlert).getByText(/créé/i)).toBeInTheDocument();
  });

  // ── Message "ignorée" corrigé (D-038) ─────────────────────────────────────

  it("shows 'préservées' message when 0 created and 0 updated (all already covered)", async () => {
    // Scenario: re-generating an already-covered period — nothing new to create
    vi.mocked(planningApi.generatePlanning).mockResolvedValue({
      versionId: 2, created: 0, updated: 0, skipped: 205,
    });
    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer le planning/i }));

    await waitFor(() => expect(screen.getAllByRole("alert").length).toBeGreaterThanOrEqual(1));
    const successAlert = screen.getAllByRole("alert").find((el) =>
      el.getAttribute("class")?.includes("MuiAlert") && el.textContent?.includes("préserv")
    ) ?? screen.getAllByRole("alert")[0];

    // Should show "préservées" not "ignorée"
    expect(successAlert?.textContent).toMatch(/préserv/i);
    expect(successAlert?.textContent).not.toMatch(/ignorée/i);
  });

  it("does NOT show 'créé' wording when 0 created and all preserved", async () => {
    vi.mocked(planningApi.generatePlanning).mockResolvedValue({
      versionId: 2, created: 0, updated: 0, skipped: 50,
    });
    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer le planning/i }));

    await waitFor(() => expect(screen.getAllByRole("alert").length).toBeGreaterThanOrEqual(1));
    const alerts = screen.getAllByRole("alert");
    const successAlert = alerts.find((el) => el.textContent?.includes("préserv") || el.textContent?.includes("Planning"));

    // Should NOT say "nouvelle(s) mission(s) créée(s)" when created=0
    expect(successAlert?.textContent).not.toMatch(/nouvelle\(s\) mission\(s\) créée/i);
  });

  it("shows deploy reminder alert after generation", async () => {
    vi.mocked(planningApi.generatePlanning).mockResolvedValue({
      versionId: 1, created: 3, updated: 0, skipped: 0,
    });
    const user = await renderAndPreview([makeLine()]);
    await user.click(screen.getByRole("button", { name: /Générer le planning/i }));
    await waitFor(() =>
      expect(screen.getByText(/Missions en attente de déploiement/i)).toBeInTheDocument(),
    );
  });

  // ── Select instrumentiste ─────────────────────────────────────────────────

  it("renders a Select for COVERED and UNCOVERED lines", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, status: "COVERED"   }),
      makeLine({ slotId: 2, status: "UNCOVERED" }),
    ]);
    const table = screen.getByRole("table");
    expect(within(table).getAllByRole("combobox")).toHaveLength(2);
  });

  it("renders — text (no Select) for SKIPPED lines", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, status: "COVERED" }),
      makeLine({ slotId: 2, status: "SKIPPED" }),
    ]);
    expect(screen.getByText("—")).toBeInTheDocument();
    // Only 1 select in the table (COVERED), not 2
    const table = screen.getByRole("table");
    expect(within(table).getAllByRole("combobox")).toHaveLength(1);
  });

  it("Select shows instrumentist name when one is assigned", async () => {
    await renderAndPreview([
      makeLine({ status: "COVERED", instrumentistId: 10, instrumentistName: "Ole Salve" }),
    ]);
    expect(screen.getByText("Ole Salve")).toBeInTheDocument();
  });

  // ── Doublons instrumentiste ───────────────────────────────────────────────

  it("shows Doublon chip and red background when same instrumentist on same day and period", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, surgeonName: "A. Alpha", instrumentistId: 10, instrumentistName: "Ole Salve", startTime: "08:00" }),
      makeLine({ slotId: 2, surgeonName: "B. Bêta",  instrumentistId: 10, instrumentistName: "Ole Salve", startTime: "09:00" }),
    ]);
    // Both rows should show the "Doublon" chip
    const chips = screen.getAllByText("Doublon");
    expect(chips).toHaveLength(2);
  });

  it("does not show Doublon chip when same instrumentist on different periods", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, instrumentistId: 10, instrumentistName: "Ole Salve", startTime: "08:00" }),
      makeLine({ slotId: 2, instrumentistId: 10, instrumentistName: "Ole Salve", startTime: "14:00" }),
    ]);
    expect(screen.queryByText("Doublon")).not.toBeInTheDocument();
  });

  it("does not show Doublon chip when different instrumentists on same period", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, instrumentistId: 10, instrumentistName: "Ole Salve",   startTime: "08:00" }),
      makeLine({ slotId: 2, instrumentistId: 11, instrumentistName: "Christine D.", startTime: "08:00" }),
    ]);
    expect(screen.queryByText("Doublon")).not.toBeInTheDocument();
  });

  // ── Bouton Résoudre ───────────────────────────────────────────────────────────

  it("shows Résoudre button when UNCOVERED lines exist", async () => {
    await renderAndPreview([makeLine({ status: "UNCOVERED" })]);
    expect(screen.getByRole("button", { name: /Résoudre/i })).toBeInTheDocument();
  });

  it("does not show Résoudre button when all lines are COVERED", async () => {
    await renderAndPreview([makeLine({ status: "COVERED" })]);
    expect(screen.queryByRole("button", { name: /Résoudre/i })).not.toBeInTheDocument();
  });

  it("Résoudre button count matches number of UNCOVERED lines", async () => {
    await renderAndPreview([
      makeLine({ slotId: 1, status: "UNCOVERED" }),
      makeLine({ slotId: 2, status: "UNCOVERED" }),
      makeLine({ slotId: 3, status: "COVERED" }),
    ]);
    expect(screen.getByRole("button", { name: /Résoudre les non-attribués \(2\)/i })).toBeInTheDocument();
  });

  // ── Modal Résoudre ────────────────────────────────────────────────────────────

  it("opens resolve modal when Résoudre button clicked", async () => {
    const user = await renderAndPreview([makeLine({ status: "UNCOVERED" })]);
    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    await waitFor(() => expect(screen.getByRole("dialog")).toBeInTheDocument());
    // The dialog title contains "Résoudre" — scope to the dialog to avoid matching the button too
    expect(within(screen.getByRole("dialog")).getByText(/Résoudre les non-attribués/i)).toBeInTheDocument();
  });

  it("modal shows freed instrumentist when SKIPPED line frees them", async () => {
    const user = await renderAndPreview([
      // SKIPPED: surgeon absent → Ole Salve freed
      makeLine({ slotId: 1, status: "SKIPPED",   surgeonName: "Dr. Absent", instrumentistId: 10, instrumentistName: "Ole Salve", startTime: "08:00", endTime: "13:00" }),
      // UNCOVERED on same day, same time → Ole could fill in
      makeLine({ slotId: 2, status: "UNCOVERED", surgeonName: "Dr. Actif",  instrumentistId: null, instrumentistName: null,       startTime: "08:00", endTime: "13:00" }),
    ]);
    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    const dialog = await waitFor(() => screen.getByRole("dialog"));
    // Ole Salve appears in the table too (Libéré chip on SKIPPED row) — scope to dialog
    expect(within(dialog).getByText("Ole Salve")).toBeInTheDocument();
    expect(within(dialog).getByText(/Libéré/i)).toBeInTheDocument();
    expect(within(dialog).getByText(/Dr\. Absent est absent/i)).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: /Envoyer/i })).toBeInTheDocument();
  });

  it("modal shows Créer une mission when no freed instrumentist", async () => {
    const user = await renderAndPreview([
      makeLine({ slotId: 1, status: "UNCOVERED", surgeonName: "Dr. B" }),
    ]);
    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Créer une mission/i })).toBeInTheDocument(),
    );
    expect(screen.getByText(/Aucun instrumentiste libéré/i)).toBeInTheDocument();
  });

  it("does not propose freed instrumentist if they have an overlapping active slot", async () => {
    const user = await renderAndPreview([
      // Ole freed from SKIPPED slot
      makeLine({ slotId: 1, status: "SKIPPED",   instrumentistId: 10, instrumentistName: "Ole Salve", startTime: "08:00", endTime: "13:00" }),
      // Ole already busy on another COVERED slot at the same time
      makeLine({ slotId: 2, status: "COVERED",   instrumentistId: 10, instrumentistName: "Ole Salve", startTime: "09:00", endTime: "12:00" }),
      // UNCOVERED line — Ole should NOT be proposed
      makeLine({ slotId: 3, status: "UNCOVERED", instrumentistId: null, surgeonName: "Dr. C",         startTime: "08:00", endTime: "13:00" }),
    ]);
    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Créer une mission/i })).toBeInTheDocument(),
    );
    // Ole should NOT appear in the modal
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).queryByText("Ole Salve")).not.toBeInTheDocument();
  });

  // ── Actions du modal ──────────────────────────────────────────────────────────

  it("calls createMission with instrumentistUserId (direct assignment) when Envoyer is clicked", async () => {
    vi.mocked(planningApi.createMission).mockResolvedValue({ id: 99 });

    const user = await renderAndPreview([
      makeLine({ slotId: 1, status: "SKIPPED",   instrumentistId: 10, instrumentistName: "Ole Salve", surgeonName: "Dr. Absent", startTime: "08:00", endTime: "13:00" }),
      makeLine({ slotId: 2, status: "UNCOVERED", instrumentistId: null, surgeonName: "Dr. Actif",    startTime: "08:00", endTime: "13:00", siteId: 1 }),
    ]);
    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    await waitFor(() => screen.getByRole("button", { name: /Envoyer/i }));
    await user.click(screen.getByRole("button", { name: /Envoyer/i }));

    await waitFor(() => {
      expect(vi.mocked(planningApi.createMission)).toHaveBeenCalledWith(
        expect.objectContaining({ instrumentistUserId: 10 }),
      );
      // No publishMission for freed case (Option B — direct assignment via DRAFT)
      expect(vi.mocked(planningApi.publishMission)).not.toHaveBeenCalled();
    });
  });

  it("calls only createMission (no publish) when Créer une mission is clicked", async () => {
    vi.mocked(planningApi.createMission).mockResolvedValue({ id: 88 });

    const user = await renderAndPreview([
      makeLine({ slotId: 1, status: "UNCOVERED", siteId: 1 }),
    ]);
    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    await waitFor(() => screen.getByRole("button", { name: /^Créer une mission$/i }));
    await user.click(screen.getByRole("button", { name: /^Créer une mission$/i }));

    await waitFor(() => expect(vi.mocked(planningApi.createMission)).toHaveBeenCalled());
    expect(vi.mocked(planningApi.publishMission)).not.toHaveBeenCalled();
  });

  it("shows Mission créée in table after Créer une mission", async () => {
    vi.mocked(planningApi.createMission).mockResolvedValue({ id: 88 });

    const user = await renderAndPreview([
      makeLine({ slotId: 1, status: "UNCOVERED", siteId: 1 }),
    ]);
    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    await waitFor(() => screen.getByRole("button", { name: /^Créer une mission$/i }));
    await user.click(screen.getByRole("button", { name: /^Créer une mission$/i }));

    await waitFor(() => expect(screen.getByText("Mission créée")).toBeInTheDocument());
  });

  it("Créer toutes les missions creates a mission for each UNCOVERED line", async () => {
    vi.mocked(planningApi.createMission)
      .mockResolvedValueOnce({ id: 1 })
      .mockResolvedValueOnce({ id: 2 });

    const user = await renderAndPreview([
      makeLine({ slotId: 1, status: "UNCOVERED", siteId: 1, surgeonName: "Dr. A" }),
      makeLine({ slotId: 2, status: "UNCOVERED", siteId: 1, surgeonName: "Dr. B" }),
    ]);
    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    await waitFor(() => screen.getByRole("button", { name: /Créer toutes les missions/i }));
    await user.click(screen.getByRole("button", { name: /Créer toutes les missions/i }));

    await waitFor(() =>
      expect(vi.mocked(planningApi.createMission)).toHaveBeenCalledTimes(2),
    );
    // Table should show "Mission créée" for both lines
    await waitFor(() =>
      expect(screen.getAllByText("Mission créée")).toHaveLength(2),
    );
  });

  it("Résoudre button disappears after all UNCOVERED lines are resolved", async () => {
    vi.mocked(planningApi.createMission).mockResolvedValue({ id: 77 });

    const user = await renderAndPreview([
      makeLine({ slotId: 1, status: "UNCOVERED", siteId: 1 }),
    ]);
    expect(screen.getByRole("button", { name: /Résoudre/i })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /Résoudre/i }));
    await waitFor(() => screen.getByRole("button", { name: /^Créer une mission$/i }));
    await user.click(screen.getByRole("button", { name: /^Créer une mission$/i }));

    await waitFor(() => screen.getByText("Mission créée"));
    await user.click(screen.getByRole("button", { name: /Fermer/i }));

    await waitFor(() =>
      expect(screen.queryByRole("button", { name: /Résoudre/i })).not.toBeInTheDocument(),
    );
  });

  it("Select options include instrumentists loaded from API", async () => {
    const user = await renderAndPreview([
      makeLine({ slotId: 1, status: "UNCOVERED" }),
    ]);
    // Open the instrumentist Select inside the table
    const table = screen.getByRole("table");
    const select = within(table).getByRole("combobox");
    await user.click(select);

    await waitFor(() => {
      expect(screen.getByRole("option", { name: "Ole Salve" })).toBeInTheDocument();
      expect(screen.getByRole("option", { name: "Christine D." })).toBeInTheDocument();
    });
  });
});
