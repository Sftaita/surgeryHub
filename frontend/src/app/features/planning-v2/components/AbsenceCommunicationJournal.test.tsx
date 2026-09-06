import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AbsenceCommunicationJournal } from "./AbsenceCommunicationJournal";
import type { AbsenceCommunicationListItemV2, AbsenceCommunicationListResponseV2 } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", async () => {
  const actual = await vi.importActual<typeof import("../api/planningV2.api")>("../api/planningV2.api");
  return {
    ...actual,
    getAbsenceCommunicationJournal: vi.fn(),
    getAbsenceCommunicationSettings: vi.fn(),
    getAbsenceCommunicationDetail: vi.fn(),
    previewAbsenceCommunicationBackfill: vi.fn(),
    executeAbsenceCommunicationBackfill: vi.fn(),
  };
});

vi.mock("../../manager-surgeons/api/surgeons.api", () => ({
  getSurgeons: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

import * as api from "../api/planningV2.api";

function makeItem(overrides: Partial<AbsenceCommunicationListItemV2> = {}): AbsenceCommunicationListItemV2 {
  return {
    id: 1,
    type: "ROOM_RELEASE",
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
    ...overrides,
  };
}

function makeListResponse(items: AbsenceCommunicationListItemV2[], total?: number): AbsenceCommunicationListResponseV2 {
  return { items, page: 1, limit: 25, total: total ?? items.length };
}

function renderJournal() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AbsenceCommunicationJournal />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(api.getAbsenceCommunicationSettings).mockResolvedValue({
    items: [{ site: { id: 3, name: "Delta" }, notifyColleaguesEnabled: true, notifyBlockManagementEnabled: true, blockManagementContactEmail: null, blockManagementContactCc: [], blockManagementDelayDays: null }],
  });
});

describe("AbsenceCommunicationJournal — liste (Lot C, D-114)", () => {
  it("affiche les lignes retournées avec libellés métier plutôt que les enums bruts", async () => {
    vi.mocked(api.getAbsenceCommunicationJournal).mockResolvedValue(makeListResponse([makeItem()]));

    renderJournal();

    expect(await screen.findByText("Etienne Willemart")).toBeInTheDocument();
    expect(screen.getByText("Libération de salle")).toBeInTheDocument();
    expect(screen.getByText("Envoyé")).toBeInTheDocument();
    expect(screen.queryByText("ROOM_RELEASE")).not.toBeInTheDocument();
    expect(screen.queryByText("SENT")).not.toBeInTheDocument();
  });

  it("affiche un état vide explicite quand aucune communication ne correspond", async () => {
    vi.mocked(api.getAbsenceCommunicationJournal).mockResolvedValue(makeListResponse([]));

    renderJournal();

    expect(await screen.findByText("Aucune communication ne correspond à ces filtres.")).toBeInTheDocument();
  });

  it("le filtre type renvoie la valeur choisie à l'API", async () => {
    vi.mocked(api.getAbsenceCommunicationJournal).mockResolvedValue(makeListResponse([makeItem()]));

    renderJournal();
    await screen.findByText("Etienne Willemart");

    const user = userEvent.setup();
    const typeSelect = screen.getAllByRole("combobox")[2]; // site, chirurgien, type, statut
    await user.click(typeSelect);
    await user.click(await screen.findByRole("option", { name: "Congé — gestion du bloc" }));

    await waitFor(() => {
      const lastCall = vi.mocked(api.getAbsenceCommunicationJournal).mock.calls.at(-1)![0];
      expect(lastCall).toMatchObject({ type: "BLOCK_MANAGEMENT_ABSENCE" });
    });
  });

  it("changer un filtre réinitialise la pagination à la première page", async () => {
    vi.mocked(api.getAbsenceCommunicationJournal).mockResolvedValue(makeListResponse([makeItem()], 100));

    renderJournal();
    await screen.findByText("Etienne Willemart");

    const user = userEvent.setup();
    await user.click(screen.getByRole("button", { name: /next page|page suivante/i }));
    await waitFor(() => {
      expect(vi.mocked(api.getAbsenceCommunicationJournal).mock.calls.at(-1)![0]).toMatchObject({ page: 2 });
    });

    const typeSelect = screen.getAllByRole("combobox")[2];
    await user.click(typeSelect);
    await user.click(await screen.findByRole("option", { name: "Libération de salle" }));

    await waitFor(() => {
      expect(vi.mocked(api.getAbsenceCommunicationJournal).mock.calls.at(-1)![0]).toMatchObject({ page: 1 });
    });
  });

  it("cliquer sur une ligne ouvre le drawer de détail", async () => {
    vi.mocked(api.getAbsenceCommunicationJournal).mockResolvedValue(makeListResponse([makeItem()]));
    vi.mocked(api.getAbsenceCommunicationDetail).mockResolvedValue({
      ...makeItem(),
      subject: "Libération de salle — Delta",
      body: "Corps",
      occurrences: [],
      replyTo: null,
      deliveries: [],
    });

    renderJournal();
    const row = await screen.findByText("Etienne Willemart");
    await userEvent.click(row);

    expect(await screen.findByText("Libération de salle — Delta")).toBeInTheDocument();
  });

  it("le bouton « Traiter les absences existantes » ouvre le dialogue de rattrapage", async () => {
    vi.mocked(api.getAbsenceCommunicationJournal).mockResolvedValue(makeListResponse([]));

    renderJournal();
    await screen.findByText("Aucune communication ne correspond à ces filtres.");

    await userEvent.click(screen.getByRole("button", { name: "Traiter les absences existantes" }));

    expect(await screen.findByRole("heading", { name: "Traiter les absences existantes" })).toBeInTheDocument();
  });
});
