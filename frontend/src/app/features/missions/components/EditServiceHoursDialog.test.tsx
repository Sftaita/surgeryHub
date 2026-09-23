import { describe, it, expect, vi, beforeEach } from "vitest";
import * as React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import EditServiceHoursDialog from "./EditServiceHoursDialog";
import type { MissionExecutionInfo } from "../api/missions.api";

const apiPatchMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    patch: (...args: unknown[]) => apiPatchMock(...args),
  },
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

const MISSION_ID = 42;

function baseMission(overrides: Record<string, any> = {}) {
  return {
    id: MISSION_ID,
    startAt: "2026-07-05T08:00:00Z",
    endAt: "2026-07-05T12:00:00Z",
    ...overrides,
  };
}

function executionInfo(overrides: Partial<MissionExecutionInfo> = {}): MissionExecutionInfo {
  return {
    missionId: MISSION_ID,
    hasExecutionRecord: false,
    actualStartAt: null,
    actualEndAt: null,
    actualDurationMinutes: null,
    hoursSource: null,
    effectiveDurationMinutes: 240,
    effectiveDurationSource: "PLANNED",
    disputes: [],
    ...overrides,
  };
}

function renderDialog(mission: any, onClose = vi.fn(), onSaved = vi.fn(), seedExecution: MissionExecutionInfo | null = executionInfo()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  if (seedExecution) {
    client.setQueryData(["mission-execution", MISSION_ID], seedExecution);
  }
  render(
    <QueryClientProvider client={client}>
      <EditServiceHoursDialog open mission={mission} onClose={onClose} onSaved={onSaved} />
    </QueryClientProvider>,
  );
  return { client };
}

beforeEach(() => {
  apiPatchMock.mockReset();
});

/**
 * Anomalie écran d'encodage (commit dédié) — la cible correcte de cette mutation est
 * ["mission-execution", missionId] (MissionExecutionInfo), pas ["mission",
 * missionId].service (un champ que le backend n'expose plus depuis le renommage
 * InstrumentistService -> MissionExecution, D-071) — voir EditServiceHoursDialog.tsx.
 */
function renderReopenable(mission: any, seedExecution: MissionExecutionInfo | null) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  if (seedExecution) client.setQueryData(["mission-execution", MISSION_ID], seedExecution);
  function Host() {
    const [open, setOpen] = React.useState(true);
    return (
      <>
        <button type="button" onClick={() => setOpen(true)}>Rouvrir</button>
        <EditServiceHoursDialog open={open} mission={mission} onClose={() => setOpen(false)} />
      </>
    );
  }
  render(<QueryClientProvider client={client}><Host /></QueryClientProvider>);
  return { client };
}

describe("EditServiceHoursDialog — préremplissage à l'ouverture", () => {
  it("sans heures enregistrées, part de l'horaire prévu (08h00 → 12h00 = 4h00)", () => {
    renderDialog(baseMission(), vi.fn(), vi.fn(), executionInfo());

    expect(screen.getByText("4h00")).toBeInTheDocument();
  });

  it("avec une durée déjà enregistrée, affiche cette valeur et non l'horaire prévu", () => {
    renderDialog(baseMission(), vi.fn(), vi.fn(), executionInfo({
      hasExecutionRecord: true, actualDurationMinutes: 270, hoursSource: "INSTRUMENTIST",
      effectiveDurationMinutes: 270, effectiveDurationSource: "ACTUAL_EXPLICIT",
    }));

    expect(screen.getByText("4h30")).toBeInTheDocument();
    expect(screen.queryByText("4h00")).not.toBeInTheDocument();
  });

  it("avec des horaires réels enregistrés, les reprend tels quels", () => {
    renderDialog(baseMission(), vi.fn(), vi.fn(), executionInfo({
      hasExecutionRecord: true, actualStartAt: "2026-07-05T09:15:00", actualEndAt: "2026-07-05T14:45:00",
      actualDurationMinutes: 330, effectiveDurationMinutes: 330, effectiveDurationSource: "ACTUAL_TIMES",
    }));

    expect(screen.getByText("09h15")).toBeInTheDocument();
    expect(screen.getByText("14h45")).toBeInTheDocument();
    expect(screen.getByText("5h30")).toBeInTheDocument();
  });

  it("régression : modifier, enregistrer puis rouvrir affiche la dernière valeur sauvegardée", async () => {
    const user = userEvent.setup();
    apiPatchMock.mockImplementation((_url: string, body: any) => Promise.resolve({
      data: executionInfo({
        hasExecutionRecord: true, actualDurationMinutes: body.actualDurationMinutes, hoursSource: "INSTRUMENTIST",
        effectiveDurationMinutes: body.actualDurationMinutes, effectiveDurationSource: "ACTUAL_EXPLICIT",
      }),
    }));
    renderReopenable(baseMission(), executionInfo());
    expect(screen.getByText("4h00")).toBeInTheDocument();

    // Fin +15 min → 4h15, enregistrer.
    await user.click(screen.getAllByRole("button", { name: "Plus 15 minutes" })[1]);
    expect(screen.getByText("4h15")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Enregistrer les heures" }));
    await waitFor(() => expect(apiPatchMock).toHaveBeenCalledWith(
      `/api/missions/${MISSION_ID}/execution`, { actualDurationMinutes: 255, hoursSource: "INSTRUMENTIST" },
    ));
    await waitFor(() => expect(screen.queryByText("Enregistrer les heures")).not.toBeInTheDocument());

    // Réouverture : jamais retour à l'horaire prévu (4h00).
    await user.click(screen.getByRole("button", { name: "Rouvrir" }));
    expect(await screen.findByText("4h15")).toBeInTheDocument();
    expect(screen.queryByText("4h00")).not.toBeInTheDocument();
  });
});

describe("EditServiceHoursDialog — mutation optimiste des heures prestées", () => {
  it("appelle PATCH .../execution avec des minutes entières, pas l'ancien endpoint /service", async () => {
    apiPatchMock.mockResolvedValue({ data: executionInfo({ hasExecutionRecord: true, actualDurationMinutes: 240, hoursSource: "INSTRUMENTIST", effectiveDurationMinutes: 240, effectiveDurationSource: "ACTUAL_EXPLICIT" }) });
    renderDialog(baseMission());

    await userEvent.click(screen.getByRole("button", { name: "Enregistrer les heures" }));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith(
        `/api/missions/${MISSION_ID}/execution`,
        { actualDurationMinutes: 240, hoursSource: "INSTRUMENTIST" },
      );
    });
  });

  it("met à jour le cache ['mission-execution', missionId] et ferme la modale avant même la réponse serveur", async () => {
    let resolvePatch: (value: any) => void = () => {};
    apiPatchMock.mockReturnValue(new Promise((resolve) => { resolvePatch = resolve; }));

    const onClose = vi.fn();
    const { client } = renderDialog(baseMission(), onClose);

    await userEvent.click(screen.getByRole("button", { name: "Enregistrer les heures" }));

    // Affichage optimiste : la modale se ferme et le cache reflète déjà la nouvelle
    // valeur (08h00 → 12h00, pas de pause = 4h = 240 min) sans attendre la résolution de
    // l'appel API. hasExecutionRecord doit déjà valoir true (sinon la carte retomberait
    // sur "Non renseigné" malgré la saisie).
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
    const optimistic = client.getQueryData<MissionExecutionInfo>(["mission-execution", MISSION_ID]);
    expect(optimistic?.hasExecutionRecord).toBe(true);
    expect(optimistic?.actualDurationMinutes).toBe(240);
    expect(optimistic?.effectiveDurationMinutes).toBe(240);

    resolvePatch({ data: executionInfo({ hasExecutionRecord: true, actualDurationMinutes: 240, hoursSource: "INSTRUMENTIST", effectiveDurationMinutes: 240, effectiveDurationSource: "ACTUAL_EXPLICIT" }) });
  });

  it("construit une valeur optimiste complète même si le cache n'a jamais été consulté (première saisie)", async () => {
    apiPatchMock.mockReturnValue(new Promise(() => {})); // jamais résolue pour ce test
    const { client } = renderDialog(baseMission(), vi.fn(), vi.fn(), /* seedExecution */ null);

    await userEvent.click(screen.getByRole("button", { name: "Enregistrer les heures" }));

    await waitFor(() => {
      const optimistic = client.getQueryData<MissionExecutionInfo>(["mission-execution", MISSION_ID]);
      expect(optimistic?.hasExecutionRecord).toBe(true);
      expect(optimistic?.actualDurationMinutes).toBe(240);
    });
  });

  it("synchronise le cache avec la réponse serveur au succès (source de vérité finale, remplacement complet)", async () => {
    const serverResponse = executionInfo({
      hasExecutionRecord: true,
      actualDurationMinutes: 270,
      hoursSource: "INSTRUMENTIST",
      effectiveDurationMinutes: 270,
      effectiveDurationSource: "ACTUAL_EXPLICIT",
    });
    apiPatchMock.mockResolvedValue({ data: serverResponse });
    const onSaved = vi.fn();
    const { client } = renderDialog(baseMission(), vi.fn(), onSaved);

    await userEvent.click(screen.getByRole("button", { name: "Enregistrer les heures" }));

    await waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
    expect(client.getQueryData<MissionExecutionInfo>(["mission-execution", MISSION_ID])).toEqual(serverResponse);
  });

  it("restaure la valeur précédente en cache si l'appel API échoue (rollback)", async () => {
    apiPatchMock.mockRejectedValue(new Error("Network Error"));
    const previous = executionInfo({ hasExecutionRecord: true, actualDurationMinutes: 180, effectiveDurationMinutes: 180, effectiveDurationSource: "ACTUAL_EXPLICIT" });
    const { client } = renderDialog(baseMission(), vi.fn(), vi.fn(), previous);

    await userEvent.click(screen.getByRole("button", { name: "Enregistrer les heures" }));

    await waitFor(() => {
      expect(client.getQueryData<MissionExecutionInfo>(["mission-execution", MISSION_ID])).toEqual(previous);
    });
  });
});
