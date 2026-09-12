import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { GeneratePlanningTab } from "./GeneratePlanningTab";
import type { PreviewLineV2, PreviewResponseV2 } from "../api/planningV2.types";

vi.mock("../../sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([{ id: 1, name: "Delta" }]),
}));

vi.mock("../../planning-manager/api/planning.api", () => ({
  listPlanningVersions: vi.fn().mockResolvedValue({ items: [], total: 0, page: 1, limit: 10 }),
  getAbsences: vi.fn().mockResolvedValue([]),
}));

// Stable references (not a fresh vi.fn() per render) so tests can assert on calls — the
// component calls useToast() again on every render, but must always get back the SAME
// success/error spies for assertions like "no success toast fired" to be meaningful.
const toastSuccess = vi.fn();
const toastError = vi.fn();
const toastWarning = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: toastWarning }),
}));

vi.mock("../api/planningV2.api", () => ({
  getSiteGroups: vi.fn().mockResolvedValue({ items: [] }),
  getSurgeonPosts: vi.fn().mockResolvedValue({ items: [{ id: 1 }, { id: 2 }] }),
  previewPlanningV2: vi.fn(),
  generatePlanningV2: vi.fn(),
  deployPlanningV2: vi.fn(),
  applyModifications: vi.fn(),
  cancelAllMissions: vi.fn(),
  verifyConflicts: vi.fn(),
  // CAS D (D-115)
  reopenDraft: vi.fn(),
  updateDraft: vi.fn(),
  deletePlanningVersionDraft: vi.fn(),
  // D-091 follow-up
  authorizeConflicts: vi.fn(),
  // D-102 — Preview Editor's instrumentist pickers now source eligibility from this
  // endpoint; defaults to "everyone selectable" (mirrors the /api/instrumentists mock
  // below) unless a test overrides it.
  fetchRosterEligibility: vi.fn().mockResolvedValue({
    policy: "STRICT_ASSIGNMENT",
    candidates: [
      { id: 9, name: "Diane Lefebvre", email: "diane@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
      { id: 10, name: "Marc Petit", email: "marc@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
    ],
  }),
  // Real logic (not a dumb String(e) stub) — the 401 special-case is exactly what the
  // session-expiry tests below exercise, and a stub here would silently bypass it.
  extractErrorV2: (err: unknown) => {
    const e = err as any;
    if (e?.response?.status === 401) {
      return "Votre session a expiré. Reconnectez-vous pour enregistrer vos modifications.";
    }
    return e?.response?.data?.error?.message ?? e?.message ?? String(err);
  },
}));

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: vi.fn((url: string, config?: { params?: Record<string, unknown> }) => {
      if (url === "/api/instrumentists") {
        return Promise.resolve({ data: { items: [{ id: 9, displayName: "Diane Lefebvre" }, { id: 10, displayName: "Marc Petit" }] } });
      }
      if (url === "/api/surgeons") {
        return Promise.resolve({ data: { items: [{ id: 1, displayName: "Dr Martin" }], total: 1 } });
      }
      if (url === "/api/missions" && config?.params?.planningVersionId) {
        return Promise.resolve({
          data: {
            items: [
              {
                id: 501, type: "BLOCK", schedulePrecision: "EXACT",
                startAt: "2026-06-15T08:00:00+02:00", endAt: "2026-06-15T13:00:00+02:00",
                site: { id: 1, name: "Delta" }, status: "ASSIGNED",
                surgeon: { id: 1, email: "martin@x.fr", firstname: "Jean", lastname: "Martin" },
                instrumentist: { id: 9, email: "diane@x.fr", firstname: "Diane", lastname: "Lefebvre" },
              },
            ],
            total: 1, page: 1, limit: 500,
          },
        });
      }
      return Promise.resolve({ data: { items: [] } });
    }),
  },
}));

import * as planningV2Api from "../api/planningV2.api";
import * as planningManagerApi from "../../planning-manager/api/planning.api";
import { apiClient } from "../../../api/apiClient";

function line(overrides: Partial<PreviewLineV2>): PreviewLineV2 {
  return {
    date: "2026-06-01", postId: 1, surgeonId: 1, surgeonName: "Dr Martin",
    missionType: "BLOCK", startTime: "08:00", endTime: "13:00",
    siteId: 1, siteName: "Delta", instrumentistId: null, instrumentistName: null,
    status: "COVERED", existingMissionId: null, existingInstrumentistId: null,
    existingInstrumentistName: null, freedFrom: false,
    ...overrides,
  };
}

function renderTab() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <GeneratePlanningTab />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

async function selectSite(user: ReturnType<typeof userEvent.setup>) {
  const label = screen.getByText("Site ou groupe de sites");
  const container = label.closest("div")!;
  const input = container.querySelector("input")!;
  await user.click(input);
  await user.click(await screen.findByText("Delta"));
}

describe("GeneratePlanningTab — sélection multi-mois", () => {
  it("démarre avec le mois courant sélectionné et le bouton Prévisualiser activé une fois un site choisi", async () => {
    const user = userEvent.setup();
    renderTab();

    const previewBtn = screen.getByRole("button", { name: "Prévisualiser" });
    expect(previewBtn).toBeDisabled();

    await selectSite(user);
    await waitFor(() => expect(previewBtn).toBeEnabled());
  });

  it("désactive Prévisualiser si tous les mois sont désélectionnés", async () => {
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);

    // Deselect the only initially-selected month chip (current month).
    const chips = screen.getAllByRole("button").filter((b) => /\d{4}/.test(b.textContent ?? ""));
    expect(chips.length).toBeGreaterThan(0);
    await user.click(chips[0]);

    await waitFor(() => expect(screen.getByRole("button", { name: "Prévisualiser" })).toBeDisabled());
  });

  it("regroupe la prévisualisation par jour puis par chirurgien, avec filtres cliquables", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [
        line({ date: "2026-06-01", surgeonId: 1, surgeonName: "Dr Martin", status: "COVERED", instrumentistId: 9, instrumentistName: "Diane Lefebvre" }),
        line({ date: "2026-06-01", surgeonId: 2, surgeonName: "Dr Dupont", status: "CONFLICT", postId: 2 }),
      ],
      summary: { total: 2, covered: 1, uncovered: 0, skipped: 0, conflict: 1, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));

    expect(await screen.findByText("Dr Martin")).toBeInTheDocument();
    expect(screen.getByText("Dr Dupont")).toBeInTheDocument();
    expect(screen.getByText("Diane Lefebvre")).toBeInTheDocument();
    expect(screen.getByText("À pourvoir")).toBeInTheDocument();

    // "Conflits" filter (count 1) hides the COVERED line for Dr Martin.
    await user.click(screen.getByText("Conflits"));
    await waitFor(() => expect(screen.queryByText("Dr Martin")).not.toBeInTheDocument());
    expect(screen.getByText("Dr Dupont")).toBeInTheDocument();
  });

  it("affiche un état vide quand le filtre actif ne renvoie aucun poste", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [line({ status: "COVERED" })],
      summary: { total: 1, covered: 1, uncovered: 0, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("Dr Martin");

    await user.click(screen.getByText("Conflits"));
    expect(await screen.findByText(/Aucun poste dans ce filtre/)).toBeInTheDocument();
  });

  it("un mois déjà généré reste sélectionnable pour un nouveau lot — seule l'icône dédiée ouvre la Modification", async () => {
    const now = new Date();
    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({
      items: [{
        id: 77, status: "ACTIVE",
        periodStart: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-01T00:00:00Z`,
        // Same site id as "Delta" (so matchedVersion resolves — matching is by id, not name) but
        // a distinct label, to avoid an ambiguous "Delta" text match against the site dropdown
        // option once this version renders in the history sidebar.
        deployedAt: "2026-06-02T00:00:00Z", site: { id: 1, name: "Delta (historique)" }, summary: { total: 1, open: 0 },
      }],
      total: 1, page: 1, limit: 10,
    });
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);

    const chip = await screen.findByText(/\d{4} · déjà généré/);
    // Clicking the chip itself must toggle month selection (so it can still be included in a
    // new multi-month batch for this hospital/group) — never jump into Modification mode.
    await user.click(chip);
    expect(screen.queryByText("Modification · Planning déployé")).not.toBeInTheDocument();
    // The Prévisualiser button reacts to selectedMonthIds — deselecting the only selected
    // (current) month disables it, proving the click really toggled selection.
    await waitFor(() => expect(screen.getByRole("button", { name: "Prévisualiser" })).toBeDisabled());

    // The dedicated edit icon next to the chip is the only way into Modification mode.
    await user.click(screen.getByRole("button", { name: "Modifier ce mois déjà généré" }));
    expect(await screen.findByText("Modification · Planning déployé")).toBeInTheDocument();

    // Restore the shared mock's default (empty history) so later tests in this file aren't
    // contaminated — vi.fn().mockResolvedValue persists across tests, unlike mockResolvedValueOnce.
    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({ items: [], total: 0, page: 1, limit: 10 });
  });
});

describe("GeneratePlanningTab — réaffectation d'instrumentiste (Preview Editor)", () => {
  it("permet de changer l'instrumentiste d'une ligne et envoie la modification au générer", async () => {
    const user = userEvent.setup();
    // The Tab always previews the current real month by default (defaultYearMonth()) — the
    // line's date must fall in that month for generateMutation's per-month filter to pick it up.
    const now = new Date();
    const currentMonthDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-01`;
    const preview: PreviewResponseV2 = {
      lines: [line({ date: currentMonthDate, status: "UNCOVERED", instrumentistId: null, instrumentistName: null })],
      summary: { total: 1, covered: 0, uncovered: 1, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);
    (planningV2Api.generatePlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue({ versionId: 1, created: 1, updated: 0, skipped: 0 });

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("À pourvoir");

    // Clicking the instrumentist area opens a popover with a searchable instrumentist select.
    await user.click(screen.getByText("À pourvoir"));
    const popoverLabel = await screen.findByText("Instrumentiste");
    const input = popoverLabel.closest("div")!.querySelector("input")!;
    await user.click(input);
    await user.click(await screen.findByText("Diane Lefebvre"));

    // The line now shows the new instrumentist and an "Édité" badge instead of the popover trigger text.
    await waitFor(() => expect(screen.queryByText("À pourvoir")).not.toBeInTheDocument());
    expect(screen.getAllByText("Diane Lefebvre").length).toBeGreaterThan(0);
    expect(screen.getByText("Édité")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Générer avec modifications" }));

    await waitFor(() => expect(planningV2Api.generatePlanningV2).toHaveBeenCalled());
    const call = (planningV2Api.generatePlanningV2 as ReturnType<typeof vi.fn>).mock.calls[0][0];
    expect(call.previewVersion).toBe("v-1");
    expect(call.lines).toHaveLength(1);
    expect(call.lines[0]).toMatchObject({ instrumentistId: 9, instrumentistName: "Diane Lefebvre", status: "COVERED" });
  });

  it("D-101 — signale explicitement au manager une affectation refusée par le backend (jamais silencieuse)", async () => {
    toastSuccess.mockClear();
    toastWarning.mockClear();
    const user = userEvent.setup();
    const now = new Date();
    const currentMonthDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-14`;
    const preview: PreviewResponseV2 = {
      lines: [line({ date: currentMonthDate, status: "COVERED", instrumentistId: 9, instrumentistName: "Diane Lefebvre" })],
      summary: { total: 1, covered: 1, uncovered: 0, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);
    (planningV2Api.generatePlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue({
      versionId: 1, created: 1, updated: 0, skipped: 0,
      rejectedAssignments: [{
        missionId: 398, date: currentMonthDate,
        requestedInstrumentistId: 9, requestedInstrumentistName: "Diane Lefebvre",
        reasons: ["ABSENT"],
      }],
    });

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("Diane Lefebvre");
    await user.click(await screen.findByRole("button", { name: "Générer les missions" }));

    await waitFor(() => expect(planningV2Api.generatePlanningV2).toHaveBeenCalled());
    await waitFor(() => expect(toastWarning).toHaveBeenCalledTimes(1));
    expect(toastWarning.mock.calls[0][0]).toContain("Diane Lefebvre");
    expect(toastWarning.mock.calls[0][0]).toContain("Absente");
  });

  it("ne signale rien quand aucune affectation n'a été refusée (non-régression)", async () => {
    toastSuccess.mockClear();
    toastWarning.mockClear();
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [line({ status: "UNCOVERED", instrumentistId: null, instrumentistName: null })],
      summary: { total: 1, covered: 0, uncovered: 1, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);
    (planningV2Api.generatePlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue({
      versionId: 1, created: 1, updated: 0, skipped: 0, rejectedAssignments: [],
    });

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("À pourvoir");
    await user.click(await screen.findByRole("button", { name: "Générer les missions" }));

    await waitFor(() => expect(planningV2Api.generatePlanningV2).toHaveBeenCalled());
    await waitFor(() => expect(toastSuccess).toHaveBeenCalled());
    expect(toastWarning).not.toHaveBeenCalled();
  });

  it("réaffecte en masse via la sélection multiple", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [
        line({ postId: 1, surgeonName: "Dr Martin", status: "UNCOVERED" }),
        line({ postId: 2, surgeonName: "Dr Martin", status: "UNCOVERED" }),
      ],
      summary: { total: 2, covered: 0, uncovered: 2, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findAllByText("À pourvoir");

    const checkboxes = screen.getAllByRole("checkbox");
    await user.click(checkboxes[0]);
    await user.click(checkboxes[1]);

    expect(await screen.findByText("2 postes sélectionnés")).toBeInTheDocument();

    const assignLabel = screen.getByText("Assigner à");
    const input = assignLabel.closest("div")!.querySelector("input")!;
    await user.click(input);
    await user.click(await screen.findByText("Marc Petit"));
    await user.click(screen.getByRole("button", { name: "Assigner" }));

    await waitFor(() => expect(screen.getAllByText("Marc Petit").length).toBeGreaterThanOrEqual(2));
    expect(screen.getAllByText("Édité")).toHaveLength(2);
  });
});

describe("GeneratePlanningTab — indicateurs congé / déjà affecté dans le sélecteur", () => {
  it("affiche un badge 'Absente' pour un instrumentiste absent ce jour-là (source: backend)", async () => {
    const user = userEvent.setup();
    (planningV2Api.fetchRosterEligibility as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      policy: "STRICT_ASSIGNMENT",
      candidates: [
        {
          id: 9, name: "Diane Lefebvre", email: "diane@test.com", eligible: false, selectable: false,
          reasons: ["ABSENT"], unavailability: { type: "ABSENCE", dateStart: "2026-06-01", dateEnd: "2026-06-01" }, conflict: null,
        },
        { id: 10, name: "Marc Petit", email: "marc@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
      ],
    });
    const preview: PreviewResponseV2 = {
      lines: [line({ status: "UNCOVERED" })],
      summary: { total: 1, covered: 0, uncovered: 1, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("À pourvoir");
    await user.click(screen.getByText("À pourvoir"));

    const popoverLabel = await screen.findByText("Instrumentiste");
    const input = popoverLabel.closest("div")!.querySelector("input")!;
    await user.click(input);

    const dianeOption = (await screen.findAllByRole("option")).find((o) => o.textContent?.includes("Diane Lefebvre"))!;
    expect(dianeOption).toHaveAttribute("aria-disabled", "true");
    expect(within(dianeOption).getByText(/Absente/)).toBeInTheDocument();
  });

  it("affiche 'Déjà affecté ailleurs' et libère l'autre poste au moment de la réaffectation (préview locale, non persistée)", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [
        line({ postId: 1, status: "COVERED", instrumentistId: 9, instrumentistName: "Diane Lefebvre", startTime: "08:00", endTime: "13:00" }),
        line({ postId: 2, status: "UNCOVERED", startTime: "14:00", endTime: "18:00" }),
      ],
      summary: { total: 2, covered: 1, uncovered: 1, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("À pourvoir");

    // Only the UNCOVERED line's trigger reads "À pourvoir" — the COVERED one already shows a name.
    await user.click(screen.getByText("À pourvoir"));
    const popoverLabel = await screen.findByText("Instrumentiste");
    const input = popoverLabel.closest("div")!.querySelector("input")!;
    await user.click(input);

    const dianeOption = (await screen.findAllByRole("option")).find((o) => o.textContent?.includes("Diane Lefebvre"))!;
    // Backend has no opinion on this (the "other slot" is an unsaved Preview line, not a
    // persisted Mission) — this stays a purely local, non-blocking warning, still selectable.
    expect(within(dianeOption).getByText("Déjà affecté ailleurs")).toBeInTheDocument();
    expect(dianeOption).not.toHaveAttribute("aria-disabled", "true");
    await user.click(dianeOption);

    // Diane now covers the previously-uncovered slot; her original slot is freed instead of
    // silently double-booking her, and both lines are marked as locally edited.
    await waitFor(() => expect(screen.getAllByText("Édité")).toHaveLength(2));
    expect(screen.getAllByText("À pourvoir")).toHaveLength(1);
    expect(screen.getAllByText("Diane Lefebvre").length).toBeGreaterThanOrEqual(1);
  });

  it("D-102 — un conflit d'horaire (backend) bloque la sélection en Génération (STRICT_ASSIGNMENT)", async () => {
    const user = userEvent.setup();
    (planningV2Api.fetchRosterEligibility as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      policy: "STRICT_ASSIGNMENT",
      candidates: [
        {
          id: 9, name: "Diane Lefebvre", email: "diane@test.com", eligible: false, selectable: false,
          reasons: ["SCHEDULE_CONFLICT"], unavailability: null,
          conflict: { missionId: 77, siteName: "Alpha", startAt: "2026-06-01T08:00:00", endAt: "2026-06-01T13:00:00" },
        },
        { id: 10, name: "Marc Petit", email: "marc@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
      ],
    });
    const preview: PreviewResponseV2 = {
      lines: [line({ status: "UNCOVERED" })],
      summary: { total: 1, covered: 0, uncovered: 1, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("À pourvoir");
    await user.click(screen.getByText("À pourvoir"));

    const popoverLabel = await screen.findByText("Instrumentiste");
    const input = popoverLabel.closest("div")!.querySelector("input")!;
    await user.click(input);

    const dianeOption = (await screen.findAllByRole("option")).find((o) => o.textContent?.includes("Diane Lefebvre"))!;
    expect(dianeOption).toHaveAttribute("aria-disabled", "true");
    expect(within(dianeOption).getByText(/Conflit d'horaire/)).toBeInTheDocument();
  });
});

describe("GeneratePlanningTab — Mode Modification (éditeur unifié)", () => {
  function mockHistoryWithOneVersion() {
    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({
      items: [{
        id: 42, status: "ACTIVE", periodStart: "2026-06-01T00:00:00Z", deployedAt: "2026-06-02T00:00:00Z",
        site: { id: 1, name: "Delta" }, summary: { total: 1, open: 0 },
      }],
      total: 1, page: 1, limit: 10,
    });
  }

  it("ouvre le MÊME éditeur en mode Modification depuis la liste des plannings déjà générés, avec les missions réelles", async () => {
    mockHistoryWithOneVersion();
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));

    // Same component, amber "Modification" badge, real Mission (Jean Martin / Diane Lefebvre) loaded — not a Preview.
    expect(await screen.findByText("Modification · Planning déployé")).toBeInTheDocument();
    expect(await screen.findByText("Jean Martin")).toBeInTheDocument();
    expect(screen.getByText("Diane Lefebvre")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Redéployer" })).toBeInTheDocument();
  });

  it("un planning ARCHIVED n'est pas ouvrable (dead end) — seuls ACTIVE et DRAFT le sont", async () => {
    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({
      items: [
        { id: 91, status: "ARCHIVED", periodStart: "2026-07-01T00:00:00Z", deployedAt: "2026-07-02T00:00:00Z", site: { id: 1, name: "Delta (historique)" }, summary: { total: 1, open: 0 } },
      ],
      total: 1, page: 1, limit: 10,
    });
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);

    // No "Modifier"/"Ouvrir" affordance, no "déjà généré" month chip — an ARCHIVED version is
    // already superseded, apply-modifications/cancel-all reject anything that isn't ACTIVE.
    await screen.findByText("Archivé");
    expect(screen.queryByText("Modifier")).not.toBeInTheDocument();
    expect(screen.queryByText("Ouvrir")).not.toBeInTheDocument();
    expect(screen.queryByText(/\d{4} · déjà généré/)).not.toBeInTheDocument();

    // Clicking the ARCHIVED row itself does nothing (no onClick wired).
    await user.click(screen.getByText("Archivé"));
    expect(screen.queryByText("Modification · Planning déployé")).not.toBeInTheDocument();

    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({ items: [], total: 0, page: 1, limit: 10 });
  });

  it("édite l'horaire d'une mission réelle via l'inspecteur permanent (pas de popup) puis redéploie", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.applyModifications as ReturnType<typeof vi.fn>).mockResolvedValue({ created: 0, updated: 1, cancelled: 0, released: 0, unchanged: 0 });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    // Click the mission row itself (via its instrumentist name, unique to that row — the
    // surgeon name above belongs to the day/surgeon group header, not the clickable line).
    await user.click(await screen.findByText("Diane Lefebvre"));

    // The inspector is a permanent panel: selecting a row just reloads its content, no dialog opens.
    const startField = await screen.findByLabelText("Début");
    await user.clear(startField);
    await user.type(startField, "09:00");

    const redeployBtn = screen.getByRole("button", { name: "Redéployer" });
    await waitFor(() => expect(redeployBtn).toBeEnabled());
    await user.click(redeployBtn);

    await waitFor(() => expect(planningV2Api.applyModifications).toHaveBeenCalled());
    const [versionId, lines] = (planningV2Api.applyModifications as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(versionId).toBe(42);
    expect(lines[0]).toMatchObject({ existingMissionId: 501, startTime: "09:00" });
  });

  it("D-102 — un conflit d'horaire reste non-bloquant (sélectionnable) en Mode Modification (PLANNING_MODIFICATION, D-091)", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.fetchRosterEligibility as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
      policy: "PLANNING_MODIFICATION",
      candidates: [
        { id: 9, name: "Diane Lefebvre", email: "diane@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
        {
          id: 10, name: "Marc Petit", email: "marc@test.com", eligible: false, selectable: true,
          reasons: ["SCHEDULE_CONFLICT"], unavailability: null,
          conflict: { missionId: 77, siteName: "Alpha", startAt: "2026-06-15T08:00:00", endAt: "2026-06-15T13:00:00" },
        },
      ],
    });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await user.click(await screen.findByText("Diane Lefebvre"));

    const popoverLabel = await screen.findByText("Instrumentiste");
    const input = popoverLabel.closest("div")!.querySelector("input")!;
    await user.click(input);

    const marcOption = await screen.findByRole("option", { name: /Marc Petit/ });
    // Non-blocking under PLANNING_MODIFICATION: visible with a warning badge, but still
    // clickable — a manager may deliberately accept a cross-site double-booking (D-091),
    // surfaced instead as a PlanningAlert rather than a hard block.
    expect(marcOption).not.toHaveAttribute("aria-disabled", "true");
    expect(within(marcOption).getByText(/Conflit d'horaire/)).toBeInTheDocument();
  });

  it("un 401 définitif sur Redéployer n'affiche jamais de succès, ne perd pas l'édition locale, et affiche le message de session expirée", async () => {
    mockHistoryWithOneVersion();
    const sessionExpiredError = { response: { status: 401, data: { message: "Expired JWT Token" } } };
    (planningV2Api.applyModifications as ReturnType<typeof vi.fn>).mockRejectedValue(sessionExpiredError);
    toastSuccess.mockClear();
    toastError.mockClear();
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await user.click(await screen.findByText("Diane Lefebvre"));

    const startField = await screen.findByLabelText("Début");
    await user.clear(startField);
    await user.type(startField, "09:00");

    const redeployBtn = screen.getByRole("button", { name: "Redéployer" });
    await waitFor(() => expect(redeployBtn).toBeEnabled());
    await user.click(redeployBtn);

    await waitFor(() => expect(planningV2Api.applyModifications).toHaveBeenCalled());

    // apiClient's interceptor already retried once via refresh before this reached the
    // mutation's onError — extractErrorV2() turns any 401 that gets this far into the
    // session-expired message, never the raw/generic backend error text.
    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Votre session a expiré. Reconnectez-vous pour enregistrer vos modifications."));
    expect(toastSuccess).not.toHaveBeenCalled();

    // Nothing about the failed save is treated as if it had succeeded: the edited start time
    // is still shown (onSuccess's setEditedLines(new Map()) never ran), and Redéployer stays
    // enabled — there is still a real, unsaved change to retry once reconnected.
    expect(screen.getByLabelText("Début")).toHaveValue("09:00");
    expect(screen.getByRole("button", { name: "Redéployer" })).toBeEnabled();
    // Still in Modification mode — a failed redeploy must not silently drop the user back to
    // the Génération screen, which would look like nothing was ever open to begin with.
    expect(screen.getByText("Modification · Planning déployé")).toBeInTheDocument();
  });

  it("supprime une mission fraîchement ajoutée en mode Modification au lieu de l'annuler (jamais envoyée au redéploiement)", { timeout: 10000 }, async () => {
    mockHistoryWithOneVersion();
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");

    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    const dateField = await screen.findByLabelText("Date");
    await user.type(dateField, "2026-09-20");

    const surgeonLabel = screen.getByText("Chirurgien");
    const surgeonInput = surgeonLabel.closest("div")!.querySelector("input")!;
    await user.click(surgeonInput);
    await user.click(await screen.findByText("Dr Martin"));

    // "Delta" also already appears as the existing mission's site badge in the background
    // list, so scope the click to the dropdown option (role) rather than a plain text match.
    const siteLabel = screen.getByText("Site");
    const siteInput = siteLabel.closest("div")!.querySelector("input")!;
    await user.click(siteInput);
    await user.click(await screen.findByRole("option", { name: "Delta" }));

    // Submit the draft — the "Ajouter" button inside the create form (not the toolbar one).
    const submitButtons = screen.getAllByRole("button", { name: "Ajouter" });
    await user.click(submitButtons[submitButtons.length - 1]);

    // The new draft line is now selected in the inspector — its delete action reads
    // "Supprimer" (never persisted server-side, nothing to "cancel").
    const deleteBtn = await screen.findByRole("button", { name: "Supprimer" });
    await user.click(deleteBtn);

    // The line is gone entirely — not just crossed out (marking it SKIPPED instead would have
    // left a stale edit behind, keeping "Redéployer" enabled with nothing genuine to submit).
    expect(screen.queryByRole("button", { name: "Supprimer" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Redéployer" })).toBeDisabled();
  });

  it("quitte le mode Modification et retrouve l'écran de génération", async () => {
    mockHistoryWithOneVersion();
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");

    await user.click(screen.getByRole("button", { name: /Quitter la modification/ }));

    expect(screen.queryByText("Modification · Planning déployé")).not.toBeInTheDocument();
    expect(screen.getByText("Générer le planning")).toBeInTheDocument();
  });

  it("supprime le mois — demande confirmation puis annule tout et quitte le mode Modification", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.cancelAllMissions as ReturnType<typeof vi.fn>).mockResolvedValue({ created: 0, updated: 0, cancelled: 2, released: 0, unchanged: 0 });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");

    await user.click(screen.getByRole("button", { name: "Supprimer ce mois" }));

    // Confirmation dialog — the destructive action must not fire on the first click.
    expect(await screen.findByText(/Toutes les missions assignées ou ouvertes/)).toBeInTheDocument();
    expect(planningV2Api.cancelAllMissions).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "Confirmer la suppression" }));

    await waitFor(() => expect(planningV2Api.cancelAllMissions).toHaveBeenCalledWith(42));
    // Success returns straight to Génération — there's nothing left to modify.
    await waitFor(() => expect(screen.queryByText("Modification · Planning déployé")).not.toBeInTheDocument());
    expect(screen.getByText("Générer le planning")).toBeInTheDocument();
  });

  it("annuler dans la boîte de dialogue de suppression ne fait rien", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.cancelAllMissions as ReturnType<typeof vi.fn>).mockClear();
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");

    await user.click(screen.getByRole("button", { name: "Supprimer ce mois" }));
    await screen.findByText(/Toutes les missions assignées ou ouvertes/);

    await user.click(screen.getByRole("button", { name: "Annuler" }));

    // MUI Dialog unmounts its content only after its close transition finishes.
    await waitFor(() => expect(screen.queryByText(/Toutes les missions assignées ou ouvertes/)).not.toBeInTheDocument());
    expect(planningV2Api.cancelAllMissions).not.toHaveBeenCalled();
    expect(screen.getByText("Modification · Planning déployé")).toBeInTheDocument();
  });
});

// ── Correctif Planning V2 — ligne avec chirurgien absent ─────────────────────

describe("GeneratePlanningTab — ligne avec chirurgien absent (SKIPPED)", () => {
  beforeEach(() => {
    // A preceding test in this file (Mode Modification block) leaves listPlanningVersions
    // mocked with a "Delta" history entry and never restores it — guarantee a clean slate
    // here regardless of execution order, so selectSite()'s "Delta" match stays unambiguous.
    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({ items: [], total: 0, page: 1, limit: 10 });
  });

  it("scénario A — n'affiche jamais l'ancien instrumentiste ni 'À pourvoir'/'Mission ouverte' sur une ligne chirurgien absent", async () => {
    const user = userEvent.setup();
    // Deliberately carries a stale instrumentistId/instrumentistName (as a SKIPPED line
    // legitimately does server-side for the D-034 "freed instrumentist" mechanism) — the
    // display layer itself, not just clean upstream data, must never surface it as if the
    // line still needed (or still had) coverage.
    const preview: PreviewResponseV2 = {
      lines: [line({
        surgeonName: "Dr Étienne Willemart", status: "SKIPPED",
        instrumentistId: 9, instrumentistName: "Salve Decorte",
      })],
      summary: { total: 1, covered: 0, uncovered: 0, skipped: 1, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));

    await screen.findByText("Dr Étienne Willemart");
    expect(screen.getByText("Chirurgien absent")).toBeInTheDocument();
    expect(screen.getByText("/")).toBeInTheDocument();
    expect(screen.queryByText("Salve Decorte")).not.toBeInTheDocument();
    expect(screen.queryByText("À pourvoir")).not.toBeInTheDocument();
    expect(screen.queryByText(/Mission ouverte/)).not.toBeInTheDocument();
  });

  it("scénario B — l'inspecteur affiche un état neutre pour une ligne chirurgien absent, sans dropdown ni proposition d'affectation", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [line({
        surgeonName: "Dr Étienne Willemart", status: "SKIPPED",
        instrumentistId: 9, instrumentistName: "Salve Decorte",
      })],
      summary: { total: 1, covered: 0, uncovered: 0, skipped: 1, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("Dr Étienne Willemart");

    // Select the SKIPPED row to load it into the permanent inspector panel.
    await user.click(screen.getByText("Chirurgien absent"));

    // Neutral state, reusing "Chirurgien absent" — never the searchable instrumentist
    // dropdown, never "Salve Decorte" presented as currently selected/active, never a
    // "Libérés disponibles + Assigner" suggestion implying this line needs coverage.
    await waitFor(() => expect(screen.queryByPlaceholderText("Rechercher un instrumentiste…")).not.toBeInTheDocument());
    expect(screen.queryByText("Libérés disponibles")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Remettre au pool (ouverte)" })).not.toBeInTheDocument();
  });
});

describe("GeneratePlanningTab — photos de profil (§6)", () => {
  beforeEach(() => {
    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({ items: [], total: 0, page: 1, limit: 10 });
  });

  it("scénario F — affiche la vraie photo quand profilePicturePath existe, sinon les initiales", async () => {
    const user = userEvent.setup();
    const preview: PreviewResponseV2 = {
      lines: [
        line({
          surgeonName: "Dr Martin", surgeonPhotoPath: "/uploads/profile-pictures/martin.jpg",
          status: "COVERED", instrumentistId: 9, instrumentistName: "Diane Lefebvre",
          instrumentistPhotoPath: "/uploads/profile-pictures/diane.jpg",
        }),
        line({
          postId: 2, surgeonId: 2, surgeonName: "Dr Dupont", surgeonPhotoPath: null,
          status: "UNCOVERED",
        }),
      ],
      summary: { total: 2, covered: 1, uncovered: 1, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-1",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));

    await screen.findByText("Dr Martin");
    // Real photo → an <img> with the resolved src, not just initials.
    const surgeonImg = screen.getByAltText("Dr Martin") as HTMLImageElement;
    expect(surgeonImg.tagName).toBe("IMG");
    expect(surgeonImg.src).toContain("/uploads/profile-pictures/martin.jpg");
    const instrImg = screen.getByAltText("Diane Lefebvre") as HTMLImageElement;
    expect(instrImg.src).toContain("/uploads/profile-pictures/diane.jpg");

    // No profilePicturePath → falls back to the existing initials pastille, no broken <img>.
    expect(screen.queryByAltText("Dr Dupont")).not.toBeInTheDocument();
    expect(screen.getByText("DD")).toBeInTheDocument();
  });
});

// ── Lot 6 (D-106) — "Vérifier les conflits" ──────────────────────────────────

describe("GeneratePlanningTab — Vérifier les conflits (Lot 6, D-106)", () => {
  function mockHistoryWithOneVersion() {
    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({
      items: [{
        id: 42, status: "ACTIVE", periodStart: "2026-06-01T00:00:00Z", deployedAt: "2026-06-02T00:00:00Z",
        site: { id: 1, name: "Delta" }, summary: { total: 1, open: 0 },
      }],
      total: 1, page: 1, limit: 10,
    });
  }

  it("le bouton est visible en Mode Modification, absent en Génération", async () => {
    mockHistoryWithOneVersion();
    const user = userEvent.setup();
    renderTab();

    // Génération mode (default screen): no version open, no button.
    expect(screen.queryByRole("button", { name: "Vérifier les conflits" })).not.toBeInTheDocument();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");

    expect(screen.getByRole("button", { name: "Vérifier les conflits" })).toBeInTheDocument();
  });

  it("état loading pendant la requête — empêche le double-clic", async () => {
    mockHistoryWithOneVersion();
    let resolvePromise: (v: unknown) => void = () => {};
    (planningV2Api.verifyConflicts as ReturnType<typeof vi.fn>).mockReturnValue(
      new Promise((resolve) => { resolvePromise = resolve; }),
    );
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");

    const verifyBtn = screen.getByRole("button", { name: "Vérifier les conflits" });
    await user.click(verifyBtn);

    // Disabled while pending (MUI sets pointer-events: none on it too, so a real user
    // literally cannot click it again) — the guard against a double-submit request.
    await waitFor(() => expect(verifyBtn).toBeDisabled());
    expect(planningV2Api.verifyConflicts).toHaveBeenCalledTimes(1);

    resolvePromise({ checkedMissions: 1, issuesFound: 0, automaticCorrections: 0, alertsCreated: 0, alertsResolved: 0, issues: [] });
    await waitFor(() => expect(verifyBtn).toBeEnabled());
  });

  it("aucun problème → message clair, aucune anomalie listée", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.verifyConflicts as ReturnType<typeof vi.fn>).mockResolvedValue({
      checkedMissions: 3, issuesFound: 0, automaticCorrections: 0, alertsCreated: 0, alertsResolved: 0, issues: [],
    });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");
    await user.click(screen.getByRole("button", { name: "Vérifier les conflits" }));

    expect(await screen.findByText("Vérification terminée")).toBeInTheDocument();
    expect(await screen.findByText(/Aucun conflit détecté/)).toBeInTheDocument();
  });

  it("corrections trouvées → résumé avec les compteurs", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.verifyConflicts as ReturnType<typeof vi.fn>).mockResolvedValue({
      checkedMissions: 5,
      issuesFound: 2,
      automaticCorrections: 1,
      alertsCreated: 1,
      alertsResolved: 0,
      issues: [
        { type: "INSTRUMENTIST_ABSENCE", missionId: 398, action: "RELEASED_TO_POOL" },
        { type: "INSTRUMENTIST_CONFLICT", missionId: 410, conflictingMissionId: 411, action: "ALERT_CREATED" },
      ],
    });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");
    await user.click(screen.getByRole("button", { name: "Vérifier les conflits" }));

    expect(await screen.findByText(/2 anomalies détectées/)).toBeInTheDocument();
    expect(screen.getByText(/1 corrigée automatiquement/)).toBeInTheDocument();
    expect(screen.getByText(/1 alerte signalée/)).toBeInTheDocument();
  });

  it("mission laissée non couverte → message d'action requise", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.verifyConflicts as ReturnType<typeof vi.fn>).mockResolvedValue({
      checkedMissions: 1,
      issuesFound: 1,
      automaticCorrections: 1,
      alertsCreated: 0,
      alertsResolved: 0,
      issues: [{ type: "INSTRUMENTIST_ABSENCE", missionId: 398, action: "RELEASED_TO_POOL" }],
    });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");
    await user.click(screen.getByRole("button", { name: "Vérifier les conflits" }));

    expect(await screen.findByText(/laissée non couverte et doit être réaffectée/)).toBeInTheDocument();
  });

  it("erreur API → message clair, pas de crash", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.verifyConflicts as ReturnType<typeof vi.fn>).mockRejectedValue({
      response: { data: { error: { message: "PlanningVersion must be ACTIVE." } } },
    });
    toastError.mockClear();
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");
    await user.click(screen.getByRole("button", { name: "Vérifier les conflits" }));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("PlanningVersion must be ACTIVE."));
    // No result dialog on error — nothing to report.
    expect(screen.queryByText("Vérification terminée")).not.toBeInTheDocument();
    // Still in Modification mode — an audit failure must not kick the manager back out.
    expect(screen.getByText("Modification · Planning déployé")).toBeInTheDocument();
  });

  it("succès → invalide les alertes et rafraîchit les missions affichées", async () => {
    mockHistoryWithOneVersion();
    (planningV2Api.verifyConflicts as ReturnType<typeof vi.fn>).mockResolvedValue({
      checkedMissions: 1, issuesFound: 1, automaticCorrections: 1, alertsCreated: 0, alertsResolved: 0,
      issues: [{ type: "INSTRUMENTIST_ABSENCE", missionId: 501, action: "RELEASED_TO_POOL" }],
    });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByText("Modifier"));
    await screen.findByText("Modification · Planning déployé");
    // Real Mission 501 was ASSIGNED to Diane before the scan.
    expect(await screen.findByText("Diane Lefebvre")).toBeInTheDocument();

    const missionsCallsBefore = (apiClient.get as ReturnType<typeof vi.fn>).mock.calls
      .filter(([url, cfg]) => url === "/api/missions" && cfg?.params?.planningVersionId).length;

    await user.click(screen.getByRole("button", { name: "Vérifier les conflits" }));
    await screen.findByText("Vérification terminée");

    // The mission list refetch was triggered — same query used elsewhere in Modification
    // mode (fetchMissions scoped to planningVersionId), proving the scan's result is
    // reflected without a full page reload.
    await waitFor(() => {
      const missionsCallsAfter = (apiClient.get as ReturnType<typeof vi.fn>).mock.calls
        .filter(([url, cfg]) => url === "/api/missions" && cfg?.params?.planningVersionId).length;
      expect(missionsCallsAfter).toBeGreaterThan(missionsCallsBefore);
    });
  });
});

describe("GeneratePlanningTab — Brouillons (CAS D, D-115)", () => {
  // The month chips only ever span "current month + next 5" (buildMonthChipIds) — the draft's
  // periodStart must fall inside that window for the chip-level assertions below, so it's
  // derived from the real clock rather than a hardcoded month that could drift outside it.
  const MONTH_LABELS_FR = [
    "Janvier", "Février", "Mars", "Avril", "Mai", "Juin",
    "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre",
  ];
  const now = new Date();
  const draftPeriodStart = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-01T00:00:00Z`;
  const draftMonthLabel = MONTH_LABELS_FR[now.getMonth()];

  function mockHistoryWithOneDraft() {
    // "Delta (historique)" (not the bare "Delta" the site selector option also renders as) —
    // same convention as the Mode Modification tests above, avoids an ambiguous findByText.
    (planningManagerApi.listPlanningVersions as ReturnType<typeof vi.fn>).mockResolvedValue({
      items: [{
        id: 77, status: "DRAFT", periodStart: draftPeriodStart, deployedAt: null,
        site: { id: 1, name: "Delta (historique)" }, summary: { total: 1, open: 1 },
      }],
      total: 1, page: 1, limit: 10,
    });
  }

  function draftReopenResponse(overrides: Partial<{ divergent: boolean }> = {}) {
    return {
      version: { id: 77, status: "DRAFT" as const, periodStart: "2026-06-01", periodEnd: "2026-06-30", siteId: 1, siteName: "Delta", generatedAt: "2026-06-01T00:00:00Z" },
      lines: [line({ existingMissionId: 501, instrumentistId: 9, instrumentistName: "Diane Lefebvre" })],
      summary: { total: 1, covered: 1, uncovered: 0, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "hash-now",
      divergent: overrides.divergent ?? false,
      generatedAt: "2026-06-02T00:00:00Z",
    };
  }

  it("un mois avec un brouillon existant propose 'Ouvrir', jamais 'Modifier' ni un second 'Générer'", async () => {
    mockHistoryWithOneDraft();
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);

    await screen.findByText("Brouillon");
    expect(screen.getByText("Ouvrir")).toBeInTheDocument();
    expect(screen.queryByText("Modifier")).not.toBeInTheDocument();
    expect(await screen.findByText(new RegExp(`${now.getFullYear()} · Brouillon`))).toBeInTheDocument();
  });

  it("'Ouvrir le brouillon' charge les lignes réelles via reopenDraft et propose 'Enregistrer les modifications'", async () => {
    mockHistoryWithOneDraft();
    (planningV2Api.reopenDraft as ReturnType<typeof vi.fn>).mockResolvedValue(draftReopenResponse());
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);

    await user.click(await screen.findByText("Ouvrir"));

    expect(planningV2Api.reopenDraft).toHaveBeenCalledWith(77);
    expect(await screen.findByText("Diane Lefebvre")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enregistrer les modifications" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Générer les missions" })).not.toBeInTheDocument();
  });

  it("affiche la bannière de divergence sans bloquer ni remplacer les lignes déjà enregistrées", async () => {
    mockHistoryWithOneDraft();
    (planningV2Api.reopenDraft as ReturnType<typeof vi.fn>).mockResolvedValue(draftReopenResponse({ divergent: true }));
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);

    await user.click(await screen.findByText("Ouvrir"));

    expect(await screen.findByText(/a chang[ée] depuis la cr[ée]ation de ce brouillon/)).toBeInTheDocument();
    // The persisted assignment is still shown as-is, never silently dropped/replaced.
    expect(screen.getByText("Diane Lefebvre")).toBeInTheDocument();
  });

  it("'Enregistrer les modifications' appelle updateDraft avec la version et les lignes courantes", async () => {
    mockHistoryWithOneDraft();
    (planningV2Api.reopenDraft as ReturnType<typeof vi.fn>).mockResolvedValue(draftReopenResponse());
    (planningV2Api.updateDraft as ReturnType<typeof vi.fn>).mockResolvedValue({ created: 0, updated: 1, removed: 0, skipped: 0, rejectedAssignments: [] });
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);
    await user.click(await screen.findByText("Ouvrir"));
    await screen.findByText("Diane Lefebvre");

    await user.click(screen.getByRole("button", { name: "Enregistrer les modifications" }));

    await waitFor(() => expect(planningV2Api.updateDraft).toHaveBeenCalled());
    const [versionId, lines] = (planningV2Api.updateDraft as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(versionId).toBe(77);
    expect(lines[0]).toMatchObject({ existingMissionId: 501, instrumentistId: 9 });
    // Reuses the exact same post-generate() UI (Déployer becomes available) — no separate draft-save screen.
    expect(await screen.findByRole("button", { name: /Déployer/ })).toBeInTheDocument();
  });

  it("'Supprimer' un brouillon demande confirmation puis appelle deletePlanningVersionDraft", async () => {
    mockHistoryWithOneDraft();
    (planningV2Api.deletePlanningVersionDraft as ReturnType<typeof vi.fn>).mockResolvedValue(undefined);
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);
    await screen.findByText("Brouillon");

    await user.click(screen.getByLabelText("Supprimer le brouillon"));

    expect(await screen.findByText(new RegExp(`Supprimer le brouillon ${draftMonthLabel} ${now.getFullYear()}`))).toBeInTheDocument();
    expect(screen.getByText(/postes récurrents des chirurgiens ne seront pas modifiés/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Supprimer le brouillon" }));

    await waitFor(() => expect(planningV2Api.deletePlanningVersionDraft).toHaveBeenCalledWith(77));
  });

  // ── CAS C (D-116) — "Ajouter" on a reopened DRAFT ─────────────────────────────
  // Strictly separate handler from ACTIVE/Modification's applyModifications: a manual
  // add on a reopened draft must flow through updateDraft only, never applyModifications
  // (that would route it into MissionPostDeployService and give it ASSIGNED/OPEN — see
  // PlanningV2DraftControllerTest's backend regressions for the same invariant).
  it("'Ajouter' est disponible sur un brouillon rouvert et envoie la nouvelle ligne via updateDraft, jamais applyModifications", async () => {
    mockHistoryWithOneDraft();
    (planningV2Api.reopenDraft as ReturnType<typeof vi.fn>).mockResolvedValue(draftReopenResponse());
    (planningV2Api.updateDraft as ReturnType<typeof vi.fn>).mockResolvedValue({ created: 1, updated: 1, removed: 0, skipped: 0, rejectedAssignments: [] });
    // Shared, never-reset module-level mock — an earlier Modification-mode test in this
    // file already called applyModifications; the "never called" assertion below must
    // compare against the count at THIS test's start, not zero.
    const applyModificationsCallsBefore = (planningV2Api.applyModifications as ReturnType<typeof vi.fn>).mock.calls.length;
    const user = userEvent.setup();
    renderTab();
    await selectSite(user);
    await user.click(await screen.findByText("Ouvrir"));
    await screen.findByText("Diane Lefebvre");

    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    const dateField = await screen.findByLabelText("Date");
    await user.type(dateField, "2026-06-20");

    // "Dr Martin" already appears as the existing mission's surgeon in the background
    // list, so scope the click to the dropdown option (role), same convention the "Delta"
    // site click below already uses for its own pre-existing ambiguous text.
    const surgeonLabel = screen.getByText("Chirurgien");
    const surgeonInput = surgeonLabel.closest("div")!.querySelector("input")!;
    await user.click(surgeonInput);
    // The option's accessible name also includes the avatar-initials text ("DM"), so
    // match by regex rather than the exact visible label.
    await user.click(await screen.findByRole("option", { name: /Dr Martin/ }));

    const siteLabel = screen.getByText("Site");
    const siteInput = siteLabel.closest("div")!.querySelector("input")!;
    await user.click(siteInput);
    await user.click(await screen.findByRole("option", { name: "Delta" }));

    const submitButtons = screen.getAllByRole("button", { name: "Ajouter" });
    await user.click(submitButtons[submitButtons.length - 1]);

    await user.click(screen.getByRole("button", { name: "Enregistrer les modifications" }));

    await waitFor(() => expect(planningV2Api.updateDraft).toHaveBeenCalled());
    // .lastCall, not .calls[0] — an earlier test in this file already called updateDraft
    // once (shared, never-reset module-level mock), so [0] would grab that stale call.
    const [versionId, lines] = (planningV2Api.updateDraft as ReturnType<typeof vi.fn>).mock.lastCall!;
    expect(versionId).toBe(77);
    // The staged addition has no existingMissionId yet — this is what tells
    // PlanningDraftService::update() to route it to createAdHocDraftMission(), never
    // createMissionFromLine()/createPostDeploy() (postId <= 0, the editor's own
    // negative-decrementing convention for a manual, non-Post add).
    const newLine = lines.find((l: PreviewLineV2) => l.existingMissionId === null);
    expect(newLine).toBeDefined();
    expect(newLine.postId).toBeLessThanOrEqual(0);
    expect(newLine.date).toBe("2026-06-20");
    expect((planningV2Api.applyModifications as ReturnType<typeof vi.fn>).mock.calls.length).toBe(applyModificationsCallsBefore);
    // Stabilisation pré-déploiement D-118 (2026-09-12) — correctif indépendant, sans
    // rapport avec D-118 : cette séquence enchaîne 7+ interactions userEvent réalistes
    // (frappe caractère par caractère, deux Autocomplete MUI ouverts/filtrés/refermés,
    // plusieurs clics) — vérifié à la main que la logique est correcte et s'exécute en
    // ~4s hors démarrage Vitest ; seul le délai par défaut de 5000ms est trop juste
    // sous charge (même convention que AdminCreateUserModal.test.tsx).
  }, 10000);
});

describe("GeneratePlanningTab — conflit de déploiement, autorisation de dérogation (D-091 suite)", () => {
  function draftConflictsError(conflicts: unknown[]) {
    return { response: { status: 409, data: { code: "DRAFT_CONFLICTS", conflicts } } };
  }

  async function reachDeployButton(user: ReturnType<typeof userEvent.setup>) {
    const now = new Date();
    const currentMonthDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-10`;
    const preview: PreviewResponseV2 = {
      lines: [line({ date: currentMonthDate, status: "COVERED", instrumentistId: 9, instrumentistName: "Diane Lefebvre" })],
      summary: { total: 1, covered: 1, uncovered: 0, skipped: 0, conflict: 0, modified: 0 },
      previewVersion: "v-conflict",
      generatedAt: "2026-06-01T00:00:00Z",
    };
    (planningV2Api.previewPlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue(preview);
    (planningV2Api.generatePlanningV2 as ReturnType<typeof vi.fn>).mockResolvedValue({ versionId: 42, created: 1, updated: 0, skipped: 0 });

    renderTab();
    await selectSite(user);
    await user.click(screen.getByRole("button", { name: "Prévisualiser" }));
    await screen.findByText("Diane Lefebvre");
    await user.click(screen.getByRole("button", { name: "Générer les missions" }));
    await user.click(await screen.findByRole("button", { name: "Déployer le planning" }));
  }

  it("un conflit waivable propose une case à cocher ; un conflit non-waivable n'en propose aucune", async () => {
    const user = userEvent.setup();
    // deployPlanningV2/authorizeConflicts are shared, never-reset module-level mocks (this
    // file's convention) — reset explicitly so this test's queued once-values can never be
    // shifted by another test's leftover queue, regardless of execution order.
    (planningV2Api.deployPlanningV2 as ReturnType<typeof vi.fn>).mockReset();
    (planningV2Api.deployPlanningV2 as ReturnType<typeof vi.fn>).mockRejectedValueOnce(draftConflictsError([
      {
        type: "CROSS_SITE_CONFLICT", missionId: 501, date: "2026-06-10",
        siteId: 1, siteName: "Delta", surgeonId: 1, surgeonName: "Dr Martin",
        instrumentistId: 9, instrumentistName: "Diane Lefebvre",
        conflictingMissionId: 502, conflictingSiteId: 1, conflictingSiteName: "Delta",
        conflictingStartAt: "2026-06-10T08:00:00+02:00", conflictingEndAt: "2026-06-10T13:00:00+02:00",
        waivable: true, reason: "Double salle — même chirurgien, même instrumentiste.",
      },
      {
        type: "CROSS_SITE_CONFLICT", missionId: 503, date: "2026-06-10",
        siteId: 1, siteName: "Delta", surgeonId: 2, surgeonName: "Dr Autre",
        instrumentistId: 9, instrumentistName: "Diane Lefebvre",
        conflictingMissionId: 504, conflictingSiteId: 2, conflictingSiteName: "Basilique",
        conflictingStartAt: "2026-06-10T08:00:00+02:00", conflictingEndAt: "2026-06-10T13:00:00+02:00",
        waivable: false, reason: "Instrumentiste déjà prévue ailleurs.",
      },
    ]));

    await reachDeployButton(user);

    expect(await screen.findByText(/Déploiement bloqué — 2 conflit/)).toBeInTheDocument();
    const checkboxes = screen.getAllByRole("checkbox");
    // Exactly one checkbox — only the waivable conflict gets one.
    expect(checkboxes).toHaveLength(1);
    expect(screen.getByText(/peut être autorisé/)).toBeInTheDocument();
  });

  it("cocher un conflit waivable puis 'Autoriser et redéployer' appelle authorizeConflicts avec la bonne paire, puis redéploie", async () => {
    const user = userEvent.setup();
    (planningV2Api.deployPlanningV2 as ReturnType<typeof vi.fn>).mockReset();
    (planningV2Api.authorizeConflicts as ReturnType<typeof vi.fn>).mockReset();
    (planningV2Api.deployPlanningV2 as ReturnType<typeof vi.fn>)
      .mockRejectedValueOnce(draftConflictsError([{
        type: "CROSS_SITE_CONFLICT", missionId: 601, date: "2026-06-11",
        siteId: 1, siteName: "Delta", surgeonId: 1, surgeonName: "Dr Martin",
        instrumentistId: 9, instrumentistName: "Diane Lefebvre",
        conflictingMissionId: 602, conflictingSiteId: 1, conflictingSiteName: "Delta",
        waivable: true, reason: "Double salle.",
      }]))
      .mockResolvedValueOnce({ deploymentId: 1, missionCount: 2, openPoolCount: 0 });
    (planningV2Api.authorizeConflicts as ReturnType<typeof vi.fn>).mockResolvedValue({
      authorized: [{ missionId: 601, conflictingMissionId: 602, waiverId: 9 }],
      failed: [],
    });

    await reachDeployButton(user);

    await screen.findByText(/Déploiement bloqué/);
    await user.click(screen.getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: /Autoriser.*redéployer/ }));

    await waitFor(() => expect(planningV2Api.authorizeConflicts).toHaveBeenCalledWith(
      [{ missionId: 601, conflictingMissionId: 602 }],
    ));
    // The dialog's whole point is "deploy anyway" — a second manual click must never be required.
    await waitFor(() => expect(planningV2Api.deployPlanningV2).toHaveBeenCalledTimes(2));
    expect(toastSuccess).toHaveBeenCalledWith(expect.stringContaining("Planning déployé"));
    // MUI's Dialog exit transition lingers in the DOM for a moment — waitFor, not a bare assertion.
    await waitFor(() => expect(screen.queryByText(/Déploiement bloqué/)).not.toBeInTheDocument());
    // Stabilisation pré-déploiement D-118 (2026-09-12) — correctif indépendant, sans
    // rapport avec D-118 : reachDeployButton() enchaîne déjà plusieurs interactions
    // userEvent réalistes avant même le scénario propre à ce test (checkbox + dialog +
    // deux appels réseau simulés) — vérifié à la main que la logique est correcte ;
    // seul le délai par défaut de 5000ms est trop juste sous charge (même convention
    // que AdminCreateUserModal.test.tsx).
  }, 10000);
});
