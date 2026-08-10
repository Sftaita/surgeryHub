import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ReassignDialog } from "./ReassignDialog";
import type { AlertEligibilityResponse, CandidateEligibility, PlanningAlertV2 } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", () => ({
  getEligibleInstrumentists: vi.fn(),
  extractErrorV2: (err: unknown) => (err as Error)?.message ?? String(err),
}));

import * as api from "../api/planningV2.api";

function makeCandidate(overrides: Partial<CandidateEligibility> = {}): CandidateEligibility {
  return {
    id: 1, name: "Claire Dubois", email: "claire@test.com",
    eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null,
    ...overrides,
  };
}

function makeAlert(): PlanningAlertV2 {
  return {
    id: 42,
    type: "INSTRUMENTIST_ABSENCE",
    status: "OPEN",
    detectedAt: "2026-08-01T00:00:00Z",
    resolvedAt: null,
    resolvedBy: null,
    resolutionNote: null,
    mission: {
      id: 398, status: "ASSIGNED", startAt: "2026-08-14T08:00:00", endAt: "2026-08-14T18:00:00",
      site: { id: 1, name: "Delta" }, surgeon: null, instrumentist: null,
    },
    absence: null,
    conflict: null,
    actions: { canAcknowledge: true, canResolve: true, canIgnore: true, canReassign: true, canOpenAsAvailable: true, recommendedAction: "REASSIGN" },
  };
}

function renderDialog(response: AlertEligibilityResponse, onConfirm = vi.fn()) {
  vi.mocked(api.getEligibleInstrumentists).mockResolvedValue(response);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <ReassignDialog open alert={makeAlert()} onClose={vi.fn()} onConfirm={onConfirm} submitting={false} />
    </QueryClientProvider>,
  );
  return { onConfirm };
}

describe("ReassignDialog — D-102 ghost UX (alert-driven reassign)", () => {
  beforeEach(() => vi.clearAllMocks());

  it("ABSENT — shown, aria-disabled, ghost label, cannot be selected", async () => {
    renderDialog({
      missionId: 398, policy: "STRICT_ASSIGNMENT",
      candidates: [
        makeCandidate({
          id: 19, name: "Sophie Collette", eligible: false, selectable: false, reasons: ["ABSENT"],
          unavailability: { type: "ABSENCE", dateStart: "2026-08-01", dateEnd: "2026-08-16" },
        }),
      ],
    });

    const row = await screen.findByTestId("reassign-candidate-19");
    expect(row).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText(/Absente/)).toBeInTheDocument();

    fireEvent.click(row);
    // Never becomes selectable — the confirm button stays disabled.
    expect(screen.getByRole("button", { name: /Confirmer la réassignation/i })).toBeDisabled();
  });

  it("SCHEDULE_CONFLICT under STRICT_ASSIGNMENT (this endpoint always uses it) — blocking ghost", async () => {
    renderDialog({
      missionId: 398, policy: "STRICT_ASSIGNMENT",
      candidates: [
        makeCandidate({
          id: 10, name: "Marc Petit", eligible: false, selectable: false, reasons: ["SCHEDULE_CONFLICT"],
          conflict: { missionId: 55, siteName: "Alpha", startAt: "2026-08-14T08:00:00", endAt: "2026-08-14T18:00:00" },
        }),
      ],
    });

    const row = await screen.findByTestId("reassign-candidate-10");
    expect(row).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText(/Conflit d'horaire/)).toBeInTheDocument();
  });

  it("INACTIVE — blocking ghost with reason label", async () => {
    renderDialog({
      missionId: 398, policy: "STRICT_ASSIGNMENT",
      candidates: [makeCandidate({ id: 30, name: "Ancien Compte", eligible: false, selectable: false, reasons: ["INACTIVE"] })],
    });

    const row = await screen.findByTestId("reassign-candidate-30");
    expect(row).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText("Compte inactif")).toBeInTheDocument();
  });

  it("valid candidate — selectable, confirms with id/note", async () => {
    const onConfirm = vi.fn();
    renderDialog({
      missionId: 398, policy: "STRICT_ASSIGNMENT",
      candidates: [makeCandidate({ id: 20, name: "Claire Dubois" })],
    }, onConfirm);

    const row = await screen.findByTestId("reassign-candidate-20");
    expect(row).not.toHaveAttribute("aria-disabled", "true");
    fireEvent.click(row);

    const confirmBtn = screen.getByRole("button", { name: /Confirmer la réassignation/i });
    await waitFor(() => expect(confirmBtn).not.toBeDisabled());
    fireEvent.click(confirmBtn);

    expect(onConfirm).toHaveBeenCalledWith(20, "");
  });

  it("shows an empty state when no candidate is returned", async () => {
    renderDialog({ missionId: 398, policy: "STRICT_ASSIGNMENT", candidates: [] });

    expect(await screen.findByText(/Aucune instrumentiste éligible trouvée/)).toBeInTheDocument();
  });
});
