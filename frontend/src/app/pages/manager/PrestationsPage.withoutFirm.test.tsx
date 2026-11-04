import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import PrestationsPage from "./PrestationsPage";

// D-183 — interventions sans firme : création « Aucune firme » ou avec une firme, catégorie
// « Interventions sans firme » calculée par le serveur, rattachements ajoutés et retirés depuis
// le Référentiel sans jamais dupliquer l'intervention.

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

const FIRMS = [{ id: 10, name: "Arthrex", active: true }, { id: 11, name: "ConMed", active: true }, { id: 12, name: "Ancienne firme", active: false }];
const RAC = { id: 7, code: "RAC", label: "Résection acromio-claviculaire", specialty: "Épaule", active: true, allowedWithoutFirm: false, firmsCount: 0, activeFirmsCount: 0, withoutFirm: true, selectableWithoutFirm: true };
const LCA = { id: 1, code: "LCA", label: "LCA primaire", specialty: null, active: true, allowedWithoutFirm: false, firmsCount: 1, activeFirmsCount: 1, withoutFirm: false, selectableWithoutFirm: false };

let types: unknown[] = [RAC, LCA];
let racOfferings: unknown[] = [];

function mockGet(url: string) {
  if (url === "/api/material-items/billing-regularisation") {
    return Promise.resolve({ data: { summary: { itemCount: 0, toConfigureCount: 0, undecidedCount: 0, rateMissingCount: 0, billableCount: 0, notBillableCount: 0, blockedMissionCount: 0, pendingMissionCount: 0 }, items: [] } });
  }
  if (url === "/api/firms") return Promise.resolve({ data: FIRMS });
  if (url === "/api/intervention-types") return Promise.resolve({ data: types });
  if (url === "/api/intervention-types/7/offerings") return Promise.resolve({ data: racOfferings });
  if (url === "/api/intervention-types/similar") return Promise.resolve({ data: [] });
  if (url === "/api/material-items") return Promise.resolve({ data: { items: [], total: 0, page: 1, limit: 200 } });
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

async function openReferentiel(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByText("Référentiel"));
}

async function fillNewIntervention(user: ReturnType<typeof userEvent.setup>, code: string, label: string) {
  await user.click(await screen.findByRole("button", { name: /Nouvelle intervention/ }));
  await user.type(screen.getByLabelText("Code *"), code);
  await user.type(screen.getByLabelText("Libellé *"), label);
}

beforeEach(() => {
  window.history.replaceState(null, "", "/app/m/catalogue/prestations");
  types = [RAC, LCA];
  racOfferings = [];
  [apiGetMock, apiPostMock, apiPatchMock, apiDeleteMock, toastSuccess, toastError].forEach((m) => m.mockReset());
  apiGetMock.mockImplementation((url: string) => mockGet(url));
});

describe("PrestationsPage — interventions sans firme (D-183)", () => {
  it("crée une intervention « Aucune firme » par défaut : aucune firme envoyée, aucune prestation", async () => {
    const user = userEvent.setup();
    apiPostMock.mockResolvedValue({ data: { ...RAC, id: 8, code: "RES-AC" } });
    renderPage();
    await openReferentiel(user);
    await fillNewIntervention(user, "RES-AC", "Résection acromio-claviculaire");

    const firmSelect = screen.getByLabelText("Firme associée") as HTMLSelectElement;
    expect(firmSelect.value).toBe("");
    expect(within(firmSelect).getByRole("option", { name: "Aucune firme" })).toBeInTheDocument();
    expect(within(firmSelect).queryByRole("option", { name: "Ancienne firme" })).toBeNull();
    expect(screen.getByText(/sans prestation ni tarif/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Ajouter au référentiel" }));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types", {
      code: "RES-AC", label: "Résection acromio-claviculaire", specialty: undefined, firmId: null,
    }));
    expect(apiPostMock).toHaveBeenCalledTimes(1);
  });

  it("crée une intervention rattachée à une firme en une seule requête", async () => {
    const user = userEvent.setup();
    apiPostMock.mockResolvedValue({ data: { ...RAC, id: 8, withoutFirm: false, activeFirmsCount: 1 } });
    renderPage();
    await openReferentiel(user);
    await fillNewIntervention(user, "RES-AC", "Résection acromio-claviculaire");
    await user.selectOptions(screen.getByLabelText("Firme associée"), "10");
    await user.click(screen.getByRole("button", { name: "Ajouter au référentiel" }));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types", expect.objectContaining({ firmId: 10 })));
    expect(apiPostMock).toHaveBeenCalledTimes(1);
    expect(toastSuccess).toHaveBeenCalledWith("Intervention ajoutée au référentiel et rattachée à Arthrex");
  });

  it("code déjà utilisé : propose l'intervention existante et la rattache à la firme choisie, sans doublon", async () => {
    const user = userEvent.setup();
    apiPostMock.mockImplementation((url: string) => {
      if (url === "/api/intervention-types") {
        return Promise.reject({ response: { status: 409, data: { error: { code: "INTERVENTION_TYPE_CODE_EXISTS", message: "Le code « RAC » est déjà utilisé.", existingInterventionType: RAC } } } });
      }
      return Promise.resolve({ data: { offeringId: 300, firmId: 10, active: true, reactivated: false, interventionType: { ...RAC, withoutFirm: false } } });
    });
    renderPage();
    await openReferentiel(user);
    await fillNewIntervention(user, "RAC", "Résection AC");
    await user.selectOptions(screen.getByLabelText("Firme associée"), "10");
    await user.click(screen.getByRole("button", { name: "Ajouter au référentiel" }));

    const reuse = await screen.findByTestId("use-existing-intervention");
    expect(reuse).toHaveTextContent("Utiliser l'intervention existante « Résection acromio-claviculaire » et la rattacher à Arthrex");
    await user.click(reuse);
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types/7/firm-links", { firmId: 10 }));
  });

  it("la catégorie « Interventions sans firme » liste les interventions calculées par le serveur et ouvre leur détail", async () => {
    const user = userEvent.setup();
    renderPage();

    const category = await screen.findByTestId("category-without-firm");
    await waitFor(() => expect(category).toHaveTextContent("Interventions sans firme1"));
    await user.click(category);

    const view = await screen.findByTestId("without-firm-category");
    expect(within(view).getByText("Résection acromio-claviculaire")).toBeInTheDocument();
    expect(within(view).queryByText("LCA primaire")).toBeNull();
    await user.click(within(view).getByRole("button", { name: "Ouvrir Résection acromio-claviculaire" }));

    expect(await screen.findByText("Firmes associées · 0 active")).toBeInTheDocument();
    expect(screen.getByText(/fait partie des « Interventions sans firme »/)).toBeInTheDocument();
  });

  it("rattache l'intervention à une firme, puis retire le rattachement après confirmation", async () => {
    const user = userEvent.setup();
    apiPostMock.mockResolvedValue({ data: { offeringId: 300, firmId: 10, active: true, reactivated: false, interventionType: { ...RAC, withoutFirm: false } } });
    apiDeleteMock.mockResolvedValue({ data: { offeringId: 300, firmId: 10, active: false, interventionType: { ...RAC, withoutFirm: true } } });
    renderPage();
    await openReferentiel(user);
    await user.click(await screen.findByText("Résection acromio-claviculaire"));

    const select = await screen.findByLabelText("Firme à rattacher");
    expect(within(select).queryByRole("option", { name: "Ancienne firme" })).toBeNull();
    await user.selectOptions(select, "10");
    await user.click(screen.getByRole("button", { name: "Rattacher" }));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types/7/firm-links", { firmId: 10 }));
    expect(toastSuccess).toHaveBeenCalledWith("« Résection acromio-claviculaire » est rattachée à Arthrex");

    // Le serveur renvoie désormais le rattachement actif.
    racOfferings = [{ offeringId: 300, firm: { id: 10, name: "Arthrex", logoPath: null }, active: true, feeApplicable: true, forfait: null, pricingConflictRuleIds: null }];
    types = [{ ...RAC, withoutFirm: false, activeFirmsCount: 1, selectableWithoutFirm: false }, LCA];
    await user.click(screen.getByText(/Retour au référentiel/));
    await user.click(await screen.findByText("Résection acromio-claviculaire"));

    await user.click(await screen.findByRole("button", { name: "Retirer le rattachement à Arthrex" }));
    const confirm = await screen.findByRole("dialog", { name: /Retirer le rattachement à Arthrex/ });
    expect(within(confirm).getByText(/désactivée, jamais supprimée/)).toBeInTheDocument();
    expect(within(confirm).getByText(/apparaîtra dans « Interventions sans firme »/)).toBeInTheDocument();
    await user.click(within(confirm).getByRole("button", { name: "Retirer le rattachement" }));

    await waitFor(() => expect(apiDeleteMock).toHaveBeenCalledWith("/api/intervention-types/7/firm-links/300"));
    expect(toastSuccess).toHaveBeenCalledWith("Rattachement retiré — l'intervention apparaît désormais dans « Interventions sans firme ».");
  });

  it("un rattachement retiré se rétablit (réactivation), jamais une nouvelle intervention", async () => {
    const user = userEvent.setup();
    racOfferings = [{ offeringId: 300, firm: { id: 10, name: "Arthrex", logoPath: null }, active: false, feeApplicable: true, forfait: null, pricingConflictRuleIds: null }];
    apiPostMock.mockResolvedValue({ data: { offeringId: 300, firmId: 10, active: true, reactivated: true, interventionType: { ...RAC, withoutFirm: false } } });
    renderPage();
    await openReferentiel(user);
    await user.click(await screen.findByText("Résection acromio-claviculaire"));

    expect(await screen.findByText(/rattachement retiré/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Rattacher à nouveau" }));
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith("/api/intervention-types/7/firm-links", { firmId: 10 }));
    expect(toastSuccess).toHaveBeenCalledWith("Rattachement à Arthrex rétabli");
  });
});
