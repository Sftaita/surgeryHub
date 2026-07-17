import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import AddInterventionDialog from "./AddInterventionDialog";

const TYPES = [
  { id: 1, code: "LCA", label: "Ligamentoplastie croisé antérieur" },
  { id: 2, code: "PTG", label: "Prothèse totale de genou" },
];
const FIRMS = [{ id: 10, name: "Arthrex", active: true }];

describe("AddInterventionDialog — Lot 5 (référentiel fermé)", () => {
  it("envoie interventionTypeId (pas de texte libre) et primaryFirmId quand sélectionnés", async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    render(
      <AddInterventionDialog
        open
        loading={false}
        interventionTypes={TYPES}
        firms={FIRMS}
        existingCount={2}
        onClose={vi.fn()}
        onSubmit={onSubmit}
        onRequestNewType={vi.fn()}
      />,
    );

    await user.click(screen.getByLabelText("Type d'intervention *"));
    await user.click(await screen.findByText("PTG — Prothèse totale de genou"));

    await user.click(screen.getByLabelText("Firme principale (optionnel)"));
    await user.click(await screen.findByRole("option", { name: "Arthrex" }));

    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(onSubmit).toHaveBeenCalledWith({ interventionTypeId: 2, primaryFirmId: 10, orderIndex: 2 });
  });

  it("le bouton Ajouter reste désactivé tant qu'aucun type n'est choisi", () => {
    render(
      <AddInterventionDialog
        open
        loading={false}
        interventionTypes={TYPES}
        firms={FIRMS}
        existingCount={0}
        onClose={vi.fn()}
        onSubmit={vi.fn()}
        onRequestNewType={vi.fn()}
      />,
    );

    expect(screen.getByRole("button", { name: "Ajouter" })).toBeDisabled();
  });

  it("ouvre la demande de nouveau type sans jamais envoyer de texte libre comme intervention", async () => {
    const user = userEvent.setup();
    const onRequestNewType = vi.fn();
    const onSubmit = vi.fn();
    render(
      <AddInterventionDialog
        open
        loading={false}
        interventionTypes={TYPES}
        firms={FIRMS}
        existingCount={0}
        onClose={vi.fn()}
        onSubmit={onSubmit}
        onRequestNewType={onRequestNewType}
      />,
    );

    await user.click(screen.getByText("Faire une demande au manager"));

    expect(onRequestNewType).toHaveBeenCalledTimes(1);
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("catalogue vide : affiche un message clair plutôt qu'un fallback texte", () => {
    render(
      <AddInterventionDialog
        open
        loading={false}
        interventionTypes={[]}
        firms={FIRMS}
        existingCount={0}
        onClose={vi.fn()}
        onSubmit={vi.fn()}
        onRequestNewType={vi.fn()}
      />,
    );

    expect(screen.getByText("Le catalogue est vide pour le moment.")).toBeInTheDocument();
  });
});
