import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { DateTile } from "./DateTile";

describe("DateTile", () => {
  it("affiche le jour et le mois", () => {
    render(<DateTile day="05" month="JUIL" variant="confirmee" />);
    expect(screen.getByText("05")).toBeInTheDocument();
    expect(screen.getByText("JUIL")).toBeInTheDocument();
  });

  it("applique les dimensions du préréglage demandé", () => {
    const { container } = render(<DateTile day="05" month="JUIL" variant="proposee" preset="offer" />);
    expect(container.firstChild).toHaveStyle({ width: "54px", height: "58px" });
  });

  it("préréglage par défaut = list (50x54)", () => {
    const { container } = render(<DateTile day="05" month="JUIL" variant="proposee" />);
    expect(container.firstChild).toHaveStyle({ width: "50px", height: "54px" });
  });
});
