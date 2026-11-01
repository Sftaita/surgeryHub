import { describe, it, expect, vi, beforeEach } from "vitest";
import { cleanup, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import AddInterventionDialog, { RECENT_FIRMS_TITLE, RECENT_TYPES_TITLE } from "./AddInterventionDialog";
import EditInterventionDialog from "./EditInterventionDialog";
import SearchablePicker, { type PickerItem } from "./SearchablePicker";
import type { MissionEncodingInterventionEntry } from "../api/encoding.types";

const apiGetMock = vi.fn();
/** D-178 — entrée fixe « Aucune firme » en tête de la liste des firmes (libellé + précision). */
const NO_FIRM_OPTION = "Aucune firmeInterventions autorisées sans firme";

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
  },
}));

const TYPES = [
  { id: 1, code: "LCA", label: "Plastie du ligament croisé antérieur" },
  { id: 2, code: "PTG", label: "Prothèse totale de genou" },
  { id: 3, code: "OSTÉOTOMIE", label: "Ostéotomie tibiale" },
  { id: 4, code: "LATARJET", label: "Butée coracoïdienne" },
];
const FIRMS = [
  { id: 10, name: "Arthrex", active: true },
  { id: 11, name: "Medacta", active: true },
  { id: 12, name: "Stryker", active: true },
  { id: 13, name: "Éthicon", active: true },
];
const CHOICE_GROUP = {
  id: 900,
  question: "Quel implant intersomatique a été utilisé ?",
  options: [
    { id: 1, label: "Signature", active: true },
    { id: 2, label: "Altera", active: true },
  ],
};

type Routes = {
  recentFirms?: Array<{ id: number; name: string }>;
  /** firmId (ou "none" = « Aucune firme ») → types récents renvoyés par le backend. */
  recentTypes?: Record<number | string, typeof TYPES>;
  /** firmId → prestations de la firme. */
  offerings?: Record<number, unknown[]>;
};

function route(routes: Routes) {
  apiGetMock.mockImplementation((url: string, config?: { params?: { firmId?: number | string } }) => {
    if (url.endsWith("/encoding-suggestions/firms")) return Promise.resolve({ data: { firms: routes.recentFirms ?? [] } });
    if (url.endsWith("/encoding-suggestions/intervention-types")) {
      const firmId = config?.params?.firmId as number | string;
      return Promise.resolve({ data: { firmId: firmId === "none" ? null : firmId, surgeonId: 7, interventionTypes: routes.recentTypes?.[firmId] ?? [] } });
    }
    const m = url.match(/\/api\/firms\/(\d+)\/service-offerings/);
    if (m) return Promise.resolve({ data: routes.offerings?.[Number(m[1])] ?? [] });
    return Promise.resolve({ data: {} });
  });
}

function suggestionCalls(kind: "firms" | "intervention-types") {
  return apiGetMock.mock.calls.filter(([url]) => String(url).endsWith(`/encoding-suggestions/${kind}`));
}

function client() {
  return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function renderAdd(props: Partial<React.ComponentProps<typeof AddInterventionDialog>> = {}) {
  const onSubmit = vi.fn();
  const qc = client();
  const ui = (p: Partial<React.ComponentProps<typeof AddInterventionDialog>>) => (
    <QueryClientProvider client={qc}>
      <AddInterventionDialog
        open
        loadingIntervention={false}
        loadingDraft={false}
        interventionTypes={TYPES}
        firms={FIRMS}
        existingCount={1}
        onClose={vi.fn()}
        onSubmit={onSubmit}
        onSubmitDraft={vi.fn()}
        missionId={42}
        surgeonId={7}
        {...p}
      />
    </QueryClientProvider>
  );
  const r = render(ui(props));
  return { onSubmit, rerender: (p: Partial<React.ComponentProps<typeof AddInterventionDialog>>) => r.rerender(ui({ ...props, ...p })) };
}

function recentGroup(title: string) {
  return screen.getByRole("group", { name: title });
}

beforeEach(() => {
  apiGetMock.mockReset();
  route({});
});

// ─────────────────────────────────────────────────────────────────────────
// Composant réutilisable
// ─────────────────────────────────────────────────────────────────────────

describe("SearchablePicker — recherche instantanée et raccourcis (D-178)", () => {
  const ITEMS: PickerItem[] = TYPES.map((t) => ({ id: t.id, primary: `${t.code} — ${t.label}` }));

  function renderPicker(props: Partial<React.ComponentProps<typeof SearchablePicker>> = {}) {
    const onSelect = vi.fn();
    render(
      <SearchablePicker
        id="t"
        searchLabel="Rechercher un type d'intervention"
        searchPlaceholder="…"
        items={ITEMS}
        recent={[]}
        recentTitle="Récents"
        allTitle="Toutes les interventions"
        emptyText="Aucune intervention ne correspond."
        onSelect={onSelect}
        {...props}
      />,
    );
    return { onSelect, input: screen.getByRole("combobox", { name: "Rechercher un type d'intervention" }) };
  }

  const visibleOptions = () => screen.getAllByRole("option").map((o) => o.textContent);

  it("recherche par code, par partie du libellé, sans casse ni accents, dès le premier caractère", async () => {
    const user = userEvent.setup();
    const { input } = renderPicker();

    await user.type(input, "l");
    expect(visibleOptions().length).toBeGreaterThan(0);

    await user.clear(input);
    await user.type(input, "lca");
    expect(visibleOptions()).toEqual(["LCA — Plastie du ligament croisé antérieur"]);

    await user.clear(input);
    await user.type(input, "LIGAMENT");
    expect(visibleOptions()).toEqual(["LCA — Plastie du ligament croisé antérieur"]);

    await user.clear(input);
    await user.type(input, "osteotomie");
    expect(visibleOptions()).toEqual(["OSTÉOTOMIE — Ostéotomie tibiale"]);

    await user.clear(input);
    await user.type(input, "coracoidienne");
    expect(visibleOptions()).toEqual(["LATARJET — Butée coracoïdienne"]);

    await user.clear(input);
    await user.type(input, "zzz");
    expect(screen.queryAllByRole("option")).toHaveLength(0);
    expect(screen.getByText("Aucune intervention ne correspond.")).toBeInTheDocument();
  });

  it("raccourcis en tête puis liste complète, sans doublon ; un raccourci absent de la liste n'est jamais proposé", () => {
    renderPicker({ recent: [ITEMS[2], { id: 99, primary: "INDISPONIBLE — retiré" }, ITEMS[0]] });

    expect(within(recentGroup("Récents")).getAllByRole("option").map((o) => o.textContent))
      .toEqual(["OSTÉOTOMIE — Ostéotomie tibiale", "LCA — Plastie du ligament croisé antérieur"]);
    expect(within(recentGroup("Toutes les interventions")).getAllByRole("option").map((o) => o.textContent))
      .toEqual(["PTG — Prothèse totale de genou", "LATARJET — Butée coracoïdienne"]);
    expect(screen.queryByText(/INDISPONIBLE/)).not.toBeInTheDocument();
  });

  it("liste complète entièrement couverte par les raccourcis : aucun en-tête vide", () => {
    renderPicker({ items: [ITEMS[0]], recent: [ITEMS[0]] });
    expect(screen.queryByRole("group", { name: "Toutes les interventions" })).not.toBeInTheDocument();
    expect(screen.getAllByRole("option")).toHaveLength(1);
  });

  it("pendant une recherche, une entrée récente n'apparaît qu'une fois", async () => {
    const user = userEvent.setup();
    const { input } = renderPicker({ recent: [ITEMS[0]] });
    await user.type(input, "a");
    const texts = visibleOptions();
    expect(texts.filter((t) => t?.startsWith("LCA"))).toHaveLength(1);
  });

  it("navigation clavier : ↓ puis Entrée sélectionne l'option active", async () => {
    const user = userEvent.setup();
    const { input, onSelect } = renderPicker({ recent: [ITEMS[3]] });
    await user.click(input);
    expect(input).toHaveAttribute("aria-activedescendant", "t-opt-recent-4");
    await user.keyboard("{ArrowDown}{ArrowDown}{ArrowUp}{ArrowDown}{Enter}");
    expect(onSelect).toHaveBeenCalledWith(2);
  });

  it("une sélection existante reste affichée ; « Changer » puis « Conserver » ne la modifie jamais", async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(
      <SearchablePicker id="t" label="Type d'intervention *" searchLabel="Rechercher" searchPlaceholder="…" items={ITEMS}
        recent={[ITEMS[1]]} recentTitle="Récents" allTitle="Toutes" emptyText="—" value={ITEMS[0]} onSelect={onSelect} />,
    );
    expect(screen.getByTestId("t-selected")).toHaveTextContent("LCA — Plastie du ligament croisé antérieur");
    expect(screen.queryByRole("option")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Changer — Type d'intervention" }));
    expect(screen.getAllByRole("option").length).toBeGreaterThan(0);
    await user.click(screen.getByRole("button", { name: /Conserver « LCA/ }));

    expect(screen.getByTestId("t-selected")).toHaveTextContent("LCA");
    expect(onSelect).not.toHaveBeenCalled();
  });
});

// ─────────────────────────────────────────────────────────────────────────
// Ajout d'une intervention
// ─────────────────────────────────────────────────────────────────────────

describe("AddInterventionDialog — firmes récemment utilisées (D-178)", () => {
  it("affiche les deux firmes récentes en tête, dans l'ordre du backend, sans doublon dans la liste complète", async () => {
    route({ recentFirms: [{ id: 12, name: "Stryker" }, { id: 10, name: "Arthrex" }] });
    renderAdd();

    await screen.findByRole("group", { name: RECENT_FIRMS_TITLE });
    expect(within(recentGroup(RECENT_FIRMS_TITLE)).getAllByRole("option").map((o) => o.textContent)).toEqual(["Stryker", "Arthrex"]);
    expect(within(recentGroup("Toutes les firmes")).getAllByRole("option").map((o) => o.textContent)).toEqual([NO_FIRM_OPTION, "Medacta", "Éthicon"]);
    expect(suggestionCalls("firms")[0][0]).toBe("/api/missions/42/encoding-suggestions/firms");
  });

  it("historique vide : aucun bloc récent, la liste complète reste disponible", async () => {
    renderAdd();
    await waitFor(() => expect(suggestionCalls("firms")).toHaveLength(1));
    expect(screen.queryByRole("group", { name: RECENT_FIRMS_TITLE })).not.toBeInTheDocument();
    expect(screen.getAllByRole("option")).toHaveLength(5); // « Aucune firme » + 4 firmes
  });

  it("une firme récente désactivée côté catalogue n'est pas proposée", async () => {
    route({ recentFirms: [{ id: 11, name: "Medacta" }] });
    renderAdd({ firms: FIRMS.map((f) => (f.id === 11 ? { ...f, active: false } : f)) });
    await waitFor(() => expect(suggestionCalls("firms")).toHaveLength(1));
    expect(screen.queryByRole("option", { name: "Medacta" })).not.toBeInTheDocument();
  });

  it("recherche de firme insensible aux accents (« ethi » → Éthicon)", async () => {
    const user = userEvent.setup();
    renderAdd();
    await user.type(screen.getByLabelText("Rechercher une firme"), "ethi");
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["Éthicon"]);
  });

  it("relues à chaque ouverture : une firme encodée entre-temps apparaît dès la réouverture", async () => {
    const qc = client();
    const ui = (open: boolean) => (
      <QueryClientProvider client={qc}>
        <AddInterventionDialog open={open} loadingIntervention={false} loadingDraft={false} interventionTypes={TYPES} firms={FIRMS}
          existingCount={1} onClose={vi.fn()} onSubmit={vi.fn()} onSubmitDraft={vi.fn()} missionId={42} surgeonId={7} />
      </QueryClientProvider>
    );
    const r = render(ui(true));
    await waitFor(() => expect(suggestionCalls("firms")).toHaveLength(1));
    expect(screen.queryByRole("group", { name: RECENT_FIRMS_TITLE })).not.toBeInTheDocument();

    r.rerender(ui(false));
    route({ recentFirms: [{ id: 10, name: "Arthrex" }] }); // intervention enregistrée avec Arthrex
    r.rerender(ui(true));
    await screen.findByRole("group", { name: RECENT_FIRMS_TITLE });
    expect(within(recentGroup(RECENT_FIRMS_TITLE)).getAllByRole("option").map((o) => o.textContent)).toEqual(["Arthrex"]);
    expect(suggestionCalls("firms")).toHaveLength(2);
  });

  it("changement de compte : les firmes récentes du compte précédent ne sont jamais réaffichées", async () => {
    const qc = client(); // même cache applicatif, comme dans l'application (déconnexion sans rechargement)
    const ui = (
      <QueryClientProvider client={qc}>
        <AddInterventionDialog open loadingIntervention={false} loadingDraft={false} interventionTypes={TYPES} firms={FIRMS}
          existingCount={1} onClose={vi.fn()} onSubmit={vi.fn()} onSubmitDraft={vi.fn()} missionId={42} surgeonId={7} />
      </QueryClientProvider>
    );
    route({ recentFirms: [{ id: 12, name: "Stryker" }] }); // compte A
    const first = render(ui);
    await screen.findByRole("group", { name: RECENT_FIRMS_TITLE });
    first.unmount(); // déconnexion : la page d'encodage est démontée
    await new Promise((r) => setTimeout(r, 0)); // le formulaire de connexion du compte B : au moins un tour de boucle

    // Compte B : sa réponse n'est pas encore arrivée.
    apiGetMock.mockImplementation((url: string) => (String(url).endsWith("/encoding-suggestions/firms") ? new Promise(() => {}) : Promise.resolve({ data: [] })));
    render(ui);
    await waitFor(() => expect(suggestionCalls("firms")).toHaveLength(2));
    expect(screen.queryByRole("group", { name: RECENT_FIRMS_TITLE })).not.toBeInTheDocument();
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual([NO_FIRM_OPTION, "Arthrex", "Medacta", "Stryker", "Éthicon"]);
  });

  it("sans mission (aucun contexte), aucune suggestion n'est demandée", async () => {
    renderAdd({ missionId: undefined });
    await screen.findByText("Étape 1/2 – Avec quelle firme cette intervention a-t-elle été réalisée ?");
    expect(suggestionCalls("firms")).toHaveLength(0);
  });
});

describe("AddInterventionDialog — interventions récemment utilisées avec cette firme et ce chirurgien (D-178)", () => {
  async function pickFirmFromList(user: ReturnType<typeof userEvent.setup>, name: string) {
    await user.click(screen.getByRole("option", { name }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
  }

  it("demande les types récents pour la firme choisie et les affiche en tête", async () => {
    const user = userEvent.setup();
    route({ recentTypes: { 10: [TYPES[2], TYPES[0]] } });
    renderAdd();
    await pickFirmFromList(user, "Arthrex");

    await screen.findByRole("group", { name: RECENT_TYPES_TITLE });
    expect(within(recentGroup(RECENT_TYPES_TITLE)).getAllByRole("option").map((o) => o.textContent))
      .toEqual(["OSTÉOTOMIE — Ostéotomie tibiale", "LCA — Plastie du ligament croisé antérieur"]);
    expect(suggestionCalls("intervention-types")[0]).toEqual(["/api/missions/42/encoding-suggestions/intervention-types", { params: { firmId: 10 } }]);
  });

  it("changement de firme : suggestions recalculées pour la nouvelle firme", async () => {
    const user = userEvent.setup();
    route({ recentTypes: { 10: [TYPES[0]], 11: [TYPES[1]] } });
    renderAdd();
    await pickFirmFromList(user, "Arthrex");
    await screen.findByRole("group", { name: RECENT_TYPES_TITLE });

    await user.click(screen.getByRole("button", { name: "Changer" }));
    await pickFirmFromList(user, "Medacta");

    await waitFor(() => expect(within(recentGroup(RECENT_TYPES_TITLE)).getAllByRole("option").map((o) => o.textContent))
      .toEqual(["PTG — Prothèse totale de genou"]));
    expect(suggestionCalls("intervention-types").map(([, c]) => c.params.firmId)).toEqual([10, 11]);
  });

  it("changement de chirurgien de la mission : suggestions redemandées", async () => {
    const user = userEvent.setup();
    route({ recentTypes: { 10: [TYPES[0]] } });
    const { rerender } = renderAdd();
    await pickFirmFromList(user, "Arthrex");
    await waitFor(() => expect(suggestionCalls("intervention-types")).toHaveLength(1));

    rerender({ surgeonId: 8 });
    await waitFor(() => expect(suggestionCalls("intervention-types")).toHaveLength(2));
  });

  it("un type récent absent des prestations actives de la firme n'est pas proposé", async () => {
    const user = userEvent.setup();
    route({
      recentTypes: { 10: [TYPES[1], TYPES[0]] },
      offerings: { 10: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: false, choiceGroup: null }] },
    });
    renderAdd();
    await pickFirmFromList(user, "Arthrex");

    await screen.findByRole("group", { name: RECENT_TYPES_TITLE });
    expect(within(recentGroup(RECENT_TYPES_TITLE)).getAllByRole("option").map((o) => o.textContent))
      .toEqual(["LCA — Plastie du ligament croisé antérieur"]);
    expect(screen.queryByText("PTG — Prothèse totale de genou")).not.toBeInTheDocument();
  });

  it("aucun historique : liste normale du catalogue, pas de bloc récent", async () => {
    const user = userEvent.setup();
    renderAdd();
    await pickFirmFromList(user, "Arthrex");
    await waitFor(() => expect(suggestionCalls("intervention-types")).toHaveLength(1));
    expect(screen.queryByRole("group", { name: RECENT_TYPES_TITLE })).not.toBeInTheDocument();
    expect(screen.getAllByRole("option")).toHaveLength(TYPES.length);
  });

  it("intervention récente avec choix exclusifs A/B : même question, choix obligatoire, un seul choix envoyé", async () => {
    const user = userEvent.setup();
    route({
      recentTypes: { 10: [TYPES[0]] },
      offerings: { 10: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: false, choiceGroup: CHOICE_GROUP }] },
    });
    const { onSubmit } = renderAdd();
    await pickFirmFromList(user, "Arthrex");

    const recent = await screen.findByRole("group", { name: RECENT_TYPES_TITLE });
    await user.click(within(recent).getByRole("option", { name: "LCA — Plastie du ligament croisé antérieur" }));

    expect(await screen.findByText(`${CHOICE_GROUP.question} *`)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Signature" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Altera" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ajouter" })).toBeDisabled();

    await user.click(screen.getByRole("button", { name: "Signature" }));
    await user.click(screen.getByRole("button", { name: "Altera" })); // B remplace A, jamais les deux
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(onSubmit).toHaveBeenCalledTimes(1);
    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 1, primaryFirmId: 10, orderIndex: 1, selectedChoiceOptionId: 2 });
  });

  it("intervention récente avec présence du délégué requise : question posée et bloquante, comme depuis le catalogue", async () => {
    const user = userEvent.setup();
    route({
      recentTypes: { 10: [TYPES[0]] },
      offerings: { 10: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: true, choiceGroup: null }] },
    });
    renderAdd();
    await pickFirmFromList(user, "Arthrex");
    const recent = await screen.findByRole("group", { name: RECENT_TYPES_TITLE });
    await user.click(within(recent).getByRole("option", { name: /^LCA/ }));

    expect(await screen.findByText("Un délégué de Arthrex était-il présent ?")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ajouter" })).toBeDisabled();
  });

  it("raccourcis (firme + intervention) et liste complète produisent exactement le même envoi", async () => {
    const offerings = { 10: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: false, choiceGroup: CHOICE_GROUP }] };
    const user = userEvent.setup();

    // 1. Depuis les raccourcis.
    route({ recentFirms: [{ id: 10, name: "Arthrex" }], recentTypes: { 10: [TYPES[0]] }, offerings });
    const viaRecent = renderAdd();
    await user.click(within(await screen.findByRole("group", { name: RECENT_FIRMS_TITLE })).getByRole("option", { name: "Arthrex" }));
    await user.click(within(await screen.findByRole("group", { name: RECENT_TYPES_TITLE })).getByRole("option", { name: /^LCA/ }));
    await user.click(await screen.findByRole("button", { name: "Altera" }));
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    cleanup();

    // 2. Depuis la recherche et la liste complète, sans aucun historique.
    route({ offerings });
    const viaCatalog = renderAdd();
    await user.type(screen.getByLabelText("Rechercher une firme"), "arth");
    await user.click(screen.getByRole("option", { name: "Arthrex" }));
    await user.type(await screen.findByLabelText("Rechercher un type d'intervention"), "ligament");
    await user.click(screen.getByRole("option", { name: /^LCA/ }));
    await user.click(await screen.findByRole("button", { name: "Altera" }));
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(viaRecent.onSubmit.mock.calls[0]).toEqual(viaCatalog.onSubmit.mock.calls[0]);
    expect(viaCatalog.onSubmit).toHaveBeenCalledWith({ interventionTypeId: 1, primaryFirmId: 10, orderIndex: 1, selectedChoiceOptionId: 2 });
  });
});

// ─────────────────────────────────────────────────────────────────────────
// Correction d'une intervention existante
// ─────────────────────────────────────────────────────────────────────────

describe("EditInterventionDialog — correction avec raccourcis (D-178)", () => {
  function intervention(overrides: Partial<MissionEncodingInterventionEntry> = {}): MissionEncodingInterventionEntry {
    return {
      kind: "INTERVENTION", id: 5, requestId: null, orderIndex: 0, label: "LCA",
      interventionType: TYPES[0], firm: { id: 10, name: "Arthrex" }, requestedFirmNameSnapshot: null,
      status: "CATALOGUED", readOnly: false, materialLines: [], materialItemRequests: [],
      representativePresent: null, selectedChoiceOptionId: 1,
      ...overrides,
    } as MissionEncodingInterventionEntry;
  }

  function renderEdit(entry = intervention()) {
    const onSubmit = vi.fn();
    render(
      <QueryClientProvider client={client()}>
        <EditInterventionDialog open loading={false} intervention={entry} interventionTypes={TYPES} firms={FIRMS}
          onClose={vi.fn()} onSubmit={onSubmit} missionId={42} surgeonId={7} />
      </QueryClientProvider>,
    );
    return { onSubmit };
  }

  const offerings = { 10: [{ id: 501, interventionType: TYPES[0], active: true, representativePresenceRelevant: false, choiceGroup: CHOICE_GROUP }] };

  it("l'ouverture conserve firme, type et choix enregistrés ; les suggestions ne remplacent rien", async () => {
    const user = userEvent.setup();
    route({ recentFirms: [{ id: 12, name: "Stryker" }], recentTypes: { 10: [TYPES[1]] }, offerings });
    const { onSubmit } = renderEdit();

    await waitFor(() => expect(suggestionCalls("intervention-types")).toHaveLength(1));
    expect(screen.getByTestId("edit-itv-firm-selected")).toHaveTextContent("Arthrex");
    expect(screen.getByTestId("edit-itv-type-selected")).toHaveTextContent("LCA — Plastie du ligament croisé antérieur");
    expect(screen.queryByRole("option")).not.toBeInTheDocument();

    await user.click(await screen.findByRole("button", { name: "Enregistrer" }));
    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 1, primaryFirmId: 10, representativePresent: null, selectedChoiceOptionId: 1 });
  });

  it("choisir une intervention récente pendant la correction repasse par les mêmes règles (choix à refaire)", async () => {
    const user = userEvent.setup();
    route({
      recentTypes: { 10: [TYPES[1]] },
      offerings: { 10: [...offerings[10], { id: 502, interventionType: TYPES[1], active: true, representativePresenceRelevant: false, choiceGroup: { ...CHOICE_GROUP, id: 901 } }] },
    });
    const { onSubmit } = renderEdit();

    await user.click(screen.getByRole("button", { name: "Changer — Type d'intervention" }));
    const recent = await screen.findByRole("group", { name: RECENT_TYPES_TITLE });
    await user.click(within(recent).getByRole("option", { name: /^PTG/ }));

    expect(screen.getByTestId("edit-itv-type-selected")).toHaveTextContent("PTG");
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "Signature" }));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 2, primaryFirmId: 10, representativePresent: null, selectedChoiceOptionId: 1 });
  });

  it("re-choisir la même firme ne réinitialise pas le choix enregistré", async () => {
    const user = userEvent.setup();
    route({ recentFirms: [{ id: 10, name: "Arthrex" }], offerings });
    const { onSubmit } = renderEdit();

    await user.click(screen.getByRole("button", { name: "Changer — Firme principale" }));
    const recent = await screen.findByRole("group", { name: RECENT_FIRMS_TITLE });
    await user.click(within(recent).getByRole("option", { name: "Arthrex" }));
    await user.click(await screen.findByRole("button", { name: "Enregistrer" }));

    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 1, primaryFirmId: 10, representativePresent: null, selectedChoiceOptionId: 1 });
  });
});

// ─────────────────────────────────────────────────────────────────────────
// D-178 — « Aucune firme »
// ─────────────────────────────────────────────────────────────────────────

/** Types 3 et 4 autorisés sans firme par le manager (Catalogue › Interventions). */
const TYPES_WITH_NO_FIRM = TYPES.map((t) => (t.id === 3 || t.id === 4 ? { ...t, allowedWithoutFirm: true } : t));

describe("AddInterventionDialog — « Aucune firme » (D-178)", () => {
  async function pickNoFirm(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole("option", { name: /^Aucune firme/ }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
  }

  it("n'affiche que les types autorisés sans firme, jamais tout le catalogue, et demande les récents « sans firme »", async () => {
    const user = userEvent.setup();
    route({ recentTypes: { none: [TYPES_WITH_NO_FIRM[3]] } });
    renderAdd({ interventionTypes: TYPES_WITH_NO_FIRM });
    await pickNoFirm(user);

    await screen.findByRole("group", { name: RECENT_TYPES_TITLE });
    expect(within(recentGroup(RECENT_TYPES_TITLE)).getAllByRole("option").map((o) => o.textContent)).toEqual(["LATARJET — Butée coracoïdienne"]);
    expect(within(recentGroup("Interventions autorisées sans firme")).getAllByRole("option").map((o) => o.textContent)).toEqual(["OSTÉOTOMIE — Ostéotomie tibiale"]);
    expect(screen.queryByRole("option", { name: /^LCA/ })).not.toBeInTheDocument();
    expect(suggestionCalls("intervention-types")[0]).toEqual(["/api/missions/42/encoding-suggestions/intervention-types", { params: { firmId: "none" } }]);
    expect(apiGetMock.mock.calls.some(([url]) => String(url).includes("/service-offerings"))).toBe(false);
  });

  it("encode sans firme : withoutFirm explicite, jamais de firme ni de question délégué", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderAdd({ interventionTypes: TYPES_WITH_NO_FIRM });
    await pickNoFirm(user);
    await user.click(screen.getByRole("option", { name: "OSTÉOTOMIE — Ostéotomie tibiale" }));
    expect(screen.queryByText(/délégué/)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 3, withoutFirm: true, orderIndex: 1 });
  });

  it("aucun type autorisé : rien n'est proposé et la demande au manager reste la seule voie", async () => {
    const user = userEvent.setup();
    const onSubmitDraft = vi.fn();
    renderAdd({ interventionTypes: TYPES, onSubmitDraft });
    await pickNoFirm(user);
    expect(screen.getByText("Aucune intervention autorisée sans firme ne correspond.")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Intervention absente du catalogue" }));
    await user.type(screen.getByLabelText(/Type d'intervention souhaité/), "Plastie du quadriceps");
    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));
    expect(onSubmitDraft).toHaveBeenCalledWith(expect.objectContaining({ label: "Plastie du quadriceps", withoutFirm: true }));
    expect(onSubmitDraft.mock.calls[0][0]).not.toHaveProperty("requestedFirmId");
    expect(onSubmitDraft.mock.calls[0][0]).not.toHaveProperty("proposedFirmName");
  });

  it("revenir à une firme lève « Aucune firme »", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderAdd({ interventionTypes: TYPES_WITH_NO_FIRM });
    await pickNoFirm(user);
    await user.click(screen.getByRole("button", { name: "Changer" }));
    await user.click(screen.getByRole("option", { name: "Arthrex" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
    await user.click(await screen.findByRole("option", { name: /^LCA/ }));
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ interventionTypeId: 1, primaryFirmId: 10 }));
    expect(onSubmit.mock.calls[0][0]).not.toHaveProperty("withoutFirm");
  });
});

describe("EditInterventionDialog — « Aucune firme » (D-178)", () => {
  function entry(overrides: Partial<MissionEncodingInterventionEntry> = {}): MissionEncodingInterventionEntry {
    return {
      kind: "INTERVENTION", id: 5, requestId: null, orderIndex: 0, label: "OSTÉOTOMIE",
      interventionType: TYPES_WITH_NO_FIRM[2], firm: { id: 10, name: "Arthrex" }, requestedFirmNameSnapshot: null,
      status: "CATALOGUED", readOnly: false, materialLines: [], materialItemRequests: [],
      representativePresent: null, selectedChoiceOptionId: null,
      ...overrides,
    } as MissionEncodingInterventionEntry;
  }

  function renderEditWith(e: MissionEncodingInterventionEntry, types = TYPES_WITH_NO_FIRM) {
    const onSubmit = vi.fn();
    render(
      <QueryClientProvider client={client()}>
        <EditInterventionDialog open loading={false} intervention={e} interventionTypes={types} firms={FIRMS}
          onClose={vi.fn()} onSubmit={onSubmit} missionId={42} surgeonId={7} />
      </QueryClientProvider>,
    );
    return { onSubmit };
  }

  it("passer à « Aucune firme » : réponse explicite envoyée, type conservé s'il est autorisé", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderEditWith(entry());
    await user.click(screen.getByRole("button", { name: "Changer — Firme principale" }));
    await user.click(screen.getByRole("option", { name: /^Aucune firme/ }));
    expect(screen.getByTestId("edit-itv-firm-selected")).toHaveTextContent("Aucune firme");

    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ interventionTypeId: 3, primaryFirmId: null, withoutFirm: true }));
  });

  it("passer à « Aucune firme » avec un type non autorisé : le type doit être re-choisi parmi les types autorisés", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderEditWith(entry({ interventionType: TYPES[0], label: "LCA" }));
    await user.click(screen.getByRole("button", { name: "Changer — Firme principale" }));
    await user.click(screen.getByRole("option", { name: /^Aucune firme/ }));

    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();
    const allowedGroup = recentGroup("Interventions autorisées sans firme");
    expect(within(allowedGroup).getAllByRole("option").map((o) => o.textContent)).toEqual(["OSTÉOTOMIE — Ostéotomie tibiale", "LATARJET — Butée coracoïdienne"]);
    await user.click(within(allowedGroup).getByRole("option", { name: "LATARJET — Butée coracoïdienne" }));
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ interventionTypeId: 4, primaryFirmId: null, withoutFirm: true }));
  });

  it("intervention déjà « Aucune firme » : enregistrer sans toucher la firme n'envoie aucune réponse (validité historique)", async () => {
    const user = userEvent.setup();
    // Autorisation retirée depuis par le manager : le type n'est plus autorisé dans le catalogue.
    const { onSubmit } = renderEditWith(entry({ firm: null, withoutFirm: true }), TYPES);
    expect(screen.getByTestId("edit-itv-firm-selected")).toHaveTextContent("Aucune firme");
    expect(screen.getByTestId("edit-itv-type-selected")).toHaveTextContent("OSTÉOTOMIE — Ostéotomie tibiale");

    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    const body = onSubmit.mock.calls[0][0];
    expect(body.interventionTypeId).toBe(3);
    expect(body).not.toHaveProperty("primaryFirmId");
    expect(body).not.toHaveProperty("withoutFirm");
  });

  it("ancienne intervention dont la firme manque : corrigeable sans firme inventée, jamais marquée « Aucune firme »", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderEditWith(entry({ firm: null, withoutFirm: false, interventionType: TYPES[0], label: "LCA" }), TYPES);
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    const body = onSubmit.mock.calls[0][0];
    expect(body.interventionTypeId).toBe(1);
    expect(body).not.toHaveProperty("primaryFirmId");
    expect(body).not.toHaveProperty("withoutFirm");
  });
});
