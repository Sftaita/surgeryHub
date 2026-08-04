import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import PrestationsPage from "./PrestationsPage";

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();
const apiPatchMock = vi.fn();
const apiDeleteMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
    patch: (...args: unknown[]) => apiPatchMock(...args),
    delete: (...args: unknown[]) => apiDeleteMock(...args),
  },
}));

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

const FIRMS = [{ id: 10, name: "Smith & Nephew" }, { id: 11, name: "ConMed" }];

function makeOffering(overrides: Partial<{
  id: number; firmId: number; interventionType: { id: number; code: string; label: string };
  active: boolean; representativePresenceRelevant: boolean; representativeSuppressesInterventionFee: boolean;
  representativeSuppressesOwnMaterialFees: boolean; feeApplicable: boolean; suggestedMaterials: unknown[];
}> = {}) {
  return {
    id: 100, firmId: 10,
    interventionType: { id: 1, code: "LCA", label: "LCA primaire" },
    label: null, active: true,
    representativePresenceRelevant: false,
    representativeSuppressesInterventionFee: false,
    representativeSuppressesOwnMaterialFees: false,
    feeApplicable: true,
    suggestedMaterials: [],
    ...overrides,
  };
}

function mockGet(url: string, offerings: unknown[], rules: unknown[], materials: unknown[]) {
  if (url === "/api/firms") return Promise.resolve({ data: FIRMS });
  if (url === "/api/firms/10/service-offerings") return Promise.resolve({ data: offerings });
  if (url === "/api/firms/10/pricing-rules") return Promise.resolve({ data: rules });
  if (url === "/api/material-items") return Promise.resolve({ data: { items: materials, total: materials.length, page: 1, limit: 200 } });
  if (url === "/api/intervention-types") return Promise.resolve({ data: [] });
  return Promise.resolve({ data: [] });
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <PrestationsPage />
    </QueryClientProvider>,
  );
}

async function selectFirm(user: ReturnType<typeof userEvent.setup>) {
  // Le bouton contient désormais un avatar (initiales de repli) en plus du nom — ne
  // pas dépendre du nom accessible exact (qui inclut les initiales).
  const name = await screen.findByText("Smith & Nephew");
  await user.click(name.closest("button")!);
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  apiPatchMock.mockReset();
  apiDeleteMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("PrestationsPage", () => {
  it("invite à choisir une firme avant d'afficher les onglets", async () => {
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await screen.findByText("Sélectionnez une firme pour commencer");
    expect(screen.queryByRole("tab", { name: /Prestations/ })).toBeNull();
  });

  it("l'onglet matériel s'appelle « Matériel », plus « Matériel facturable »", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [makeOffering()], [], []));
    renderPage();
    await selectFirm(user);
    expect(await screen.findByRole("tab", { name: /^Matériel/ })).toBeInTheDocument();
    expect(screen.queryByRole("tab", { name: "Matériel facturable" })).toBeNull();
  });

  // ── États vides (§4/§12) ──────────────────────────────────────────────

  it("état vide prestations : texte dédié, bouton d'ajout accessible via l'intro", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await selectFirm(user);

    expect(await screen.findByText("Aucune prestation renseignée pour cette firme.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Ajouter une prestation" })).toBeInTheDocument();
  });

  it("état vide matériel : texte dédié, bouton d'ajout accessible via l'intro", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: /^Matériel/ }));

    expect(await screen.findByText("Aucun matériel renseigné pour cette firme.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Nouveau matériel" })).toBeInTheDocument();
  });

  // ── Ajout d'une prestation : modale dédiée, liste complète triée + recherche dynamique (§5) ──

  it("le bouton d'ajout de prestation est visible dès l'onglet Prestations, pas seulement à l'état vide", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [makeOffering()], [], []));
    renderPage();
    await selectFirm(user);
    await screen.findByText("LCA primaire");

    expect(screen.getByRole("button", { name: "Ajouter une prestation" })).toBeInTheDocument();
  });

  it("la modale « Ajouter une prestation » liste le référentiel et filtre dynamiquement à la recherche", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") {
        return Promise.resolve({
          data: [
            { id: 1, code: "PTG", label: "Prothèse totale de genou", specialty: null, active: true },
            { id: 2, code: "LCA", label: "Ligamentoplastie du LCA", specialty: null, active: true },
          ],
        });
      }
      return mockGet(url, [], [], []);
    });
    renderPage();
    await selectFirm(user);
    await screen.findByText("Aucune prestation renseignée pour cette firme.");

    await user.click(screen.getByRole("button", { name: "Ajouter une prestation" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Choisir dans le référentiel")).toBeInTheDocument();
    await within(dialog).findByText("Prothèse totale de genou");
    expect(within(dialog).getByText("Ligamentoplastie du LCA")).toBeInTheDocument();

    await user.type(within(dialog).getByPlaceholderText("Rechercher une intervention…"), "LCA");
    expect(within(dialog).getByText("Ligamentoplastie du LCA")).toBeInTheDocument();
    expect(within(dialog).queryByText("Prothèse totale de genou")).toBeNull();
  });

  it("ajoute directement une prestation existante du référentiel, sans étape de confirmation supplémentaire", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") {
        return Promise.resolve({ data: [{ id: 1, code: "PTG", label: "Prothèse totale de genou", specialty: null, active: true }] });
      }
      return mockGet(url, [], [], []);
    });
    apiPostMock.mockResolvedValue({ data: makeOffering({ id: 101, interventionType: { id: 1, code: "PTG", label: "Prothèse totale de genou" } }) });
    renderPage();
    await selectFirm(user);
    await screen.findByText("Aucune prestation renseignée pour cette firme.");

    await user.click(screen.getByRole("button", { name: "Ajouter une prestation" }));
    const dialog = await screen.findByRole("dialog");
    await within(dialog).findByText("Prothèse totale de genou");
    await user.click(within(dialog).getByRole("button", { name: "Ajouter" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/firms/10/service-offerings", { interventionTypeId: 1 });
    });
  });

  it("propose « Déjà configurée » + Ouvrir pour une intervention déjà rattachée à la firme", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") {
        return Promise.resolve({ data: [{ id: 1, code: "LCA", label: "LCA primaire", specialty: null, active: true }] });
      }
      return mockGet(url, [makeOffering()], [], []);
    });
    renderPage();
    await selectFirm(user);
    await screen.findByText("LCA primaire");

    await user.click(screen.getByRole("button", { name: "Ajouter une prestation" }));
    const dialog = await screen.findByRole("dialog");
    await within(dialog).findByText("Déjà configurée");
    expect(within(dialog).queryByRole("button", { name: "Ajouter" })).toBeNull();
  });

  // ── Création d'une intervention inline depuis « Ajouter une prestation » (Task 11) ──

  it("crée une intervention inline depuis la recherche quand elle n'existe pas dans le référentiel", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") return Promise.resolve({ data: [] });
      return mockGet(url, [], [], []);
    });
    apiPostMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") {
        return Promise.resolve({ data: { id: 2, code: "PTG", label: "Reconstruction PTG" } });
      }
      if (url === "/api/firms/10/service-offerings") {
        return Promise.resolve({ data: makeOffering({ id: 101, interventionType: { id: 2, code: "PTG", label: "Reconstruction PTG" } }) });
      }
      return Promise.resolve({ data: {} });
    });
    renderPage();
    await selectFirm(user);
    await screen.findByText("Aucune prestation renseignée pour cette firme.");

    await user.click(screen.getByRole("button", { name: "Ajouter une prestation" }));
    const addDialog = await screen.findByRole("dialog");
    await user.type(within(addDialog).getByPlaceholderText("Rechercher une intervention…"), "Reconstruction PTG");
    await user.click(within(addDialog).getByText(/Ajouter/).closest("button")!);

    const createDialog = await screen.findByRole("dialog");
    within(createDialog).getByText("Nouvelle intervention");
    expect(within(createDialog).getByLabelText("Libellé *")).toHaveValue("Reconstruction PTG");
    await user.type(within(createDialog).getByLabelText("Code *"), "PTG");
    await user.click(within(createDialog).getByRole("button", { name: "Ajouter au référentiel" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types", { code: "PTG", label: "Reconstruction PTG", specialty: undefined });
    });
    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/firms/10/service-offerings", { interventionTypeId: 2 });
    });
  });

  it("suggère le type existant avant de créer un doublon, et permet de l'utiliser directement", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types/similar") {
        return Promise.resolve({
          data: [{ type: { id: 1, code: "PTG", label: "Prothèse totale de genou", specialty: null, active: true }, confidence: "HIGH" }],
        });
      }
      if (url === "/api/intervention-types") return Promise.resolve({ data: [] });
      return mockGet(url, [], [], []);
    });
    apiPostMock.mockImplementation((url: string) => {
      if (url === "/api/firms/10/service-offerings") {
        return Promise.resolve({ data: makeOffering({ id: 102, interventionType: { id: 1, code: "PTG", label: "Prothèse totale de genou" } }) });
      }
      return Promise.resolve({ data: {} });
    });
    renderPage();
    await selectFirm(user);
    await screen.findByText("Aucune prestation renseignée pour cette firme.");

    await user.click(screen.getByRole("button", { name: "Ajouter une prestation" }));
    const addDialog = await screen.findByRole("dialog");
    await user.type(within(addDialog).getByPlaceholderText("Rechercher une intervention…"), "PTG bis");
    await user.click(within(addDialog).getByText(/Ajouter/).closest("button")!);

    const createDialog = await screen.findByRole("dialog");
    await user.type(within(createDialog).getByLabelText("Code *"), "PTG2");
    await user.click(within(createDialog).getByRole("button", { name: "Ajouter au référentiel" }));

    expect(await within(createDialog).findByText("Utiliser ce type")).toBeInTheDocument();
    expect(apiPostMock).not.toHaveBeenCalledWith("/api/intervention-types", expect.anything());

    await user.click(within(createDialog).getByRole("button", { name: "Utiliser ce type" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/firms/10/service-offerings", { interventionTypeId: 1 });
    });
    expect(apiPostMock).not.toHaveBeenCalledWith("/api/intervention-types", expect.anything());
  });

  it("laisse créer quand même un type malgré la suggestion, si l'intervention est réellement différente", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types/similar") {
        return Promise.resolve({
          data: [{ type: { id: 1, code: "PTG", label: "Prothèse totale de genou", specialty: null, active: true }, confidence: "LOW" }],
        });
      }
      if (url === "/api/intervention-types") return Promise.resolve({ data: [] });
      return mockGet(url, [], [], []);
    });
    apiPostMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") {
        return Promise.resolve({ data: { id: 5, code: "LCARV", label: "Révision LCA" } });
      }
      if (url === "/api/firms/10/service-offerings") {
        return Promise.resolve({ data: makeOffering({ id: 103, interventionType: { id: 5, code: "LCARV", label: "Révision LCA" } }) });
      }
      return Promise.resolve({ data: {} });
    });
    renderPage();
    await selectFirm(user);
    await screen.findByText("Aucune prestation renseignée pour cette firme.");

    await user.click(screen.getByRole("button", { name: "Ajouter une prestation" }));
    const addDialog = await screen.findByRole("dialog");
    await user.type(within(addDialog).getByPlaceholderText("Rechercher une intervention…"), "Révision LCA");
    await user.click(within(addDialog).getByText(/Ajouter/).closest("button")!);

    const createDialog = await screen.findByRole("dialog");
    await user.type(within(createDialog).getByLabelText("Code *"), "LCARV");
    await user.click(within(createDialog).getByRole("button", { name: "Ajouter au référentiel" }));

    await within(createDialog).findByText("Utiliser ce type");
    await user.click(within(createDialog).getByRole("button", { name: "Créer quand même" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types", { code: "LCARV", label: "Révision LCA", specialty: undefined });
    });
  });

  // ── Détail d'une prestation : forfait / matériels / délégué (§6/§7) ────

  it("le résumé d'une prestation distingue forfait normal, pas de forfait et tarif à définir", async () => {
    const user = userEvent.setup();
    const offerings = [
      makeOffering({ id: 100, interventionType: { id: 1, code: "LCA", label: "LCA primaire" } }),
      makeOffering({ id: 101, interventionType: { id: 2, code: "PTG", label: "PTG" }, feeApplicable: false }),
    ];
    apiGetMock.mockImplementation((url: string) => mockGet(url, offerings, [], []));
    renderPage();
    await selectFirm(user);

    expect(await screen.findByText(/Tarif à définir/)).toBeInTheDocument();
    expect(screen.getByText(/Pas de forfait/)).toBeInTheDocument();
  });

  it("ouvre le détail d'une prestation au clic et affiche forfait/matériels/délégué", async () => {
    const user = userEvent.setup();
    const offering = makeOffering({ representativePresenceRelevant: true, representativeSuppressesInterventionFee: true });
    apiGetMock.mockImplementation((url: string) => mockGet(url, [offering], [], []));
    renderPage();
    await selectFirm(user);

    await user.click(await screen.findByText("LCA primaire"));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Facturation")).toBeInTheDocument();
    expect(within(dialog).getByText("Présence d'un délégué")).toBeInTheDocument();
    expect(within(dialog).getByLabelText("Neutralise le forfait de cette prestation")).toBeChecked();
  });

  it("bascule la politique délégué depuis le détail de la prestation", async () => {
    const user = userEvent.setup();
    const offering = makeOffering();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [offering], [], []));
    apiPatchMock.mockResolvedValue({ data: { ...offering, representativePresenceRelevant: true } });
    renderPage();
    await selectFirm(user);

    await user.click(await screen.findByText("LCA primaire"));
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByLabelText("Oui"));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith(
        "/api/firms/10/service-offerings/100",
        expect.objectContaining({ representativePresenceRelevant: true }),
      );
    });
  });

  // ── Onglet Matériel : facturable / non facturable, gestion depuis la ligne (§11/§13/§15) ──

  it("liste tous les matériels, même sans tarif — jamais masqués", async () => {
    const user = userEvent.setup();
    const materials = [
      { id: 5, label: "FAST-FIX", referenceCode: "FF-01", isImplant: false, billingStatus: "BILLABLE" },
      { id: 6, label: "ULTRABUTTON", referenceCode: "UB-02", isImplant: false, billingStatus: "NOT_BILLABLE" },
    ];
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], materials));
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: /^Matériel/ }));

    expect(await screen.findByText("FAST-FIX")).toBeInTheDocument();
    expect(screen.getByText("ULTRABUTTON")).toBeInTheDocument();
  });

  it("le tarif actif ressort en clair, sans badge « Non facturable » déduit de l'absence de tarif", async () => {
    const user = userEvent.setup();
    const materials = [
      { id: 5, label: "FAST-FIX", referenceCode: "FF-01", isImplant: false, billingStatus: "UNSPECIFIED" },
      { id: 6, label: "ULTRABUTTON", referenceCode: "UB-02", isImplant: false, billingStatus: "BILLABLE" },
    ];
    const rules = [{ id: 200, ruleType: "MATERIAL_FEE", interventionType: null, materialItem: { id: 6, label: "ULTRABUTTON", referenceCode: "UB-02", firm: { id: 10, name: "Smith & Nephew" } }, unitPrice: "45.00", currency: "EUR", validFrom: null, validTo: null, active: true }];
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], rules, materials));
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: /^Matériel/ }));

    await screen.findByText("FAST-FIX");
    expect(screen.getByText("Tarif à définir")).toBeInTheDocument();
    expect(screen.queryByText("Non facturable")).not.toBeInTheDocument();
    expect(screen.getByText("45.00 EUR HTVA")).toBeInTheDocument();

    expect(screen.queryByRole("button", { name: "Ajouter un tarif matériel" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Définir un tarif" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Modifier le tarif" })).toBeNull();
    expect(screen.getByRole("button", { name: "Modifier FAST-FIX" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Modifier ULTRABUTTON" })).toBeInTheDocument();
  });

  it("le dialogue « Modifier » combine identification (référence préremplie, corrigeable) et tarification (tarif actuel prérempli)", async () => {
    const user = userEvent.setup();
    const materials = [{ id: 5, label: "FAST-FIX", unit: "pièce", referenceCode: "FF-01", isImplant: false, billingStatus: "BILLABLE" }];
    const rules = [{ id: 200, ruleType: "MATERIAL_FEE", interventionType: null, materialItem: { id: 5, label: "FAST-FIX", referenceCode: "FF-01", firm: { id: 10, name: "Smith & Nephew" } }, unitPrice: "45.00", currency: "EUR", validFrom: null, validTo: null, active: true }];
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], rules, materials));
    apiPatchMock.mockResolvedValue({ data: { ...materials[0], referenceCode: "FF-01-REV2" } });
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: /^Matériel/ }));

    await screen.findByText("FAST-FIX");
    await user.click(screen.getByRole("button", { name: "Modifier FAST-FIX" }));

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Modifier le matériel")).toBeInTheDocument();

    const referenceField = within(dialog).getByLabelText("Référence");
    expect(referenceField).toHaveValue("FF-01");
    await user.clear(referenceField);
    await user.type(referenceField, "FF-01-REV2");
    await user.click(within(dialog).getByRole("button", { name: "Enregistrer l'identification" }));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith(
        "/api/material-items/5",
        expect.objectContaining({ referenceCode: "FF-01-REV2" }),
      );
    });
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Matériel mis à jour"));

    expect(within(dialog).getByText(/45\.00 EUR/)).toBeInTheDocument();
    expect(within(dialog).getByRole("button", { name: "Remplacer à partir d'une date" })).toBeInTheDocument();
  });

  it("ajoute un tarif pour un matériel qui n'en a pas encore, depuis le même dialogue « Modifier »", async () => {
    const user = userEvent.setup();
    const materials = [{ id: 5, label: "Q-FIX", unit: "pièce", referenceCode: "", isImplant: false, billingStatus: "UNSPECIFIED" }];
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], materials));
    apiPostMock.mockResolvedValue({ data: { id: 300, ruleType: "MATERIAL_FEE", unitPrice: "125.00", currency: "EUR", validFrom: null, validTo: null, active: true } });
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: /^Matériel/ }));

    await screen.findByText("Q-FIX");
    await user.click(screen.getByRole("button", { name: "Modifier Q-FIX" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Définir un tarif" }));
    await user.type(within(dialog).getByLabelText("Montant"), "125");
    await user.click(within(dialog).getByRole("button", { name: "Créer" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith(
        "/api/firms/10/pricing-rules",
        expect.objectContaining({ ruleType: "MATERIAL_FEE", materialItemId: 5, unitPrice: 125 }),
      );
    });
  });

  // ── Création de matériel sans choix de firme (§14) ──────────────────────

  it("crée un matériel sans demander la firme — contexte déjà connu", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: /^Matériel/ }));

    await user.click(await screen.findByRole("button", { name: "Nouveau matériel" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByLabelText("Firme *")).toBeNull();
    expect(within(dialog).getByText("Smith & Nephew")).toBeInTheDocument();
  });

  it("valide nom et unité obligatoires à la création de matériel, puis envoie la requête complète", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    apiPostMock.mockResolvedValue({ data: { id: 9, label: "Q-FIX", unit: "pièce", referenceCode: "", isImplant: false, billingStatus: "UNSPECIFIED" } });
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: /^Matériel/ }));
    await user.click(await screen.findByRole("button", { name: "Nouveau matériel" }));

    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: "Enregistrer le matériel" }));
    expect(within(dialog).getByText(/Indiquez au moins le nom et l'unité/)).toBeInTheDocument();
    expect(apiPostMock).not.toHaveBeenCalled();

    await user.type(within(dialog).getByLabelText("Nom du matériel *"), "Q-FIX");
    await user.type(within(dialog).getByLabelText("Unité *"), "pièce");
    await user.click(within(dialog).getByRole("button", { name: "Enregistrer le matériel" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/material-items", { firmId: 10, label: "Q-FIX", unit: "pièce", referenceCode: undefined, isImplant: false });
    });
  });

  // ── Divers ───────────────────────────────────────────────────────────

  it("le contact de facturation n'apparaît nulle part sur cette page", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [makeOffering()], [], []));
    renderPage();
    await selectFirm(user);

    await screen.findByText("LCA primaire");
    expect(screen.queryByText(/contact de facturation/i)).toBeNull();
  });

  // ── Refonte UX — distinction FIRME / GLOBAL (TopNav, ContextBanner, Référentiel) ──

  it("bascule vers le Référentiel global via le TopNav, sans logo de firme affiché", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await selectFirm(user);
    await screen.findByText("CONFIGURATION FIRME");

    await user.click(screen.getByRole("button", { name: /Référentiel/ }));

    expect(await screen.findByText("RÉFÉRENTIEL GLOBAL")).toBeInTheDocument();
    expect(screen.queryByText("CONFIGURATION FIRME")).toBeNull();
    expect(screen.queryByText("Smith & Nephew")).toBeNull();
    expect(await screen.findByText("Aucune intervention dans le référentiel.")).toBeInTheDocument();
  });

  it("revient au contexte firme précédemment sélectionné en rebasculant sur « Firmes »", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [makeOffering()], [], []));
    renderPage();
    await selectFirm(user);
    await screen.findByText("LCA primaire");

    await user.click(screen.getByRole("button", { name: /Référentiel/ }));
    await screen.findByText("RÉFÉRENTIEL GLOBAL");

    await user.click(screen.getByRole("button", { name: /^Firmes/ }));

    expect(await screen.findByText("CONFIGURATION FIRME")).toBeInTheDocument();
    expect(await screen.findByText("LCA primaire")).toBeInTheDocument();
  });

  it("liste le référentiel triée alphabétiquement et filtre dynamiquement", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") {
        return Promise.resolve({
          data: [
            { id: 1, code: "PTG", label: "Prothèse totale de genou", specialty: null, active: true, firmsCount: 3 },
            { id: 2, code: "LCA", label: "Ligamentoplastie du LCA", specialty: null, active: true, firmsCount: 1 },
          ],
        });
      }
      return mockGet(url, [], [], []);
    });
    renderPage();
    await user.click(await screen.findByRole("button", { name: /Référentiel/ }));

    const rows = await screen.findAllByText(/Ligamentoplastie du LCA|Prothèse totale de genou/);
    expect(rows[0]).toHaveTextContent("Ligamentoplastie du LCA");
    expect(rows[1]).toHaveTextContent("Prothèse totale de genou");

    await user.type(screen.getByPlaceholderText("Rechercher un code ou une intervention…"), "PTG");
    expect(screen.getByText("Prothèse totale de genou")).toBeInTheDocument();
    expect(screen.queryByText("Ligamentoplastie du LCA")).toBeNull();
  });

  it("navigue d'une prestation firme vers son détail dans le Référentiel (inline) puis vers une autre firme utilisatrice", async () => {
    const user = userEvent.setup();
    const offering = makeOffering();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") {
        return Promise.resolve({ data: [{ id: 1, code: "LCA", label: "LCA primaire", specialty: null, active: true, firmsCount: 2 }] });
      }
      if (url === "/api/intervention-types/1/offerings") {
        return Promise.resolve({
          data: [
            { offeringId: 100, firm: { id: 10, name: "Smith & Nephew", logoPath: null }, active: true, feeApplicable: true, forfait: { amount: "180.00", currency: "EUR" } },
            { offeringId: 201, firm: { id: 11, name: "ConMed", logoPath: null }, active: true, feeApplicable: true, forfait: { amount: "150.00", currency: "EUR" } },
          ],
        });
      }
      if (url === "/api/firms/11/service-offerings") {
        return Promise.resolve({ data: [makeOffering({ id: 201, firmId: 11 })] });
      }
      return mockGet(url, [offering], [], []);
    });
    renderPage();
    await selectFirm(user);
    await user.click(await screen.findByText("LCA primaire"));

    const offeringDialog = await screen.findByRole("dialog");
    await user.click(within(offeringDialog).getByRole("button", { name: "Voir dans le référentiel →" }));

    expect(await screen.findByText("RÉFÉRENTIEL GLOBAL")).toBeInTheDocument();
    expect(await screen.findByText("Utilisée par 2 firmes")).toBeInTheDocument();
    expect(screen.getByText("ConMed")).toBeInTheDocument();

    const conmedRow = screen.getByText("ConMed").parentElement!.parentElement!;
    await user.click(within(conmedRow).getByRole("button", { name: "Ouvrir chez cette firme →" }));

    // Retour au contexte FIRME, sur ConMed, prestation ouverte directement.
    expect(await screen.findByText("CONFIGURATION FIRME")).toBeInTheDocument();
    await waitFor(() => expect(screen.getAllByText("ConMed").length).toBeGreaterThan(0));
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
  });
});
