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

function renderDialog(materialLineCount: number, mission: Mission = MISSION, execution: Record<string, any> = executionInfo(), onCompleteHours?: () => void) {
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
      <SubmitDialog open mission={mission} onClose={vi.fn()} onSubmitted={vi.fn()} onCompleteHours={onCompleteHours} />
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

  it("n'exige jamais de commentaire pour une mission CONSULTATION, même sans matériel", async () => {
    const user = userEvent.setup();
    const consultation: Mission = { ...MISSION, type: "CONSULTATION" };
    renderDialog(0, consultation);

    await screen.findByText("0 ligne");
    expect(screen.queryByLabelText(/décrivez les interventions réalisées/i)).not.toBeInTheDocument();

    const submitButton = screen.getByRole("button", { name: "Valider et clôturer la mission" });
    expect(submitButton).not.toBeDisabled();
    await user.click(submitButton);

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        "/api/missions/690/submit",
        { noMaterial: true, comment: "" },
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
  it("affiche 'À compléter' quand aucune heure réelle n'existe", async () => {
    renderDialog(1, MISSION, executionInfo({ hasExecutionRecord: false }));

    expect(await screen.findByText("Heures prestées")).toBeInTheDocument();
    expect(screen.getByText("Non renseignées")).toBeInTheDocument();
    expect(screen.getByTestId("submit-incomplete")).toHaveTextContent("heures prestées (début et fin)");
  });

  it("D-143 — une durée seule ne suffit plus : début et fin restent à compléter", async () => {
    renderDialog(1, MISSION, executionInfo({ hasExecutionRecord: true, actualDurationMinutes: 240 }));

    expect(await screen.findByText("Début et fin à compléter")).toBeInTheDocument();
  });

  it("affiche la durée quand début et fin réels sont connus, sans avertissement", async () => {
    renderDialog(1, MISSION, executionInfo({
      hasExecutionRecord: true, actualStartAt: "2026-07-05T08:00:00Z", actualEndAt: "2026-07-05T12:00:00Z", actualDurationMinutes: 240,
    }));

    expect(await screen.findByText("10h00 → 14h00 · 4h00")).toBeInTheDocument();
    expect(screen.queryByTestId("submit-incomplete")).not.toBeInTheDocument();
  });

  it("D-146 bis — début et fin enregistrés sans durée stockée : affiche les heures prestées, jamais « Non renseignées »", async () => {
    renderDialog(1, MISSION, executionInfo({
      hasExecutionRecord: true, actualStartAt: "2026-07-05T08:00:00Z", actualEndAt: "2026-07-05T11:00:00Z", actualDurationMinutes: null,
    }));

    expect(await screen.findByText("10h00 → 13h00 · 3h00")).toBeInTheDocument();
    expect(screen.queryByText(/pause/i)).not.toBeInTheDocument();
    expect(screen.queryByText("Non renseignées")).not.toBeInTheDocument();
    expect(screen.queryByTestId("submit-incomplete")).not.toBeInTheDocument();
  });
});

describe("SubmitDialog — refus ENCODING_INCOMPLETE (D-143)", () => {
  it("affiche ce qui manque et propose d'ouvrir directement la saisie des heures", async () => {
    const onCompleteHours = vi.fn();
    renderDialog(1, MISSION, executionInfo({
      hasExecutionRecord: true, actualStartAt: "2026-07-05T08:00:00Z", actualEndAt: "2026-07-05T12:00:00Z", actualDurationMinutes: 240,
    }), onCompleteHours);
    apiPostMock.mockRejectedValue({ response: { status: 422, data: { error: {
      code: "ENCODING_INCOMPLETE",
      message: "Il reste une petite étape avant de terminer : pensez à renseigner vos heures réelles de début et de fin.",
      missing: [{ code: "ACTUAL_END_TIME", label: "Heure réelle de fin", section: "hours" }],
    } } } });

    await userEvent.click(await screen.findByRole("button", { name: "Valider et clôturer la mission" }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("Il reste une petite étape avant de terminer");
    expect(alert).toHaveTextContent("Heure réelle de fin");
    expect(alert).toHaveTextContent("Tout ce que vous avez déjà encodé est conservé.");
    await userEvent.click(screen.getByRole("button", { name: "Renseigner mes heures" }));
    expect(onCompleteHours).toHaveBeenCalledTimes(1);
  });
});

describe("SubmitDialog — activité réalisée sans instrumentiste (D-155)", () => {
  it("ne montre ni ne réclame aucune heure ; la description sans matériel reste exigée", async () => {
    renderDialog(0, { ...MISSION, executedWithoutInstrumentist: { declaredAt: "2026-07-20T12:00:00Z", declaredByName: "Jean Dupont" } } as Mission);

    expect(await screen.findByText(/aucune heure n'est déclarée/i)).toBeInTheDocument();
    expect(screen.queryByText("Heures prestées")).toBeNull();
    expect(screen.queryByTestId("submit-incomplete")).toBeNull();
    expect(await screen.findByLabelText(/Décrivez les interventions réalisées/)).toBeInTheDocument();
  });
});

describe("SubmitDialog — activité sans instrumentiste : on soumet au manager (D-172)", () => {
  it("parle de soumission au manager, jamais de validation ni de clôture", async () => {
    const user = userEvent.setup();
    renderDialog(1, { ...MISSION, executedWithoutInstrumentist: { declaredAt: "2026-10-23T06:31:00Z", declaredByName: "Arnaud Deltour" } } as Mission);
    expect(await screen.findByText("Récapitulatif avant soumission")).toBeInTheDocument();
    expect(screen.queryByText("Valider et clôturer la mission")).toBeNull();
    expect(screen.getByText(/le manager le vérifiera et le validera/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Soumettre l'encodage au manager" }));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalled());
  });
});
