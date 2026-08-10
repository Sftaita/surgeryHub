import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ReassignMissionDialog } from "./ReassignMissionDialog";
import type { CandidateEligibility, MissionEligibilityResponse } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", () => ({
  fetchMissionEligibleInstrumentists: vi.fn(),
}));

import * as api from "../api/planningV2.api";

function makeCandidate(overrides: Partial<CandidateEligibility> = {}): CandidateEligibility {
  return {
    id: 5,
    name: "Alice Martin",
    email: "alice@test.com",
    eligible: true,
    selectable: true,
    reasons: [],
    unavailability: null,
    conflict: null,
    ...overrides,
  };
}

function makeEligibilityResponse(
  overrides: Partial<MissionEligibilityResponse> = {},
): MissionEligibilityResponse {
  return {
    missionId: 42,
    missionStatus: "ASSIGNED",
    policy: "STRICT_ASSIGNMENT",
    candidates: [makeCandidate()],
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

  it("shows selectable candidates from API", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({ candidates: [makeCandidate({ id: 5, name: "Alice Martin" })] }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(screen.getByTestId("reassign-candidate-list")).toBeInTheDocument();
      expect(screen.getByText("Alice Martin")).toBeInTheDocument();
    });
  });

  it("shows non-selectable candidates as ghost rows with reason chips", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        candidates: [
          makeCandidate({
            id: 7, name: "Bob Dupont", eligible: false, selectable: false,
            reasons: ["ABSENT", "SCHEDULE_CONFLICT"],
          }),
        ],
      }),
    );

    renderDialog({});

    await waitFor(() => {
      const row = screen.getByTestId("reassign-candidate-7");
      expect(row).toHaveAttribute("aria-disabled", "true");
      expect(screen.getByText("Bob Dupont")).toBeInTheDocument();
      expect(screen.getByTestId("reason-chip-ABSENT")).toBeInTheDocument();
      expect(screen.getByTestId("reason-chip-SCHEDULE_CONFLICT")).toBeInTheDocument();
    });
  });

  it("shows French labels for rejection reasons", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        candidates: [
          makeCandidate({
            id: 7, name: "Bob Dupont", eligible: false, selectable: false,
            reasons: ["ABSENT", "NO_SITE_MEMBERSHIP"],
          }),
        ],
      }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(screen.getByText("Absente")).toBeInTheDocument();
      expect(screen.getByText("Non affiliée à ce site")).toBeInTheDocument();
    });
  });

  it("shows empty state when there are no candidates", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({ candidates: [] }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(
        screen.getByText("Aucun instrumentiste disponible pour cette mission."),
      ).toBeInTheDocument();
    });
  });

  it("shows warning when no candidate is selectable", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        candidates: [makeCandidate({ id: 7, name: "Bob", eligible: false, selectable: false, reasons: ["ABSENT"] })],
      }),
    );

    renderDialog({});

    await waitFor(() => {
      expect(screen.getByText(/Aucun instrumentiste sélectionnable/i)).toBeInTheDocument();
    });
  });

  it("does not allow clicking a non-selectable candidate", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        candidates: [
          makeCandidate({ id: 5, name: "Alice", selectable: true }),
          makeCandidate({ id: 7, name: "Bob", eligible: false, selectable: false, reasons: ["ABSENT"] }),
        ],
      }),
    );

    renderDialog({});

    await waitFor(() => screen.getByTestId("reassign-candidate-7"));
    fireEvent.click(screen.getByTestId("reassign-candidate-7"));

    const confirmBtn = screen.getByRole("button", { name: /Réassigner/i });
    expect(confirmBtn).toBeDisabled();
  });

  it("calls onConfirm with id and name after selecting a selectable candidate", async () => {
    vi.mocked(api.fetchMissionEligibleInstrumentists).mockResolvedValue(
      makeEligibilityResponse({
        candidates: [makeCandidate({ id: 5, name: "Alice Martin", selectable: true })],
      }),
    );

    const onConfirm = vi.fn();
    renderDialog({ onConfirm });

    await waitFor(() => screen.getByTestId("reassign-candidate-5"));
    fireEvent.click(screen.getByTestId("reassign-candidate-5"));

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
