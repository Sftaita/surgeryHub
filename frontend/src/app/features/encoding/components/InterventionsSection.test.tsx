import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider, useQuery } from "@tanstack/react-query";
import InterventionsSection from "./InterventionsSection";
import type { EncodingIntervention } from "../api/encoding.types";

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
};

function makeIntervention(overrides: Partial<EncodingIntervention> = {}): EncodingIntervention {
  return {
    id: 1,
    code: "GEN01",
    label: "Réparation coiffe des rotateurs",
    orderIndex: 0,
    materialLines: [],
    materialItemRequests: [],
    ...overrides,
  };
}

/**
 * InterventionsSection reads its `interventions` prop from its parent's
 * `["missionEncoding", missionId]` query cache (MissionEncodingPage) — mutations write
 * to that cache, and `invalidate()` then forces a real refetch of it. A static prop (or
 * a queryFn that just re-resolves a frozen fixture) would get clobbered by that
 * post-mutation refetch. This tiny in-memory fake server — mutated by the mocked
 * apiClient.post/patch/delete, read back by apiClient.get — mirrors what the real
 * backend does, so a refetch after a mutation genuinely reflects it (exactly like
 * production).
 */
let serverInterventions: EncodingIntervention[] = [];
let nextServerId = 900;

function installFakeServer(initial: EncodingIntervention[]) {
  serverInterventions = initial;
  nextServerId = 900;

  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${MISSION_ID}/encoding`) {
      return Promise.resolve({
        data: {
          mission: { id: MISSION_ID, type: "BLOCK", status: "ASSIGNED", allowedActions: ["encoding"] },
          interventions: serverInterventions,
          catalog: CATALOG,
        },
      });
    }
    return Promise.resolve({ data: {} });
  });

  apiPostMock.mockImplementation((url: string, body: any) => {
    if (url === `/api/missions/${MISSION_ID}/material-lines`) {
      const item = CATALOG.items.find((i) => i.id === body.itemId)!;
      const created = {
        id: ++nextServerId,
        missionInterventionId: body.missionInterventionId,
        item: { id: item.id, label: item.label, referenceCode: item.referenceCode, unit: item.unit, isImplant: item.isImplant, firm: item.firm },
        quantity: body.quantity,
        comment: body.comment ?? "",
      };
      serverInterventions = serverInterventions.map((itv) =>
        itv.id === body.missionInterventionId
          ? { ...itv, materialLines: [...(itv.materialLines ?? []), created] }
          : itv,
      );
      return Promise.resolve({ data: created });
    }
    return Promise.resolve({ data: {} });
  });

  apiDeleteMock.mockImplementation((url: string) => {
    const m = url.match(/\/material-lines\/(\d+)$/);
    if (m) {
      const id = Number(m[1]);
      serverInterventions = serverInterventions.map((itv) => ({
        ...itv,
        materialLines: (itv.materialLines ?? []).filter((l) => l.id !== id),
      }));
    }
    return Promise.resolve({ data: undefined });
  });

  apiPatchMock.mockImplementation(() => Promise.resolve({ data: {} }));
}

function Harness({ canEdit }: { canEdit: boolean }) {
  const { data } = useQuery({
    queryKey: ["missionEncoding", MISSION_ID],
    queryFn: async () => {
      const { data } = await apiGetMock(`/api/missions/${MISSION_ID}/encoding`);
      return data;
    },
  });
  return (
    <InterventionsSection missionId={MISSION_ID} canEdit={canEdit} interventions={data?.interventions ?? []} catalog={CATALOG} />
  );
}

function renderSection(interventions: EncodingIntervention[], canEdit = true) {
  installFakeServer(interventions);
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
});

describe("InterventionsSection — état vide", () => {
  it("affiche l'état vide fidèle à la maquette et un seul bouton d'ajout persistant", async () => {
    renderSection([]);

    expect(await screen.findByText("Aucune intervention encodée")).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /nouvelle intervention/i })).toHaveLength(1);
  });
});

describe("InterventionsSection — titre d'intervention", () => {
  it("affiche le nom brut de l'intervention, sans préfixe artificiel", async () => {
    renderSection([makeIntervention({ label: "Réparation coiffe des rotateurs" })]);

    expect(await screen.findByText("Réparation coiffe des rotateurs")).toBeInTheDocument();
    expect(screen.queryByText(/Intervention 1/)).not.toBeInTheDocument();
    expect(screen.queryByText(/—.*Réparation/)).not.toBeInTheDocument();
  });
});

describe("InterventionsSection — bouton persistant Nouvelle intervention", () => {
  it("reste visible et unique quand des interventions existent déjà", async () => {
    renderSection([makeIntervention(), makeIntervention({ id: 2, code: "GEN02", label: "Autre intervention" })]);

    await screen.findByText("Réparation coiffe des rotateurs");
    expect(screen.getAllByRole("button", { name: /nouvelle intervention/i })).toHaveLength(1);
  });
});

describe("InterventionsSection — lignes de matériel", () => {
  const withMaterial = () =>
    makeIntervention({
      materialLines: [
        {
          id: 501,
          missionInterventionId: 1,
          item: { id: 100, label: "FiberWire n°2", referenceCode: "FW-2", unit: "unité", isImplant: false, firm: { id: 10, name: "Arthrex" } },
          quantity: "2.00",
          comment: "",
        },
      ],
    });

  it("affiche la quantité au format x{N}, pas un stepper", async () => {
    renderSection([withMaterial()]);

    expect(await screen.findByText("x2")).toBeInTheDocument();
    expect(screen.queryByLabelText(/remove/i)).not.toBeInTheDocument();
  });

  it("ouvre l'édition au clic sur la ligne", async () => {
    const user = userEvent.setup();
    renderSection([withMaterial()]);

    await user.click(await screen.findByRole("button", { name: /modifier fiberwire/i }));

    expect(await screen.findByText("Modifier la ligne matériel")).toBeInTheDocument();
    expect(screen.getByDisplayValue("2.00")).toBeInTheDocument();
  });

  it("supprime explicitement via la confirmation, jamais en passant la quantité à zéro", async () => {
    const user = userEvent.setup();
    renderSection([withMaterial()]);

    await user.click(await screen.findByRole("button", { name: /modifier fiberwire/i }));
    await screen.findByText("Modifier la ligne matériel");

    await user.click(screen.getByRole("button", { name: "Supprimer" }));

    const confirmDialogTitle = await screen.findByText("Supprimer le matériel ?");
    const dialog = confirmDialogTitle.closest('[role="dialog"]') as HTMLElement;
    await user.click(within(dialog).getByRole("button", { name: "Supprimer" }));

    await waitFor(() => {
      expect(apiDeleteMock).toHaveBeenCalledWith(`/api/missions/${MISSION_ID}/material-lines/501`);
    });
    // Jamais de suppression implicite par quantité à zéro : aucun stepper n'existe pour le déclencher.
    expect(apiPatchMock).not.toHaveBeenCalled();
  });

  it("n'affiche pas le badge Nouveau sur une ligne déjà présente au chargement du brouillon", async () => {
    renderSection([withMaterial()]);
    await screen.findByText("x2");
    expect(screen.queryByText("Nouveau")).not.toBeInTheDocument();
  });

  it("affiche le badge Nouveau uniquement sur une ligne ajoutée pendant la session en cours", async () => {
    const user = userEvent.setup();
    renderSection([withMaterial()]);
    await screen.findByText("x2");

    // Pas de badge tant qu'aucun ajout n'a eu lieu.
    expect(screen.queryByText("Nouveau")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Ajouter du matériel" }));
    await screen.findByText("Étape 1/3 – Choisir une marque");

    // "Arthrex" apparaît à la fois en marque récente (déjà utilisée par la ligne
    // existante) et dans la liste complète — les deux mènent à la même marque.
    const arthrexButtons = screen.getAllByRole("button", { name: "Arthrex" });
    await user.click(arthrexButtons[0]);
    await screen.findByText("Étape 2/3 – Rechercher un matériel");
    await user.click(screen.getByRole("button", { name: /ajouter fiberwire/i }));
    await screen.findByText("Étape 3/3 – Détails du matériel");
    await user.click(screen.getByRole("button", { name: "Ajouter à l'intervention" }));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));

    // La ligne pré-existante (id 501) ne porte jamais le badge ; seule la nouvelle l'affiche.
    await waitFor(() => expect(screen.getAllByText("Nouveau")).toHaveLength(1));
    expect(screen.getByText("x2")).toBeInTheDocument();
    expect(screen.getByText("x1")).toBeInTheDocument();
  });
});
