import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import AddInterventionDialog from "./AddInterventionDialog";

const apiGetMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
  },
}));

const TYPES = [
  { id: 1, code: "LCA", label: "Ligamentoplastie croisé antérieur" },
  { id: 2, code: "PTG", label: "Prothèse totale de genou" },
];
const FIRMS = [
  { id: 10, name: "Arthrex", active: true },
  { id: 11, name: "Medacta", active: true },
];

function renderDialog(props: Partial<React.ComponentProps<typeof AddInterventionDialog>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const onSubmit = props.onSubmit ?? vi.fn();
  const onSubmitDraft = props.onSubmitDraft ?? vi.fn();
  const onClose = props.onClose ?? vi.fn();
  render(
    <QueryClientProvider client={client}>
      <AddInterventionDialog
        open
        loadingIntervention={false}
        loadingDraft={false}
        interventionTypes={TYPES}
        firms={FIRMS}
        existingCount={2}
        onClose={onClose}
        onSubmit={onSubmit}
        onSubmitDraft={onSubmitDraft}
        {...props}
      />
    </QueryClientProvider>,
  );
  return { onSubmit, onSubmitDraft, onClose };
}

beforeEach(() => {
  apiGetMock.mockReset();
  // GET /api/firms/{id}/service-offerings — vide par défaut (repli sur le catalogue complet).
  apiGetMock.mockImplementation((url: string) => {
    if (url.includes("/service-offerings")) return Promise.resolve({ data: [] });
    if (url.includes("/encoding-context")) return Promise.resolve({ data: { interventionType: TYPES[0], suggestedPrimaryFirm: null, offerings: [] } });
    return Promise.resolve({ data: {} });
  });
});

describe("AddInterventionDialog — parcours firme puis intervention", () => {
  it("étape 1 affiche la recherche de firme, jamais le type d'abord", async () => {
    renderDialog();
    expect(screen.getByText("Étape 1/2 – Choisir une firme")).toBeInTheDocument();
    expect(screen.getByLabelText("Rechercher une firme")).toBeInTheDocument();
    expect(screen.queryByText("Type d'intervention *")).not.toBeInTheDocument();
  });

  it("la recherche filtre les firmes à la saisie", async () => {
    const user = userEvent.setup();
    renderDialog();

    expect(screen.getByRole("button", { name: "Arthrex" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Medacta" })).toBeInTheDocument();

    await user.type(screen.getByLabelText("Rechercher une firme"), "arth");

    expect(screen.getByRole("button", { name: "Arthrex" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Medacta" })).not.toBeInTheDocument();
  });

  it("choisir une firme passe à l'étape 2 avec un select de type d'intervention", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByRole("button", { name: "Arthrex" }));

    expect(await screen.findByText("Étape 2/2 – Choisir le type d'intervention")).toBeInTheDocument();
    expect(screen.getByText("Arthrex")).toBeInTheDocument();
    const typeSelect = screen.getByRole("combobox", { name: /type d'intervention/i });
    expect(typeSelect).toBeInTheDocument();
  });

  it("envoie interventionTypeId et primaryFirmId après avoir choisi une firme puis un type", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderDialog();

    await user.click(screen.getByRole("button", { name: "Arthrex" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");

    await user.click(screen.getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("PTG — Prothèse totale de genou"));

    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 2, primaryFirmId: 10, orderIndex: 2 });
  });

  it("'Aucune firme' permet de voir tout le catalogue et de soumettre sans primaryFirmId", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderDialog();

    await user.click(screen.getByRole("button", { name: "— Aucune firme (voir tout le catalogue)" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
    expect(screen.getByText("Aucune")).toBeInTheDocument();

    await user.click(screen.getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("LCA — Ligamentoplastie croisé antérieur"));
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 1, primaryFirmId: undefined, orderIndex: 2 });
  });

  it("possibilité de revenir en arrière et changer la firme avant validation", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByRole("button", { name: "Arthrex" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");

    await user.click(screen.getByRole("button", { name: "Changer" }));
    await screen.findByText("Étape 1/2 – Choisir une firme");

    await user.click(screen.getByRole("button", { name: "Medacta" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
    expect(screen.getByText("Medacta")).toBeInTheDocument();
  });

  it("le bouton Ajouter reste désactivé tant qu'aucun type n'est choisi", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByRole("button", { name: "Arthrex" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");

    expect(screen.getByRole("button", { name: "Ajouter" })).toBeDisabled();
  });

  it("quand la firme a des prestations, le select ne propose que ces types", async () => {
    const user = userEvent.setup();
    apiGetMock.mockImplementation((url: string) => {
      if (url.includes("/service-offerings")) {
        return Promise.resolve({ data: [{ id: 501, interventionType: TYPES[0], active: true }] });
      }
      return Promise.resolve({ data: {} });
    });
    renderDialog();

    await user.click(screen.getByRole("button", { name: "Arthrex" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
    await waitFor(() => expect(apiGetMock).toHaveBeenCalledWith("/api/firms/10/service-offerings"));

    await user.click(screen.getByLabelText(/type d'intervention/i));
    expect(await screen.findByText("LCA — Ligamentoplastie croisé antérieur")).toBeInTheDocument();
    expect(screen.queryByText("PTG — Prothèse totale de genou")).not.toBeInTheDocument();
    expect(screen.getByText("Intervention absente du catalogue")).toBeInTheDocument();
  });
});

describe("AddInterventionDialog — intervention absente du catalogue (draft)", () => {
  it("choisir l'option dédiée révèle le libellé libre et appelle onSubmitDraft avec la firme", async () => {
    const user = userEvent.setup();
    const { onSubmitDraft, onSubmit } = renderDialog();

    await user.click(screen.getByRole("button", { name: "Arthrex" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");

    await user.click(screen.getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("Intervention absente du catalogue"));

    expect(await screen.findByLabelText("Type d'intervention souhaité *")).toBeInTheDocument();
    await user.type(screen.getByLabelText("Type d'intervention souhaité *"), "Prothèse épaule inversée");

    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));

    expect(onSubmitDraft).toHaveBeenCalledWith({
      label: "Prothèse épaule inversée",
      suggestedCode: undefined,
      comment: undefined,
      requestedFirmId: 10,
    });
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("le bouton Envoyer la demande reste désactivé tant qu'aucun libellé n'est saisi", async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByRole("button", { name: "— Aucune firme (voir tout le catalogue)" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");
    await user.click(screen.getByLabelText(/type d'intervention/i));
    await user.click(await screen.findByText("Intervention absente du catalogue"));

    expect(await screen.findByRole("button", { name: "Envoyer la demande" })).toBeDisabled();
  });

  it("catalogue vide : affiche un message clair plutôt qu'un fallback texte", async () => {
    const user = userEvent.setup();
    renderDialog({ interventionTypes: [] });

    await user.click(screen.getByRole("button", { name: "— Aucune firme (voir tout le catalogue)" }));
    await screen.findByText("Étape 2/2 – Choisir le type d'intervention");

    expect(screen.getByText("Le catalogue est vide pour le moment.")).toBeInTheDocument();
    // L'option "absente du catalogue" reste disponible même sans catalogue.
    await user.click(screen.getByLabelText(/type d'intervention/i));
    expect(screen.getByText("Intervention absente du catalogue")).toBeInTheDocument();
  });
});
