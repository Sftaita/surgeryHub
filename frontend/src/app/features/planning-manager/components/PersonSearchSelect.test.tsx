import * as React from "react";
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
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

function renderSelect(props: Partial<React.ComponentProps<typeof PersonSearchSelect>> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const onChange = props.onChange ?? vi.fn();
  render(
    <QueryClientProvider client={client}>
      <PersonSearchSelect label="Personne" value={null} onChange={onChange} {...props} />
    </QueryClientProvider>,
  );
  return { onChange };
}

describe("PersonSearchSelect — scope (all/instrumentists/surgeons)", () => {
  it('scope="all" (par défaut) appelle les deux API et affiche les deux rôles', async () => {
    vi.mocked(instrumentistsApi.getInstrumentists).mockResolvedValue({
      items: [{ id: 2, email: "diane@test.com", firstname: "Diane", lastname: "Lefebvre", active: true, employmentType: null, defaultCurrency: "EUR", displayName: "Diane Lefebvre" }],
      total: 1,
    });
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
      total: 1,
    });
    const user = userEvent.setup();
    renderSelect();

    await waitFor(() => {
      expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledWith({ active: true });
      expect(surgeonsApi.getSurgeons).toHaveBeenCalledWith({ active: true });
    });

    await user.click(screen.getByRole("combobox"));
    expect(await screen.findByText("Diane Lefebvre", {}, { timeout: 3000 })).toBeInTheDocument();
    expect(screen.getByText("Jean Martin")).toBeInTheDocument();
  });

  it('scope="instrumentists" n\'appelle que getInstrumentists, jamais getSurgeons', async () => {
    vi.mocked(instrumentistsApi.getInstrumentists).mockResolvedValue({
      items: [{ id: 2, email: "diane@test.com", firstname: "Diane", lastname: "Lefebvre", active: true, employmentType: null, defaultCurrency: "EUR", displayName: "Diane Lefebvre" }],
      total: 1,
    });
    const user = userEvent.setup();
    renderSelect({ scope: "instrumentists" });

    await waitFor(() => expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledWith({ active: true }));
    expect(surgeonsApi.getSurgeons).not.toHaveBeenCalled();

    await user.click(screen.getByRole("combobox"));
    expect(await screen.findByText("Diane Lefebvre", {}, { timeout: 3000 })).toBeInTheDocument();
  });

  it('scope="surgeons" n\'appelle que getSurgeons, jamais getInstrumentists', async () => {
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
      total: 1,
    });
    const user = userEvent.setup();
    renderSelect({ scope: "surgeons" });

    await waitFor(() => expect(surgeonsApi.getSurgeons).toHaveBeenCalledWith({ active: true }));
    expect(instrumentistsApi.getInstrumentists).not.toHaveBeenCalled();

    await user.click(screen.getByRole("combobox"));
    expect(await screen.findByText("Jean Martin", {}, { timeout: 3000 })).toBeInTheDocument();
  });

  it('scope="instrumentists" garde le tri (nom → prénom) et la recherche locale sans nouvel appel réseau', async () => {
    vi.mocked(instrumentistsApi.getInstrumentists).mockResolvedValue({
      items: [
        { id: 1, email: "alice@test.com", firstname: "Alice", lastname: "Martin", active: true, employmentType: null, defaultCurrency: "EUR", displayName: "Alice Martin" },
        { id: 2, email: "zoe@test.com", firstname: "Zoé", lastname: "Dupont", active: true, employmentType: null, defaultCurrency: "EUR", displayName: "Zoé Dupont" },
      ],
      total: 2,
    });
    const user = userEvent.setup();
    renderSelect({ scope: "instrumentists" });
    const input = screen.getByRole("combobox");
    await user.click(input);

    const options = await screen.findAllByRole("option", undefined, { timeout: 3000 });
    expect(options).toHaveLength(2);
    expect(options[0]).toHaveTextContent("Zoé Dupont");
    expect(options[1]).toHaveTextContent("Alice Martin");

    await user.type(input, "alice");
    expect(screen.getByText("Alice Martin")).toBeInTheDocument();
    expect(screen.queryByText("Zoé Dupont")).not.toBeInTheDocument();
    expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledTimes(1);
  });

  it('scope="surgeons" garde le tri (nom → prénom) et la recherche locale sans nouvel appel réseau', async () => {
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [
        { id: 1, email: "alice@test.com", firstname: "Alice", lastname: "Martin", displayName: "Alice Martin", active: true, profilePicturePath: null },
        { id: 2, email: "zoe@test.com", firstname: "Zoé", lastname: "Dupont", displayName: "Zoé Dupont", active: true, profilePicturePath: null },
      ],
      total: 2,
    });
    const user = userEvent.setup();
    renderSelect({ scope: "surgeons" });
    const input = screen.getByRole("combobox");
    await user.click(input);

    const options = await screen.findAllByRole("option", undefined, { timeout: 3000 });
    expect(options).toHaveLength(2);
    expect(options[0]).toHaveTextContent("Zoé Dupont");
    expect(options[1]).toHaveTextContent("Alice Martin");

    await user.type(input, "alice");
    expect(screen.getByText("Alice Martin")).toBeInTheDocument();
    expect(screen.queryByText("Zoé Dupont")).not.toBeInTheDocument();
    expect(surgeonsApi.getSurgeons).toHaveBeenCalledTimes(1);
  });
});

describe("PersonSearchSelect — chargement unique, filtrage 100% client", () => {
  it("charge la liste des actifs une seule fois au montage (active=true), jamais pendant la frappe", async () => {
    renderSelect();

    await waitFor(() => {
      expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledWith({ active: true });
      expect(surgeonsApi.getSurgeons).toHaveBeenCalledWith({ active: true });
    });
    expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledTimes(1);
    expect(surgeonsApi.getSurgeons).toHaveBeenCalledTimes(1);

    const user = userEvent.setup();
    await user.type(screen.getByRole("combobox"), "Martin");

    expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledTimes(1);
    expect(surgeonsApi.getSurgeons).toHaveBeenCalledTimes(1);
  });

  it("ouvre la liste immédiatement au focus, avant toute saisie", async () => {
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
      total: 1,
    });
    const user = userEvent.setup();
    renderSelect();

    await user.click(screen.getByRole("combobox"));

    expect(await screen.findByText("Jean Martin", {}, { timeout: 3000 })).toBeInTheDocument();
  });

  it("filtre instantanément côté client sur prénom, nom, email ou rôle, sans nouvel appel API", async () => {
    vi.mocked(instrumentistsApi.getInstrumentists).mockResolvedValue({
      items: [{ id: 2, email: "diane@test.com", firstname: "Diane", lastname: "Lefebvre", active: true, employmentType: null, defaultCurrency: "EUR", displayName: "Diane Lefebvre" }],
      total: 1,
    });
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
      total: 1,
    });
    const user = userEvent.setup();
    renderSelect();
    const input = screen.getByRole("combobox");
    await user.click(input);
    await screen.findByText("Diane Lefebvre", {}, { timeout: 3000 });

    await user.type(input, "Martin");
    expect(screen.getByText("Jean Martin")).toBeInTheDocument();
    expect(screen.queryByText("Diane Lefebvre")).not.toBeInTheDocument();

    await user.clear(input);
    await user.type(input, "diane@test.com");
    expect(screen.getByText("Diane Lefebvre")).toBeInTheDocument();
    expect(screen.queryByText("Jean Martin")).not.toBeInTheDocument();

    await user.clear(input);
    await user.type(input, "chirurgien");
    expect(screen.getByText("Jean Martin")).toBeInTheDocument();
    expect(screen.queryByText("Diane Lefebvre")).not.toBeInTheDocument();

    expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledTimes(1);
    expect(surgeonsApi.getSurgeons).toHaveBeenCalledTimes(1);
  });

  it("appelle onChange avec la personne complète (id, name, email, role) à la sélection", async () => {
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
      total: 1,
    });
    const user = userEvent.setup();
    const { onChange } = renderSelect();
    const input = screen.getByRole("combobox");
    await user.click(input);
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
    renderSelect();
    await user.click(screen.getByRole("combobox"));

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
    renderSelect();
    await user.click(screen.getByRole("combobox"));

    const options = await screen.findAllByRole("option", undefined, { timeout: 3000 });
    expect(options[0]).toHaveTextContent("Alice Martin");
    expect(options[1]).toHaveTextContent("Zoé Martin");
  });

  it("trie les instrumentistes avant les chirurgiens", async () => {
    vi.mocked(instrumentistsApi.getInstrumentists).mockResolvedValue({
      items: [{ id: 2, email: "diane@test.com", firstname: "Diane", lastname: "Zorro", active: true, employmentType: null, defaultCurrency: "EUR", displayName: "Diane Zorro" }],
      total: 1,
    });
    vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
      items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Albert", displayName: "Jean Albert", active: true, profilePicturePath: null }],
      total: 1,
    });

    const user = userEvent.setup();
    renderSelect();
    await user.click(screen.getByRole("combobox"));

    const options = await screen.findAllByRole("option", undefined, { timeout: 3000 });
    expect(options[0]).toHaveTextContent("Diane Zorro"); // instrumentist, despite "Z" > "A" alphabetically
    expect(options[1]).toHaveTextContent("Jean Albert");
  });

  it("affiche un indicateur de chargement uniquement pendant le chargement initial, jamais pendant la frappe", async () => {
    let resolveInstrumentists!: (v: { items: never[]; total: number }) => void;
    vi.mocked(instrumentistsApi.getInstrumentists).mockReturnValue(
      new Promise((resolve) => { resolveInstrumentists = resolve; }),
    );

    renderSelect();
    expect(screen.getByRole("progressbar")).toBeInTheDocument();

    resolveInstrumentists({ items: [], total: 0 });
    await waitFor(() => expect(screen.queryByRole("progressbar")).not.toBeInTheDocument());

    const user = userEvent.setup();
    await user.type(screen.getByRole("combobox"), "anything");
    expect(screen.queryByRole("progressbar")).not.toBeInTheDocument();
  });
});
