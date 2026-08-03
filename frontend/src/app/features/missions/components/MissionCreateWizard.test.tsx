import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import dayjs from "dayjs";
import MissionCreateWizard from "./MissionCreateWizard";

const SITES = [{ id: 1, name: "Site Delta" }];
const SURGEONS = [{ id: 2, label: "Dr Martin" }];

function renderWizard() {
  return render(
    <MissionCreateWizard
      sites={SITES}
      surgeons={SURGEONS}
      onDone={vi.fn()}
      onCancel={vi.fn()}
    />,
  );
}

describe("MissionCreateWizard — horaires par défaut du formulaire (jamais une règle backend)", () => {
  it("préremplit Début 08:00 et Fin 17:00 le jour même, modifiables librement", async () => {
    const user = userEvent.setup();
    renderWizard();

    await user.click(screen.getByLabelText("Site"));
    await user.click(await screen.findByText("Site Delta"));
    await user.click(screen.getByLabelText("Chirurgien"));
    await user.click(await screen.findByText("Dr Martin"));
    await user.click(screen.getByRole("button", { name: "Continuer" }));

    const today = dayjs().format("YYYY-MM-DD");
    const startField = (await screen.findByLabelText("Début")) as HTMLInputElement;
    const endField = screen.getByLabelText("Fin") as HTMLInputElement;

    expect(startField.value).toBe(`${today}T08:00`);
    expect(endField.value).toBe(`${today}T17:00`);

    // Valeur de formulaire uniquement — librement modifiable, aucune contrainte.
    await user.clear(startField);
    await user.type(startField, `${today}T06:30`);
    expect(startField.value).toBe(`${today}T06:30`);
  });
});
