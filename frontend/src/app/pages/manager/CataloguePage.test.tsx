import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import CataloguePage from "./CataloguePage";

const getMaterialItemsMock = vi.fn();
const createMaterialItemMock = vi.fn();
const updateMaterialItemMock = vi.fn();
const getFirmsMock = vi.fn();

vi.mock("../../features/manager-catalogue/api/catalogue.api", () => ({
  getMaterialItems: (...args: unknown[]) => getMaterialItemsMock(...args),
  createMaterialItem: (...args: unknown[]) => createMaterialItemMock(...args),
  updateMaterialItem: (...args: unknown[]) => updateMaterialItemMock(...args),
  getFirms: (...args: unknown[]) => getFirmsMock(...args),
}));

const toastSuccess = vi.fn();
vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: vi.fn(), warning: vi.fn() }),
}));

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <CataloguePage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  getMaterialItemsMock.mockReset();
  createMaterialItemMock.mockReset();
  updateMaterialItemMock.mockReset();
  getFirmsMock.mockReset().mockResolvedValue([{ id: 10, name: "Smith & Nephew" }]);
  toastSuccess.mockReset();
});

/**
 * Point 10 (audit tarification) — même traitement que PrestationsPage : le tarif actif
 * ressort clairement (en vert), "Tarif à définir" sinon, jamais de badge "Non facturable"
 * déduit de la simple absence de tarif. Cette page n'avait aucun test jusqu'ici.
 */
describe("CataloguePage — tarification visible (Point 10)", () => {
  it("affiche le tarif actuel en évidence quand currentPrice est fourni par le backend", async () => {
    getMaterialItemsMock.mockResolvedValue({
      items: [
        { id: 1, firm: { id: 10, name: "Smith & Nephew" }, label: "Agrafe de Richards", referenceCode: "STAPLE", unit: "pièce", isImplant: false, billingStatus: "BILLABLE", currentPrice: "125.00", currentCurrency: "EUR" },
      ],
      total: 1, page: 1, limit: 50,
    });
    renderPage();

    expect(await screen.findByText("Agrafe de Richards")).toBeInTheDocument();
    const price = screen.getByText("125.00 EUR HTVA");
    expect(price.closest("button")).toBeNull();
  });

  it("affiche « Tarif à définir » quand currentPrice est null, jamais un statut inventé", async () => {
    getMaterialItemsMock.mockResolvedValue({
      items: [
        { id: 2, firm: { id: 10, name: "Smith & Nephew" }, label: "Q-FIX", referenceCode: "", unit: "pièce", isImplant: false, billingStatus: "UNSPECIFIED", currentPrice: null, currentCurrency: null },
      ],
      total: 1, page: 1, limit: 50,
    });
    renderPage();

    await screen.findByText("Q-FIX");
    expect(screen.getByText("Tarif à définir")).toBeInTheDocument();
    expect(screen.queryByText("Non facturable")).not.toBeInTheDocument();
  });

  it("le tarif n'est pas affiché du tout pour un rôle qui n'a pas currentPrice dans la réponse (RBAC serveur)", async () => {
    getMaterialItemsMock.mockResolvedValue({
      items: [
        { id: 3, firm: { id: 10, name: "Smith & Nephew" }, label: "FastFix", referenceCode: "FF", unit: "pièce", isImplant: false, billingStatus: "BILLABLE" },
      ],
      total: 1, page: 1, limit: 50,
    });
    renderPage();

    await screen.findByText("FastFix");
    expect(screen.getByText("Tarif à définir")).toBeInTheDocument();
  });

  it("le crayon Modifier ouvre l'édition et permet de corriger la référence", async () => {
    const user = userEvent.setup();
    getMaterialItemsMock.mockResolvedValue({
      items: [
        { id: 1, firm: { id: 10, name: "Smith & Nephew" }, label: "Agrafe de Richards", referenceCode: "STAPLE", unit: "pièce", isImplant: false, billingStatus: "BILLABLE", currentPrice: "125.00", currentCurrency: "EUR" },
      ],
      total: 1, page: 1, limit: 50,
    });
    updateMaterialItemMock.mockResolvedValue({});
    renderPage();

    await screen.findByText("Agrafe de Richards");
    await user.click(screen.getByRole("button", { name: "Modifier Agrafe de Richards" }));

    const dialog = await screen.findByRole("dialog");
    const referenceField = within(dialog).getByLabelText("Référence");
    expect(referenceField).toHaveValue("STAPLE");
    await user.clear(referenceField);
    await user.type(referenceField, "STAPLE-2");
    await user.click(within(dialog).getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(updateMaterialItemMock).toHaveBeenCalledWith(
        1,
        expect.objectContaining({ referenceCode: "STAPLE-2" }),
      );
    });
  });
});
