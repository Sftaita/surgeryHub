import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import CatalogueRequestsPage from "./CatalogueRequestsPage";

const getMaterialRequestsMock = vi.fn();
const resolveMaterialRequestMock = vi.fn();
const ignoreMaterialRequestMock = vi.fn();
const getFirmsMock = vi.fn();
const getMaterialItemsMock = vi.fn();
const createMaterialItemMock = vi.fn();
const getDuplicateCandidatesMock = vi.fn();

vi.mock("../../features/manager-catalogue/api/catalogue.api", () => ({
  getMaterialRequestDuplicateCandidates: (...args: unknown[]) => getDuplicateCandidatesMock(...args),
  getMaterialRequests: (...args: unknown[]) => getMaterialRequestsMock(...args),
  resolveMaterialRequest: (...args: unknown[]) => resolveMaterialRequestMock(...args),
  ignoreMaterialRequest: (...args: unknown[]) => ignoreMaterialRequestMock(...args),
  getFirms: (...args: unknown[]) => getFirmsMock(...args),
  getMaterialItems: (...args: unknown[]) => getMaterialItemsMock(...args),
  createMaterialItem: (...args: unknown[]) => createMaterialItemMock(...args),
}));

const getInterventionTypeRequestsMock = vi.fn();
const resolveInterventionTypeRequestMock = vi.fn();
const ignoreInterventionTypeRequestMock = vi.fn();

vi.mock("../../features/manager-catalogue/api/interventionTypeRequests.api", () => ({
  getInterventionTypeRequests: (...args: unknown[]) => getInterventionTypeRequestsMock(...args),
  resolveInterventionTypeRequest: (...args: unknown[]) => resolveInterventionTypeRequestMock(...args),
  ignoreInterventionTypeRequest: (...args: unknown[]) => ignoreInterventionTypeRequestMock(...args),
}));

const getInterventionTypesMock = vi.fn();
const createInterventionTypeMock = vi.fn();

vi.mock("../../features/intervention-types/api/interventionTypes.api", () => ({
  getInterventionTypes: (...args: unknown[]) => getInterventionTypesMock(...args),
  createInterventionType: (...args: unknown[]) => createInterventionTypeMock(...args),
}));

const fetchFirmServiceOfferingsMock = vi.fn();
vi.mock("../../features/encoding/api/encoding.api", () => ({
  fetchFirmServiceOfferings: (...args: unknown[]) => fetchFirmServiceOfferingsMock(...args),
}));

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

function renderPage(initialEntries: string[] = ["/app/m/catalogue/requests"]) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const utils = render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={initialEntries}>
        <CatalogueRequestsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return { ...utils, client };
}

// jsdom n'implémente pas scrollIntoView — nécessaire pour le comportement de mise en
// évidence du deep-link (D-113).
beforeEach(() => {
  Element.prototype.scrollIntoView = vi.fn();
});

const materialRequest = {
  id: 10,
  status: "PENDING" as const,
  label: "Vis titane 4mm",
  referenceCode: "REF-10",
  comment: "Utilisée hors catalogue",
  createdAt: "2026-07-20T10:00:00Z",
  mission: { id: 501, site: "Clinique Saint-Jean" },
  requestedBy: { id: 20, displayName: "Ada Lovelace" },
  materialItem: null,
  ignoreReason: null,
  ignoreComment: null,
  decidedBy: null,
  decidedAt: null,
};

const interventionRequest = {
  id: 11,
  status: "PENDING" as const,
  label: "Prothèse épaule inversée",
  suggestedCode: "PEI",
  comment: null,
  createdAt: "2026-07-19T09:00:00Z",
  mission: { id: 502, site: "Clinique du Parc" },
  requestedBy: { id: 21, displayName: "Grace Hopper" },
  resolvedInterventionType: null,
  ignoreReason: null,
  ignoreComment: null,
  decidedBy: null,
  decidedAt: null,
};

beforeEach(() => {
  getMaterialRequestsMock.mockReset().mockResolvedValue({ items: [], total: 0 });
  resolveMaterialRequestMock.mockReset();
  ignoreMaterialRequestMock.mockReset();
  getFirmsMock.mockReset().mockResolvedValue([]);
  getMaterialItemsMock.mockReset().mockResolvedValue({ items: [], total: 0, page: 1, limit: 200 });
  createMaterialItemMock.mockReset();
  getDuplicateCandidatesMock.mockReset().mockResolvedValue([]);
  fetchFirmServiceOfferingsMock.mockReset().mockResolvedValue([]);
  getInterventionTypeRequestsMock.mockReset().mockResolvedValue({ items: [], total: 0 });
  resolveInterventionTypeRequestMock.mockReset();
  ignoreInterventionTypeRequestMock.mockReset();
  getInterventionTypesMock.mockReset().mockResolvedValue([]);
  createInterventionTypeMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("CatalogueRequestsPage — affichage", () => {
  it("affiche les demandes matériel", async () => {
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    expect(screen.getByText("Matériel")).toBeInTheDocument();
  });

  it("affiche les demandes de types d'intervention", async () => {
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [interventionRequest], total: 1 });
    renderPage();

    await screen.findByText("Prothèse épaule inversée");
    expect(screen.getByText("Intervention")).toBeInTheDocument();
  });

  it("affiche l'état vide quand aucune demande n'existe dans aucune catégorie", async () => {
    renderPage();

    await screen.findByText("Aucune demande.");
  });

  it("n'affiche aucune donnée patient — uniquement mission id/site et demandeur", async () => {
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [interventionRequest], total: 1 });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    // Seules les informations mission (#id, site) et demandeur (nom) apparaissent — aucun
    // champ patient (nom, date de naissance, numéro de dossier...) n'existe dans ce DTO.
    expect(screen.getByText("#501")).toBeInTheDocument();
    expect(screen.getByText("Clinique Saint-Jean")).toBeInTheDocument();
    expect(screen.getByText("Ada Lovelace")).toBeInTheDocument();
    expect(screen.queryByText(/patient/i)).toBeNull();
  });
});

describe("CatalogueRequestsPage — résolution matériel (D-146 bis, une seule requête atomique)", () => {
  const withFirm = {
    ...materialRequest,
    proposedFirm: { id: 1, name: "Arthrex" },
    proposedFirmName: null,
    quantity: "2.00",
    target: { kind: "INTERVENTION" as const, id: 70, label: "Bankart", firm: { id: 4, name: "Smith & Nephew" } },
    mission: { id: 501, site: "Clinique Saint-Jean", status: "SUBMITTED", locked: false, reopenAvailable: false },
  };

  it("affiche la firme proposée, la quantité et l'intervention, puis crée la référence avec la firme validée", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [withFirm], total: 1 });
    getFirmsMock.mockResolvedValue([{ id: 1, name: "Arthrex" }, { id: 4, name: "Smith & Nephew" }]);
    resolveMaterialRequestMock.mockResolvedValue({ request: { ...withFirm, status: "RESOLVED" }, materialLine: { id: 7, quantity: "2.00" }, createdFirm: false, createdItem: true });
    renderPage();

    const row = (await screen.findByText("Vis titane 4mm")).closest("tr")!;
    expect(within(row).getByText("Arthrex")).toBeInTheDocument();
    expect(within(row).getByText("Qté 2")).toBeInTheDocument();
    await user.click(within(row).getByRole("button", { name: "Résoudre" }));

    const dialog = await screen.findByRole("dialog", { name: "Résoudre la demande de matériel" });
    expect(within(dialog).getByText("Firme fabricante indiquée")).toBeInTheDocument();
    expect(within(dialog).getByText("Bankart · Smith & Nephew")).toBeInTheDocument();
    await waitFor(() => expect(within(dialog).getByLabelText(/Firme du matériel/)).toHaveValue("Arthrex"));
    expect(within(dialog).getByLabelText(/Quantité utilisée/)).toHaveValue("2");

    await user.click(within(dialog).getByRole("button", { name: "Créer et résoudre" }));

    await waitFor(() => expect(resolveMaterialRequestMock).toHaveBeenCalledWith(10, {
      quantity: "2",
      newItem: { label: "Vis titane 4mm", unit: "pièce", referenceCode: "REF-10", isImplant: false, firmId: 1 },
    }));
    expect(toastSuccess).toHaveBeenCalledWith("Demande résolue : matériel ajouté au catalogue (facturation à décider) et à la mission.");
  });

  it("firme proposée absente du catalogue : préremplie en création de firme, jamais une firme existante devinée", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [{ ...withFirm, proposedFirm: null, proposedFirmName: "Medacta" }], total: 1 });
    getFirmsMock.mockResolvedValue([{ id: 1, name: "Arthrex" }]);
    resolveMaterialRequestMock.mockResolvedValue({ request: withFirm, materialLine: { id: 7, quantity: "2.00" }, createdFirm: true, createdItem: true });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog", { name: "Résoudre la demande de matériel" });
    await waitFor(() => expect(within(dialog).getByLabelText(/nouvelle firme/)).toHaveValue("Medacta"));
    await user.click(within(dialog).getByRole("button", { name: "Créer et résoudre" }));

    await waitFor(() => expect(resolveMaterialRequestMock).toHaveBeenCalledWith(10, expect.objectContaining({
      newItem: expect.objectContaining({ newFirmName: "Medacta" }),
    })));
  });

  it("doublon possible signalé par le serveur : la modal reste ouverte, propose d'associer ou de confirmer", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [withFirm], total: 1 });
    getFirmsMock.mockResolvedValue([{ id: 1, name: "Arthrex" }]);
    resolveMaterialRequestMock
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: "MATERIAL_ITEM_POSSIBLE_DUPLICATE", message: "Un matériel semblable existe déjà.", candidates: [{ id: 42, label: "Vis titane 4 mm", referenceCode: "REF-10", firm: { id: 9, name: "Stryker" }, billingStatus: "BILLABLE", active: true }] } } } })
      .mockResolvedValueOnce({ request: withFirm, materialLine: { id: 7, quantity: "2.00" }, createdFirm: false, createdItem: true });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog", { name: "Résoudre la demande de matériel" });
    await waitFor(() => expect(within(dialog).getByLabelText(/Firme du matériel/)).toHaveValue("Arthrex"));
    await user.click(within(dialog).getByRole("button", { name: "Créer et résoudre" }));

    expect(await within(dialog).findByText("Un matériel semblable existe déjà.")).toBeInTheDocument();
    expect(within(dialog).getByText("Vis titane 4 mm")).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Créer quand même" }));

    await waitFor(() => expect(resolveMaterialRequestMock).toHaveBeenLastCalledWith(10, expect.objectContaining({ confirmPossibleDuplicate: true })));
  });

  it("mission validée : refus expliqué, réouverture proposée, aucune résolution possible", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [{ ...withFirm, mission: { id: 501, site: "Clinique Saint-Jean", status: "VALIDATED", locked: true, reopenAvailable: true } }], total: 1 });
    getFirmsMock.mockResolvedValue([{ id: 1, name: "Arthrex" }]);
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog", { name: "Résoudre la demande de matériel" });
    expect(within(dialog).getByText(/est validée : rien ne peut y être ajouté directement/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Rouvrez d'abord son encodage/)).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Créer et résoudre" })).toBeDisabled();
  });
});

describe("CatalogueRequestsPage — résolution type d'intervention (D-146 bis)", () => {
  const withFirm = {
    ...interventionRequest,
    requestedFirm: { id: 3, name: "Zimmer Biomet" },
    requestedFirmNameSnapshot: "Zimmer Biomet",
    proposedFirmName: null,
    draft: { id: 1, status: "OPEN", materialLineCount: 0, materialItemRequestCount: 0 },
    mission: { id: 502, site: "Clinique du Parc", status: "ASSIGNED", locked: false, reopenAvailable: false, interventions: [] },
  };
  const TYPE = { id: 5, code: "PEI", label: "Prothèse épaule inversée", specialty: null, active: true };

  async function pickType(user: ReturnType<typeof userEvent.setup>, dialog: HTMLElement) {
    await user.click(within(dialog).getByLabelText(/Intervention correspondante/));
    await user.click(await screen.findByRole("option", { name: /Prothèse épaule inversée \(PEI\)/ }));
  }

  it("préremplit la firme demandée et résout en associant un type existant dont la prestation existe", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [withFirm], total: 1 });
    getInterventionTypesMock.mockResolvedValue([TYPE]);
    getFirmsMock.mockResolvedValue([{ id: 3, name: "Zimmer Biomet" }]);
    fetchFirmServiceOfferingsMock.mockResolvedValue([{ id: 8, interventionType: TYPE, active: true, representativePresenceRelevant: false, choiceGroup: null }]);
    resolveInterventionTypeRequestMock.mockResolvedValue({ requestId: 11, draftId: 1, status: "RESOLVED", draftStatus: "CONVERTED", missionInterventionId: 77, offeringDecision: null });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog", { name: "Résoudre la demande d'intervention" });
    await waitFor(() => expect(within(dialog).getByLabelText(/Avec quelle firme/)).toHaveValue("Zimmer Biomet"));
    await pickType(user, dialog);
    await user.click(within(dialog).getByRole("button", { name: "Résoudre" }));

    await waitFor(() => expect(resolveInterventionTypeRequestMock).toHaveBeenCalledWith(11, { interventionTypeId: 5, firmId: 3 }));
    expect(toastSuccess).toHaveBeenCalledWith("Demande résolue. Intervention créée sur la mission.");
  });

  it("prestation absente : décision explicite obligatoire, jamais « Pas de forfait » par défaut", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [withFirm], total: 1 });
    getInterventionTypesMock.mockResolvedValue([TYPE]);
    getFirmsMock.mockResolvedValue([{ id: 3, name: "Zimmer Biomet" }]);
    fetchFirmServiceOfferingsMock.mockResolvedValue([]);
    resolveInterventionTypeRequestMock.mockResolvedValue({ requestId: 11, draftId: 1, status: "RESOLVED", draftStatus: "CONVERTED", missionInterventionId: 77, offeringDecision: "NO_FEE" });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog", { name: "Résoudre la demande d'intervention" });
    await waitFor(() => expect(within(dialog).getByLabelText(/Avec quelle firme/)).toHaveValue("Zimmer Biomet"));
    await pickType(user, dialog);

    expect(await within(dialog).findByText(/n'est pas encore configurée chez Zimmer Biomet/)).toBeInTheDocument();
    const radios = within(dialog).getAllByRole("radio");
    expect(radios.every((r) => !(r as HTMLInputElement).checked)).toBe(true);
    expect(within(dialog).getByRole("button", { name: "Résoudre" })).toBeDisabled();

    await user.click(within(dialog).getByLabelText(/Aucun forfait n'est prévu/));
    await user.click(within(dialog).getByRole("button", { name: "Résoudre" }));
    await waitFor(() => expect(resolveInterventionTypeRequestMock).toHaveBeenCalledWith(11, { interventionTypeId: 5, firmId: 3, offeringDecision: "NO_FEE" }));
  });

  it("délégué et système choisi exigés par la prestation : jamais répondus à la place du manager", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [withFirm], total: 1 });
    getInterventionTypesMock.mockResolvedValue([TYPE]);
    getFirmsMock.mockResolvedValue([{ id: 3, name: "Zimmer Biomet" }]);
    fetchFirmServiceOfferingsMock.mockResolvedValue([{
      id: 8, interventionType: TYPE, active: true, representativePresenceRelevant: true,
      choiceGroup: { id: 2, question: "Quel système ?", options: [{ id: 31, label: "Altera", active: true }, { id: 32, label: "Signature", active: true }] },
    }]);
    resolveInterventionTypeRequestMock.mockResolvedValue({ requestId: 11, draftId: 1, status: "RESOLVED", draftStatus: "CONVERTED", missionInterventionId: 77 });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog", { name: "Résoudre la demande d'intervention" });
    await waitFor(() => expect(within(dialog).getByLabelText(/Avec quelle firme/)).toHaveValue("Zimmer Biomet"));
    await pickType(user, dialog);

    expect(await within(dialog).findByText("Un délégué de Zimmer Biomet était-il présent ?")).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Résoudre" })).toBeDisabled();
    await user.click(within(dialog).getByLabelText("Non"));
    expect(within(dialog).getByRole("button", { name: "Résoudre" })).toBeDisabled();
    await user.click(within(dialog).getByLabelText("Altera"));
    await user.click(within(dialog).getByRole("button", { name: "Résoudre" }));

    await waitFor(() => expect(resolveInterventionTypeRequestMock).toHaveBeenCalledWith(11, {
      interventionTypeId: 5, firmId: 3, representativePresent: false, selectedChoiceOptionId: 31,
    }));
  });
});

describe("CatalogueRequestsPage — ignorance avec matériel déjà déclaré (D-146 bis)", () => {
  it("exige une stratégie explicite et transmet la réaffectation choisie", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [{
      ...interventionRequest,
      draft: { id: 1, status: "OPEN", materialLineCount: 2, materialItemRequestCount: 0 },
      mission: { id: 502, site: "Clinique du Parc", status: "ASSIGNED", locked: false, reopenAvailable: false, interventions: [{ id: 70, label: "Bankart", firm: "Smith & Nephew" }] },
    }], total: 1 });
    ignoreInterventionTypeRequestMock.mockResolvedValue({ requestId: 11, draftId: 1, status: "IGNORED", draftStatus: "MATERIAL_REASSIGNED", missionInterventionId: 70 });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Ignorer" }));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Demande en doublon" }));
    await user.type(within(dialog).getByLabelText("Explication"), "Déjà encodée sous Bankart.");

    expect(within(dialog).getByText(/Du matériel est déjà déclaré sur cette demande \(2 élément\(s\)\)/)).toBeInTheDocument();
    const confirm = within(dialog).getByRole("button", { name: "Ignorer la demande" });
    expect(confirm).toBeDisabled();

    await user.click(within(dialog).getByLabelText(/Le rattacher à une intervention existante/));
    expect(confirm).toBeDisabled();
    await user.click(within(dialog).getByText("Intervention de destination"));
    await user.click(await screen.findByRole("option", { name: "Bankart · Smith & Nephew" }));
    await user.click(confirm);

    await waitFor(() => expect(ignoreInterventionTypeRequestMock).toHaveBeenCalledWith({
      id: 11, reason: "DUPLICATE", comment: "Déjà encodée sous Bankart.", strategy: "REASSIGN", missionInterventionId: 70,
    }, expect.anything()));
  });
});

describe("CatalogueRequestsPage — ignorance (modal obligatoire, D-113)", () => {
  it("ouvre une modal au clic sur Ignorer, plutôt que d'agir immédiatement", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Ignorer" }));

    const dialog = await screen.findByRole("dialog");
    within(dialog).getByText("Ignorer cette demande ?");
    expect(ignoreMaterialRequestMock).not.toHaveBeenCalled();
  });

  it("désactive la validation tant que motif et explication ne sont pas renseignés", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Ignorer" }));
    const dialog = await screen.findByRole("dialog");

    const submit = within(dialog).getByRole("button", { name: "Ignorer la demande" });
    expect(submit).toBeDisabled();

    await user.click(within(dialog).getByText("Motif"));
    await user.click(await screen.findByRole("option", { name: "Matériel déjà existant" }));
    expect(submit).toBeDisabled();

    await user.type(within(dialog).getByLabelText("Explication"), "Déjà référencé sous REF-9.");
    expect(submit).toBeEnabled();
  });

  it("ignore une demande matériel avec motif + explication, retire la ligne, avertit le demandeur", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    ignoreMaterialRequestMock.mockResolvedValue({ ...materialRequest, status: "IGNORED" });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Ignorer" }));
    const dialog = await screen.findByRole("dialog");

    await user.click(within(dialog).getByText("Motif"));
    await user.click(await screen.findByRole("option", { name: "Matériel déjà existant" }));
    await user.type(within(dialog).getByLabelText("Explication"), "Déjà référencé sous REF-9.");
    await user.click(within(dialog).getByRole("button", { name: "Ignorer la demande" }));

    // mutationFn référence directement ignoreMaterialRequest (pas de wrapper) : TanStack
    // Query v5 lui passe un 2e argument de contexte interne — on ne vérifie que le
    // premier argument, celui réellement significatif pour l'appel API.
    await waitFor(() => expect(ignoreMaterialRequestMock.mock.calls[0]?.[0]).toEqual({
      id: 10,
      reason: "MATERIAL_ALREADY_EXISTS",
      comment: "Déjà référencé sous REF-9.",
    }));
    expect(toastSuccess).toHaveBeenCalledWith("Demande ignorée et demandeur averti.");
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
  });

  it("ignore une demande de type d'intervention avec motif + explication", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [interventionRequest], total: 1 });
    ignoreInterventionTypeRequestMock.mockResolvedValue({ requestId: 11, draftId: 1, status: "IGNORED", draftStatus: "IGNORED", missionInterventionId: null });
    renderPage();

    await screen.findByText("Prothèse épaule inversée");
    await user.click(screen.getByRole("button", { name: "Ignorer" }));
    const dialog = await screen.findByRole("dialog");

    await user.click(within(dialog).getByText("Motif"));
    await user.click(await screen.findByRole("option", { name: "Intervention déjà existante" }));
    await user.type(within(dialog).getByLabelText("Explication"), "Déjà cataloguée sous PEI-2.");
    await user.click(within(dialog).getByRole("button", { name: "Ignorer la demande" }));

    await waitFor(() => expect(ignoreInterventionTypeRequestMock.mock.calls[0]?.[0]).toEqual({
      id: 11,
      reason: "ALREADY_EXISTS",
      comment: "Déjà cataloguée sous PEI-2.",
    }));
    expect(toastSuccess).toHaveBeenCalledWith("Demande ignorée et demandeur averti.");
  });
});

describe("CatalogueRequestsPage — erreurs backend", () => {
  it("affiche une alerte quand le chargement échoue", async () => {
    getMaterialRequestsMock.mockRejectedValue(new Error("network error"));
    renderPage();

    await screen.findByText("Impossible de charger les demandes.");
  });

  it("conserve la modal Ignorer ouverte avec les valeurs saisies quand l'action échoue, affiche l'erreur en ligne", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    ignoreMaterialRequestMock.mockRejectedValue({
      response: { status: 409, data: { error: { code: "MATERIAL_ITEM_REQUEST_ALREADY_PROCESSED", message: "Cette demande a déjà été traitée." } } },
    });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Ignorer" }));
    const dialog = await screen.findByRole("dialog");

    await user.click(within(dialog).getByText("Motif"));
    await user.click(await screen.findByRole("option", { name: "Matériel déjà existant" }));
    await user.type(within(dialog).getByLabelText("Explication"), "Déjà référencé sous REF-9.");
    await user.click(within(dialog).getByRole("button", { name: "Ignorer la demande" }));

    await screen.findByText("Cette demande a déjà été traitée.");
    // La modal reste ouverte — jamais un toast qui laisserait croire à un succès — et les
    // valeurs saisies sont conservées.
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(within(dialog).getByLabelText("Explication")).toHaveValue("Déjà référencé sous REF-9.");
    expect(toastSuccess).not.toHaveBeenCalled();
  });
});

describe("CatalogueRequestsPage — synchronisation (D-113, cause racine #1)", () => {
  it("affiche une nouvelle demande dès l'invalidation du cache, sans reload navigateur", async () => {
    getMaterialRequestsMock.mockResolvedValueOnce({ items: [], total: 0 });
    const { client } = renderPage();

    await screen.findByText("Aucune demande.");

    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    await client.invalidateQueries({ queryKey: ["material-requests"] });

    await screen.findByText("Vis titane 4mm");
  });
});

describe("CatalogueRequestsPage — deep-link (kind, requestId)", () => {
  it("force l'onglet En attente et met en évidence la demande ciblée par la notification", async () => {
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    renderPage(["/app/m/catalogue/requests?kind=MATERIAL_ITEM&requestId=10"]);

    await screen.findByText("Vis titane 4mm");
    await waitFor(() => expect(Element.prototype.scrollIntoView).toHaveBeenCalled());
  });

  it("un requestId sans kind (ou l'inverse) est ignoré plutôt que de risquer une collision d'id entre les deux types de demande", async () => {
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    renderPage(["/app/m/catalogue/requests?requestId=10"]);

    await screen.findByText("Vis titane 4mm");
    expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
  });
});

describe("CatalogueRequestsPage — commentaire de l'instrumentiste", () => {
  const LONG_COMMENT =
    "Le chirurgien a demandé en cours d'intervention une plaque absente du catalogue du site ; " +
    "le représentant a fourni un modèle de démonstration qui a finalement été implanté. " +
    "Vérifier avec la firme le prix, le code article exact, la taille, le côté et le stock.\n\n" +
    "Lot fournisseur : https://fournisseur.example/lots/PLQ-TIB-12G-LOT-20261004-AAAAAAAAAAAAAAAA";
  const longRequest = { ...materialRequest, id: 12, label: "Plaque tibia 12 trous", comment: LONG_COMMENT };
  const noCommentRequest = { ...materialRequest, id: 13, label: "Ancre 5.5", comment: null };
  // getByText normalise les espaces par défaut : le commentaire multi-lignes est comparé
  // brut, sur l'élément qui le porte.
  const exactText = (text: string) => (_: string, el: Element | null) => el?.tagName === "SPAN" && el.textContent === text;

  it("affiche un commentaire court en entier sous le matériel, identifié comme commentaire, sans bouton de dépliage", async () => {
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    renderPage();

    const row = (await screen.findByText("Vis titane 4mm")).closest("tr")!;
    expect(within(row).getByText("Commentaire :")).toBeInTheDocument();
    expect(within(row).getByText("Utilisée hors catalogue")).toBeInTheDocument();
    expect(within(row).queryByRole("button", { name: "Voir tout le commentaire" })).toBeNull();
  });

  it("un commentaire long est replié en aperçu mais reste entièrement consultable", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [longRequest], total: 1 });
    renderPage();

    const row = (await screen.findByText("Plaque tibia 12 trous")).closest("tr")!;
    // Le texte complet est présent (repli purement visuel), jamais tronqué.
    expect(within(row).getByText(exactText(LONG_COMMENT))).toBeInTheDocument();
    const toggle = within(row).getByRole("button", { name: "Voir tout le commentaire" });
    expect(toggle).toHaveAttribute("aria-expanded", "false");

    await user.click(toggle);
    expect(within(row).getByRole("button", { name: "Réduire" })).toHaveAttribute("aria-expanded", "true");
    expect(within(row).getByText(exactText(LONG_COMMENT))).toBeInTheDocument();
  });

  it("une demande sans commentaire s'affiche normalement, sans faux contenu dans la ligne", async () => {
    getMaterialRequestsMock.mockResolvedValue({ items: [noCommentRequest], total: 1 });
    renderPage();

    const row = (await screen.findByText("Ancre 5.5")).closest("tr")!;
    expect(within(row).queryByText("Commentaire :")).toBeNull();
    expect(within(row).queryByText("Aucun commentaire")).toBeNull();
    expect(within(row).getByRole("button", { name: "Ignorer" })).toBeInTheDocument();
  });

  it("la modal de création de produit affiche le contexte complet de la demande, commentaire long intégral", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [longRequest], total: 1 });
    renderPage();

    await screen.findByText("Plaque tibia 12 trous");
    await user.click(screen.getByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog");

    expect(within(dialog).getByText("Matériel demandé")).toBeInTheDocument();
    expect(within(dialog).getByText("Ada Lovelace")).toBeInTheDocument();
    expect(within(dialog).getByText("#501 · Clinique Saint-Jean")).toBeInTheDocument();
    expect(within(dialog).getByText("Commentaire de l'instrumentiste")).toBeInTheDocument();
    expect(within(dialog).getByText(exactText(LONG_COMMENT))).toBeInTheDocument();
  });

  it("résout en associant un produit existant depuis la modal, qui garde le commentaire visible", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    getMaterialItemsMock.mockResolvedValue({
      items: [{ id: 42, firm: { id: 1, name: "Arthrex" }, label: "Vis titane 4mm Arthrex", referenceCode: "AR-4", unit: "u", isImplant: false }],
      total: 1, page: 1, limit: 50,
    });
    resolveMaterialRequestMock.mockResolvedValue({ request: { ...materialRequest, status: "RESOLVED" }, materialLine: { id: 7, quantity: "3.00" }, createdFirm: false, createdItem: false });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog", { name: "Résoudre la demande de matériel" });
    expect(within(dialog).getByText("Utilisée hors catalogue")).toBeInTheDocument();
    // Demande antérieure sans quantité : jamais une valeur inventée, le manager la saisit.
    expect(within(dialog).getByLabelText(/Quantité utilisée/)).toHaveValue("");
    await user.type(within(dialog).getByLabelText(/Quantité utilisée/), "3");

    await user.click(within(dialog).getByRole("button", { name: "Associer un matériel existant" }));
    await user.click(within(dialog).getByLabelText(/Rechercher un matériel/));
    await user.click(await screen.findByRole("option", { name: /Vis titane 4mm Arthrex/ }));
    expect(within(dialog).getByText(/garde sa firme \(Arthrex\)/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Associer et résoudre" }));

    await waitFor(() => expect(resolveMaterialRequestMock).toHaveBeenCalledWith(10, { quantity: "3", materialItemId: 42 }));
    expect(toastSuccess).toHaveBeenCalledWith("Demande résolue : matériel ajouté à la mission.");
  });

  it("la modal Ignorer affiche le commentaire de l'instrumentiste, ou « Aucun commentaire »", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest, noCommentRequest], total: 2 });
    renderPage();

    const row = (await screen.findByText("Vis titane 4mm")).closest("tr")!;
    await user.click(within(row).getByRole("button", { name: "Ignorer" }));
    let dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Utilisée hors catalogue")).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Annuler" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());

    const otherRow = screen.getByText("Ancre 5.5").closest("tr")!;
    await user.click(within(otherRow).getByRole("button", { name: "Ignorer" }));
    dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Aucun commentaire")).toBeInTheDocument();
  });

  it("la modal de résolution d'un type d'intervention affiche le même contexte", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [interventionRequest], total: 1 });
    renderPage();

    await screen.findByText("Prothèse épaule inversée");
    await user.click(screen.getByRole("button", { name: "Résoudre" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Intervention demandée")).toBeInTheDocument();
    expect(within(dialog).getByText("Aucun commentaire")).toBeInTheDocument();
  });

  it("le commentaire reste consultable dans l'historique Résolues et Ignorées", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockImplementation(async ({ status }: { status: string }) => {
      if (status === "RESOLVED") {
        return { items: [{ ...materialRequest, status: "RESOLVED", comment: "Commentaire résolu" }], total: 1 };
      }
      if (status === "IGNORED") {
        return {
          items: [{
            ...materialRequest, status: "IGNORED", comment: "Commentaire ignoré",
            ignoreReason: "DUPLICATE", ignoreComment: "Doublon de #9.",
            decidedBy: { id: 1, displayName: "Manager" }, decidedAt: "2026-07-21T10:00:00Z",
          }],
          total: 1,
        };
      }
      return { items: [], total: 0 };
    });
    renderPage();

    await screen.findByText("Aucune demande.");
    await user.click(screen.getByRole("tab", { name: "Résolues" }));
    expect(await screen.findByText("Commentaire résolu")).toBeInTheDocument();

    await user.click(screen.getByRole("tab", { name: "Ignorées" }));
    expect(await screen.findByText("Commentaire ignoré")).toBeInTheDocument();
    // Commentaire de l'instrumentiste et explication du manager restent distincts.
    expect(screen.getByText("« Doublon de #9. »")).toBeInTheDocument();
  });
});

describe("CatalogueRequestsPage — détail d'une demande (consultation sans traitement)", () => {
  const MPFL_COMMENT = "Reconstruction MPFL avec greffe gracilis.\nAncre fémorale 4.5 fournie par le représentant.";
  const mpflRequest = {
    ...interventionRequest,
    id: 16,
    label: "MPFL",
    suggestedCode: null,
    comment: MPFL_COMMENT,
    createdAt: "2026-10-02T10:18:12Z",
    mission: { id: 1040, site: "CHU Saint-Pierre" },
    requestedBy: { id: 30, displayName: "Sophie Collette" },
  };
  const noCommentIntervention = { ...interventionRequest, id: 12, label: "Ostéosynthèse plaque-vis MI", comment: null };
  // Le commentaire multi-lignes est comparé brut, sur l'élément qui le porte (getByText
  // normalise les espaces par défaut).
  const exactComment = (text: string) => (_: string, el: Element | null) =>
    el?.getAttribute("data-testid") === "catalogue-request-detail-comment" && el.textContent === text;

  function expectNoMutation() {
    expect(resolveMaterialRequestMock).not.toHaveBeenCalled();
    expect(ignoreMaterialRequestMock).not.toHaveBeenCalled();
    expect(resolveInterventionTypeRequestMock).not.toHaveBeenCalled();
    expect(ignoreInterventionTypeRequestMock).not.toHaveBeenCalled();
    expect(createMaterialItemMock).not.toHaveBeenCalled();
    expect(createInterventionTypeMock).not.toHaveBeenCalled();
  }

  it("cliquer sur « MPFL » ouvre son détail avec le commentaire exact de l'instrumentiste, sans aucune action métier", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [mpflRequest], total: 1 });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "MPFL" }));
    const dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });

    expect(within(dialog).getByText(exactComment(MPFL_COMMENT))).toBeInTheDocument();
    expect(within(dialog).getByText("Sophie Collette")).toBeInTheDocument();
    expect(within(dialog).getByText("Nouveau type d'intervention")).toBeInTheDocument();
    expect(within(dialog).getByText("CHU Saint-Pierre")).toBeInTheDocument();
    expect(within(dialog).getByText(/12:18/)).toBeInTheDocument();
    expect(within(dialog).getByRole("link", { name: "Mission #1040" })).toHaveAttribute("href", "/app/m/missions/1040");
    expect(within(dialog).getByText("En attente de traitement par un manager.")).toBeInTheDocument();
    expectNoMutation();
  });

  it("ouvrir puis fermer le détail ne déclenche aucun appel au-delà du chargement de la liste", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [mpflRequest], total: 1 });
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    renderPage();

    await screen.findByRole("button", { name: "MPFL" });
    await waitFor(() => expect(getInterventionTypeRequestsMock).toHaveBeenCalled());
    const listCalls = [getMaterialRequestsMock.mock.calls.length, getInterventionTypeRequestsMock.mock.calls.length];

    await user.click(screen.getByRole("button", { name: "MPFL" }));
    const dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });
    await user.click(within(dialog).getByRole("button", { name: "Fermer" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());

    expect([getMaterialRequestsMock.mock.calls.length, getInterventionTypeRequestsMock.mock.calls.length]).toEqual(listCalls);
    expectNoMutation();
    // La demande est toujours en attente, actions intactes dans la ligne.
    const row = screen.getByRole("button", { name: "MPFL" }).closest("tr")!;
    expect(within(row).getByRole("button", { name: "Résoudre" })).toBeInTheDocument();
  });

  it("un clic n'importe où sur la ligne ouvre aussi le détail ; « Voir le détail » est disponible explicitement", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    renderPage();

    const row = (await screen.findByRole("button", { name: "Vis titane 4mm" })).closest("tr")!;
    await user.click(within(row).getByText("Ada Lovelace"));
    let dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });
    await user.click(within(dialog).getByRole("button", { name: "Fermer" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());

    await user.click(within(row).getByRole("button", { name: "Voir le détail" }));
    dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });
    expect(within(dialog).getByText("REF-10")).toBeInTheDocument();
    expect(within(dialog).getByText(exactComment("Utilisée hors catalogue"))).toBeInTheDocument();
    expectNoMutation();
  });

  it("une demande sans commentaire affiche explicitement « Aucun commentaire »", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [noCommentIntervention], total: 1 });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Ostéosynthèse plaque-vis MI" }));
    const dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });
    expect(within(dialog).getByText("Aucun commentaire")).toBeInTheDocument();
  });

  it("les boutons de traitement de la ligne n'ouvrent pas le détail", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [mpflRequest], total: 1 });
    renderPage();

    const row = (await screen.findByRole("button", { name: "MPFL" })).closest("tr")!;
    await user.click(within(row).getByRole("button", { name: "Ignorer" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Ignorer cette demande ?")).toBeInTheDocument();
    expect(screen.queryByRole("dialog", { name: /Détail de la demande/ })).toBeNull();
  });

  it("depuis le détail d'une demande en attente, Ignorer et Résoudre ouvrent les modals existantes, commentaire visible", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [mpflRequest], total: 1 });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "MPFL" }));
    let dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });
    await user.click(within(dialog).getByRole("button", { name: "Ignorer" }));
    dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Ignorer cette demande ?")).toBeInTheDocument();
    expect(within(dialog).getByText(/Ancre fémorale 4.5/)).toBeInTheDocument();
    await user.click(within(dialog).getByRole("button", { name: "Annuler" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());

    await user.click(screen.getByRole("button", { name: "MPFL" }));
    dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });
    await user.click(within(dialog).getByRole("button", { name: "Résoudre" }));
    dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Résoudre la demande d'intervention")).toBeInTheDocument();
    expect(within(dialog).getByText(/Ancre fémorale 4.5/)).toBeInTheDocument();
    expectNoMutation();
  });

  it("une demande matériel résolue : détail consultable, commentaire conservé, produit associé, aucune action de traitement", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockImplementation(async ({ status }: { status: string }) =>
      status === "RESOLVED"
        ? {
          items: [{
            ...materialRequest, status: "RESOLVED", comment: "Boîte ouverte.\nUne seule vis implantée.",
            materialItem: { id: 42, firm: { id: 1, name: "Arthrex" }, label: "Vis titane 4mm Arthrex", referenceCode: "AR-4", unit: "u", isImplant: false },
          }],
          total: 1,
        }
        : { items: [], total: 0 });
    renderPage();

    await screen.findByText("Aucune demande.");
    await user.click(screen.getByRole("tab", { name: "Résolues" }));
    await user.click(await screen.findByRole("button", { name: "Vis titane 4mm" }));
    const dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });

    expect(within(dialog).getByText(exactComment("Boîte ouverte.\nUne seule vis implantée."))).toBeInTheDocument();
    expect(within(dialog).getByText("Vis titane 4mm Arthrex · AR-4")).toBeInTheDocument();
    expect(within(dialog).getByText("Résolue")).toBeInTheDocument();
    expect(within(dialog).queryByRole("button", { name: /Créer produit|Résoudre|Ignorer/ })).toBeNull();
  });

  it("une demande d'intervention ignorée : motif, explication du manager complète, décideur, commentaire conservé", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockImplementation(async ({ status }: { status: string }) =>
      status === "IGNORED"
        ? {
          items: [{
            ...mpflRequest, status: "IGNORED",
            ignoreReason: "ALREADY_EXISTS", ignoreComment: "Existe déjà sous « MPFL reconstruction ».\nMerci de l'utiliser.",
            decidedBy: { id: 1, displayName: "Marie Manager" }, decidedAt: "2026-10-03T08:00:00Z",
          }],
          total: 1,
        }
        : { items: [], total: 0 });
    renderPage();

    await screen.findByText("Aucune demande.");
    await user.click(screen.getByRole("tab", { name: "Ignorées" }));
    await user.click(await screen.findByRole("button", { name: "MPFL" }));
    const dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });

    expect(within(dialog).getByText(exactComment(MPFL_COMMENT))).toBeInTheDocument();
    expect(within(dialog).getByText("Intervention déjà existante")).toBeInTheDocument();
    expect(within(dialog).getByText((_, el) => el?.tagName === "SPAN" && el.textContent === "Existe déjà sous « MPFL reconstruction ».\nMerci de l'utiliser.")).toBeInTheDocument();
    expect(within(dialog).getByText(/Ignorée par Marie Manager le/)).toBeInTheDocument();
    expect(within(dialog).queryByRole("button", { name: /Résoudre|Ignorer/ })).toBeNull();
  });

  it("le commentaire reste consultable dans le détail après une résolution (même demande rechargée RESOLVED)", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockImplementation(async ({ status }: { status: string }) =>
      status === "PENDING"
        ? { items: [mpflRequest], total: 1 }
        : status === "RESOLVED"
          ? { items: [{ ...mpflRequest, status: "RESOLVED", resolvedInterventionType: { id: 5, code: "MPFL", label: "Reconstruction MPFL" } }], total: 1 }
          : { items: [], total: 0 });
    renderPage();

    await screen.findByRole("button", { name: "MPFL" });
    await user.click(screen.getByRole("tab", { name: "Résolues" }));
    await user.click(await screen.findByRole("button", { name: "MPFL" }));
    const dialog = await screen.findByRole("dialog", { name: /Détail de la demande/ });
    expect(within(dialog).getByText(exactComment(MPFL_COMMENT))).toBeInTheDocument();
    expect(within(dialog).getByText("Reconstruction MPFL (MPFL)")).toBeInTheDocument();
  });

  it("déplier l'aperçu du commentaire dans la ligne n'ouvre pas le détail", async () => {
    const user = userEvent.setup();
    const long = "x".repeat(200);
    getMaterialRequestsMock.mockResolvedValue({ items: [{ ...materialRequest, comment: long }], total: 1 });
    renderPage();

    const row = (await screen.findByRole("button", { name: "Vis titane 4mm" })).closest("tr")!;
    await user.click(within(row).getByRole("button", { name: "Voir tout le commentaire" }));
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});
