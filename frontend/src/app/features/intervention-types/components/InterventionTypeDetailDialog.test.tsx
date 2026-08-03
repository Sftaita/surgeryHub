import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { InterventionTypeDetailDialog } from "./InterventionTypeDetailDialog";

const getInterventionTypeOfferingsMock = vi.fn();

vi.mock("../api/interventionTypes.api", () => ({
  getInterventionTypeOfferings: (...args: unknown[]) => getInterventionTypeOfferingsMock(...args),
}));

const INTERVENTION = { id: 1, code: "PTG", label: "Prothèse totale de genou", specialty: null, active: true };

function renderDialog(onOpenFirm = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <InterventionTypeDetailDialog open onClose={vi.fn()} intervention={INTERVENTION} onOpenFirm={onOpenFirm} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  getInterventionTypeOfferingsMock.mockReset();
});

/**
 * Catalogue > Prestations, refonte UX (écran 14) — détail d'une intervention globale :
 * contexte strictement GLOBAL, firmes utilisatrices avec leur propre forfait (jamais
 * éditable depuis cet écran), navigation directe vers la prestation d'une firme.
 */
describe("InterventionTypeDetailDialog", () => {
  it("affiche le code et le libellé de l'intervention", async () => {
    getInterventionTypeOfferingsMock.mockResolvedValue([]);
    renderDialog();
    expect(await screen.findByText("Prothèse totale de genou")).toBeInTheDocument();
    expect(screen.getByText("PTG")).toBeInTheDocument();
  });

  it("distingue les trois états de forfait par firme utilisatrice", async () => {
    getInterventionTypeOfferingsMock.mockResolvedValue([
      { offeringId: 100, firm: { id: 10, name: "Smith & Nephew", logoPath: null }, active: true, feeApplicable: true, forfait: { amount: "191.00", currency: "EUR" } },
      { offeringId: 201, firm: { id: 11, name: "ConMed", logoPath: null }, active: true, feeApplicable: true, forfait: null },
      { offeringId: 301, firm: { id: 12, name: "Medacta", logoPath: null }, active: true, feeApplicable: false, forfait: null },
    ]);
    renderDialog();

    expect(await screen.findByText("Utilisée par 3 firmes")).toBeInTheDocument();
    expect(screen.getByText("191.00 EUR HTVA")).toBeInTheDocument();
    expect(screen.getByText(/Tarif à définir/)).toBeInTheDocument();
    expect(screen.getByText(/Pas de forfait/)).toBeInTheDocument();
  });

  it("état vide : aucune firme n'utilise encore cette intervention", async () => {
    getInterventionTypeOfferingsMock.mockResolvedValue([]);
    renderDialog();
    expect(await screen.findByText("Aucune firme ne configure encore cette intervention.")).toBeInTheDocument();
  });

  it("« Ouvrir chez cette firme » transmet le firmId et l'offeringId exacts", async () => {
    const onOpenFirm = vi.fn();
    getInterventionTypeOfferingsMock.mockResolvedValue([
      { offeringId: 201, firm: { id: 11, name: "ConMed", logoPath: null }, active: true, feeApplicable: true, forfait: { amount: "150.00", currency: "EUR" } },
    ]);
    renderDialog(onOpenFirm);
    const user = userEvent.setup();

    await screen.findByText("ConMed");
    await user.click(screen.getByRole("button", { name: "Ouvrir chez cette firme →" }));

    expect(onOpenFirm).toHaveBeenCalledWith(11, 201);
  });

  it("ne requête pas quand aucune intervention n'est fournie", () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <InterventionTypeDetailDialog open onClose={vi.fn()} intervention={null} onOpenFirm={vi.fn()} />
      </QueryClientProvider>,
    );
    expect(getInterventionTypeOfferingsMock).not.toHaveBeenCalled();
  });
});
