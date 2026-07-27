import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { InterventionTypesManager } from "./InterventionTypesManager";

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

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

function renderManager(props: Partial<{ showHeader: boolean }> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <InterventionTypesManager {...props} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  apiPatchMock.mockReset();
  apiDeleteMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("InterventionTypesManager — liste et état vide", () => {
  it("affiche la liste des types d'intervention", async () => {
    apiGetMock.mockResolvedValue({
      data: [{ id: 1, code: "LCA", label: "LCA primaire", specialty: null, active: true }],
    });
    renderManager();

    await screen.findByText("LCA primaire");
    expect(screen.getByText("LCA")).toBeInTheDocument();
  });

  it("affiche l'état vide quand aucun type n'existe", async () => {
    apiGetMock.mockResolvedValue({ data: [] });
    renderManager();

    await screen.findByText("Aucun type d'intervention enregistré.");
  });

  it("masque le PageHeader quand showHeader=false (usage embarqué, ex. dialog Prestations)", async () => {
    apiGetMock.mockResolvedValue({ data: [] });
    renderManager({ showHeader: false });

    await screen.findByText("Aucun type d'intervention enregistré.");
    expect(screen.queryByText("Types d'intervention")).toBeNull();
  });
});

describe("InterventionTypesManager — création", () => {
  it("ouvre le formulaire de création", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({ data: [] });
    renderManager();

    await screen.findByText("Aucun type d'intervention enregistré.");
    await user.click(screen.getByRole("button", { name: "Ajouter le premier type" }));

    expect(await screen.findByRole("dialog")).toBeInTheDocument();
  });

  it("crée un nouveau type avec succès", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({ data: [] });
    apiPostMock.mockResolvedValue({ data: { id: 2, code: "PTG", label: "PTG", specialty: null, active: true } });
    renderManager();

    await screen.findByText("Aucun type d'intervention enregistré.");
    await user.click(screen.getByRole("button", { name: "Ajouter le premier type" }));

    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Code *"), "ptg");
    await user.type(within(dialog).getByLabelText("Libellé *"), "PTG");
    await user.click(within(dialog).getByRole("button", { name: "Créer" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types", { code: "PTG", label: "PTG" });
    });
    expect(toastSuccess).toHaveBeenCalledWith("Type d'intervention créé");
  });

  it("affiche l'erreur backend en cas de conflit de code (409)", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({ data: [] });
    apiPostMock.mockRejectedValue({
      response: { data: { error: { status: 409, code: "CONFLICT", message: "Un type d'intervention avec ce code existe déjà." } } },
    });
    renderManager();

    await screen.findByText("Aucun type d'intervention enregistré.");
    await user.click(screen.getByRole("button", { name: "Ajouter le premier type" }));

    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Code *"), "lca");
    await user.type(within(dialog).getByLabelText("Libellé *"), "LCA primaire");
    await user.click(within(dialog).getByRole("button", { name: "Créer" }));

    await waitFor(() => {
      expect(toastError).toHaveBeenCalledWith("Un type d'intervention avec ce code existe déjà.");
    });
  });
});

describe("InterventionTypesManager — édition", () => {
  it("le formulaire d'édition ne contient aucun champ code (immuable)", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({
      data: [{ id: 4, code: "ARTHRO", label: "Arthroscopie", specialty: null, active: true }],
    });
    renderManager();

    await screen.findByText("Arthroscopie");
    await user.click(screen.getAllByLabelText("Modifier")[0]);

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByLabelText("Code *")).toBeNull();
  });

  it("modifie le libellé avec succès", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({
      data: [{ id: 4, code: "ARTHRO", label: "Arthroscopie", specialty: null, active: true }],
    });
    apiPatchMock.mockResolvedValue({ data: { id: 4, code: "ARTHRO", label: "Arthroscopie du genou", specialty: null, active: true } });
    renderManager();

    await screen.findByText("Arthroscopie");
    await user.click(screen.getAllByLabelText("Modifier")[0]);

    const dialog = await screen.findByRole("dialog");
    const labelField = within(dialog).getByLabelText("Libellé *");
    await user.clear(labelField);
    await user.type(labelField, "Arthroscopie du genou");
    await user.click(within(dialog).getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith("/api/intervention-types/4", { label: "Arthroscopie du genou", specialty: undefined });
    });
    expect(toastSuccess).toHaveBeenCalledWith("Type d'intervention mis à jour");
  });
});

describe("InterventionTypesManager — activation/désactivation", () => {
  it("désactive un type en cliquant sur son statut", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({
      data: [{ id: 3, code: "MPFL", label: "Ligamentoplastie MPFL", specialty: null, active: true }],
    });
    apiPatchMock.mockResolvedValue({ data: { id: 3, code: "MPFL", label: "Ligamentoplastie MPFL", specialty: null, active: false } });
    renderManager();

    await screen.findByText("Ligamentoplastie MPFL");
    await user.click(screen.getByText("Actif"));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith("/api/intervention-types/3", { active: false });
    });
  });

  it("réactive un type inactif en cliquant sur son statut", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({
      data: [{ id: 5, code: "OLD", label: "Ancienne technique", specialty: null, active: false }],
    });
    apiPatchMock.mockResolvedValue({ data: { id: 5, code: "OLD", label: "Ancienne technique", specialty: null, active: true } });
    renderManager();

    await screen.findByText("Ancienne technique");
    await user.click(screen.getByText("Inactif"));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith("/api/intervention-types/5", { active: true });
    });
  });
});

describe("InterventionTypesManager — invalidation après mutation", () => {
  it("recharge la liste après une création réussie (refetch déclenché par l'invalidation)", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValueOnce({ data: [] });
    apiPostMock.mockResolvedValue({ data: { id: 2, code: "PTG", label: "Prothèse totale genou", specialty: null, active: true } });
    apiGetMock.mockResolvedValueOnce({ data: [{ id: 2, code: "PTG", label: "Prothèse totale genou", specialty: null, active: true }] });
    renderManager();

    await screen.findByText("Aucun type d'intervention enregistré.");
    await user.click(screen.getByRole("button", { name: "Ajouter le premier type" }));

    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Code *"), "ptg");
    await user.type(within(dialog).getByLabelText("Libellé *"), "Prothèse totale genou");
    await user.click(within(dialog).getByRole("button", { name: "Créer" }));

    await waitFor(() => expect(apiGetMock).toHaveBeenCalledTimes(2));
    await screen.findByText("Prothèse totale genou");
  });
});
