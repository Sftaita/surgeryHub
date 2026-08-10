import { describe, it, expect, vi } from "vitest";
import { render, screen, within, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SearchableSelect, type SearchableOption } from "./SearchableSelect";

// D-102 — the 5 ghost-UX scenarios from the Lot 2 spec, exercised directly against the
// shared primitive every instrumentist picker is built on: ABSENT, INACTIVE,
// SCHEDULE_CONFLICT under STRICT_ASSIGNMENT (blocking), SCHEDULE_CONFLICT under
// PLANNING_MODIFICATION (non-blocking — stays a plain selectable option here, since the
// policy decision already happened upstream when `disabled` was computed), and a plain
// eligible candidate.

function openPicker() {
  return screen.getByRole("combobox");
}

function renderSelect(options: SearchableOption[], onChange = vi.fn()) {
  render(
    <SearchableSelect
      label="Instrumentiste"
      options={options}
      value={null}
      onChange={onChange}
    />,
  );
  return { onChange };
}

describe("SearchableSelect — D-102 ghost UX", () => {
  it("ABSENT — renders visible, dimmed, aria-disabled, with a reason badge, and blocks selection", async () => {
    const user = userEvent.setup();
    const { onChange } = renderSelect([
      { id: 1, label: "Sophie Collette", disabled: true, badge: "Absente · 01/08 → 16/08" },
    ]);

    await user.click(openPicker());
    const option = await screen.findByRole("option", { name: /Sophie Collette/ });

    expect(option).toHaveAttribute("aria-disabled", "true");
    expect(within(option).getByText("Absente · 01/08 → 16/08")).toBeInTheDocument();

    // Real MUI disablement (getOptionDisabled) sets pointer-events:none, so a genuine
    // click physically can't land — fireEvent bypasses that guard to prove onChange is
    // still never invoked, belt-and-suspenders on top of the CSS itself.
    fireEvent.click(option);
    expect(onChange).not.toHaveBeenCalled();
  });

  it("INACTIVE — renders visible, disabled, with a reason badge, and blocks selection", async () => {
    const user = userEvent.setup();
    const { onChange } = renderSelect([
      { id: 2, label: "Ancien Compte", disabled: true, badge: "Compte inactif" },
    ]);

    await user.click(openPicker());
    const option = await screen.findByRole("option", { name: /Ancien Compte/ });

    expect(option).toHaveAttribute("aria-disabled", "true");
    expect(within(option).getByText("Compte inactif")).toBeInTheDocument();
    fireEvent.click(option);
    expect(onChange).not.toHaveBeenCalled();
  });

  it("SCHEDULE_CONFLICT under STRICT_ASSIGNMENT — blocking ghost, cannot be selected", async () => {
    const user = userEvent.setup();
    const { onChange } = renderSelect([
      { id: 3, label: "Marc Petit", disabled: true, badge: "Conflit d'horaire (Delta)" },
    ]);

    await user.click(openPicker());
    const option = await screen.findByRole("option", { name: /Marc Petit/ });

    expect(option).toHaveAttribute("aria-disabled", "true");
    expect(within(option).getByText("Conflit d'horaire (Delta)")).toBeInTheDocument();
    fireEvent.click(option);
    expect(onChange).not.toHaveBeenCalled();
  });

  it("SCHEDULE_CONFLICT under PLANNING_MODIFICATION — non-blocking, stays selectable", async () => {
    const user = userEvent.setup();
    const { onChange } = renderSelect([
      { id: 3, label: "Marc Petit", disabled: false, muted: true, badge: "Conflit d'horaire (Delta)" },
    ]);

    await user.click(openPicker());
    const option = await screen.findByRole("option", { name: /Marc Petit/ });

    expect(option).not.toHaveAttribute("aria-disabled", "true");
    expect(within(option).getByText("Conflit d'horaire (Delta)")).toBeInTheDocument();
    await user.click(option);
    expect(onChange).toHaveBeenCalledWith(3);
  });

  it("valid candidate — no badge, fully selectable", async () => {
    const user = userEvent.setup();
    const { onChange } = renderSelect([
      { id: 4, label: "Claire Dubois", disabled: false },
    ]);

    await user.click(openPicker());
    const option = await screen.findByRole("option", { name: "Claire Dubois" });

    expect(option).not.toHaveAttribute("aria-disabled", "true");
    await user.click(option);
    expect(onChange).toHaveBeenCalledWith(4);
  });

  it("does not select a disabled option via keyboard Enter", async () => {
    const user = userEvent.setup();
    const { onChange } = renderSelect([
      { id: 1, label: "Sophie Collette", disabled: true, badge: "Absente" },
      { id: 4, label: "Claire Dubois", disabled: false },
    ]);

    const input = openPicker();
    await user.click(input);
    await user.keyboard("{ArrowDown}{Enter}");

    // MUI Autocomplete's own getOptionDisabled skips disabled options during keyboard
    // navigation — the first Enter should never resolve to the disabled candidate.
    expect(onChange).not.toHaveBeenCalledWith(1);
  });
});
