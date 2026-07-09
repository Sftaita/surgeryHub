import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import PlanningSchedulePage from "./PlanningSchedulePage";

// ── Module mocks ──────────────────────────────────────────────────────────────

vi.mock("../../../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn(),
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
  cancelMission:   vi.fn().mockResolvedValue(undefined),
  reassignMission: vi.fn().mockResolvedValue(undefined),
  fetchMissionAudit: vi.fn().mockResolvedValue([]),
  fetchMissionEligibleInstrumentists: vi.fn().mockResolvedValue({
    missionId: 1,
    missionStatus: "ASSIGNED",
    eligible: [{ id: 20, name: "Claire Dubois", email: "claire@test.com" }],
    ineligible: [{ id: 21, name: "Marc Leroy", email: "marc@test.com", reasons: ["ABSENT"] }],
  }),
}));

import * as missionsApi from "../../../features/missions/api/missions.api";
import * as planningApi from "../../../features/planning-v2/api/planningV2.api";

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
    <QueryClientProvider client={client}>
      <PlanningSchedulePage />
    </QueryClientProvider>,
  );
}

async function loadMissions(missions: unknown[]) {
  vi.mocked(missionsApi.fetchMissions).mockResolvedValue({
    items: missions as any,
    total: missions.length,
  });
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

describe("PlanningSchedulePage — Cancel action", () => {
  it("shows Cancel button only on OPEN missions", async () => {
    await loadMissions([makeMission({ status: "OPEN" })]);
    await waitFor(() => {
      expect(screen.getByRole("button", { name: /annuler la mission/i })).toBeInTheDocument();
    });
  });

  it("does not show Cancel button on ASSIGNED missions", async () => {
    await loadMissions([makeAssignedMission()]);
    await waitFor(() => expect(screen.queryByRole("button", { name: /annuler la mission/i })).toBeNull());
  });

  it("does not show Cancel button on CANCELLED missions", async () => {
    await loadMissions([makeMission({ status: "CANCELLED" })]);
    await waitFor(() => expect(screen.queryByRole("button", { name: /annuler la mission/i })).toBeNull());
  });

  it("opens cancel dialog and calls API on confirm", async () => {
    const user = await loadMissions([makeMission({ status: "OPEN" })]);

    await waitFor(() =>
      expect(screen.getByRole("button", { name: /annuler la mission/i })).toBeInTheDocument(),
    );

    await user.click(screen.getByRole("button", { name: /annuler la mission/i }));
    expect(await screen.findByText(/annuler la mission \?/i)).toBeInTheDocument();

    const dialog = screen.getByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /confirmer l'annulation/i }));

    await waitFor(() => {
      expect(planningApi.cancelMission).toHaveBeenCalledWith(1, undefined);
    });
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

describe("PlanningSchedulePage — Reassign action", () => {
  it("shows Reassign button on ASSIGNED missions", async () => {
    await loadMissions([makeAssignedMission()]);
    await waitFor(() => {
      expect(screen.getByRole("button", { name: /réassigner la mission/i })).toBeInTheDocument();
    });
  });

  it("does not show Reassign button on OPEN missions", async () => {
    await loadMissions([makeMission({ status: "OPEN" })]);
    await waitFor(() =>
      expect(screen.queryByRole("button", { name: /réassigner la mission/i })).toBeNull(),
    );
  });

  it("opens reassign dialog with eligible candidates on click", async () => {
    const user = await loadMissions([makeAssignedMission()]);

    await waitFor(() =>
      expect(screen.getByRole("button", { name: /réassigner la mission/i })).toBeInTheDocument(),
    );

    await user.click(screen.getByRole("button", { name: /réassigner la mission/i }));

    // Dialog should open
    await waitFor(() =>
      expect(screen.getByRole("dialog")).toBeInTheDocument(),
    );
    expect(await screen.findByText("Réassigner la mission")).toBeInTheDocument();
  });

  it("shows eligible and ineligible candidates in reassign dialog", async () => {
    const user = await loadMissions([makeAssignedMission()]);

    await waitFor(() =>
      expect(screen.getByRole("button", { name: /réassigner la mission/i })).toBeInTheDocument(),
    );
    await user.click(screen.getByRole("button", { name: /réassigner la mission/i }));

    // Wait for eligibility data to load in dialog
    await waitFor(() => expect(screen.getByTestId("ineligible-list")).toBeInTheDocument());
    expect(screen.getByText("Marc Leroy")).toBeInTheDocument();
  });

  it("calls reassignMission API on reassign confirm", async () => {
    const user = await loadMissions([makeAssignedMission()]);

    await waitFor(() =>
      expect(screen.getByRole("button", { name: /réassigner la mission/i })).toBeInTheDocument(),
    );
    await user.click(screen.getByRole("button", { name: /réassigner la mission/i }));

    await waitFor(() => screen.getByTestId("reassign-eligible-select"));

    // Select eligible candidate
    const selectEl = screen.getByTestId("reassign-eligible-select").querySelector("[role='combobox']");
    if (selectEl) {
      fireEvent.mouseDown(selectEl);
      const option = await screen.findByRole("option", { name: "Claire Dubois" });
      fireEvent.click(option);
    }

    // Confirm
    const dialog = screen.getByRole("dialog");
    const confirmBtn = within(dialog).getByRole("button", { name: /Réassigner/i });
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(planningApi.reassignMission).toHaveBeenCalledWith(1, 20);
    });
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
