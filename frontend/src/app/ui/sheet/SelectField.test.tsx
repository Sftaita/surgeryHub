import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SelectField } from "./SelectField";

const OPTIONS = [
  { value: 1, label: "CHIREC - Hôpital Delta" },
  { value: 2, label: "Clinique Saint-Jean" },
];

describe("SelectField", () => {
  it("affiche le placeholder quand aucune valeur n'est sélectionnée", () => {
    render(
      <SelectField
        label="Site"
        placeholder="Sélectionner un site…"
        value={null}
        options={OPTIONS}
        onChange={() => {}}
      />,
    );
    expect(screen.getByText("Sélectionner un site…")).toBeInTheDocument();
  });

  it("affiche le libellé de l'option sélectionnée, pas la valeur brute", () => {
    render(
      <SelectField
        label="Site"
        placeholder="Sélectionner un site…"
        value={2}
        options={OPTIONS}
        onChange={() => {}}
      />,
    );
    expect(screen.getByText("Clinique Saint-Jean")).toBeInTheDocument();
  });

  it("la liste est fermée par défaut", () => {
    render(
      <SelectField
        label="Site"
        placeholder="Sélectionner…"
        value={null}
        options={OPTIONS}
        onChange={() => {}}
      />,
    );
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });

  it("ouvre la liste au clic sur le déclencheur", async () => {
    const user = userEvent.setup();
    render(
      <SelectField
        label="Site"
        placeholder="Sélectionner…"
        value={null}
        options={OPTIONS}
        onChange={() => {}}
      />,
    );
    await user.click(screen.getByRole("combobox"));
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    expect(screen.getAllByRole("option")).toHaveLength(2);
  });

  it("sélectionner une option appelle onChange avec la value et referme la liste", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(
      <SelectField
        label="Site"
        placeholder="Sélectionner…"
        value={null}
        options={OPTIONS}
        onChange={onChange}
      />,
    );
    await user.click(screen.getByRole("combobox"));
    await user.click(screen.getByText("Clinique Saint-Jean"));
    expect(onChange).toHaveBeenCalledWith(2);
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });

  it("touche Échap referme la liste", async () => {
    const user = userEvent.setup();
    render(
      <SelectField
        label="Site"
        placeholder="Sélectionner…"
        value={null}
        options={OPTIONS}
        onChange={() => {}}
      />,
    );
    await user.click(screen.getByRole("combobox"));
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });

  it("un clic en dehors referme la liste", async () => {
    const user = userEvent.setup();
    render(
      <div>
        <SelectField
          label="Site"
          placeholder="Sélectionner…"
          value={null}
          options={OPTIONS}
          onChange={() => {}}
        />
        <button type="button">Ailleurs</button>
      </div>,
    );
    await user.click(screen.getByRole("combobox"));
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    await user.click(screen.getByText("Ailleurs"));
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });

  it("disabled empêche l'ouverture de la liste", async () => {
    const user = userEvent.setup();
    render(
      <SelectField
        label="Site"
        placeholder="Sélectionner…"
        value={null}
        options={OPTIONS}
        onChange={() => {}}
        disabled
      />,
    );
    await user.click(screen.getByRole("combobox"));
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });

  it("marque l'option sélectionnée via aria-selected", async () => {
    const user = userEvent.setup();
    render(
      <SelectField
        label="Site"
        placeholder="Sélectionner…"
        value={2}
        options={OPTIONS}
        onChange={() => {}}
      />,
    );
    await user.click(screen.getByRole("combobox"));
    const options = screen.getAllByRole("option");
    expect(options[0]).toHaveAttribute("aria-selected", "false");
    expect(options[1]).toHaveAttribute("aria-selected", "true");
  });
});
