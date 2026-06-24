import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PersonSearchSelect } from "./PersonSearchSelect";

vi.mock("../../manager-instrumentists/api/instrumentists.api", () => ({
  getInstrumentists: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));

vi.mock("../../manager-surgeons/api/surgeons.api", () => ({
  getSurgeons: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));

import * as instrumentistsApi from "../../manager-instrumentists/api/instrumentists.api";
import * as surgeonsApi from "../../manager-surgeons/api/surgeons.api";

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(instrumentistsApi.getInstrumentists).mockResolvedValue({ items: [], total: 0 });
  vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({ items: [], total: 0 });
});

describe("PersonSearchSelect — recherche serveur débouncée, pas de liste eager", () => {
  it("n'appelle aucune API et n'affiche aucune option au montage", () => {
    render(<PersonSearchSelect label="Personne" value={null} onChange={() => {}} />);

    expect(screen.getByPlaceholderText("Rechercher une personne…")).toBeInTheDocument();
    expect(instrumentistsApi.getInstrumentists).not.toHaveBeenCalled();
    expect(surgeonsApi.getSurgeons).not.toHaveBeenCalled();
  });

  it("interroge les deux endpoints (search/q) seulement après une saisie, et fusionne triée (instrumentistes puis chirurgiens, nom)", async () => {
    vi.mocked(instrumentistsApi.getInstrumentists).mockResolvedValue({
      items: [{ id: 2, email: "diane@test.com", firstname: "Diane", lastname: "Lefebvre", active: true, employmentType: null, defaultCurrency: "EUR", displayName: "Diane Lefebvre" }],
      total: 1,
    });
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
      total: 1,
    });

    const user = userEvent.setup();
    render(<PersonSearchSelect label="Personne" value={null} onChange={() => {}} />);

    await user.type(screen.getByRole("combobox"), "a");

    const options = await screen.findAllByRole("option", undefined, { timeout: 3000 });
    expect(options).toHaveLength(2);
    expect(options[0]).toHaveTextContent("Diane Lefebvre"); // instrumentist first
    expect(options[1]).toHaveTextContent("Jean Martin");

    await waitFor(() => {
      expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledWith({ search: "a" });
      expect(surgeonsApi.getSurgeons).toHaveBeenCalledWith({ q: "a" });
    });
  });

  it("appelle onChange avec la personne complète (id, name, email, role) à la sélection", async () => {
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
      total: 1,
    });
    const onChange = vi.fn();

    const user = userEvent.setup();
    render(<PersonSearchSelect label="Personne" value={null} onChange={onChange} />);
    await user.type(screen.getByRole("combobox"), "Martin");
    await user.click(await screen.findByText("Jean Martin", {}, { timeout: 3000 }));

    expect(onChange).toHaveBeenCalledWith({ id: 1, name: "Jean Martin", firstname: "Jean", lastname: "Martin", email: "martin@test.com", role: "SURGEON" });
  });

  it("trie par nom de famille puis prénom — Dupont avant Martin même si les prénoms inverseraient l'ordre (Zoé Dupont vs Alice Martin)", async () => {
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [
        { id: 1, email: "alice@test.com", firstname: "Alice", lastname: "Martin", displayName: "Alice Martin", active: true, profilePicturePath: null },
        { id: 2, email: "zoe@test.com", firstname: "Zoé", lastname: "Dupont", displayName: "Zoé Dupont", active: true, profilePicturePath: null },
      ],
      total: 2,
    });

    const user = userEvent.setup();
    render(<PersonSearchSelect label="Personne" value={null} onChange={() => {}} />);
    await user.type(screen.getByRole("combobox"), "a");

    const options = await screen.findAllByRole("option", undefined, { timeout: 3000 });
    expect(options).toHaveLength(2);
    // "Zoé" would sort after "Alice" by prénom, but "Dupont" must sort before "Martin" by nom de famille.
    expect(options[0]).toHaveTextContent("Zoé Dupont");
    expect(options[1]).toHaveTextContent("Alice Martin");
  });

  it("trie par prénom quand le nom de famille est identique", async () => {
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [
        { id: 1, email: "zoe@test.com", firstname: "Zoé", lastname: "Martin", displayName: "Zoé Martin", active: true, profilePicturePath: null },
        { id: 2, email: "alice@test.com", firstname: "Alice", lastname: "Martin", displayName: "Alice Martin", active: true, profilePicturePath: null },
      ],
      total: 2,
    });

    const user = userEvent.setup();
    render(<PersonSearchSelect label="Personne" value={null} onChange={() => {}} />);
    await user.type(screen.getByRole("combobox"), "a");

    const options = await screen.findAllByRole("option", undefined, { timeout: 3000 });
    expect(options[0]).toHaveTextContent("Alice Martin");
    expect(options[1]).toHaveTextContent("Zoé Martin");
  });

  it("n'affiche aucune option si le champ est vidé après une recherche", async () => {
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
      total: 1,
    });

    const user = userEvent.setup();
    render(<PersonSearchSelect label="Personne" value={null} onChange={() => {}} />);
    const input = screen.getByRole("combobox");
    await user.type(input, "Martin");
    await screen.findByText("Jean Martin", {}, { timeout: 3000 });

    await user.clear(input);

    await waitFor(() => expect(screen.queryByText("Jean Martin")).not.toBeInTheDocument());
  });
});
