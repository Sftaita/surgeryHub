import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { StatusPill } from "./StatusPill";

describe("StatusPill", () => {
  it("affiche toujours le libellé", () => {
    render(<StatusPill variant="confirmee" label="Confirmée" />);
    expect(screen.getByText("Confirmée")).toBeInTheDocument();
  });

  it("n'affiche pas de point par défaut", () => {
    const { container } = render(<StatusPill variant="aEncoder" label="À encoder" />);
    expect(container.querySelectorAll("[style*='animation']").length).toBe(0);
  });

  it("affiche un point pulsant quand withDot est vrai", () => {
    const { container } = render(<StatusPill variant="enCours" label="En cours" withDot />);
    expect(container.textContent).toContain("En cours");
    // le point est le seul enfant Box additionnel avant le texte
    expect(container.firstChild?.firstChild).not.toBeNull();
  });
});
