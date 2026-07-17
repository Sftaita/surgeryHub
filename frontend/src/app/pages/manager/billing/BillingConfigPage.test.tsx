import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import BillingConfigPage from "./BillingConfigPage";

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

const FIRMS = [{ id: 10, name: "Smith & Nephew" }];
const OFFERINGS = [
  {
    id: 100, firmId: 10,
    interventionType: { id: 1, code: "LCA", label: "LCA primaire" },
    label: null, active: true, suggestedMaterials: [],
  },
];
const RULES = [
  {
    id: 200, ruleType: "MATERIAL_FEE",
    interventionType: null,
    materialItem: { id: 5, label: "Suture FiberWire", referenceCode: "FW-2", firm: { id: 10, name: "Smith & Nephew" } },
    unitPrice: "40.00", currency: "EUR", validFrom: null, validTo: null, active: true,
  },
];
const MATERIALS = [{ id: 5, label: "Suture FiberWire", referenceCode: "FW-2" }];

function mockGet(url: string) {
  if (url === "/api/firms") return Promise.resolve({ data: FIRMS });
  if (url === "/api/firms/10/service-offerings") return Promise.resolve({ data: OFFERINGS });
  if (url === "/api/firms/10/pricing-rules") return Promise.resolve({ data: RULES });
  if (url === "/api/material-items") return Promise.resolve({ data: { items: MATERIALS } });
  return Promise.resolve({ data: [] });
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <BillingConfigPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset().mockImplementation(mockGet);
  apiPostMock.mockReset();
  apiPatchMock.mockReset();
  apiDeleteMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("BillingConfigPage", () => {
  it("invite à choisir une firme avant d'afficher quoi que ce soit", async () => {
    renderPage();
    await screen.findByText("Sélectionnez une firme pour commencer");
    expect(screen.queryByText("Prestations")).toBeNull();
  });

  it("affiche les prestations et le matériel facturable après sélection d'une firme", async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Smith & Nephew" }));

    await screen.findByText("LCA primaire");
    expect(screen.getByText("Définir un forfait")).toBeInTheDocument();
    expect(screen.getByText("Suture FiberWire")).toBeInTheDocument();
    expect(screen.getByText("40.00 EUR")).toBeInTheDocument();
  });

  it("le contact de facturation n'apparaît nulle part sur cette page", async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(await screen.findByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Smith & Nephew" }));

    await screen.findByText("LCA primaire");
    expect(screen.queryByText(/contact de facturation/i)).toBeNull();
  });

  it("crée un forfait pour une prestation sans forfait", async () => {
    const user = userEvent.setup();
    apiPostMock.mockResolvedValue({
      data: { id: 300, ruleType: "INTERVENTION_FEE", interventionType: { id: 1, code: "LCA", label: "LCA primaire" }, materialItem: null, unitPrice: "180.00", currency: "EUR", validFrom: null, validTo: null, active: true },
    });
    renderPage();
    await user.click(await screen.findByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Smith & Nephew" }));

    await user.click(await screen.findByText("Définir un forfait"));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText("Montant (€) *"), "180");
    await user.click(within(dialog).getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => {
      expect(apiPostMock).toHaveBeenCalledWith("/api/firms/10/pricing-rules", expect.objectContaining({
        ruleType: "INTERVENTION_FEE", interventionTypeId: 1, unitPrice: 180,
      }));
    });
  });
});
