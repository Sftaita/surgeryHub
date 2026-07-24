import { describe, it, expect, vi, beforeEach } from "vitest";
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
