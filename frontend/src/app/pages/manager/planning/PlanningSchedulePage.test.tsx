import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import PlanningSchedulePage from "./PlanningSchedulePage";

// Recette D-156 (2026-10-19) — these full-editor flows (MUI Autocomplete, many userEvent
// steps) take 5–11 s on a loaded machine, already timing out on main at 5/10 s: a
// realistic per-file timeout instead of flaky failures (the logic itself is unchanged).
vi.setConfig({ testTimeout: 30_000 });

// ── Module mocks ──────────────────────────────────────────────────────────────

vi.mock("../../../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn(),
  fetchAllMissions: vi.fn(),
  fetchMissionById: vi.fn(),
  cancelMission: vi.fn(),
}));
vi.mock("../../../features/sites/api/sites.api", () => ({
  fetchSites: vi.fn().mockResolvedValue([{ id: 1, name: "Alpha" }]),
}));
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));
vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get:  vi.fn().mockResolvedValue({ data: { items: [] } }),
    post: vi.fn().mockResolvedValue({ data: {} }),
  },
}));
vi.mock("../../../features/planning-v2/api/planningV2.api", () => ({
  fetchCoverageSummary: vi.fn().mockResolvedValue({
    versionId: 1, total: 10, covered: 8, open: 2, cancelled: 0, coveragePercent: 80,
  }),
  releaseMission:  vi.fn().mockResolvedValue(undefined),
  reassignMission: vi.fn().mockResolvedValue(undefined),
  extractErrorV2: (err: any) => err?.response?.data?.error?.message ?? "Erreur",
  fetchMissionAudit: vi.fn().mockResolvedValue([]),
  fetchMissionEligibleInstrumentists: vi.fn().mockResolvedValue({
    missionId: 1,
    missionStatus: "ASSIGNED",
    policy: "STRICT_ASSIGNMENT",
    candidates: [
      { id: 20, name: "Claire Dubois", email: "claire@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
      { id: 21, name: "Marc Leroy", email: "marc@test.com", eligible: false, selectable: false, reasons: ["ABSENT"], unavailability: null, conflict: null },
    ],
  }),
  // D-102 — ScheduleInstrumentistCell's OPEN-mission picker; default everyone selectable.
  fetchRosterEligibility: vi.fn().mockResolvedValue({
    policy: "STRICT_ASSIGNMENT",
    candidates: [
      { id: 20, name: "Claire Dubois", email: "claire@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
    ],
  }),
}));

import * as missionsApi from "../../../features/missions/api/missions.api";
import * as planningApi from "../../../features/planning-v2/api/planningV2.api";
import { apiClient } from "../../../api/apiClient";

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeMission(overrides: Record<string, unknown> = {}) {
  return {
    id:      1,
    type:    "BLOCK",
    startAt: "2026-07-01T08:00:00",
    endAt:   "2026-07-01T13:00:00",
    status:  "OPEN",
    surgeon:      { id: 10, email: "dr@test.com", firstname: "Jean", lastname: "Dupont" },
    instrumentist: null,
    site: { id: 1, name: "Alpha" },
    ...overrides,
  };
}

function makeAssignedMission(overrides: Record<string, unknown> = {}) {
  return makeMission({
    status: "ASSIGNED",
    instrumentist: { id: 5, email: "instr@test.com", firstname: "Marie", lastname: "Martin" },
    ...overrides,
  });
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <PlanningSchedulePage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

async function loadMissions(missions: unknown[]) {
  vi.mocked(missionsApi.fetchMissions).mockResolvedValue({
    items: missions as any,
    total: missions.length,
  });
  vi.mocked(missionsApi.fetchAllMissions).mockResolvedValue(missions as any);
  const user = userEvent.setup();
  renderPage();
  const btn = screen.getByRole("button", { name: /charger le planning/i });
  await user.click(btn);
  return user;
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe("PlanningSchedulePage — base render", () => {
  it("renders filter controls", () => {
    renderPage();
    expect(screen.getByLabelText("Du")).toBeInTheDocument();
    expect(screen.getByLabelText("Au")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /charger le planning/i })).toBeInTheDocument();
  });
});

describe("PlanningSchedulePage — mission status chips", () => {
  it("shows 'À réserver' chip for OPEN missions", async () => {
    await loadMissions([makeMission({ status: "OPEN" })]);
    await waitFor(() => expect(screen.getByText("À réserver")).toBeInTheDocument());
  });

  it("deux salles simultanées restent identifiables : période juste et horaire affiché (recette D-156)", async () => {
    await loadMissions([
      makeAssignedMission({ id: 1, startAt: "2026-07-01T07:45:00", endAt: "2026-07-01T13:00:00" }),
      makeAssignedMission({ id: 2, startAt: "2026-07-01T07:45:00", endAt: "2026-07-01T18:00:00" }),
    ]);
    await waitFor(() => expect(screen.getByText("07h45–18h00")).toBeInTheDocument());
    expect(screen.getByText("07h45–13h00")).toBeInTheDocument();
    expect(screen.getByText("Journée")).toBeInTheDocument();
    expect(screen.getByText("Matin")).toBeInTheDocument();
  });

  it("shows 'Assigné' chip for ASSIGNED missions", async () => {
    await loadMissions([makeAssignedMission()]);
    await waitFor(() => expect(screen.getByText("Assigné")).toBeInTheDocument());
  });

  it("shows 'Annulé' chip for CANCELLED missions", async () => {
    await loadMissions([makeMission({ status: "CANCELLED" })]);
    await waitFor(() => expect(screen.getByText("Annulé")).toBeInTheDocument());
  });
});

describe("PlanningSchedulePage — Release action", () => {
  it("shows Release button only on ASSIGNED missions", async () => {
    await loadMissions([makeAssignedMission()]);
    await waitFor(() => {
      expect(screen.getByRole("button", { name: /remettre au pool/i })).toBeInTheDocument();
    });
  });

  it("does not show Release button on OPEN missions", async () => {
    await loadMissions([makeMission({ status: "OPEN" })]);
    await waitFor(() => expect(screen.queryByRole("button", { name: /remettre au pool/i })).toBeNull());
  });

  it("opens confirm dialog on Release click and calls API on confirm", async () => {
    const user = await loadMissions([makeAssignedMission()]);

    await waitFor(() =>
      expect(screen.getByRole("button", { name: /remettre au pool/i })).toBeInTheDocument(),
    );

    await user.click(screen.getByRole("button", { name: /remettre au pool/i }));
    expect(await screen.findByText(/remettre au pool \?/i)).toBeInTheDocument();

    // Confirm in dialog
    const dialog = screen.getByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /remettre au pool/i }));

    await waitFor(() => {
      expect(planningApi.releaseMission).toHaveBeenCalledWith(1);
    });
  });
});

describe("PlanningSchedulePage — Cancel action (D-182)", () => {
  it("shows the Cancel button when the server allows it (allowedActions)", async () => {
    await loadMissions([makeMission({ status: "OPEN", allowedActions: ["view", "cancel"] })]);
    await waitFor(() => {
      expect(screen.getByRole("button", { name: /annuler la mission/i })).toBeInTheDocument();
    });
  });

  it("never deduces it from the status: no Cancel button without the server's 'cancel'", async () => {
    await loadMissions([makeAssignedMission({ allowedActions: ["view", "reassign"] })]);
    await waitFor(() => expect(screen.getByText("Marie Martin")).toBeInTheDocument());
    expect(screen.queryByRole("button", { name: /annuler la mission/i })).toBeNull();
  });

  it("shows the action disabled, with the server's reason, when the mission cannot be cancelled", async () => {
    const user = await loadMissions([makeMission({
      status: "VALIDATED",
      allowedActions: ["view"],
      cancellation: { blockedReason: { code: "CANCELLATION_MISSION_BILLED", message: "Cette mission est déjà facturée." }, correction: null },
    })]);
    const button = await screen.findByRole("button", { name: /annuler la mission/i });
    expect(button).toBeDisabled();
    await user.hover(button.parentElement!);
    expect(await screen.findByRole("tooltip")).toHaveTextContent("Annulation impossible : Cette mission est déjà facturée.");
    expect(missionsApi.fetchMissionById).not.toHaveBeenCalled();
  });

  it("does not show Cancel button on CANCELLED missions", async () => {
    await loadMissions([makeMission({ status: "CANCELLED" })]);
    await waitFor(() => expect(screen.queryByRole("button", { name: /annuler la mission/i })).toBeNull());
  });

  it("cancels an ASSIGNED mission directly, without releasing it to the pool first (two clicks)", async () => {
    const assigned = makeAssignedMission({
      allowedActions: ["view", "cancel", "reassign"],
      cancellation: { blockedReason: null, hasEncodingData: false },
    });
    vi.mocked(planningApi.releaseMission).mockClear();
    vi.mocked(missionsApi.fetchMissionById).mockResolvedValue(assigned as any);
    vi.mocked(missionsApi.cancelMission).mockResolvedValue({ ...assigned, status: "CANCELLED" } as any);
    const user = await loadMissions([assigned]);

    await user.click(await screen.findByRole("button", { name: /annuler la mission/i }));
    await user.click(await screen.findByTestId("confirm-cancel-mission"));

    await waitFor(() => expect(missionsApi.cancelMission).toHaveBeenCalledWith(1, "", "", false));
    expect(planningApi.releaseMission).not.toHaveBeenCalled();
  });
});

describe("PlanningSchedulePage — optimistic updates", () => {
  it("immediately shows OPEN status after Release click (optimistic)", async () => {
    // Make releaseMission take a moment so we can check interim state
    vi.mocked(planningApi.releaseMission).mockImplementation(
      () => new Promise((resolve) => setTimeout(resolve, 200)),
    );

    const user = await loadMissions([makeAssignedMission()]);

    await waitFor(() =>
      expect(screen.getByRole("button", { name: /remettre au pool/i })).toBeInTheDocument(),
    );
    await user.click(screen.getByRole("button", { name: /remettre au pool/i }));

    const dialog = screen.getByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /remettre au pool/i }));

    // Status chip should optimistically change to "À réserver"
    await waitFor(() => expect(screen.getByText("À réserver")).toBeInTheDocument());
  });
});

describe("PlanningSchedulePage — Changer d'instrumentiste (D-181)", () => {
  const REASSIGNABLE = { allowedActions: ["view", "cancel", "reassign"], reassignment: { modes: ["DIRECT"], blockedReason: null, relayWindow: null } };
  const done = (ids: number[], failed: number[] = []) => ({ data: {
    operationId: "01a1a10c-8f95-7388-bf2e-9304293a241d",
    results: [
      ...ids.map((id) => ({ missionId: id, status: "DONE", mode: "DIRECT", relayMissionId: null })),
      ...failed.map((id) => ({ missionId: id, status: "FAILED", error: { status: 409, code: "INSTRUMENTIST_INCOMPATIBLE", message: "Instrumentiste indisponible", reasons: ["SCHEDULE_CONFLICT"] } })),
    ],
    recipients: [{ userId: 5, name: "Marie Martin", roles: ["PREVIOUS_INSTRUMENTIST"] }],
  } });

  function missionsById(missions: Array<Record<string, unknown>>) {
    vi.mocked(missionsApi.fetchMissionById).mockImplementation(async (id: number) => missions.find((m) => m.id === id) as any);
  }

  it("the action follows allowedActions — also on a mission of the day already started", async () => {
    const started = makeAssignedMission({ id: 1, status: "IN_PROGRESS", ...REASSIGNABLE });
    const locked = makeAssignedMission({ id: 2, status: "SUBMITTED", allowedActions: ["view", "validate", "reject"] });
    await loadMissions([started, locked]);

    await waitFor(() => expect(screen.getAllByRole("button", { name: /changer d'instrumentiste/i })).toHaveLength(1));
    expect(screen.getByTestId("schedule-select-1")).toBeInTheDocument();
    expect(screen.queryByTestId("schedule-select-2")).toBeNull();
  });

  it("opens the mission's dialog and sends one reassignment operation", async () => {
    const mission = makeAssignedMission({ id: 1, ...REASSIGNABLE });
    missionsById([mission]);
    vi.mocked(apiClient.post).mockResolvedValueOnce(done([1]));
    const user = await loadMissions([mission]);

    await user.click(await screen.findByRole("button", { name: /changer d'instrumentiste/i }));
    expect(await screen.findByTestId("change-instrumentist-summary")).toHaveTextContent("Marie Martin");
    expect(await screen.findByTestId("candidate-21")).toHaveAttribute("aria-disabled", "true");
    fireEvent.click(screen.getByTestId("candidate-20"));
    await user.click(screen.getByTestId("confirm-reassignment"));

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith("/api/missions/reassignments", {
      items: [{ missionId: 1, instrumentistId: 20, mode: "DIRECT", expectedInstrumentistId: 5 }],
    }));
    expect(await screen.findByTestId("change-instrumentist-done")).toBeInTheDocument();
  });

  it("réaffectation multiple : un choix par mission, récapitulatif, puis résultat mission par mission", async () => {
    const morning = makeAssignedMission({ id: 1, ...REASSIGNABLE });
    const afternoon = makeAssignedMission({ id: 2, startAt: "2026-07-01T13:00:00", endAt: "2026-07-01T17:00:00", ...REASSIGNABLE });
    missionsById([morning, afternoon]);
    vi.mocked(apiClient.post).mockClear();
    vi.mocked(apiClient.post).mockResolvedValueOnce(done([1], [2]));
    const user = await loadMissions([morning, afternoon]);

    await user.click(await screen.findByTestId("schedule-select-1"));
    await user.click(screen.getByTestId("schedule-select-2"));
    await user.click(screen.getByTestId("schedule-batch-reassign"));

    for (const id of [1, 2]) {
      const row = await screen.findByTestId(`batch-row-${id}`);
      fireEvent.mouseDown(within(row).getByRole("combobox"));
      fireEvent.click(await screen.findByTestId(`batch-option-${id}-20`));
    }
    await user.click(screen.getByTestId("batch-to-recap"));
    const recap = await screen.findByTestId("batch-recap");
    expect(within(recap).getAllByText(/Marie Martin/)).toHaveLength(2);
    expect(within(recap).getAllByText(/Claire Dubois/)).toHaveLength(2);
    expect(apiClient.post).not.toHaveBeenCalledWith("/api/missions/reassignments", expect.anything());

    await user.click(screen.getByTestId("batch-confirm"));
    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith("/api/missions/reassignments", {
      items: [
        { missionId: 1, instrumentistId: 20, mode: "DIRECT", expectedInstrumentistId: 5 },
        { missionId: 2, instrumentistId: 20, mode: "DIRECT", expectedInstrumentistId: 5 },
      ],
    }));
    expect(await screen.findByTestId("batch-result-1")).toHaveTextContent("réaffectée");
    expect(screen.getByTestId("batch-result-2")).toHaveTextContent("Instrumentiste indisponible");
  });
});

describe("PlanningSchedulePage — status filter", () => {
  it("shows filter buttons", () => {
    renderPage();
    expect(screen.getByRole("group", { name: /filtre par statut/i })).toBeInTheDocument();
  });

  it("filters to OPEN only when OPEN filter is selected", async () => {
    const user = await loadMissions([
      makeMission({ id: 1, status: "OPEN" }),
      makeAssignedMission({ id: 2 }),
    ]);

    // Wait for both missions to load
    await waitFor(() => expect(screen.getByText("À réserver")).toBeInTheDocument());
    await waitFor(() => expect(screen.getByText("Assigné")).toBeInTheDocument());

    // Click the "Ouverts" filter button
    await user.click(screen.getByRole("button", { name: /^Ouverts$/i }));

    await waitFor(() => {
      expect(screen.getByText("À réserver")).toBeInTheDocument();
      expect(screen.queryByText("Assigné")).toBeNull();
    });
  });
});

describe("PlanningSchedulePage — coverage banner", () => {
  it("shows CoverageBanner when a versionId is entered", async () => {
    vi.mocked(planningApi.fetchCoverageSummary).mockResolvedValue({
      versionId: 5, total: 10, covered: 9, open: 1, cancelled: 0, coveragePercent: 90,
    });

    const user = userEvent.setup();
    renderPage();

    const versionInput = screen.getByLabelText(/numéro de version/i);
    await user.clear(versionInput);
    await user.type(versionInput, "5");

    await waitFor(() => {
      expect(screen.getByTestId("coverage-banner")).toBeInTheDocument();
    });
  });

  it("does not show CoverageBanner when no versionId is entered", () => {
    renderPage();
    expect(screen.queryByTestId("coverage-banner")).toBeNull();
  });
});

describe("PlanningSchedulePage — ScheduleInstrumentistCell D-102 ghost UX (OPEN mission picker)", () => {
  it("shows an ABSENT candidate as a disabled, ghost option", async () => {
    vi.mocked(planningApi.fetchRosterEligibility).mockResolvedValueOnce({
      policy: "STRICT_ASSIGNMENT",
      candidates: [
        { id: 20, name: "Claire Dubois", email: "claire@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
        {
          id: 19, name: "Sophie Collette", email: "sophie@test.com", eligible: false, selectable: false,
          reasons: ["ABSENT"], unavailability: { type: "ABSENCE", dateStart: "2026-07-01", dateEnd: "2026-07-01" }, conflict: null,
        },
      ],
    });
    const user = await loadMissions([makeMission({ status: "OPEN" })]);

    await waitFor(() => expect(screen.getByText("À réserver")).toBeInTheDocument());
    const trigger = screen.getByText("Non assigné");
    await user.click(trigger);

    const sophieOption = await screen.findByTestId("schedule-instrumentist-option-19");
    expect(sophieOption).toHaveAttribute("aria-disabled", "true");
    expect(sophieOption.textContent).toContain("Absente");
  });

  it("allows selecting a fully eligible candidate", async () => {
    vi.mocked(planningApi.fetchRosterEligibility).mockResolvedValueOnce({
      policy: "STRICT_ASSIGNMENT",
      candidates: [
        { id: 20, name: "Claire Dubois", email: "claire@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
      ],
    });
    const user = await loadMissions([makeMission({ status: "OPEN" })]);

    await waitFor(() => expect(screen.getByText("À réserver")).toBeInTheDocument());
    const trigger = screen.getByText("Non assigné");
    await user.click(trigger);

    const claireOption = await screen.findByTestId("schedule-instrumentist-option-20");
    expect(claireOption).not.toHaveAttribute("aria-disabled", "true");
  });
});

describe("PlanningSchedulePage — attribution sans liste complète des comptes (D-152, réserve 1)", () => {
  it("while the backend roster loads, offers only the current instrumentist, and never fetches /api/instrumentists", async () => {
    vi.mocked(planningApi.fetchRosterEligibility).mockReturnValueOnce(new Promise(() => {}));
    vi.mocked(apiClient.get).mockClear();
    const user = await loadMissions([makeMission({ status: "OPEN" })]);

    await waitFor(() => expect(screen.getByText("À réserver")).toBeInTheDocument());
    await user.click(screen.getByText("Non assigné"));

    const pending = await screen.findByTestId("schedule-instrumentist-roster-pending");
    expect(pending).toHaveAttribute("aria-disabled", "true");
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["Non assigné", "Chargement des instrumentistes…"]);
    expect(vi.mocked(apiClient.get).mock.calls.map((c) => c[0])).not.toContain("/api/instrumentists");
  });
});

// D-125 — "Planning publié" reflects operational mutations made anywhere (creation,
// dispatch, accepted surgeon request…) through React Query invalidation — no reload, no
// month change, no regeneration.
describe("PlanningSchedulePage — rafraîchissement après mutation (D-125)", () => {
  it("une mission créée ailleurs apparaît dès l'invalidation du planning opérationnel", async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    vi.mocked(missionsApi.fetchAllMissions).mockResolvedValue([makeAssignedMission()] as any);
    const user = userEvent.setup();
    render(
      <MemoryRouter>
        <QueryClientProvider client={client}>
          <PlanningSchedulePage />
        </QueryClientProvider>
      </MemoryRouter>,
    );
    await user.click(screen.getByRole("button", { name: /charger le planning/i }));
    await waitFor(() => expect(screen.getByText("Assigné")).toBeInTheDocument());
    expect(screen.queryByText("À réserver")).not.toBeInTheDocument();

    // e.g. a surgeon request accepted "au pool" from the requests tab
    vi.mocked(missionsApi.fetchAllMissions).mockResolvedValue([
      makeAssignedMission(),
      makeMission({ id: 2, status: "OPEN" }),
    ] as any);
    await client.invalidateQueries({ queryKey: ["planning-schedule"] });

    await waitFor(() => expect(screen.getByText("À réserver")).toBeInTheDocument());
  });
});

// D-156 lot 1 — the published planning shows the same operational reality as Missions.
describe("PlanningSchedulePage — cohérence avec Missions (D-156 lot 1)", () => {
  it("la date « Au » est incluse : l'API reçoit le lendemain comme borne exclusive", async () => {
    vi.mocked(missionsApi.fetchAllMissions).mockResolvedValue([] as any);
    const user = userEvent.setup();
    renderPage();
    fireEvent.change(screen.getByLabelText("Du"), { target: { value: "2026-11-01" } });
    fireEvent.change(screen.getByLabelText("Au"), { target: { value: "2026-11-30" } });
    await user.click(screen.getByRole("button", { name: /charger le planning/i }));

    await waitFor(() => expect(missionsApi.fetchAllMissions).toHaveBeenCalledWith(
      expect.objectContaining({ from: "2026-11-01", to: "2026-12-01" }),
    ));
  });

  it("annonce les brouillons de la période au lieu de les laisser invisibles", async () => {
    await loadMissions([makeMission({ id: 1, status: "OPEN" }), makeMission({ id: 2, status: "DRAFT" })]);

    const notice = await screen.findByTestId("schedule-draft-notice");
    expect(notice.textContent).toContain("1 mission en brouillon");
    expect(within(notice).getByRole("link", { name: /voir les brouillons/i })).toHaveAttribute("href", "/app/m/missions?status=DRAFT&period=all");
  });

  it("affecter une mission OPEN passe par l'attribution directe, jamais par l'endpoint réservé aux brouillons", async () => {
    vi.mocked(planningApi.fetchRosterEligibility).mockResolvedValueOnce({
      policy: "STRICT_ASSIGNMENT",
      candidates: [
        { id: 20, name: "Claire Dubois", email: "claire@test.com", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
      ],
    });
    vi.mocked(apiClient.post).mockClear();
    const user = await loadMissions([makeMission({ status: "OPEN" })]);

    await waitFor(() => expect(screen.getByText("À réserver")).toBeInTheDocument());
    await user.click(screen.getByText("Non assigné"));
    await user.click(await screen.findByTestId("schedule-instrumentist-option-20"));

    await waitFor(() => expect(apiClient.post).toHaveBeenCalledWith("/api/missions/1/assign-directly", { instrumentistId: 20 }));
    expect(vi.mocked(apiClient.post).mock.calls.map(([url]) => url)).not.toContain("/api/missions/1/assign-instrumentist");
  });
});
