import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AbsenceCommunicationJournalDrawer } from "./AbsenceCommunicationJournalDrawer";
import type { AbsenceCommunicationDetailV2 } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", async () => {
  const actual = await vi.importActual<typeof import("../api/planningV2.api")>("../api/planningV2.api");
  return { ...actual, getAbsenceCommunicationDetail: vi.fn() };
});

import * as api from "../api/planningV2.api";

function makeDetail(overrides: Partial<AbsenceCommunicationDetailV2> = {}): AbsenceCommunicationDetailV2 {
  return {
    id: 5,
    type: "BLOCK_MANAGEMENT_ABSENCE",
    revisionNumber: 0,
    surgeon: { id: 7, name: "Etienne Willemart" },
    site: { id: 3, name: "CHIREC - Hôpital Delta" },
    absenceId: 42,
    absenceDateStart: "2026-09-10",
    absenceDateEnd: "2026-09-15",
    createdAt: "2026-09-03T10:00:00+00:00",
    globalStatus: "SENT",
    deliveryCount: 1,
    sentCount: 1,
    failedCount: 0,
    cancelledCount: 0,
    scheduledCount: 0,
    subject: "Congé — Dr Etienne Willemart",
    body: "Bonjour,\nJe vous informe...",
    occurrences: [],
    replyTo: "etienne@surgicalhub.test",
    deliveries: [{
      id: 9, to: "bloc@example.com", cc: ["secretariat@example.com"], status: "SENT",
      attemptCount: 1, scheduledAt: null, sentAt: "2026-09-03T10:05:00+00:00", cancelledAt: null, lastError: null,
    }],
    ...overrides,
  };
}

function renderDrawer(id: number | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AbsenceCommunicationJournalDrawer id={id} onClose={vi.fn()} />
    </QueryClientProvider>,
  );
}

describe("AbsenceCommunicationJournalDrawer (Lot C, D-114)", () => {
  it("n'appelle pas l'API et n'affiche rien tant qu'aucun id n'est fourni", () => {
    renderDrawer(null);
    expect(api.getAbsenceCommunicationDetail).not.toHaveBeenCalled();
  });

  it("affiche le sujet, le corps exact, le Reply-To et les deliveries", async () => {
    vi.mocked(api.getAbsenceCommunicationDetail).mockResolvedValue(makeDetail());

    renderDrawer(5);

    expect(await screen.findByText("Congé — Dr Etienne Willemart")).toBeInTheDocument();
    expect(screen.getByText(/Je vous informe/)).toBeInTheDocument();
    expect(screen.getByText("etienne@surgicalhub.test")).toBeInTheDocument();
    expect(screen.getByText("bloc@example.com")).toBeInTheDocument();
    expect(screen.getByText(/secretariat@example.com/)).toBeInTheDocument();
  });

  it("ROOM_RELEASE n'affiche jamais de Reply-To", async () => {
    vi.mocked(api.getAbsenceCommunicationDetail).mockResolvedValue(makeDetail({ type: "ROOM_RELEASE", replyTo: null }));

    renderDrawer(5);
    await screen.findByText("Congé — Dr Etienne Willemart");

    expect(screen.queryByText(/Reply-To/)).not.toBeInTheDocument();
  });

  // ── §20 : absence supprimée, détail reste entièrement exploitable ─────────
  it("reste entièrement exploitable après suppression de l'absence source", async () => {
    vi.mocked(api.getAbsenceCommunicationDetail).mockResolvedValue(makeDetail({ absenceId: null }));

    renderDrawer(5);

    expect(await screen.findByText("Etienne Willemart")).toBeInTheDocument();
    expect(screen.getByText("CHIREC - Hôpital Delta")).toBeInTheDocument();
    expect(screen.getByText(/supprimée depuis/)).toBeInTheDocument();
    expect(screen.getByText("Congé — Dr Etienne Willemart")).toBeInTheDocument();
  });

  it("affiche le motif d'échec d'une delivery FAILED", async () => {
    vi.mocked(api.getAbsenceCommunicationDetail).mockResolvedValue(makeDetail({
      deliveries: [{
        id: 9, to: "bloc@example.com", cc: [], status: "FAILED",
        attemptCount: 3, scheduledAt: null, sentAt: null, cancelledAt: null, lastError: "Adresse invalide",
      }],
    }));

    renderDrawer(5);

    expect(await screen.findByText("Adresse invalide")).toBeInTheDocument();
    expect(screen.getByText("Échec")).toBeInTheDocument();
  });
});
