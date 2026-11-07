import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ManagerPrestationCard } from "./ManagerPrestationCard";
import type { PrestationView } from "../api/prestations.api";

const apiGetMock = vi.fn();
const apiPutMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    put: (...args: unknown[]) => apiPutMock(...args),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}));

const toastSuccess = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: vi.fn(), warning: vi.fn() }),
}));

function view(allowedActions: string[]): PrestationView {
  const mission = (id: number, ordinal: number, start: string, end: string, imputed: number) => ({
    id, ordinal, label: `Mission ${ordinal}/2 · ${start}–${end} · n° ${id}`, roomLabel: null,
    startAt: `2026-09-30T${start}:00+02:00`, endAt: `2026-09-30T${end}:00+02:00`, plannedMinutes: 0, status: "SUBMITTED",
    imputedMinutes: imputed, effectiveMinutes: imputed, effectiveSource: "PRESENCE_SHARE", linkedToPresence: true,
    hasOwnHours: false, interventionCount: 0, activeMaterialLineCount: 0, noMaterialComment: null,
    isReference: id === 487, carriesContent: false, allowedActions: [],
  });
  return {
    key: "p487-488", size: 2, missionIds: [487, 488],
    missions: [mission(488, 1, "08:00", "13:00", 300), mission(487, 2, "08:00", "18:00", 300)],
    date: "2026-09-30", instrumentist: { id: 18, name: "Salve Decorte" }, surgeon: { id: 8, name: "Dr Deltour" },
    site: { id: 1, name: "CHIREC - Hôpital Delta" },
    planned: { startAt: "2026-09-30T08:00:00+02:00", endAt: "2026-09-30T18:00:00+02:00", unionMinutes: 600 },
    presence: { id: 1, revision: 3, actualStartAt: "2026-09-30T08:00:00+02:00", actualEndAt: "2026-09-30T18:00:00+02:00", totalMinutes: 600, missionIds: [487, 488], allocationRule: "EARLIEST_END_FIRST" },
    unlinkedMissionIds: [], ownHoursMissionIds: [], statuses: ["SUBMITTED"], globalStatus: "SUBMITTED", fingerprint: "f",
    allowedActions,
    referenceMissionId: 487, otherEncodedMissionIds: [],
    encoding: { interventionCount: 0, encodedInterventionCount: 0, materialLineCount: 0 }, noMaterialJustificationRequired: true,
  };
}

function renderCard() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ManagerPrestationCard missionId={487} roomLabel={null} canEditRoomLabel />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("ManagerPrestationCard — correction des heures communes (D-156)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("n'est proposée que si le serveur sert edit_presence", async () => {
    apiGetMock.mockResolvedValue({ data: view([]) });
    renderCard();
    expect(await screen.findByText(/Salle et prestation multi-salles/)).toBeInTheDocument();
    expect(screen.queryByTestId("correct-common-hours")).toBeNull();
  });

  it("toute la prestation, motif obligatoire, révision attendue envoyée", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({ data: view(["edit_presence"]) });
    apiPutMock.mockResolvedValue({ data: view(["edit_presence"]) });
    renderCard();

    await user.click(await screen.findByTestId("correct-common-hours"));
    expect(await screen.findByText("Corriger les heures communes", { selector: "h2, h3, p, div, span" })).toBeInTheDocument();
    const submit = screen.getByRole("button", { name: "Corriger les heures communes" });
    expect(submit).toBeDisabled();

    await user.type(screen.getByTestId("presence-correction-reason"), "Fin réelle à 17h selon la feuille de salle.");
    await user.click(screen.getAllByRole("button", { name: "Moins 15 minutes" })[1]);
    await user.click(submit);

    await waitFor(() => expect(apiPutMock).toHaveBeenCalledTimes(1));
    const [url, body] = apiPutMock.mock.calls[0];
    expect(url).toBe("/api/missions/487/prestation/presence");
    expect(body).toMatchObject({ expectedMissionIds: [487, 488], expectedRevision: 3, reason: "Fin réelle à 17h selon la feuille de salle." });
    expect(String(body.actualEndAt)).toContain("T17:45:00");
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Heures communes corrigées : parts imputées recalculées pour toute la prestation."));
  });
});
