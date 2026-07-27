import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { EntityHeader } from "./EntityHeader";

describe("EntityHeader", () => {
  it("affiche le nom et le sous-titre quand fourni", () => {
    render(<EntityHeader name="Ada Lovelace" subtitle="ada@test.com" />);
    expect(screen.getByText("Ada Lovelace")).toBeInTheDocument();
    expect(screen.getByText("ada@test.com")).toBeInTheDocument();
  });

  it("n'affiche aucun sous-titre quand absent", () => {
    render(<EntityHeader name="Ada Lovelace" />);
    expect(screen.getByText("Ada Lovelace")).toBeInTheDocument();
    expect(screen.queryByText("ada@test.com")).toBeNull();
  });

  it("affiche les chips (actions/statuts facultatifs) quand fournis", () => {
    render(<EntityHeader name="Ada Lovelace" chips={<span>Actif</span>} />);
    expect(screen.getByText("Actif")).toBeInTheDocument();
  });

  it("n'affiche aucune ligne de chips quand absente — reste générique, aucune logique métier codée en dur", () => {
    const { container } = render(<EntityHeader name="Ada Lovelace" />);
    expect(screen.queryByText("Actif")).toBeNull();
    // Aucune référence codée en dur à un domaine (Instrumentiste/Chirurgien/Firme...).
    expect(container.textContent).not.toMatch(/instrumentiste|chirurgien|firme/i);
  });

  it("affiche l'avatar-initiales à partir du nom quand aucune photo n'est fournie", () => {
    render(<EntityHeader name="Ada Lovelace" />);
    expect(screen.getByText("AL")).toBeInTheDocument();
  });
});
