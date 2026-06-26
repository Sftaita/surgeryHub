import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PostFormDialog } from "./PostFormDialog";
import type { Site } from "../../sites/api/sites.api";
import type { SearchableOption } from "./SearchableSelect";

const sites: Site[] = [{ id: 1, name: "Alpha" }];
const surgeons: SearchableOption[] = [{ id: 1, label: "Dr Martin" }];
const instrumentists: SearchableOption[] = [];

function renderDialog(onSubmit = vi.fn()) {
  render(
    <PostFormDialog
      open
      onClose={() => {}}
      onSubmit={onSubmit}
      submitting={false}
      sites={sites}
      surgeons={surgeons}
      instrumentists={instrumentists}
    />
  );
  return { onSubmit };
}

// SearchableSelect's label is a plain <label> with no `for` attribute (MUI Autocomplete
// generates its own input id), so getByLabelText can't associate them — find the visible
// label text instead and look up its sibling combobox within the same wrapper Box.
async function pickAutocomplete(user: ReturnType<typeof userEvent.setup>, labelText: string, optionText: string) {
  const label = screen.getByText(labelText);
  const container = label.closest("div");
  if (!container) throw new Error(`No container found for label "${labelText}"`);
  const input = within(container).getByRole("combobox");
  await user.click(input);
  await user.click(await screen.findByText(optionText));
}

describe("PostFormDialog — récurrence mensuelle (Batch 14C)", () => {
  it("désactive l'enregistrement tant qu'aucun jour de la semaine n'est sélectionné en mode mensuel", async () => {
    const user = userEvent.setup();
    renderDialog();

    await pickAutocomplete(user, "Chirurgien", "Dr Martin");
    await pickAutocomplete(user, "Site", "Alpha");
    await pickAutocomplete(user, "Récurrence", "Certains jours du mois");

    // Deselect the default weekday (Lundi) without picking another one.
    await user.click(screen.getByRole("button", { name: "Lundi" }));

    expect(screen.getByRole("button", { name: /Enregistrer le poste/i })).toBeDisabled();
  });

  it("désactive l'enregistrement tant qu'aucune occurrence (1er..5e) n'est sélectionnée en mode mensuel", async () => {
    const user = userEvent.setup();
    renderDialog();

    await pickAutocomplete(user, "Chirurgien", "Dr Martin");
    await pickAutocomplete(user, "Site", "Alpha");
    await pickAutocomplete(user, "Récurrence", "Certains jours du mois");

    // Deselect the default occurrence (1er) without picking another one.
    await user.click(screen.getByRole("button", { name: "1er" }));
    await user.click(screen.getByRole("button", { name: "Jeudi" }));

    expect(screen.getByRole("button", { name: /Enregistrer le poste/i })).toBeDisabled();
  });

  it("envoie weekdays + monthWeeks, sans monthlyNthWeekday, pour '2e et 3e jeudi du mois'", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderDialog();

    await pickAutocomplete(user, "Chirurgien", "Dr Martin");
    await pickAutocomplete(user, "Site", "Alpha");
    await pickAutocomplete(user, "Récurrence", "Certains jours du mois");

    // Default occurrence is [1er] — deselect it, then pick 2e and 3e.
    await user.click(screen.getByRole("button", { name: "1er" }));
    await user.click(screen.getByRole("button", { name: "2e" }));
    await user.click(screen.getByRole("button", { name: "3e" }));

    // Default weekday is [Lundi] — deselect it, then pick Jeudi.
    await user.click(screen.getByRole("button", { name: "Lundi" }));
    await user.click(screen.getByRole("button", { name: "Jeudi" }));

    const submit = screen.getByRole("button", { name: /Enregistrer le poste/i });
    expect(submit).toBeEnabled();
    await user.click(submit);

    expect(onSubmit).toHaveBeenCalledTimes(1);
    const payload = onSubmit.mock.calls[0][0];
    expect(payload.recurrence).toEqual({
      frequency: "MONTHLY",
      interval: 1,
      weekdays: [4],
      monthWeeks: [2, 3],
      anchorDate: payload.startDate,
    });
    expect(payload.recurrence).not.toHaveProperty("monthlyNthWeekday");
  });
});
