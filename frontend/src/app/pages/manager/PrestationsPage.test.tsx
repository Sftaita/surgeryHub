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
  await user.click(await screen.findByRole("button", { name: "Smith & Nephew" }));
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
    expect(screen.queryByRole("tab", { name: "Prestations" })).toBeNull();
  });

  it("l'onglet matériel s'appelle « Matériel », plus « Matériel facturable »", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [makeOffering()], [], []));
    renderPage();
    await selectFirm(user);
    expect(await screen.findByRole("tab", { name: "Matériel" })).toBeInTheDocument();
    expect(screen.queryByRole("tab", { name: "Matériel facturable" })).toBeNull();
  });

  // ── États vides (§4/§12) ──────────────────────────────────────────────

  it("état vide prestations : texte, sous-texte et CTA dédiés", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await selectFirm(user);

    expect(await screen.findByText("Aucune prestation renseignée pour cette firme")).toBeInTheDocument();
    expect(screen.getByText(/Ajoutez les interventions proposées par cette firme/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "+ Ajouter une prestation" })).toBeInTheDocument();
  });

  it("état vide matériel : texte, sous-texte et CTA dédiés", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: "Matériel" }));

    expect(await screen.findByText("Aucun matériel renseigné pour cette firme")).toBeInTheDocument();
    expect(screen.getByText(/pour pouvoir le sélectionner pendant l'encodage/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "+ Ajouter un matériel" })).toBeInTheDocument();
  });

  // ── Ajout d'une prestation, un seul écran, recherche + création (§5) ───

  it("le bouton d'ajout de prestation est compact (icône + aria-label), pas un bouton texte", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [makeOffering()], [], []));
    renderPage();
    await selectFirm(user);
    await screen.findByText("LCA primaire");

    expect(screen.getByRole("button", { name: "Ajouter une prestation" })).toBeInTheDocument();
    expect(screen.queryByText("Ajouter une prestation", { selector: "button *" })).toBeNull();
  });

  it("ajoute une prestation via recherche puis création du type dans le même écran, sans modal intermédiaire", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") return Promise.resolve({ data: [] }); // catalogue vide
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
    await screen.findByText("Aucune prestation renseignée pour cette firme");

    await user.click(screen.getByRole("button", { name: "Ajouter une prestation" }));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Type d'intervention"), "Reconstruction PTG");
    await user.click(within(dialog).getByText('+ Créer «Reconstruction PTG»'));

    // Le libellé est déjà pré-rempli depuis la recherche (startCreatingFromSearch) —
    // ne pas re-taper dedans, seul le code reste à saisir.
    await user.type(within(dialog).getByLabelText("Code *"), "PTG");
    await user.click(within(dialog).getByRole("button", { name: "Créer et ajouter" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types", { code: "PTG", label: "Reconstruction PTG" });
    });
    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/firms/10/service-offerings", { interventionTypeId: 2 });
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
    // representativePresenceRelevant=true → les sous-options apparaissent déjà cochées.
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
    await user.click(screen.getByRole("tab", { name: "Matériel" }));

    expect(await screen.findByText("FAST-FIX")).toBeInTheDocument();
    expect(screen.getByText("ULTRABUTTON")).toBeInTheDocument();
  });

  it("affiche Facturable/Non facturable et ne propose plus de bouton global « Ajouter un tarif matériel »", async () => {
    const user = userEvent.setup();
    const materials = [
      { id: 5, label: "FAST-FIX", referenceCode: "FF-01", isImplant: false, billingStatus: "UNSPECIFIED" },
      { id: 6, label: "ULTRABUTTON", referenceCode: "UB-02", isImplant: false, billingStatus: "NOT_BILLABLE" },
    ];
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], materials));
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: "Matériel" }));

    await screen.findByText("FAST-FIX");
    expect(screen.getByText("Tarif à définir")).toBeInTheDocument();
    // "Non facturable" apparaît à la fois comme statut (ULTRABUTTON) et comme action
    // rapide sur la ligne FAST-FIX (UNSPECIFIED) — les deux sont attendus ici.
    expect(screen.getAllByText("Non facturable").length).toBeGreaterThanOrEqual(1);
    expect(screen.queryByRole("button", { name: "Ajouter un tarif matériel" })).toBeNull();
  });

  it("gère le tarif depuis la ligne matériel (pas de modal séparé « ajouter un tarif »)", async () => {
    const user = userEvent.setup();
    const materials = [{ id: 5, label: "HEALICOIL", referenceCode: "HC-12", isImplant: false, billingStatus: "BILLABLE" }];
    const rules = [{ id: 200, ruleType: "MATERIAL_FEE", interventionType: null, materialItem: { id: 5, label: "HEALICOIL", referenceCode: "HC-12", firm: { id: 10, name: "Smith & Nephew" } }, unitPrice: "45.00", currency: "EUR", validFrom: null, validTo: null, active: true }];
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], rules, materials));
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: "Matériel" }));

    await user.click(await screen.findByRole("button", { name: "45.00 EUR" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Tarif — HEALICOIL")).toBeInTheDocument();
  });

  // ── Création de matériel sans choix de firme (§14) ──────────────────────

  it("crée un matériel sans demander la firme — contexte déjà connu", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await selectFirm(user);
    await user.click(screen.getByRole("tab", { name: "Matériel" }));

    await user.click(await screen.findByRole("button", { name: "Ajouter un matériel" }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByLabelText("Firme *")).toBeNull();
    expect(within(dialog).getByText("Smith & Nephew")).toBeInTheDocument();
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

  it("ouvre le dialog de gestion des types d'intervention depuis le header", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => mockGet(url, [], [], []));
    renderPage();
    await user.click(await screen.findByRole("button", { name: "Gérer les types d'intervention" }));
    expect(await screen.findByRole("heading", { name: "Types d'intervention" })).toBeInTheDocument();
  });
});
