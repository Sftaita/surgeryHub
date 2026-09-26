import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import PublishMissionDialog from "./PublishMissionDialog";
import type { Mission } from "../api/missions.types";

const getMock = vi.fn();
const postMock = vi.fn();
vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => getMock(...args),
    post: (...args: unknown[]) => postMock(...args),
  },
}));

const MISSION = {
  id: 7,
  type: "BLOCK",
  schedulePrecision: "EXACT",
  startAt: "2026-10-06T08:00:00+02:00",
  endAt: "2026-10-06T13:00:00+02:00",
  site: { id: 3, name: "Delta" },
  status: "DRAFT",
  allowedActions: ["view", "edit", "publish", "cancel"],
} as unknown as Mission;

const CANDIDATES = [
  { id: 41, name: "Salve Decorte", email: "salve@x.be", eligible: true, selectable: true, reasons: [], unavailability: null, conflict: null },
  { id: 42, name: "Anne Absente", email: "anne@x.be", eligible: false, selectable: false, reasons: ["ABSENT"], unavailability: { type: "ABSENCE", dateStart: "2026-10-06", dateEnd: "2026-10-06" }, conflict: null },
];

function renderDialog() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidateSpy = vi.spyOn(client, "invalidateQueries");
  const onClose = vi.fn();
  render(
    <QueryClientProvider client={client}>
      <PublishMissionDialog open onClose={onClose} mission={MISSION} />
    </QueryClientProvider>,
  );
  return { invalidateSpy, onClose };
}

beforeEach(() => {
  getMock.mockReset();
  postMock.mockReset();
  getMock.mockResolvedValue({ data: { candidates: CANDIDATES } });
  postMock.mockResolvedValue({ data: {} });
});

describe("PublishMissionDialog — D-125 pool / demande / attribution directe", () => {
  it("proposer au pool — publication POOL, sans choisir personne", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByRole("button", { name: "Proposer au pool" }));
    await waitFor(() => expect(postMock).toHaveBeenCalledWith("/api/missions/7/publish", { scope: "POOL" }));
    expect(getMock).not.toHaveBeenCalled();
  });

  it("attribuer directement — sélecteur par nom (jamais un id), filtré au site, recherche, puis assign-directly", async () => {
    const user = userEvent.setup();
    const { invalidateSpy, onClose } = renderDialog();

    await user.click(screen.getByLabelText(/Attribuer directement/));
    expect(screen.queryByLabelText(/Target User ID/i)).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Attribuer" })).toBeDisabled();

    await user.type(screen.getByRole("combobox"), "dec");
    const option = await screen.findByRole("option", { name: /Salve Decorte/ });
    expect(within(option).getByText("salve@x.be")).toBeInTheDocument();

    // The candidates are the mission site's, evaluated for this very slot.
    expect(getMock).toHaveBeenCalledWith("/api/missions/dispatch-candidates", {
      params: { siteId: 3, startAt: MISSION.startAt, endAt: MISSION.endAt, missionId: 7 },
    });

    await user.click(option);
    await user.click(screen.getByRole("button", { name: "Attribuer" }));

    await waitFor(() => expect(postMock).toHaveBeenCalledWith("/api/missions/7/assign-directly", { instrumentistId: 41 }));
    await waitFor(() => expect(onClose).toHaveBeenCalled());

    // The operational planning is refreshed in place (calendar, published planning, coverage).
    const keys = invalidateSpy.mock.calls.map(([f]) => JSON.stringify((f as { queryKey: unknown }).queryKey));
    expect(keys).toEqual(expect.arrayContaining([
      JSON.stringify(["planning-schedule"]),
      JSON.stringify(["planning-v2", "modification-missions"]),
      JSON.stringify(["coverage-summary"]),
      JSON.stringify(["mission", 7]),
    ]));
  });

  it("une instrumentiste momentanément inéligible est visible, désactivée, avec la raison du backend", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByLabelText(/Demander à un instrumentiste/));
    await user.click(screen.getByRole("combobox"));
    const absent = await screen.findByRole("option", { name: /Anne Absente/ });
    expect(absent).toHaveAttribute("aria-disabled", "true");
    expect(within(absent).getByText("Absente · 06/10 → 06/10")).toBeInTheDocument();
  });

  it("demander à un instrumentiste — publication TARGETED vers la personne choisie", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByLabelText(/Demander à un instrumentiste/));
    await user.type(screen.getByRole("combobox"), "salve");
    await user.click(await screen.findByRole("option", { name: /Salve Decorte/ }));
    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));

    await waitFor(() => expect(postMock).toHaveBeenCalledWith("/api/missions/7/publish", { scope: "TARGETED", targetUserId: 41 }));
  });

  it("refus backend (inéligible) — message affiché, dialogue conservé", async () => {
    postMock.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: "INSTRUMENTIST_INCOMPATIBLE", message: "Instrumentiste non éligible : Absente" } } } });
    const user = userEvent.setup();
    const { onClose } = renderDialog();

    await user.click(screen.getByLabelText(/Attribuer directement/));
    await user.type(screen.getByRole("combobox"), "salve");
    await user.click(await screen.findByRole("option", { name: /Salve Decorte/ }));
    await user.click(screen.getByRole("button", { name: "Attribuer" }));

    expect(await screen.findByText("Instrumentiste non éligible : Absente")).toBeInTheDocument();
    expect(onClose).not.toHaveBeenCalled();
  });
});
