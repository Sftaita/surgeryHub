import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClientProvider, QueryClient } from "@tanstack/react-query";
import { AbsenceCommunicationBackfillDialog } from "./AbsenceCommunicationBackfillDialog";
import type { BackfillExecuteResponseV2, BackfillPreviewV2 } from "../api/planningV2.types";

vi.mock("../api/planningV2.api", async () => {
  const actual = await vi.importActual<typeof import("../api/planningV2.api")>("../api/planningV2.api");
  return {
    ...actual,
    previewAbsenceCommunicationBackfill: vi.fn(),
    executeAbsenceCommunicationBackfill: vi.fn(),
  };
});

const toastError = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: toastError, warning: vi.fn() }),
}));

import * as api from "../api/planningV2.api";

function makePreview(overrides: Partial<BackfillPreviewV2> = {}): BackfillPreviewV2 {
  return {
    createdFrom: "2026-09-01",
    summary: {
      totalAbsencesAnalyzed: 20, ignoredOlderAbsences: 12, eligibleAbsences: 2, noActionAbsences: 1,
      roomReleaseEmailsPotential: 1, blockManagementImmediate: 1, blockManagementScheduled: 0, alreadyProcessedSites: 0,
    },
    items: [
      {
        absenceId: 42, surgeonId: 7, surgeonName: "Etienne Willemart", createdAt: "2026-09-03T10:00:00+00:00",
        dateStart: "2026-09-10", dateEnd: "2026-09-15", selectable: true,
        sites: [{
          siteId: 3, siteName: "CHIREC - Hôpital Delta", futureBlockOccurrenceCount: 2,
          roomRelease: { status: "WILL_SEND", recipientCount: 3, newOccurrenceCount: 2 },
          blockManagement: { status: "WILL_SEND_NOW", scheduledAt: "2026-09-06T00:00:00+00:00" },
        }],
      },
      {
        absenceId: 43, surgeonId: 8, surgeonName: "Diane Lefebvre", createdAt: "2026-09-04T10:00:00+00:00",
        dateStart: "2026-09-12", dateEnd: "2026-09-14", selectable: false,
        sites: [{
          siteId: 3, siteName: "CHIREC - Hôpital Delta", futureBlockOccurrenceCount: 0,
          roomRelease: { status: "DISABLED", recipientCount: 0, newOccurrenceCount: 0 },
          blockManagement: { status: "DISABLED", scheduledAt: null },
        }],
      },
    ],
    ...overrides,
  };
}

function renderDialog(onExecuted = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AbsenceCommunicationBackfillDialog open onClose={vi.fn()} onExecuted={onExecuted} />
    </QueryClientProvider>,
  );
}

beforeEach(() => vi.clearAllMocks());

describe("AbsenceCommunicationBackfillDialog — cycle complet (Lot C, D-114)", () => {
  it("Analyser appelle preview() avec le cutoff choisi et affiche le résumé + les lignes", async () => {
    vi.mocked(api.previewAbsenceCommunicationBackfill).mockResolvedValue(makePreview());

    renderDialog();
    const user = userEvent.setup();

    const dateInput = screen.getByLabelText("Absences créées à partir du");
    await user.clear(dateInput);
    await user.type(dateInput, "2026-09-01");
    await user.click(screen.getByRole("button", { name: "Analyser" }));

    await waitFor(() => expect(api.previewAbsenceCommunicationBackfill).toHaveBeenCalledWith("2026-09-01"));
    expect(await screen.findByText("Etienne Willemart")).toBeInTheDocument();
    expect(screen.getByText("Diane Lefebvre")).toBeInTheDocument();
    expect(screen.getByText("2 absences éligibles")).toBeInTheDocument();
  });

  it("pré-sélectionne uniquement les absences actionnables, les autres sont décochées et désactivées", async () => {
    vi.mocked(api.previewAbsenceCommunicationBackfill).mockResolvedValue(makePreview());

    renderDialog();
    await userEvent.click(screen.getByRole("button", { name: "Analyser" }));
    await screen.findByText("Etienne Willemart");

    const checkboxes = screen.getAllByRole("checkbox");
    expect(checkboxes[0]).toBeChecked(); // Etienne — selectable
    expect(checkboxes[0]).toBeEnabled();
    expect(checkboxes[1]).not.toBeChecked(); // Diane — not selectable
    expect(checkboxes[1]).toBeDisabled();

    expect(screen.getByRole("button", { name: /Traiter les absences sélectionnées \(1\)/ })).toBeInTheDocument();
  });

  it("décocher une absence retire le CTA de traitement pour elle et met à jour le compteur", async () => {
    vi.mocked(api.previewAbsenceCommunicationBackfill).mockResolvedValue(makePreview());

    renderDialog();
    await userEvent.click(screen.getByRole("button", { name: "Analyser" }));
    await screen.findByText("Etienne Willemart");

    const checkboxes = screen.getAllByRole("checkbox");
    await userEvent.click(checkboxes[0]);

    expect(screen.getByRole("button", { name: "Traiter les absences sélectionnées (0)" })).toBeDisabled();
  });

  it("demande une confirmation explicite avant d'exécuter, puis appelle execute() avec la sélection", async () => {
    vi.mocked(api.previewAbsenceCommunicationBackfill).mockResolvedValue(makePreview());
    const executeResult: BackfillExecuteResponseV2 = {
      createdFrom: "2026-09-01",
      results: [{ absenceId: 42, status: "PROCESSED", newCommunicationCount: 2, blockManagementSkippedReason: null }],
    };
    vi.mocked(api.executeAbsenceCommunicationBackfill).mockResolvedValue(executeResult);
    const onExecuted = vi.fn();

    renderDialog(onExecuted);
    await userEvent.click(screen.getByRole("button", { name: "Analyser" }));
    await screen.findByText("Etienne Willemart");

    await userEvent.click(screen.getByRole("button", { name: /Traiter les absences sélectionnées/ }));

    expect(await screen.findByText(/Des emails seront réellement envoyés/)).toBeInTheDocument();
    expect(api.executeAbsenceCommunicationBackfill).not.toHaveBeenCalled();

    await userEvent.click(screen.getByRole("button", { name: "Traiter" }));

    const usedCutoff = vi.mocked(api.previewAbsenceCommunicationBackfill).mock.calls[0][0];
    await waitFor(() => expect(api.executeAbsenceCommunicationBackfill).toHaveBeenCalledWith(usedCutoff, [42]));
    expect(await screen.findByText(/1 absence traitée/)).toBeInTheDocument();
    expect(onExecuted).toHaveBeenCalled();
  });

  it("un double-clic rapide sur Traiter n'appelle execute() qu'une seule fois (§24)", async () => {
    vi.mocked(api.previewAbsenceCommunicationBackfill).mockResolvedValue(makePreview());
    let resolveExecute: (v: BackfillExecuteResponseV2) => void;
    vi.mocked(api.executeAbsenceCommunicationBackfill).mockReturnValue(
      new Promise<BackfillExecuteResponseV2>((resolve) => { resolveExecute = resolve; }),
    );

    renderDialog();
    await userEvent.click(screen.getByRole("button", { name: "Analyser" }));
    await screen.findByText("Etienne Willemart");
    await userEvent.click(screen.getByRole("button", { name: /Traiter les absences sélectionnées/ }));
    await screen.findByText(/Des emails seront réellement envoyés/);

    const traiterButton = screen.getByRole("button", { name: "Traiter" });
    // Le premier clic passe par userEvent (interaction réaliste). MUI désactive
    // immédiatement le bouton (pointer-events: none) une fois isPending — userEvent refuse
    // alors toute interaction sur un élément désactivé, ce qui est déjà la preuve que la
    // protection fonctionne. `fireEvent` (sans vérification pointer-events) simule un second
    // et un troisième clic qui atteindraient malgré tout le handler, pour vérifier que la
    // garde explicite `if (!executeMutation.isPending)` empêche tout second appel.
    await userEvent.click(traiterButton);
    fireEvent.click(traiterButton);
    fireEvent.click(traiterButton);

    expect(api.executeAbsenceCommunicationBackfill).toHaveBeenCalledTimes(1);

    resolveExecute!({
      createdFrom: "2026-09-01",
      results: [{ absenceId: 42, status: "PROCESSED", newCommunicationCount: 2, blockManagementSkippedReason: null }],
    });
    await screen.findByText(/1 absence traitée/);
  });

  it("annuler la confirmation revient à l'étape de sélection sans appeler execute()", async () => {
    vi.mocked(api.previewAbsenceCommunicationBackfill).mockResolvedValue(makePreview());

    renderDialog();
    await userEvent.click(screen.getByRole("button", { name: "Analyser" }));
    await screen.findByText("Etienne Willemart");
    await userEvent.click(screen.getByRole("button", { name: /Traiter les absences sélectionnées/ }));
    await screen.findByText(/Des emails seront réellement envoyés/);

    await userEvent.click(screen.getByRole("button", { name: "Annuler" }));

    expect(await screen.findByText("Etienne Willemart")).toBeInTheDocument();
    expect(api.executeAbsenceCommunicationBackfill).not.toHaveBeenCalled();
  });

  it("affiche les erreurs partielles sans masquer les succès", async () => {
    vi.mocked(api.previewAbsenceCommunicationBackfill).mockResolvedValue(makePreview());
    vi.mocked(api.executeAbsenceCommunicationBackfill).mockResolvedValue({
      createdFrom: "2026-09-01",
      results: [
        { absenceId: 42, status: "PROCESSED", newCommunicationCount: 2, blockManagementSkippedReason: null },
        { absenceId: 44, status: "ERROR", error: "boom" },
      ],
    });

    renderDialog();
    await userEvent.click(screen.getByRole("button", { name: "Analyser" }));
    await screen.findByText("Etienne Willemart");
    await userEvent.click(screen.getByRole("button", { name: /Traiter les absences sélectionnées/ }));
    await screen.findByText(/Des emails seront réellement envoyés/);
    await userEvent.click(screen.getByRole("button", { name: "Traiter" }));

    expect(await screen.findByText(/1 erreur/)).toBeInTheDocument();
    expect(screen.getByText(/Absence #44 : boom/)).toBeInTheDocument();
  });

  it("affiche un toast d'erreur si la preview échoue", async () => {
    vi.mocked(api.previewAbsenceCommunicationBackfill).mockRejectedValue(new Error("network error"));

    renderDialog();
    await userEvent.click(screen.getByRole("button", { name: "Analyser" }));

    await waitFor(() => expect(toastError).toHaveBeenCalled());
  });
});
