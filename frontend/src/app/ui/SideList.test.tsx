import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SideList, type SideListItem } from "./SideList";

const ITEMS: SideListItem[] = [
  { id: 1, label: "Arthrex", sublabel: "Belgique" },
  { id: 2, label: "Zimmer Biomet" },
];

describe("SideList", () => {
  it("affiche les éléments de la liste", () => {
    render(
      <SideList items={ITEMS} selectedId={null} onSelect={vi.fn()} searchValue="" onSearchChange={vi.fn()} countLabel="Firmes" />,
    );
    expect(screen.getByText("Arthrex")).toBeInTheDocument();
    expect(screen.getByText("Belgique")).toBeInTheDocument();
    expect(screen.getByText("Zimmer Biomet")).toBeInTheDocument();
    expect(screen.getByText("Firmes · 2")).toBeInTheDocument();
  });

  it("appelle le callback de sélection au clic sur un élément", async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(
      <SideList items={ITEMS} selectedId={null} onSelect={onSelect} searchValue="" onSearchChange={vi.fn()} countLabel="Firmes" />,
    );
    await user.click(screen.getByText("Zimmer Biomet"));
    expect(onSelect).toHaveBeenCalledWith(2);
  });

  it("distingue visuellement l'élément actif des autres (classe MUI différente sur le bouton parent)", () => {
    render(
      <SideList items={ITEMS} selectedId={2} onSelect={vi.fn()} searchValue="" onSearchChange={vi.fn()} countLabel="Firmes" />,
    );
    const selectedButton = screen.getByText("Zimmer Biomet").closest("button");
    const unselectedButton = screen.getByText("Arthrex").closest("button");
    // Le style vient d'une classe générée par emotion (sx), pas d'un style inline —
    // on compare donc les classes plutôt qu'un backgroundColor inline toujours vide en jsdom.
    expect(selectedButton?.className).not.toBe(unselectedButton?.className);
  });

  it("affiche l'état vide avec le message par défaut quand la liste est vide", () => {
    render(
      <SideList items={[]} selectedId={null} onSelect={vi.fn()} searchValue="" onSearchChange={vi.fn()} countLabel="Firmes" />,
    );
    expect(screen.getByText("Aucun résultat.")).toBeInTheDocument();
  });

  it("affiche un message d'état vide personnalisé quand fourni", () => {
    render(
      <SideList
        items={[]}
        selectedId={null}
        onSelect={vi.fn()}
        searchValue=""
        onSearchChange={vi.fn()}
        countLabel="Firmes"
        emptyMessage="Sélectionnez une firme."
      />,
    );
    expect(screen.getByText("Sélectionnez une firme.")).toBeInTheDocument();
  });

  it("expose un champ de recherche relié à searchValue/onSearchChange, sans appel API interne", async () => {
    const user = userEvent.setup();
    const onSearchChange = vi.fn();
    render(
      <SideList items={ITEMS} selectedId={null} onSelect={vi.fn()} searchValue="" onSearchChange={onSearchChange} countLabel="Firmes" searchPlaceholder="Rechercher une firme…" />,
    );
    await user.type(screen.getByPlaceholderText("Rechercher une firme…"), "a");
    expect(onSearchChange).toHaveBeenCalledWith("a");
  });
});
