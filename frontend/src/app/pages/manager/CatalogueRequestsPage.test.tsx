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

vi.mock("../../features/manager-catalogue/api/catalogue.api", () => ({
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

describe("CatalogueRequestsPage — résolution matériel", () => {
  it("résout une demande matériel en créant un produit", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    createMaterialItemMock.mockResolvedValue({ id: 99, firm: { id: 1, name: "Arthrex" }, label: "Vis titane 4mm", referenceCode: "REF-10", unit: "u", isImplant: false });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Créer produit" }));

    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText(/firme/i), "Arthrex");

    // Le formulaire de création de produit (MaterialItemFormDialog, déjà sur HEAD) exige
    // une firme sélectionnée — non simulée ici en détail, ce test couvre l'ouverture du
    // flux de résolution, la mutation elle-même étant testée côté MaterialItemFormDialog.
    expect(dialog).toBeInTheDocument();
  });
});

describe("CatalogueRequestsPage — résolution type d'intervention", () => {
  it("résout une demande de type d'intervention en associant un type existant, avec la firme principale choisie", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [interventionRequest], total: 1 });
    getInterventionTypesMock.mockResolvedValue([{ id: 5, code: "PEI", label: "Prothèse épaule inversée", specialty: null, active: true }]);
    getFirmsMock.mockResolvedValue([{ id: 3, name: "Zimmer Biomet" }]);
    resolveInterventionTypeRequestMock.mockResolvedValue({ requestId: 11, draftId: 1, status: "RESOLVED", draftStatus: "RESOLVED", missionInterventionId: 77 });
    renderPage();

    await screen.findByText("Prothèse épaule inversée");
    await user.click(screen.getByRole("button", { name: "Résoudre" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByText("Plutôt associer un type existant →"));

    const typeSelect = await within(dialog).findByText("Sélectionner un type d'intervention");
    await user.click(typeSelect);
    await user.click(await screen.findByRole("option", { name: /Prothèse épaule inversée \(PEI\)/ }));

    const firmSelect = within(dialog).getByText("Aucune firme principale (optionnel)");
    await user.click(firmSelect);
    await user.click(await screen.findByRole("option", { name: "Zimmer Biomet" }));

    await user.click(within(dialog).getByRole("button", { name: "Résoudre" }));

    await waitFor(() => {
      expect(resolveInterventionTypeRequestMock).toHaveBeenCalledWith(11, 5, 3);
    });
    expect(toastSuccess).toHaveBeenCalledWith("Demande résolue. Intervention créée sur la mission.");
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
    await user.click(screen.getByRole("button", { name: "Créer produit" }));
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
      total: 1, page: 1, limit: 200,
    });
    resolveMaterialRequestMock.mockResolvedValue({ request: { ...materialRequest, status: "RESOLVED" }, materialLine: { id: 7 } });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Créer produit" }));
    await user.click(within(await screen.findByRole("dialog")).getByText("Plutôt associer un produit existant →"));

    const pickDialog = await screen.findByRole("dialog", { name: "Associer un produit existant" });
    expect(within(pickDialog).getByText("Utilisée hors catalogue")).toBeInTheDocument();
    await user.click(await within(pickDialog).findByText("Vis titane 4mm Arthrex"));
    await user.click(within(pickDialog).getByRole("button", { name: "Associer et résoudre" }));

    await waitFor(() => expect(resolveMaterialRequestMock).toHaveBeenCalledWith(10, 42));
    expect(toastSuccess).toHaveBeenCalledWith("Demande résolue. Ligne matériel créée.");
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
