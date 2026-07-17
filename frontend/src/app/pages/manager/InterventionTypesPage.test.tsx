import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import InterventionTypesPage from "./InterventionTypesPage";

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

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <InterventionTypesPage />
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

describe("InterventionTypesPage", () => {
  it("affiche la liste des types d'intervention", async () => {
    apiGetMock.mockResolvedValue({
      data: [{ id: 1, code: "LCA", label: "LCA primaire", specialty: null, active: true }],
    });
    renderPage();

    await screen.findByText("LCA primaire");
    expect(screen.getByText("LCA")).toBeInTheDocument();
  });

  it("affiche l'état vide quand aucun type n'existe", async () => {
    apiGetMock.mockResolvedValue({ data: [] });
    renderPage();

    await screen.findByText("Aucun type d'intervention enregistré.");
  });

  it("crée un nouveau type d'intervention", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({ data: [] });
    apiPostMock.mockResolvedValue({ data: { id: 2, code: "PTG", label: "PTG", specialty: null, active: true } });
    renderPage();

    await screen.findByText("Aucun type d'intervention enregistré.");
    await user.click(screen.getByRole("button", { name: "Ajouter le premier type" }));

    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Code *"), "ptg");
    await user.type(within(dialog).getByLabelText("Libellé *"), "PTG");
    await user.click(within(dialog).getByRole("button", { name: "Créer" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types", { code: "PTG", label: "PTG" });
    });
    expect(toastSuccess).toHaveBeenCalled();
  });

  it("désactive un type d'intervention en cliquant sur son statut", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({
      data: [{ id: 3, code: "MPFL", label: "Ligamentoplastie MPFL", specialty: null, active: true }],
    });
    apiPatchMock.mockResolvedValue({ data: { id: 3, code: "MPFL", label: "Ligamentoplastie MPFL", specialty: null, active: false } });
    renderPage();

    await screen.findByText("Ligamentoplastie MPFL");
    await user.click(screen.getByText("Actif"));

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith("/api/intervention-types/3", { active: false });
    });
  });

  it("le formulaire d'édition ne contient aucun champ code (immuable)", async () => {
    const user = userEvent.setup();
    apiGetMock.mockResolvedValue({
      data: [{ id: 4, code: "ARTHRO", label: "Arthroscopie", specialty: null, active: true }],
    });
    renderPage();

    await screen.findByText("Arthroscopie");
    await user.click(screen.getAllByLabelText("Modifier")[0]);

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByLabelText("Code *")).toBeNull();
  });
});
