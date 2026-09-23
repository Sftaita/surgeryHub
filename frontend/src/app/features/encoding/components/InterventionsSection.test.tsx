import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider, useQuery } from "@tanstack/react-query";
import InterventionsSection from "./InterventionsSection";
import type {
  EncodingIntervention,
  MissionEncodingEntry,
  MissionEncodingInterventionEntry,
  MissionEncodingDraftEntry,
} from "../api/encoding.types";

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

const toastErrorMock = vi.fn();

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: toastErrorMock, warning: vi.fn() }),
}));

const MISSION_ID = 42;

const CATALOG = {
  firms: [{ id: 10, name: "Arthrex", active: true }],
  items: [
    {
      id: 100,
      label: "FiberWire n°2",
      referenceCode: "FW-2",
      unit: "unité",
      isImplant: false,
      firm: { id: 10, name: "Arthrex" },
    },
  ],
  interventionTypes: [{ id: 1, code: "GEN01", label: "Réparation coiffe des rotateurs" }],
};

function makeInterventionEntry(overrides: Partial<MissionEncodingInterventionEntry> = {}): MissionEncodingInterventionEntry {
  return {
    kind: "INTERVENTION",
    id: 1,
    requestId: null,
    orderIndex: 0,
    label: "Réparation coiffe des rotateurs",
    interventionType: { id: 1, code: "GEN01", label: "Réparation coiffe des rotateurs" },
    firm: null,
    requestedFirmNameSnapshot: null,
    status: "CATALOGUED",
    readOnly: false,
    materialLines: [],
    materialItemRequests: [],
    ...overrides,
  };
}

function makeDraftEntry(overrides: Partial<MissionEncodingDraftEntry> = {}): MissionEncodingDraftEntry {
  return {
    kind: "DRAFT",
    id: 17,
    requestId: 28,
    orderIndex: 1,
    label: "Reconstruction LCL",
    interventionType: null,
    firm: null,
    requestedFirmNameSnapshot: null,
    status: "OPEN",
    readOnly: false,
    materialLines: [],
    materialItemRequests: [],
    ...overrides,
  };
}

/** Dérive la vue "legacy" (interventions[]) depuis les entrées INTERVENTION, pour que
 *  l'enrichissement Lot 6 (suggestedMaterials/coherence) reste cohérent avec le matériel
 *  réellement porté par l'entrée — jamais une seconde copie divergente. */
function deriveLegacy(entries: MissionEncodingEntry[], extras: Record<number, Partial<EncodingIntervention>> = {}): EncodingIntervention[] {
  return entries
    .filter((e): e is MissionEncodingInterventionEntry => e.kind === "INTERVENTION")
    .map((e) => ({
      id: e.id,
      code: e.interventionType?.code ?? "",
      label: e.label,
      orderIndex: e.orderIndex,
      interventionType: e.interventionType,
      primaryFirm: e.firm,
      materialLines: e.materialLines,
      materialItemRequests: e.materialItemRequests,
      suggestedMaterials: [],
      coherence: { hasNoMaterialLines: e.materialLines.length === 0, unusedSuggestedMaterialItemIds: [], unexpectedMaterialItemIds: [], materialLineIdsFromOtherFirm: [] },
      ...extras[e.id],
    }));
}

/**
 * InterventionsSection reads its `entries` prop from its parent's
 * `["missionEncoding", missionId]` query cache (MissionEncodingPage) — mutations write
 * to that cache, and `invalidate()` then forces a real refetch of it. This tiny in-memory
 * fake server — mutated by the mocked apiClient.post/patch/delete, read back by
 * apiClient.get — mirrors what the real backend does, so a refetch after a mutation
 * genuinely reflects it (exactly like production), instead of clobbering the optimistic
 * UI with a stale fixture.
 */
let serverEntries: MissionEncodingEntry[] = [];
let legacyExtras: Record<number, Partial<EncodingIntervention>> = {};
let nextServerId = 900;

/** Simule `progress` tel que le backend le calcule (EncodingTrackingRepository, D-118) :
 *  interventions RÉELLES uniquement, "encodée" = au moins une ligne à quantité > 0. */
function serverProgress(entries: MissionEncodingEntry[]) {
  const active = (e: MissionEncodingEntry) => (e.materialLines ?? []).filter((l) => parseFloat(l.quantity) > 0).length;
  const real = entries.filter((e) => e.kind === "INTERVENTION");
  return {
    interventionCount: real.length,
    encodedInterventionCount: real.filter((e) => active(e) > 0).length,
    materialLineCount: entries.reduce((sum, e) => sum + active(e), 0),
  };
}

function installFakeServer(initial: MissionEncodingEntry[], extras: Record<number, Partial<EncodingIntervention>> = {}) {
  serverEntries = initial;
  legacyExtras = extras;
  nextServerId = 900;

  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${MISSION_ID}/encoding`) {
      return Promise.resolve({
        data: {
          mission: { id: MISSION_ID, type: "BLOCK", status: "ASSIGNED", allowedActions: ["encoding"] },
          entries: serverEntries,
          interventions: deriveLegacy(serverEntries, legacyExtras),
          interventionTypeRequests: [],
          catalog: CATALOG,
          progress: serverProgress(serverEntries),
        },
      });
    }
    // AddInterventionDialog interroge les prestations de la firme choisie (parcours
    // "firme puis intervention") — vide par défaut, aucune prestation configurée dans
    // ces fixtures de test : le repli sur le catalogue complet doit s'appliquer.
    if (url.includes("/service-offerings")) {
      return Promise.resolve({ data: [] });
    }
    return Promise.resolve({ data: {} });
  });

  apiPostMock.mockImplementation((url: string, body: any) => {
    if (url === `/api/missions/${MISSION_ID}/material-lines`) {
      const item = CATALOG.items.find((i) => i.id === body.itemId)!;
      const created = {
        id: ++nextServerId,
        missionInterventionId: body.missionInterventionId ?? null,
        interventionDraftId: body.interventionDraftId ?? null,
        item: { id: item.id, label: item.label, referenceCode: item.referenceCode, unit: item.unit, isImplant: item.isImplant, firm: item.firm },
        quantity: body.quantity,
        comment: body.comment ?? "",
      };
      const targetKind = body.missionInterventionId != null ? "INTERVENTION" : "DRAFT";
      const targetId = body.missionInterventionId ?? body.interventionDraftId;
      serverEntries = serverEntries.map((e) =>
        e.kind === targetKind && e.id === targetId
          ? { ...e, materialLines: [...e.materialLines, created] }
          : e,
      );
      return Promise.resolve({ data: created });
    }
    if (url === `/api/missions/${MISSION_ID}/material-item-requests`) {
      const created = {
        id: ++nextServerId,
        label: body.label,
        referenceCode: body.referenceCode ?? null,
        comment: body.comment ?? null,
        status: "PENDING",
      };
      const targetKind = body.missionInterventionId != null ? "INTERVENTION" : "DRAFT";
      const targetId = body.missionInterventionId ?? body.interventionDraftId;
      serverEntries = serverEntries.map((e) =>
        e.kind === targetKind && e.id === targetId
          ? { ...e, materialItemRequests: [...e.materialItemRequests, created] }
          : e,
      );
      return Promise.resolve({ data: created });
    }
    if (url === `/api/missions/${MISSION_ID}/interventions`) {
      const type = CATALOG.interventionTypes.find((t) => t.id === body.interventionTypeId) ?? null;
      const firm = body.primaryFirmId != null ? CATALOG.firms.find((f) => f.id === body.primaryFirmId) ?? null : null;
      const id = ++nextServerId;
      const created: MissionEncodingInterventionEntry = {
        kind: "INTERVENTION", id, requestId: null, orderIndex: body.orderIndex,
        label: type?.label ?? "", interventionType: type, firm: firm ? { id: firm.id, name: firm.name } : null,
        requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false,
        materialLines: [], materialItemRequests: [],
      };
      serverEntries = [...serverEntries, created];
      return Promise.resolve({ data: { id } });
    }
    if (url === `/api/missions/${MISSION_ID}/intervention-type-requests`) {
      const requestId = ++nextServerId;
      const draftId = ++nextServerId;
      const firm = body.requestedFirmId != null ? CATALOG.firms.find((f) => f.id === body.requestedFirmId) ?? null : null;
      const created: MissionEncodingDraftEntry = {
        kind: "DRAFT", id: draftId, requestId, orderIndex: serverEntries.length, label: body.label,
        interventionType: null, firm: firm ? { id: firm.id, name: firm.name } : null,
        requestedFirmNameSnapshot: firm?.name ?? null, status: "OPEN", readOnly: false,
        materialLines: [], materialItemRequests: [],
      };
      serverEntries = [...serverEntries, created];
      return Promise.resolve({
        data: { id: requestId, draftId, orderIndex: created.orderIndex, label: created.label, requestedFirm: created.firm },
      });
    }
    return Promise.resolve({ data: {} });
  });

  apiDeleteMock.mockImplementation((url: string) => {
    const m = url.match(/\/material-lines\/(\d+)$/);
    if (m) {
      const id = Number(m[1]);
      serverEntries = serverEntries.map((e) => ({
        ...e,
        materialLines: e.materialLines.filter((l) => l.id !== id),
      }));
    }
    return Promise.resolve({ data: undefined });
  });

  apiPatchMock.mockImplementation((url: string, body: any) => {
    const m = url.match(/\/material-lines\/(\d+)$/);
    if (m) {
      const id = Number(m[1]);
      let updated: any = null;
      serverEntries = serverEntries.map((e) => ({
        ...e,
        materialLines: e.materialLines.map((l) => {
          if (l.id !== id) return l;
          updated = { ...l, quantity: body.quantity ?? l.quantity, comment: body.comment ?? l.comment };
          return updated;
        }),
      }));
      return Promise.resolve({ data: updated });
    }
    return Promise.resolve({ data: {} });
  });
}

function Harness({ canEdit }: { canEdit: boolean }) {
  const { data } = useQuery({
    queryKey: ["missionEncoding", MISSION_ID],
    queryFn: async () => {
      const { data } = await apiGetMock(`/api/missions/${MISSION_ID}/encoding`);
      return data;
    },
  });
  // Comme MissionEncodingPage : la section n'est rendue qu'une fois l'encodage chargé.
  if (!data) return null;
  return (
    <InterventionsSection
      missionId={MISSION_ID}
      canEdit={canEdit}
      entries={data?.entries ?? []}
      legacyInterventions={data?.interventions ?? []}
      catalog={CATALOG}
      canSubmit={false}
      onValidate={() => {}}
      progress={data.progress}
    />
  );
}

function renderSection(entries: MissionEncodingEntry[], canEdit = true, extras: Record<number, Partial<EncodingIntervention>> = {}) {
  installFakeServer(entries, extras);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <Harness canEdit={canEdit} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  apiPatchMock.mockReset();
  apiDeleteMock.mockReset();
  toastErrorMock.mockReset();
});

describe("InterventionsSection — état vide", () => {
  it("affiche l'état vide fidèle à la maquette et un seul bouton d'ajout persistant", async () => {
    renderSection([]);

    expect(await screen.findByText("Aucune intervention encodée")).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /nouvelle intervention/i })).toHaveLength(1);
  });

  it("n'affiche jamais une progression « 0 / 0 — Mission complète » sans intervention", async () => {
    renderSection([]);

    await screen.findByText("Aucune intervention encodée");
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
    expect(screen.queryByText(/Mission complète/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Tout est encodé/)).not.toBeInTheDocument();
  });
});

describe("InterventionsSection — compteurs alignés sur le suivi manager (D-118)", () => {
  // Cas réel de la mission #1 : interventions réelles + draft OPEN + draft KEPT_AS_HISTORY
  // portant du matériel. Le manager voit "N interventions" = interventions réelles ; la
  // page instrumentiste doit afficher le même N, les drafts restant visibles mais hors décompte.
  const line = (id: number, quantity: string, target: { missionInterventionId: number | null; interventionDraftId: number | null }) => ({
    id, ...target, item: { id: 100, label: "FiberWire n°2", referenceCode: "FW-2", unit: "unité", isImplant: false, firm: { id: 10, name: "Arthrex" } }, quantity, comment: "",
  });

  it("affiche le compteur et la progression du backend, pas le nombre d'entrées de la liste", async () => {
    renderSection([
      makeInterventionEntry({ id: 1, orderIndex: 0, label: "Prothèse totale de genou", materialLines: [line(1, "1.00", { missionInterventionId: 1, interventionDraftId: null })] }),
      makeInterventionEntry({ id: 2, orderIndex: 1, label: "Réparation coiffe", materialLines: [] }),
      makeDraftEntry({ id: 17, orderIndex: 2, label: "Ablation matériel rachis" }),
      makeDraftEntry({ id: 18, orderIndex: 3, label: "Ostéosynthèse poignet", status: "KEPT_AS_HISTORY", readOnly: true, materialLines: [line(2, "3.00", { missionInterventionId: null, interventionDraftId: 18 })] }),
    ]);

    // 4 entrées listées, mais 2 interventions (même définition que le manager), 1 encodée.
    expect(await screen.findByText("2 interventions · 2 matériels · 2 hors décompte")).toBeInTheDocument();
    expect(screen.getByRole("progressbar")).toHaveAttribute("aria-valuemax", "2");
    expect(screen.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "1");
    expect(screen.getAllByText("Hors décompte")).toHaveLength(2);
    // Aucun draft n'est présenté comme "à compléter" : ils ne pèsent pas sur la progression.
    expect(screen.getAllByText("À compléter")).toHaveLength(1);
  });

  it("une ligne à quantité 0 ne rend pas une intervention « Complété » (même critère « active » que le backend)", async () => {
    renderSection([makeInterventionEntry({ materialLines: [line(3, "0.00", { missionInterventionId: 1, interventionDraftId: null })] })]);

    expect(await screen.findByText("À compléter")).toBeInTheDocument();
    expect(screen.queryByText("Complété")).not.toBeInTheDocument();
    expect(screen.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "0");
  });
});

describe("InterventionsSection — rendu d'une intervention réelle", () => {
  it("affiche le nom brut de l'intervention, sans préfixe artificiel", async () => {
    renderSection([makeInterventionEntry({ label: "Réparation coiffe des rotateurs" })]);

    expect(await screen.findByText("Réparation coiffe des rotateurs")).toBeInTheDocument();
    expect(screen.queryByText(/Intervention 1/)).not.toBeInTheDocument();
  });

  it("reste modifiable/supprimable (comportement existant conservé)", async () => {
    renderSection([makeInterventionEntry()]);
    await screen.findByText("Réparation coiffe des rotateurs");

    expect(screen.getByText("Modifier l'intervention")).toBeInTheDocument();
    // Nouveau design (option 2b) : corbeille icône seule, plus de lien texte "Supprimer".
    expect(screen.getByRole("button", { name: "Supprimer l'intervention" })).toBeInTheDocument();
  });
});

describe("InterventionsSection — rendu d'un draft OPEN", () => {
  it("affiche le libellé, la firme demandée et un badge 'en attente de validation'", async () => {
    renderSection([makeDraftEntry({ label: "Reconstruction LCL", firm: { id: 10, name: "Arthrex" }, requestedFirmNameSnapshot: "Arthrex" })]);

    expect(await screen.findByText("Reconstruction LCL")).toBeInTheDocument();
    expect(screen.getByText("Arthrex")).toBeInTheDocument();
    expect(screen.getByText("En attente de validation manager")).toBeInTheDocument();
  });

  it("draft sans firme affiche un état explicite plutôt qu'un vide silencieux", async () => {
    renderSection([makeDraftEntry({ firm: null, requestedFirmNameSnapshot: null })]);

    await screen.findByText("Reconstruction LCL");
    expect(screen.getByText("Sans firme")).toBeInTheDocument();
  });

  it("un draft OPEN reste modifiable : le bouton Ajouter du matériel est proposé", async () => {
    renderSection([makeDraftEntry()]);
    await screen.findByText("Reconstruction LCL");

    expect(screen.getByRole("button", { name: "Ajouter du matériel" })).toBeInTheDocument();
  });

  it("n'affiche jamais Modifier/Supprimer l'intervention sur un draft (workflow manager hors périmètre)", async () => {
    renderSection([makeDraftEntry()]);
    await screen.findByText("Reconstruction LCL");

    expect(screen.queryByText("Modifier l'intervention")).not.toBeInTheDocument();
    expect(screen.queryByText("Supprimer")).not.toBeInTheDocument();
  });
});

describe("InterventionsSection — ordre entrelacé intervention/draft", () => {
  it("trie la liste unifiée par orderIndex, sans regrouper par type, puis l'affiche inversée (la plus récente en haut — DOCUMENTATION §1)", async () => {
    renderSection([
      makeInterventionEntry({ id: 1, orderIndex: 0, label: "Intervention A" }),
      makeDraftEntry({ id: 17, requestId: 28, orderIndex: 1, label: "Draft B" }),
      makeInterventionEntry({ id: 2, orderIndex: 2, label: "Intervention C" }),
    ]);

    await screen.findByText("Intervention A");
    const headings = screen.getAllByText(/^(Intervention A|Draft B|Intervention C)$/);
    // Liste anti-chronologique (option 2b) : la dernière créée (orderIndex le plus haut)
    // s'affiche en premier — les numéros de rang restent chronologiques, jamais l'ordre
    // d'affichage.
    expect(headings.map((h) => h.textContent)).toEqual(["Intervention C", "Draft B", "Intervention A"]);
  });
});

describe("InterventionsSection — draft KEPT_AS_HISTORY (lecture seule)", () => {
  function historyDraft(overrides: Partial<MissionEncodingDraftEntry> = {}) {
    return makeDraftEntry({
      status: "KEPT_AS_HISTORY",
      readOnly: true,
      materialLines: [
        {
          id: 501,
          missionInterventionId: null,
          interventionDraftId: 17,
          item: { id: 100, label: "FiberWire n°2", referenceCode: "FW-2", unit: "unité", isImplant: false, firm: { id: 10, name: "Arthrex" } },
          quantity: "2.00",
          comment: "",
        },
      ],
      ...overrides,
    });
  }

  it("affiche son contenu avec un libellé explicite 'Conservé comme historique'", async () => {
    renderSection([historyDraft()]);

    await screen.findByText("Reconstruction LCL");
    expect(screen.getByText("Conservé comme historique")).toBeInTheDocument();
    expect(screen.getByText("FiberWire n°2")).toBeInTheDocument();
  });

  it("masque tous les boutons de création/modification/suppression", async () => {
    renderSection([historyDraft()]);

    await screen.findByText("FiberWire n°2");
    expect(screen.queryByRole("button", { name: "Ajouter du matériel" })).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/augmenter la quantité/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/diminuer la quantité/i)).not.toBeInTheDocument();
    // La quantité reste lisible, juste non éditable (pas de stepper). displayQty()
    // retire les décimales inutiles ("2.00" -> "2"), même formatage que pour une
    // intervention réelle en lecture seule.
    expect(screen.getByText("2 unité")).toBeInTheDocument();
  });

  it("le clic sur la ligne matériel n'ouvre pas l'édition (aucune mutation possible)", async () => {
    const user = userEvent.setup();
    renderSection([historyDraft()]);

    await screen.findByText("FiberWire n°2");
    await user.click(screen.getByText("FiberWire n°2"));

    expect(screen.queryByText("Modifier la ligne matériel")).not.toBeInTheDocument();
  });
});

describe("InterventionsSection — matériels suggérés (Lot 6, via legacyInterventions)", () => {
  const withSuggestion = () =>
    makeInterventionEntry({ firm: { id: 10, name: "Arthrex" } });
  const suggestionExtras = {
    1: {
      suggestedMaterials: [
        { id: 100, label: "FiberWire n°2", referenceCode: "FW-2", unit: "unité", isImplant: false, firm: { id: 10, name: "Arthrex" } },
      ],
      coherence: { hasNoMaterialLines: true, unusedSuggestedMaterialItemIds: [100], unexpectedMaterialItemIds: [], materialLineIdsFromOtherFirm: [] },
    },
  };

  it("affiche un matériel suggéré non encore ajouté avec un bouton Ajouter direct", async () => {
    renderSection([withSuggestion()], true, suggestionExtras);

    expect(await screen.findByText("Suggéré — Arthrex")).toBeInTheDocument();
  });

  it("ajouter un matériel suggéré appelle directement material-lines avec missionInterventionId", async () => {
    const user = userEvent.setup();
    renderSection([withSuggestion()], true, suggestionExtras);

    await screen.findByText("Suggéré — Arthrex");
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        `/api/missions/${MISSION_ID}/material-lines`,
        expect.objectContaining({ missionInterventionId: 1, itemId: 100, quantity: "1" }),
      );
    });
  });
});

describe("InterventionsSection — lignes de matériel", () => {
  const withMaterial = () =>
    makeInterventionEntry({
      materialLines: [
        {
          id: 501,
          missionInterventionId: 1,
          interventionDraftId: null,
          item: { id: 100, label: "FiberWire n°2", referenceCode: "FW-2", unit: "unité", isImplant: false, firm: { id: 10, name: "Arthrex" } },
          quantity: "2.00",
          comment: "",
        },
      ],
    });

  it("affiche la quantité au format x{N} avec un stepper inline ±1", async () => {
    renderSection([withMaterial()]);

    expect(await screen.findByText("x2")).toBeInTheDocument();
    expect(screen.getByLabelText(/diminuer la quantité de fiberwire/i)).toBeInTheDocument();
  });

  it("le bouton + du stepper inline ajuste la quantité sans ouvrir la modale d'édition", async () => {
    const user = userEvent.setup();
    renderSection([withMaterial()]);

    await screen.findByText("x2");
    await user.click(screen.getByLabelText(/augmenter la quantité de fiberwire/i));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith(
        `/api/missions/${MISSION_ID}/material-lines/501`,
        { quantity: "3" },
      );
    });
    expect(screen.queryByText("Modifier la ligne matériel")).not.toBeInTheDocument();
  });

  it("supprime explicitement via la confirmation, jamais en passant la quantité à zéro", async () => {
    const user = userEvent.setup();
    renderSection([withMaterial()]);

    await user.click(await screen.findByRole("button", { name: /modifier fiberwire/i }));
    const editLineTitle = await screen.findByText("Modifier la ligne matériel");
    const editLineDialog = editLineTitle.closest('[role="dialog"]') as HTMLElement;
    await user.click(within(editLineDialog).getByRole("button", { name: "Supprimer" }));

    const confirmDialogTitle = await screen.findByText("Supprimer le matériel ?");
    const dialog = confirmDialogTitle.closest('[role="dialog"]') as HTMLElement;
    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));

    await waitFor(() => {
      expect(apiDeleteMock).toHaveBeenCalledWith(`/api/missions/${MISSION_ID}/material-lines/501`);
    });
    expect(apiPatchMock).not.toHaveBeenCalled();
  });
});

describe("InterventionsSection — création optimiste d'une intervention réelle", () => {
  async function openAddInterventionAndPickCatalogType(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole("button", { name: /nouvelle intervention/i }));
    await screen.findByText("Étape 1/2 – Choisir une firme");
    await user.click(screen.getByRole("button", { name: "— Aucune firme (voir tout le catalogue)" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
    await user.click(screen.getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("GEN01 — Réparation coiffe des rotateurs"));
  }

  it("insère immédiatement une entrée temporaire avec le bon orderIndex, remplacée après succès", async () => {
    const user = userEvent.setup();
    renderSection([]);
    await screen.findByText("Aucune intervention encodée");

    let resolvePost: () => void = () => {};
    apiPostMock.mockImplementationOnce((_url: string, body: any) => new Promise((resolve) => {
      resolvePost = () => {
        const created = { id: 999, kind: "INTERVENTION" };
        serverEntries = [...serverEntries, {
          kind: "INTERVENTION", id: 999, requestId: null, orderIndex: body.orderIndex,
          label: "Réparation coiffe des rotateurs", interventionType: CATALOG.interventionTypes[0], firm: null,
          requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false, materialLines: [], materialItemRequests: [],
        }];
        resolve({ data: { id: created.id } });
      };
    }));

    await openAddInterventionAndPickCatalogType(user);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    // Optimiste : la ligne apparaît immédiatement, avant toute réponse serveur.
    expect(await screen.findByText("Réparation coiffe des rotateurs")).toBeInTheDocument();
    expect(screen.getByText("Enregistrement…")).toBeInTheDocument();
    // Le compteur reflète l'état serveur (progress, D-118) : il ne bouge qu'une fois la
    // création confirmée, jamais sur une entrée provisoire.
    expect(screen.getByText("0 intervention · 0 matériel")).toBeInTheDocument();

    resolvePost();

    await waitFor(() => expect(screen.queryByText("Enregistrement…")).not.toBeInTheDocument());
    expect(await screen.findByText("1 intervention · 0 matériel")).toBeInTheDocument();
    expect(screen.getByText("Réparation coiffe des rotateurs")).toBeInTheDocument();
    // Aucun doublon après le refetch déclenché par invalidate().
    expect(screen.getAllByText("Réparation coiffe des rotateurs")).toHaveLength(1);
  });

  it("rollback complet si la création échoue", async () => {
    const user = userEvent.setup();
    renderSection([]);
    await screen.findByText("Aucune intervention encodée");

    apiPostMock.mockImplementationOnce(() => Promise.reject(new Error("Network Error")));

    await openAddInterventionAndPickCatalogType(user);
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    await waitFor(() => expect(toastErrorMock).toHaveBeenCalled());
    expect(screen.queryByText("Réparation coiffe des rotateurs")).not.toBeInTheDocument();
    expect(screen.getByText("Aucune intervention encodée")).toBeInTheDocument();
  });
});

describe("InterventionsSection — création optimiste d'un draft (intervention absente du catalogue)", () => {
  async function openAddInterventionAndRequestDraft(user: ReturnType<typeof userEvent.setup>, label: string) {
    await user.click(screen.getByRole("button", { name: /nouvelle intervention/i }));
    await screen.findByText("Étape 1/2 – Choisir une firme");
    await user.click(screen.getByRole("button", { name: "Arthrex" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
    await user.click(screen.getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("Intervention absente du catalogue"));
    await user.type(await screen.findByLabelText("Type d'intervention souhaité *"), label);
  }

  it("affiche immédiatement le libellé et la firme choisie, remplace l'entrée après succès serveur", async () => {
    const user = userEvent.setup();
    renderSection([]);
    await screen.findByText("Aucune intervention encodée");

    let resolvePost: () => void = () => {};
    apiPostMock.mockImplementationOnce((_url: string, body: any) => new Promise((resolve) => {
      resolvePost = () => {
        const requestId = 777;
        const draftId = 778;
        const created = { kind: "DRAFT" as const, id: draftId, requestId, orderIndex: 0, label: body.label, interventionType: null, firm: { id: 10, name: "Arthrex" }, requestedFirmNameSnapshot: "Arthrex", status: "OPEN" as const, readOnly: false, materialLines: [], materialItemRequests: [] };
        serverEntries = [...serverEntries, created];
        resolve({ data: { id: requestId, draftId, orderIndex: 0, label: body.label, requestedFirm: { id: 10, name: "Arthrex" } } });
      };
    }));

    await openAddInterventionAndRequestDraft(user, "Prothèse épaule inversée");
    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));

    expect(await screen.findByText("Prothèse épaule inversée")).toBeInTheDocument();
    // "Arthrex" apparaît aussi dans le résumé de firme du sheet encore en cours de
    // fermeture (animation SheetModal) : au moins une occurrence suffit à prouver que
    // l'entrée optimiste porte bien la firme choisie.
    expect(screen.getAllByText("Arthrex").length).toBeGreaterThan(0);
    expect(screen.getByText("Enregistrement…")).toBeInTheDocument();
    expect(screen.getByText("En attente de validation manager")).toBeInTheDocument();

    resolvePost();

    await waitFor(() => expect(screen.queryByText("Enregistrement…")).not.toBeInTheDocument());
    expect(screen.getAllByText("Prothèse épaule inversée")).toHaveLength(1);
  });

  it("rollback complet si l'envoi de la demande échoue", async () => {
    const user = userEvent.setup();
    renderSection([]);
    await screen.findByText("Aucune intervention encodée");

    apiPostMock.mockImplementationOnce(() => Promise.reject(new Error("Network Error")));

    await openAddInterventionAndRequestDraft(user, "Prothèse épaule inversée");
    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));

    await waitFor(() => expect(toastErrorMock).toHaveBeenCalled());
    expect(screen.queryByText("Prothèse épaule inversée")).not.toBeInTheDocument();
    expect(screen.getByText("Aucune intervention encodée")).toBeInTheDocument();
  });
});

describe("InterventionsSection — ajout optimiste de matériel sur un draft", () => {
  async function openWizardOnDraft(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole("button", { name: "Ajouter du matériel" }));
    await screen.findByText("Étape 1/3 – Choisir une marque");
    await user.click(screen.getAllByRole("button", { name: "Arthrex" })[0]);
    await screen.findByText("Étape 2/3 – Rechercher un matériel");
  }

  it("MaterialLine : transmet interventionDraftId, jamais missionInterventionId", async () => {
    const user = userEvent.setup();
    renderSection([makeDraftEntry()]);
    await screen.findByText("Reconstruction LCL");

    await openWizardOnDraft(user);
    await user.click(screen.getByRole("button", { name: /ajouter fiberwire/i }));
    await screen.findByText("Étape 3/3 – Détails du matériel");
    await user.click(screen.getByRole("button", { name: "Ajouter à l'intervention" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        `/api/missions/${MISSION_ID}/material-lines`,
        expect.objectContaining({ interventionDraftId: 17, missionInterventionId: undefined, itemId: 100 }),
      );
    });
    // Optimiste : la ligne apparaît sous le draft avant la réponse serveur.
    expect(await screen.findByText("FiberWire n°2")).toBeInTheDocument();
  });

  it("MaterialItemRequest : transmet interventionDraftId pour une demande hors catalogue depuis un draft", async () => {
    const user = userEvent.setup();
    renderSection([makeDraftEntry()]);
    await screen.findByText("Reconstruction LCL");

    await openWizardOnDraft(user);
    await user.click(screen.getByRole("button", { name: "Je ne trouve pas le matériel" }));
    await screen.findByText("Déclarer un matériel manquant");
    await user.type(screen.getByLabelText("Nom du matériel *"), "Ancre titane 4.5mm");
    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        `/api/missions/${MISSION_ID}/material-item-requests`,
        expect.objectContaining({ interventionDraftId: 17, missionInterventionId: undefined, label: "Ancre titane 4.5mm" }),
      );
    });
    expect(await screen.findByText("Ancre titane 4.5mm")).toBeInTheDocument();
  });

  it("MaterialLine sur un draft : rollback complet si l'ajout échoue", async () => {
    const user = userEvent.setup();
    renderSection([makeDraftEntry()]);
    await screen.findByText("Reconstruction LCL");

    apiPostMock.mockImplementationOnce(() => Promise.reject(new Error("Network Error")));

    await openWizardOnDraft(user);
    await user.click(screen.getByRole("button", { name: /ajouter fiberwire/i }));
    await screen.findByText("Étape 3/3 – Détails du matériel");
    await user.click(screen.getByRole("button", { name: "Ajouter à l'intervention" }));

    await waitFor(() => expect(toastErrorMock).toHaveBeenCalled());
    // Le wizard porte lui-même "FiberWire n°2" dans son récapitulatif d'étape 3 (SheetModal
    // ferme avec une animation de 250ms, voir CLOSE_DURATION_MS) : attendre la disparition
    // de ce récapitulatif (texte unique au wizard — "Ajouter du matériel" seul est ambigu,
    // c'est aussi le libellé du bouton persistant du draft) avant de vérifier l'absence de
    // toute ligne matériel, sinon un résidu du dialogue en cours de fermeture produirait un
    // faux négatif.
    await waitFor(() => expect(screen.queryByText("Étape 3/3 – Détails du matériel")).not.toBeInTheDocument());
    expect(screen.queryByText("FiberWire n°2")).not.toBeInTheDocument();
    expect(screen.getByText("Aucun matériel encodé")).toBeInTheDocument();
  });

  it("un draft n'est jamais compté comme intervention ; les compteurs suivent `progress` du serveur", async () => {
    const user = userEvent.setup();
    renderSection([makeDraftEntry()]);
    await screen.findByText("Reconstruction LCL");
    // Même définition que le suivi manager (D-118) : 0 intervention réelle, le draft reste
    // visible mais explicitement "hors décompte".
    expect(screen.getByText("0 intervention · 0 matériel · 1 hors décompte")).toBeInTheDocument();
    expect(screen.getByText("Hors décompte")).toBeInTheDocument();

    await openWizardOnDraft(user);
    await user.click(screen.getByRole("button", { name: /ajouter fiberwire/i }));
    await screen.findByText("Étape 3/3 – Détails du matériel");
    await user.click(screen.getByRole("button", { name: "Ajouter à l'intervention" }));

    // Le matériel du draft est actif (compté comme matériel), jamais comme intervention.
    expect(await screen.findByText("0 intervention · 1 matériel · 1 hors décompte")).toBeInTheDocument();
  });
});

describe("InterventionsSection — demande de matériel hors catalogue sur une intervention réelle (optimiste)", () => {
  async function openMaterialItemRequestDialog(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole("button", { name: "Ajouter du matériel" }));
    await screen.findByText("Étape 1/3 – Choisir une marque");
    const arthrexButtons = screen.getAllByRole("button", { name: "Arthrex" });
    await user.click(arthrexButtons[0]);
    await screen.findByText("Étape 2/3 – Rechercher un matériel");
    await user.click(screen.getByRole("button", { name: "Je ne trouve pas le matériel" }));
    await screen.findByText("Déclarer un matériel manquant");
  }

  it("affiche la ligne provisoire immédiatement avec 'Enregistrement…', puis 'À préciser' après confirmation, et transmet missionInterventionId", async () => {
    const user = userEvent.setup();
    renderSection([makeInterventionEntry()]);
    await screen.findByText("Réparation coiffe des rotateurs");

    let resolvePost: () => void = () => {};
    apiPostMock.mockImplementationOnce((_url: string, body: any) => new Promise((resolve) => {
      resolvePost = () => {
        const created = { id: 999, label: body.label, referenceCode: body.referenceCode ?? null, comment: body.comment ?? null, status: "PENDING" };
        serverEntries = serverEntries.map((e) =>
          e.kind === "INTERVENTION" && e.id === body.missionInterventionId
            ? { ...e, materialItemRequests: [...e.materialItemRequests, created] }
            : e,
        );
        resolve({ data: created });
      };
    }));

    await openMaterialItemRequestDialog(user);
    await user.type(screen.getByLabelText("Nom du matériel *"), "Ancre titane 4.5mm");
    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));

    expect(await screen.findByText("Ancre titane 4.5mm")).toBeInTheDocument();
    expect(screen.getByText("Enregistrement…")).toBeInTheDocument();

    resolvePost();

    await waitFor(() => expect(screen.getByText("À préciser")).toBeInTheDocument());
    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        `/api/missions/${MISSION_ID}/material-item-requests`,
        expect.objectContaining({ missionInterventionId: 1, interventionDraftId: undefined }),
      );
    });
  });

  it("retire la ligne provisoire si la demande échoue (rollback)", async () => {
    const user = userEvent.setup();
    renderSection([makeInterventionEntry()]);
    await screen.findByText("Réparation coiffe des rotateurs");

    apiPostMock.mockImplementationOnce(() => Promise.reject(new Error("Network Error")));

    await openMaterialItemRequestDialog(user);
    await user.type(screen.getByLabelText("Nom du matériel *"), "Ancre titane 4.5mm");
    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));

    await waitFor(() => expect(toastErrorMock).toHaveBeenCalled());
    expect(screen.queryByText("Ancre titane 4.5mm")).not.toBeInTheDocument();
    expect(screen.getByText("Aucun matériel encodé")).toBeInTheDocument();
  });
});
