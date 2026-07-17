import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { CloseButton } from "./CloseButton";

describe("CloseButton", () => {
  it("variant close (défaut) — aria-label Fermer, une seule icône", () => {
    render(<CloseButton onClick={() => {}} />);
    const btn = screen.getByRole("button", { name: "Fermer" });
    expect(btn.querySelectorAll("svg")).toHaveLength(1);
  });

  it("variant back — aria-label Retour, les deux icônes (flèche + croix) sont dans le DOM pour le bascule CSS mobile/PC", () => {
    render(<CloseButton onClick={() => {}} variant="back" />);
    const btn = screen.getByRole("button", { name: "Retour" });
    expect(btn.querySelectorAll("svg")).toHaveLength(2);
    expect(screen.queryByRole("button", { name: "Fermer" })).not.toBeInTheDocument();
  });

  it("appelle onClick au clic, quel que soit le variant", async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<CloseButton onClick={onClick} variant="back" />);
    await user.click(screen.getByRole("button", { name: "Retour" }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it("disabled empêche le clic", async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<CloseButton onClick={onClick} disabled />);
    await user.click(screen.getByRole("button", { name: "Fermer" }));
    expect(onClick).not.toHaveBeenCalled();
  });
});
