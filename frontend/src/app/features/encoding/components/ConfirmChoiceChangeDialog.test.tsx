import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import ConfirmChoiceChangeDialog from "./ConfirmChoiceChangeDialog";

describe("ConfirmChoiceChangeDialog", () => {
  it("affiche le message serveur et appelle onConfirm au clic sur Changer", async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    const onClose = vi.fn();
    render(
      <ConfirmChoiceChangeDialog
        open
        loading={false}
        message='Le matériel « Cage Signature » est actuellement encodé pour cette intervention. En sélectionnant « Altera », il devra être retiré.'
        onClose={onClose}
        onConfirm={onConfirm}
      />,
    );

    expect(screen.getByText(/Cage Signature/)).toBeInTheDocument();
    expect(screen.getByText(/Altera/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Changer" }));
    expect(onConfirm).toHaveBeenCalled();
  });

  it("Annuler ferme sans confirmer", async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    const onClose = vi.fn();
    render(
      <ConfirmChoiceChangeDialog open loading={false} message="msg" onClose={onClose} onConfirm={onConfirm} />,
    );

    await user.click(screen.getByRole("button", { name: "Annuler" }));
    expect(onClose).toHaveBeenCalled();
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("désactive les boutons pendant loading", () => {
    render(
      <ConfirmChoiceChangeDialog open loading message="msg" onClose={vi.fn()} onConfirm={vi.fn()} />,
    );
    expect(screen.getByRole("button", { name: "…" })).toBeDisabled();
  });
});
