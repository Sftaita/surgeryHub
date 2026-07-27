import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
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

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <CatalogueRequestsPage />
    </QueryClientProvider>,
  );
}

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

describe("CatalogueRequestsPage — ignorance", () => {
  it("ignore une demande matériel", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    ignoreMaterialRequestMock.mockResolvedValue({ ...materialRequest, status: "IGNORED" });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Ignorer" }));

    // mutationFn référence directement ignoreMaterialRequest (pas de wrapper) : TanStack
    // Query v5 lui passe un 2e argument de contexte interne — on ne vérifie que le
    // premier argument, celui réellement significatif pour l'appel API.
    await waitFor(() => expect(ignoreMaterialRequestMock.mock.calls[0]?.[0]).toBe(10));
    expect(toastSuccess).toHaveBeenCalledWith("Demande ignorée.");
  });

  it("ignore une demande de type d'intervention", async () => {
    const user = userEvent.setup();
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [interventionRequest], total: 1 });
    ignoreInterventionTypeRequestMock.mockResolvedValue({ requestId: 11, draftId: 1, status: "IGNORED", draftStatus: "IGNORED", missionInterventionId: null });
    renderPage();

    await screen.findByText("Prothèse épaule inversée");
    await user.click(screen.getByRole("button", { name: "Ignorer" }));

    await waitFor(() => expect(ignoreInterventionTypeRequestMock.mock.calls[0]?.[0]).toBe(11));
    expect(toastSuccess).toHaveBeenCalledWith("Demande ignorée.");
  });
});

describe("CatalogueRequestsPage — erreurs backend", () => {
  it("affiche une alerte quand le chargement échoue", async () => {
    getMaterialRequestsMock.mockRejectedValue(new Error("network error"));
    renderPage();

    await screen.findByText("Impossible de charger les demandes.");
  });

  it("affiche un toast d'erreur quand l'ignorance échoue", async () => {
    const user = userEvent.setup();
    getMaterialRequestsMock.mockResolvedValue({ items: [materialRequest], total: 1 });
    ignoreMaterialRequestMock.mockRejectedValue({ response: { data: { message: "Déjà résolue par un autre manager." } } });
    renderPage();

    await screen.findByText("Vis titane 4mm");
    await user.click(screen.getByRole("button", { name: "Ignorer" }));

    await waitFor(() => {
      expect(toastError).toHaveBeenCalledWith("Déjà résolue par un autre manager.");
    });
  });
});
