import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import InterventionsSection from "./InterventionsSection";
import type { EncodingIntervention, MissionEncodingInterventionEntry, MissionEncodingResponse } from "../api/encoding.types";

// Complément D-156 — liste unifiée d'une prestation multi-salles : une seule liste, chaque
// élément modifié sur sa propre mission, les nouvelles interventions sur la mission de référence.

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();
const apiPatchMock = vi.fn();
const apiDeleteMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
    patch: (...args: unknown[]) => apiPatchMock(...args),
    delete: (...args: unknown[]) => apiDeleteMock(...args),
  },
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

const REFERENCE = 674;
const HISTORICAL = 675;
const KEY = ["prestationEncoding", HISTORICAL] as const;
const ITEM = { id: 100, label: "FiberWire n°2", referenceCode: "FW-2", unit: "unité", isImplant: false, firm: { id: 10, name: "Arthrex" } };
const CATALOG = { firms: [{ id: 10, name: "Arthrex", active: true }], items: [ITEM], interventionTypes: [{ id: 1, code: "GEN01", label: "Coiffe" }] };

function entry(id: number, missionId: number, label: string, lines: MissionEncodingInterventionEntry["materialLines"] = []): MissionEncodingInterventionEntry {
  return {
    kind: "INTERVENTION", id, requestId: null, orderIndex: 0, label,
    interventionType: { id: 1, code: "GEN01", label }, firm: { id: 10, name: "Arthrex" }, requestedFirmNameSnapshot: null,
    status: "CATALOGUED", readOnly: false, materialLines: lines, materialItemRequests: [], version: 1, missionId,
  };
}

// Historique (mission 675) servi d'abord, puis la référence (674) : l'ordre du serveur.
const ENTRIES = [
  entry(11, HISTORICAL, "Ligament historique", [{ id: 101, missionInterventionId: 11, item: ITEM, quantity: "1.00", comment: "", version: 3 }]),
  entry(12, REFERENCE, "Ménisque du jour"),
];
const LEGACY: EncodingIntervention[] = [{
  id: 11, code: "GEN01", label: "Ligament historique", orderIndex: 0, interventionType: { id: 1, code: "GEN01", label: "x" },
  primaryFirm: { id: 10, name: "Arthrex" }, materialLines: [], suggestedMaterials: [ITEM],
  coherence: { hasNoMaterialLines: false, unusedSuggestedMaterialItemIds: [100], unexpectedMaterialItemIds: [], materialLineIdsFromOtherFirm: [] },
}];

function renderUnified() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const data = {
    mission: { id: REFERENCE, type: "BLOCK", status: "IN_PROGRESS", allowedActions: ["edit_encoding"] },
    entries: ENTRIES, interventions: LEGACY, interventionTypeRequests: [], catalog: CATALOG,
    progress: { interventionCount: 2, encodedInterventionCount: 1, materialLineCount: 1 },
    prestation: { size: 2, missionIds: [REFERENCE, HISTORICAL], referenceMissionId: REFERENCE, acceptsNewContent: true },
  } as unknown as MissionEncodingResponse;
  client.setQueryData(KEY, data);
  return render(
    <QueryClientProvider client={client}>
      <InterventionsSection
        missionId={REFERENCE}
        encodingQueryKey={KEY}
        canEdit
        entries={data.entries}
        legacyInterventions={data.interventions}
        catalog={CATALOG}
        canSubmit
        onValidate={() => undefined}
        progress={data.progress}
        prestationSize={2}
        submitLabel="Soumettre la prestation"
      />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset().mockResolvedValue({ data: {} });
  apiPostMock.mockReset().mockResolvedValue({ data: { id: 999, missionInterventionId: 11, item: ITEM, quantity: "1.00", comment: "", version: 1 } });
  apiPatchMock.mockReset().mockResolvedValue({ data: { id: 101, missionInterventionId: 11, item: ITEM, quantity: "2.00", comment: "", version: 4 } });
  apiDeleteMock.mockReset().mockResolvedValue({ data: undefined });
});

describe("InterventionsSection — liste unifiée d'une prestation multi-salles (complément D-156)", () => {
  it("affiche toutes les interventions de la prestation dans une seule liste, compteurs = interventions affichées", () => {
    renderUnified();

    expect(screen.getByText("Ligament historique")).toBeInTheDocument();
    expect(screen.getByText("Ménisque du jour")).toBeInTheDocument();
    expect(screen.getByText(/2 interventions · 1 matériel/)).toBeInTheDocument();
    expect(screen.getAllByText("1 / 2").length).toBeGreaterThan(0);
    expect(screen.getAllByRole("button", { name: /nouvelle intervention/i })).toHaveLength(1);
    expect(screen.queryByText(/Encodage antérieur/)).not.toBeInTheDocument();
    expect(screen.queryByText(/salle|Mission \d/i)).not.toBeInTheDocument();
  });

  it("modifie le matériel d'une intervention historique sur SA mission, jamais sur la référence", async () => {
    const user = userEvent.setup();
    renderUnified();

    await user.click(screen.getByText("Ligament historique"));
    await user.click(await screen.findByRole("button", { name: "Augmenter la quantité de FiberWire n°2" }));

    await waitFor(() => expect(apiPatchMock).toHaveBeenCalled());
    const [url, body, config] = apiPatchMock.mock.calls[0];
    expect(url).toBe(`/api/missions/${HISTORICAL}/material-lines/101`);
    expect(body).toEqual({ quantity: "2" });
    expect(JSON.stringify(config)).toContain("3");
  });

  it("ajoute du matériel à une intervention historique sur sa mission d'origine", async () => {
    const user = userEvent.setup();
    renderUnified();

    await user.click(screen.getByText("Ligament historique"));
    const row = (await screen.findByText(/Suggéré — Arthrex/)).parentElement!.parentElement!;
    await user.click(within(row).getByText("Ajouter"));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalled());
    const [url, body] = apiPostMock.mock.calls[0];
    expect(url).toBe(`/api/missions/${HISTORICAL}/material-lines`);
    expect(body).toMatchObject({ missionInterventionId: 11, itemId: 100 });
  });
});
