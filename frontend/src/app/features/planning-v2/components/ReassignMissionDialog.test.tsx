import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ReassignMissionDialog } from "./ReassignMissionDialog";
import type { MissionEligibilityResponse } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", () => ({
  fetchMissionEligibleInstrumentists: vi.fn(),
}));

import * as api from "../api/planningV2.api";

function makeEligibilityResponse(
  overrides: Partial<MissionEligibilityResponse> = {},
): MissionEligibilityResponse {
  return {
    missionId: 42,
    missionStatus: "ASSIGNED",
    eligible: [{ id: 5, name: "Alice Martin", email: "alice@test.com" }],
    ineligible: [],
    ...overrides,
  };
}

function renderDialog(props: {
  open?: boolean;
  missionId?: number | null;
  onClose?: () => void;
  onConfirm?: (id: number, name: string) => void;
}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const onClose = props.onClose ?? vi.fn();
  const onConfirm = props.onConfirm ?? vi.fn();
  render(
    <QueryClientProvider client={client}>
      <ReassignMissionDialog
        open={props.open ?? true}
        missionId={props.missionId ?? 42}
        onClose={onClose}
        onConfirm={onConfirm}
      />
    </QueryClientProvider>,
  );
  return { onClose, onConfirm };
}

describe("ReassignMissionDialog", () => {
  beforeEach(() => vi.clearAllMocks());

  it("shows eligible instrumentists from API", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({ eligible: [{ id: 5, name: "Alice Martin", email: "alice@test.com" }] }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(screen.getByTestId("reassign-eligible-select")).toBeInTheDocument();
    });
  });

  it("shows ineligible candidates with reason chips", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        eligible: [],
        ineligible: [
          { id: 7, name: "Bob Dupont", email: "bob@test.com", reasons: ["ABSENT", "SCHEDULE_CONFLICT"] },
        ],
      }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(screen.getByTestId("ineligible-list")).toBeInTheDocument();
      expect(screen.getByText("Bob Dupont")).toBeInTheDocument();
      expect(screen.getByTestId("reason-chip-ABSENT")).toBeInTheDocument();
      expect(screen.getByTestId("reason-chip-SCHEDULE_CONFLICT")).toBeInTheDocument();
    });
  });

  it("shows French labels for rejection reasons", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        eligible: [],
        ineligible: [
          { id: 7, name: "Bob Dupont", email: "bob@test.com", reasons: ["ABSENT", "NO_SITE_MEMBERSHIP"] },
        ],
      }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(screen.getByText("Absent ce jour")).toBeInTheDocument();
      expect(screen.getByText("Non affilié au site")).toBeInTheDocument();
    });
  });

  it("shows empty state when no eligible and no ineligible candidates", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({ eligible: [], ineligible: [] }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(
        screen.getByText("Aucun instrumentiste disponible pour cette mission."),
      ).toBeInTheDocument();
    });
  });

  it("shows warning when no eligible but there are ineligible candidates", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        eligible: [],
        ineligible: [{ id: 7, name: "Bob", email: "bob@test.com", reasons: ["ABSENT"] }],
      }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(screen.getByText(/Aucun instrumentiste éligible/i)).toBeInTheDocument();
    });
  });

  it("calls onConfirm with id and name after selecting eligible candidate", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        eligible: [{ id: 5, name: "Alice Martin", email: "alice@test.com" }],
      }),
    );

    const onConfirm = vi.fn();
    renderDialog({ onConfirm });

    // Wait for dialog content to load
    await waitFor(() => screen.getByTestId("reassign-eligible-select"));

    // Select the eligible candidate via MUI Select (use fireEvent.mouseDown)
    const selectEl = screen.getByTestId("reassign-eligible-select").querySelector("[role='combobox']");
    if (selectEl) {
      fireEvent.mouseDown(selectEl);
      const option = await screen.findByRole("option", { name: "Alice Martin" });
      fireEvent.click(option);
    }

    // Click confirm
    const confirmBtn = screen.getByRole("button", { name: /Réassigner/i });
    fireEvent.click(confirmBtn);

    await waitFor(() => {
      expect(onConfirm).toHaveBeenCalledWith(5, "Alice Martin");
    });
  });

  it("does not fetch eligibility when dialog is closed", () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse(),
    );

    renderDialog({ open: false });

    expect(api.fetchMissionEligibleInstrumentists).not.toHaveBeenCalled();
  });

  it("shows error alert when API fails", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockRejectedValue(
      new Error("network"),
    );

    renderDialog({});

    await waitFor(() => {
      expect(
        screen.getByText("Impossible de charger les instrumentistes éligibles."),
      ).toBeInTheDocument();
    });
  });
});
