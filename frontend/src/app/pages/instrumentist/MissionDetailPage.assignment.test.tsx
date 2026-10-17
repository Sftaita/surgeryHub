import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import { MissionDetailContent } from "./MissionDetailPage";

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
    patch: vi.fn().mockResolvedValue({ data: {} }),
  },
}));

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn(), info: vi.fn() }),
}));

const MISSION_ID = 151;

function surgeonMission(overrides: Record<string, any> = {}) {
  return {
    id: MISSION_ID,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startAt: "2030-07-20T08:00:00+02:00",
    endAt: "2030-07-20T12:00:00+02:00",
    status: "ASSIGNED",
    site: { id: 1, name: "Clinique du Parc" },
    surgeon: { id: 2, firstname: "Simon", lastname: "Chirurgien", email: "s@test.be" },
    instrumentist: { id: 7, firstname: "Iris", lastname: "Martin", email: "i@test.be" },
    allowedActions: ["view", "refuse_assignment", "view_encoding"],
    currentAssignment: {
      id: 42,
      instrumentist: { id: 7, name: "Iris Martin" },
      source: "POOL_CLAIM",
      assignedAt: "2030-07-15T09:30:00+02:00",
      refusalDeadlineAt: "2030-07-16T09:30:00+02:00",
      refusable: true,
      refusalBlockedReason: null,
      reviewRequestedAt: null,
    },
    ...overrides,
  };
}

function mockGet(mission: any) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}`) return Promise.resolve({ data: mission });
    if (url === `/api/missions/${mission.id}/execution`) return Promise.resolve({ data: { missionId: mission.id, hasExecutionRecord: false, disputes: [] } });
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[path]}>
      <QueryClientProvider client={client}>
        <MissionDetailContent missionId={MISSION_ID} />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("D-151 — surgeon assignment card", () => {
  it("shows who accepted, when, and until when the surgeon may refuse", async () => {
    mockGet(surgeonMission());
    renderAt(`/app/s/missions/${MISSION_ID}`);

    const card = await screen.findByTestId("surgeon-assignment-card");
    expect(card).toHaveTextContent("Iris Martin");
    expect(card).toHaveTextContent("Prise en charge le");
    expect(card).toHaveTextContent("Refus possible jusqu'au");
    expect(screen.getByRole("button", { name: "Refuser cet instrumentiste" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Demander l'intervention du manager" })).not.toBeInTheDocument();
  });

  it("refuses the affectation the surgeon saw, after an explicit confirmation", async () => {
    mockGet(surgeonMission());
    apiPostMock.mockResolvedValue({ data: surgeonMission({ status: "OPEN", instrumentist: null, currentAssignment: null, allowedActions: ["view"] }) });
    renderAt(`/app/s/missions/${MISSION_ID}`);

    await userEvent.click(await screen.findByRole("button", { name: "Refuser cet instrumentiste" }));
    expect(apiPostMock).not.toHaveBeenCalled();
    expect(screen.getByText(/La mission n'est pas annulée/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Refuser pour cette mission" }));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(`/api/missions/${MISSION_ID}/assignments/42/refuse`, { scope: "PUNCTUAL" }));
    expect(toastSuccess).toHaveBeenCalled();
  });

  it("refuses and blocks for his future missions when the surgeon chooses the durable refusal", async () => {
    mockGet(surgeonMission());
    apiPostMock.mockResolvedValue({ data: surgeonMission({ status: "OPEN", instrumentist: null, currentAssignment: null, allowedActions: ["view"] }) });
    renderAt(`/app/s/missions/${MISSION_ID}`);

    await userEvent.click(await screen.findByRole("button", { name: "Refuser cet instrumentiste" }));
    expect(screen.getByLabelText("Refuser pour cette mission")).toBeChecked();
    await userEvent.click(screen.getByLabelText("Refuser et bloquer pour mes futures missions"));
    expect(screen.getByText(/ne verra plus vos nouvelles missions/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Refuser et bloquer" }));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(`/api/missions/${MISSION_ID}/assignments/42/refuse`, { scope: "DURABLE" }));
  });

  it("offers only the manager intervention once the refusal is no longer possible, with the reason", async () => {
    mockGet(
      surgeonMission({
        allowedActions: ["view", "request_assignment_review"],
        currentAssignment: { ...surgeonMission().currentAssignment, refusable: false, refusalBlockedReason: "REFUSAL_WINDOW_EXPIRED" },
      }),
    );
    apiPostMock.mockResolvedValue({ data: surgeonMission({ allowedActions: ["view"] }) });
    renderAt(`/app/s/missions/${MISSION_ID}`);

    expect(await screen.findByText(/Le délai de refus de 24 heures/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Refuser cet instrumentiste" })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Demander l'intervention du manager" }));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(`/api/missions/${MISSION_ID}/assignment-review-request`));
  });

  it("shows the server's refusal message when the refusal is refused (e.g. superseded)", async () => {
    mockGet(surgeonMission());
    apiPostMock.mockRejectedValue({ response: { data: { error: { code: "ASSIGNMENT_SUPERSEDED", message: "Cette affectation n'est plus d'actualité" } } } });
    renderAt(`/app/s/missions/${MISSION_ID}`);

    await userEvent.click(await screen.findByRole("button", { name: "Refuser cet instrumentiste" }));
    await userEvent.click(screen.getByRole("button", { name: "Refuser pour cette mission" }));
    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Cette affectation n'est plus d'actualité"));
  });

  it("is never shown on the instrumentist side", async () => {
    mockGet(surgeonMission());
    renderAt(`/app/i/missions/${MISSION_ID}`);

    await screen.findByText("Clinique du Parc");
    expect(screen.queryByTestId("surgeon-assignment-card")).not.toBeInTheDocument();
  });
});

describe("D-151 — former instrumentist minimal view", () => {
  it("shows « Affectation annulée » without anybody's name, any reason or any action", async () => {
    mockGet(
      surgeonMission({
        status: "ASSIGNED",
        instrumentist: null,
        allowedActions: ["view"],
        currentAssignment: null,
        myAssignmentStatus: "ENDED",
      }),
    );
    renderAt(`/app/i/missions/${MISSION_ID}`);

    expect(await screen.findByText("Affectation annulée")).toBeInTheDocument();
    expect(screen.getByTestId("assignment-ended-note")).toHaveTextContent("n'a pas pu être maintenue");
    expect(screen.queryByText("Instrumentiste")).not.toBeInTheDocument();
    expect(screen.queryByText(/refus/i)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Encoder|Terminer/ })).not.toBeInTheDocument();
    expect(apiGetMock).not.toHaveBeenCalledWith(`/api/missions/${MISSION_ID}/execution`);
  });
});
