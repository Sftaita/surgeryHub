import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import InterventionTypeRequestDialog from "./InterventionTypeRequestDialog";

describe("InterventionTypeRequestDialog", () => {
  it("envoie label + code suggéré + commentaire", async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();
    render(<InterventionTypeRequestDialog open loading={false} onClose={vi.fn()} onSubmit={onSubmit} />);

    await user.type(screen.getByLabelText("Type d'intervention souhaité *"), "Prothèse épaule inversée");
    await user.type(screen.getByLabelText("Code suggéré"), "pte-inv");
    await user.type(screen.getByLabelText("Commentaire"), "Urgent");
    await user.click(screen.getByRole("button", { name: "Envoyer la demande" }));

    expect(onSubmit).toHaveBeenCalledWith({
      label: "Prothèse épaule inversée",
      suggestedCode: "PTE-INV",
      comment: "Urgent",
    });
  });

  it("reste désactivé sans label", () => {
    render(<InterventionTypeRequestDialog open loading={false} onClose={vi.fn()} onSubmit={vi.fn()} />);
    expect(screen.getByRole("button", { name: "Envoyer la demande" })).toBeDisabled();
  });
});
