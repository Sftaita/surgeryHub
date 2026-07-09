import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MissionHistoryDrawer } from "./MissionHistoryDrawer";
import type { MissionAuditEvent } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", () => ({
  fetchMissionAudit: vi.fn(),
}));

import * as api from "../api/planningV2.api";

function makeEvent(overrides: Partial<MissionAuditEvent> = {}): MissionAuditEvent {
  return {
    eventType: "MISSION_CLAIMED_FROM_POOL",
    occurredAt: "2026-06-05T10:00:00+00:00",
    actorId: 1,
    actorName: "Alice Martin",
    payload: null,
    ...overrides,
  };
}

function renderDrawer(props: { missionId: number | null; open: boolean }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const onClose = vi.fn();
  render(
    <QueryClientProvider client={client}>
      <MissionHistoryDrawer missionId={props.missionId} open={props.open} onClose={onClose} />
    </QueryClientProvider>,
  );
  return { onClose };
}

describe("MissionHistoryDrawer", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders audit events when open", async () => {
    vi.mocked(api.fetchMissionAudit).mockResolvedValue([
      makeEvent({ eventType: "MISSION_CLAIMED_FROM_POOL", actorName: "Alice Martin" }),
      makeEvent({ eventType: "MISSION_RELEASED_TO_POOL", actorName: "Bob Dupont" }),
    ]);

    renderDrawer({ missionId: 42, open: true });

    await waitFor(() => {
      expect(screen.getByText("Prise en charge")).toBeInTheDocument();
      expect(screen.getByText("Remise au pool")).toBeInTheDocument();
    });
  });

  it("shows actor names for each event", async () => {
    vi.mocked(api.fetchMissionAudit).mockResolvedValue([
      makeEvent({ actorName: "Alice Martin" }),
    ]);

    renderDrawer({ missionId: 42, open: true });

    await waitFor(() => {
      expect(screen.getByText(/Alice Martin/)).toBeInTheDocument();
    });
  });

  it("shows empty state when no events", async () => {
    vi.mocked(api.fetchMissionAudit).mockResolvedValue([]);

    renderDrawer({ missionId: 42, open: true });

    await waitFor(() => {
      expect(screen.getByText("Aucune modification enregistrée")).toBeInTheDocument();
    });
  });

  it("renders events in the order returned by the API (DESC from backend)", async () => {
    vi.mocked(api.fetchMissionAudit).mockResolvedValue([
      makeEvent({ eventType: "MISSION_RELEASED_TO_POOL",  actorName: "Bob", occurredAt: "2026-06-06T09:00:00+00:00" }),
      makeEvent({ eventType: "MISSION_CLAIMED_FROM_POOL", actorName: "Alice", occurredAt: "2026-06-05T10:00:00+00:00" }),
    ]);

    renderDrawer({ missionId: 42, open: true });

    await waitFor(() => {
      const labels = screen.getAllByText(/Prise en charge|Remise au pool/);
      expect(labels[0].textContent).toBe("Remise au pool");
      expect(labels[1].textContent).toBe("Prise en charge");
    });
  });

  it("does not fetch when drawer is closed", () => {
    vi.mocked(api.fetchMissionAudit).mockResolvedValue([]);

    renderDrawer({ missionId: 42, open: false });

    expect(api.fetchMissionAudit).not.toHaveBeenCalled();
  });
});
