import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { AvailableRoomsTab } from "./AvailableRoomsTab";
import type { ReleasedRoomSlotV2 } from "../api/planningV2.types";

const getRoomsMock = vi.fn();
vi.mock("../api/planningV2.api", () => ({
  getAvailableRoomsForManager: (...args: unknown[]) => getRoomsMock(...args),
  getAbsenceCommunicationSettings: () => Promise.resolve({ items: [] }),
}));
vi.mock("../../manager-surgeons/api/surgeons.api", () => ({
  getSurgeons: () => Promise.resolve({ items: [] }),
}));

const historyMock = vi.fn();
const liftMock = vi.fn();
vi.mock("../../room-takeover/roomTakeover.api", () => ({
  getReleasedRoomHistory: (...args: unknown[]) => historyMock(...args),
  liftMissionPriority: (...args: unknown[]) => liftMock(...args),
}));

function slot(overrides: Partial<ReleasedRoomSlotV2> = {}): ReleasedRoomSlotV2 {
  return {
    id: 14,
    site: { id: 1, name: "CHIREC - Hôpital Delta" },
    occurrenceDate: "2026-11-04",
    period: "JOURNEE",
    startTime: "07:45",
    endTime: "18:00",
    surgeon: { id: 8, name: "Dr Alain Martin" },
    status: "CLAIMED",
    createdAt: "2026-09-07T10:03:21+00:00",
    claimedBy: { id: 15, name: "Dr Bruno Lambert" },
    claimedAt: "2026-10-30T09:00:00+00:00",
    claimedByMe: false,
    takeoverMission: { id: 1300, status: "OPEN", instrumentist: null },
    allowedActions: { takeOver: false, release: true },
    operational: {
      state: "PRIORITY_PENDING",
      priority: {
        publicationId: 99,
        instrumentist: { id: 18, name: "Marie Dupont" },
        offeredAt: "2026-10-30T09:00:00+00:00",
        expiresAt: "2026-11-01T09:00:00+00:00",
        startsBeforeExpiry: false,
      },
      nextAutomaticAction: { type: "PRIORITY_EXPIRY", at: "2026-11-01T09:00:00+00:00" },
    },
    ...overrides,
  };
}

function renderTab() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AvailableRoomsTab />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  getRoomsMock.mockReset();
  historyMock.mockReset();
  liftMock.mockReset();
});

describe("AvailableRoomsTab — D-177", () => {
  it("affiche la proposition prioritaire avec destinataire et échéance", async () => {
    getRoomsMock.mockResolvedValue({ items: [slot()], page: 1, limit: 25, total: 1 });
    renderTab();

    expect(await screen.findByTestId("room-operational-state")).toHaveTextContent("Proposition prioritaire en attente");
    expect(screen.getByText(/Marie Dupont · jusqu'au/)).toBeInTheDocument();
    expect(screen.queryByTestId("room-priority-imminent")).not.toBeInTheDocument();
  });

  it("alerte quand l'intervention commence avant l'échéance, et lève la priorité avec justification", async () => {
    const imminent = slot();
    imminent.operational!.priority!.startsBeforeExpiry = true;
    getRoomsMock.mockResolvedValue({ items: [imminent], page: 1, limit: 25, total: 1 });
    liftMock.mockResolvedValue(undefined);
    const user = userEvent.setup();
    renderTab();

    expect(await screen.findByTestId("room-priority-imminent")).toBeInTheDocument();
    await user.click(screen.getByTestId("lift-priority-14"));
    const dialog = await screen.findByRole("dialog");
    const confirm = within(dialog).getByRole("button", { name: "Lever et ouvrir le pool" });
    expect(confirm).toBeDisabled();
    await user.type(within(dialog).getByTestId("lift-priority-justification"), "Intervention demain");
    await user.click(confirm);

    await waitFor(() => expect(liftMock).toHaveBeenCalledWith(1300, "Intervention demain"));
  });

  it("ouvre l'historique chronologique du créneau avec la prochaine action automatique", async () => {
    getRoomsMock.mockResolvedValue({ items: [slot()], page: 1, limit: 25, total: 1 });
    historyMock.mockResolvedValue({
      slotId: 14,
      current: slot().operational,
      events: [
        { key: "absence-64", at: "2026-09-06T15:20:24+00:00", type: "ABSENCE_DECLARED", actor: { id: 8, name: "Alain Martin", system: false }, missionId: null, details: { surgeonName: "Dr Alain Martin", dateStart: "2026-10-31", dateEnd: "2026-11-08", reason: "Congé" } },
        { key: "audit-2", at: "2026-10-30T09:00:00+00:00", type: "ROOM_TAKEOVER_PRIORITY_OFFERED", actor: { id: 7, name: "Manon Leroy", system: false }, missionId: 1300, details: { instrumentistName: "Marie Dupont", expiresAt: "2026-11-01T09:00:00+00:00" } },
      ],
    });
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByTestId("room-history-14"));
    const events = await screen.findByTestId("room-history-events");
    expect(within(events).getByText("Absence de Dr Alain Martin")).toBeInTheDocument();
    expect(within(events).getByText("Proposition prioritaire à Marie Dupont")).toBeInTheDocument();
    expect(screen.getByText(/Prochaine action automatique/)).toBeInTheDocument();
    expect(historyMock).toHaveBeenCalledWith(14);
  });
});
