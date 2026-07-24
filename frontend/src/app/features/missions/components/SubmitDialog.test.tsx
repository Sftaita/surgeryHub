import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import SubmitDialog from "./SubmitDialog";
import type { Mission } from "../api/missions.types";

const apiPostMock = vi.fn();
const apiGetMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
  },
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

vi.mock("../sync/missionSyncBus", () => ({
  requestMissionSync: vi.fn(),
}));

const MISSION: Mission = {
  id: 690,
  type: "BLOCK",
  schedulePrecision: "EXACT",
  startAt: "2026-07-20T08:00:00Z",
  endAt: "2026-07-20T11:00:00Z",
};

/** MissionExecutionInfo — voir missions.api.ts. hasExecutionRecord=false par défaut :
 *  reflète l'état "Non renseigné" attendu quand aucune saisie n'a encore eu lieu. */
function executionInfo(overrides: Record<string, any> = {}) {
  return {
    missionId: 690,
    hasExecutionRecord: false,
    actualStartAt: null,
    actualEndAt: null,
    actualDurationMinutes: null,
    hoursSource: null,
    effectiveDurationMinutes: 180,
    effectiveDurationSource: "PLANNED",
    disputes: [],
    ...overrides,
  };
}

function encodingWith(materialLineCount: number) {
  return {
    mission: { id: 690, type: "BLOCK", status: "ENCODING_IN_PROGRESS", allowedActions: [] },
    interventions: materialLineCount > 0
      ? [{
          id: 1, code: "A", label: "Intervention A", orderIndex: 0,
          materialLines: Array.from({ length: materialLineCount }, (_, i) => ({
            id: i + 1, missionInterventionId: 1,
            item: { id: 1, label: "Vis", referenceCode: "V1", unit: "u", isImplant: false, firm: { id: 1, name: "Arthrex" } },
            quantity: "1.00", comment: "",
          })),
          materialItemRequests: [],
        }]
      : [{ id: 1, code: "A", label: "Intervention A", orderIndex: 0, materialLines: [], materialItemRequests: [] }],
    catalog: { items: [], firms: [], interventionTypes: [] },
    coherenceSummary: {},
    encodingComments: [],
  };
}

function renderDialog(materialLineCount: number, mission: Mission = MISSION, execution: Record<string, any> = executionInfo()) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}/encoding`) {
      return Promise.resolve({ data: encodingWith(materialLineCount) });
    }
    if (url === `/api/missions/${mission.id}/execution`) {
      return Promise.resolve({ data: execution });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
  apiPostMock.mockResolvedValue({ data: { ...mission, status: "SUBMITTED" } });

  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <SubmitDialog open mission={mission} onClose={vi.fn()} onSubmitted={vi.fn()} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiPostMock.mockReset();
  apiGetMock.mockReset();
});

describe("SubmitDialog — commentaire requis si aucun matériel encodé (D-070 suite)", () => {
  it("n'affiche aucun champ commentaire quand du matériel est encodé", async () => {
    renderDialog(2);

    await screen.findByText("2 lignes");
    expect(screen.queryByLabelText(/décrivez les interventions réalisées/i)).not.toBeInTheDocument();
  });

  it("affiche un champ commentaire obligatoire quand aucun matériel n'est encodé", async () => {
    renderDialog(0);

    expect(await screen.findByText(/décrivez les interventions réalisées/i)).toBeInTheDocument();
  });

  it("désactive la validation tant que le commentaire est vide (0 matériel)", async () => {
    renderDialog(0);

    await screen.findByText(/décrivez les interventions réalisées/i);
    expect(screen.getByRole("button", { name: "Valider et clôturer la mission" })).toBeDisabled();
  });

  it("active la validation une fois le commentaire saisi et l'envoie avec noMaterial=true", async () => {
    const user = userEvent.setup();
    renderDialog(0);

    const field = await screen.findByLabelText(/décrivez les interventions réalisées/i);
    await user.type(field, "Consultation de suivi, aucun matériel utilisé.");

    const submitButton = screen.getByRole("button", { name: "Valider et clôturer la mission" });
    expect(submitButton).not.toBeDisabled();
    await user.click(submitButton);

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        "/api/missions/690/submit",
        { noMaterial: true, comment: "Consultation de suivi, aucun matériel utilisé." },
      );
    });
  });

  it("envoie noMaterial=false et un commentaire vide quand du matériel est déjà encodé", async () => {
    const user = userEvent.setup();
    renderDialog(1);

    await screen.findByText("1 ligne");
    const submitButton = screen.getByRole("button", { name: "Valider et clôturer la mission" });
    await waitFor(() => expect(submitButton).not.toBeDisabled());
    await user.click(submitButton);

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        "/api/missions/690/submit",
        { noMaterial: false, comment: "" },
      );
    });
  });
});

/**
 * Anomalie écran d'encodage (commit dédié) — le récapitulatif final lisait
 * mission.service?.hours, un champ que le backend n'expose plus depuis D-071 : la ligne
 * "Heures" affichait donc en permanence "Non renseignées", même quand la carte "Heures
 * prestées" de l'écran principal affichait déjà la bonne valeur. Corrigé pour lire
 * ["mission-execution", missionId], la même source que MissionEncodingPage.tsx et
 * EditServiceHoursDialog.tsx (formatage centralisé, formatExecutionHours).
 */
describe("SubmitDialog — ligne Heures du récapitulatif (source de vérité mission-execution)", () => {
  it("affiche 'Non renseigné' quand aucune saisie n'existe réellement", async () => {
    renderDialog(1, MISSION, executionInfo({ hasExecutionRecord: false }));

    expect(await screen.findByText("Non renseigné")).toBeInTheDocument();
  });

  it("affiche les heures réellement enregistrées, au même format que l'écran principal", async () => {
    renderDialog(1, MISSION, executionInfo({ hasExecutionRecord: true, actualDurationMinutes: 240 }));

    expect(await screen.findByText("4 h")).toBeInTheDocument();
    expect(screen.queryByText("Non renseigné")).not.toBeInTheDocument();
  });
});
