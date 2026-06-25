import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import AbsencesPage, { getIsolatedDatesToSubmit } from "./AbsencesPage";
import type { Absence } from "../../../features/planning-manager/api/planning.api";

vi.mock("../../../features/planning-manager/api/planning.api", () => ({
  getAbsences: vi.fn().mockResolvedValue([]),
  createAbsence: vi.fn(),
  createIsolatedDayAbsences: vi.fn(),
  deleteAbsence: vi.fn(),
  getMissingAbsencesPreview: vi.fn().mockResolvedValue({ count: 0, people: [] }),
  getEncodedAbsencesPreview: vi.fn().mockResolvedValue({ count: 0, groups: [] }),
  requestMissingAbsences: vi.fn(),
  confirmEncodedAbsences: vi.fn(),
}));

vi.mock("../../../features/manager-instrumentists/api/instrumentists.api", () => ({
  getInstrumentists: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));

vi.mock("../../../features/manager-surgeons/api/surgeons.api", () => ({
  getSurgeons: vi.fn().mockResolvedValue({ items: [], total: 0 }),
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}));

import * as planningApi from "../../../features/planning-manager/api/planning.api";
import * as instrumentistsApi from "../../../features/manager-instrumentists/api/instrumentists.api";
import * as surgeonsApi from "../../../features/manager-surgeons/api/surgeons.api";

function makeAbsence(overrides: Partial<Absence> = {}): Absence {
  return {
    id: 1,
    user: { id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", role: "SURGEON" },
    dateStart: "2026-07-01",
    dateEnd: "2026-07-15",
    reason: null,
    createdAt: "2026-06-24T00:00:00Z",
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AbsencesPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  // clearAllMocks() does not reset mockResolvedValue() — restore the safe defaults here so a
  // test that populated data doesn't leak it into the next test in this file.
  vi.mocked(planningApi.getAbsences).mockResolvedValue([]);
  vi.mocked(instrumentistsApi.getInstrumentists).mockResolvedValue({ items: [], total: 0 });
  vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({ items: [], total: 0 });
});

/** Mocks the surgeon "Jean Martin" as part of PersonSearchSelect's once-loaded active list. */
function mockSearchablePerson() {
  vi.mocked(surgeonsApi.getSurgeons).mockResolvedValue({
    items: [{ id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", displayName: "Jean Martin", active: true, profilePicturePath: null }],
    total: 1,
  });
}

async function openCreateDialog(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole("button", { name: /Nouvelle absence/i }));
  const label = screen.getByText("Personne");
  const container = label.closest("div")!;
  const input = within(container).getByRole("combobox");
  await user.click(input);
  await user.click(await screen.findByText("Jean Martin", {}, { timeout: 3000 }));
}

describe("getIsolatedDatesToSubmit() — fusion champ + chips, dédoublonnée", () => {
  it("ajoute la date du champ quand la liste de chips est vide", () => {
    expect(getIsolatedDatesToSubmit([], "2026-07-04")).toEqual(["2026-07-04"]);
  });

  it("renvoie la liste de chips inchangée quand le champ est vide", () => {
    expect(getIsolatedDatesToSubmit(["2026-07-04"], "")).toEqual(["2026-07-04"]);
  });

  it("fusionne le champ avec les chips existants, trié", () => {
    expect(getIsolatedDatesToSubmit(["2026-07-18"], "2026-07-04")).toEqual(["2026-07-04", "2026-07-18"]);
  });

  it("ne duplique pas si la date du champ est déjà dans les chips", () => {
    expect(getIsolatedDatesToSubmit(["2026-07-04", "2026-07-18"], "2026-07-04")).toEqual(["2026-07-04", "2026-07-18"]);
  });
});

describe("AbsencesPage — sélecteur personne : liste chargée une fois, filtrée côté client", () => {
  it("précharge la liste des actifs dès le montage de la page, pas seulement à l'ouverture du dialogue", async () => {
    renderPage();

    await waitFor(() => {
      expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledWith({ active: true });
      expect(surgeonsApi.getSurgeons).toHaveBeenCalledWith({ active: true });
    });
  });

  it("ouvre la liste complète au focus puis filtre côté client en tapant, avatar+rôle+email affichés, sans nouvel appel API", async () => {
    mockSearchablePerson();
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(surgeonsApi.getSurgeons).toHaveBeenCalledTimes(1));

    await user.click(screen.getByRole("button", { name: /Nouvelle absence/i }));
    const input = within(screen.getByText("Personne").closest("div")!).getByRole("combobox");
    await user.click(input);
    expect(await screen.findByText("Jean Martin", {}, { timeout: 3000 })).toBeInTheDocument();

    await user.type(input, "Martin");
    expect(screen.getByText("Jean Martin")).toBeInTheDocument();
    expect(screen.getByText(/Chirurgien · martin@test.com/)).toBeInTheDocument();

    // Filtering while typing must never trigger another network call — it's all client-side now.
    expect(surgeonsApi.getSurgeons).toHaveBeenCalledTimes(1);
    expect(instrumentistsApi.getInstrumentists).toHaveBeenCalledTimes(1);
  });
});

describe("AbsencesPage — table principale : identité, tri, recherche, filtres", () => {
  function mockAbsences() {
    vi.mocked(planningApi.getAbsences).mockResolvedValue([
      makeAbsence({ id: 1, user: { id: 1, email: "martin@test.com", firstname: "Jean", lastname: "Martin", role: "SURGEON" } }),
      makeAbsence({ id: 2, user: { id: 2, email: "diane@test.com", firstname: "Diane", lastname: "Lefebvre", role: "INSTRUMENTIST" } }),
    ]);
  }

  it("affiche prénom/nom/rôle/email au lieu de l'email seul comme libellé", async () => {
    mockAbsences();
    renderPage();

    expect(await screen.findByText(/Jean Martin/)).toBeInTheDocument();
    expect(screen.getByText("(Chirurgien)")).toBeInTheDocument();
    expect(screen.getByText("martin@test.com")).toBeInTheDocument();
    expect(screen.getByText(/Diane Lefebvre/)).toBeInTheDocument();
    expect(screen.getByText("(Instrumentiste)")).toBeInTheDocument();
  });

  it("trie par rôle (instrumentistes puis chirurgiens) puis nom de famille", async () => {
    mockAbsences();
    renderPage();
    await screen.findByText(/Jean Martin/);

    const rows = screen.getAllByRole("row").slice(1); // skip header
    expect(within(rows[0]).getByText(/Diane Lefebvre/)).toBeInTheDocument(); // INSTRUMENTIST first
    expect(within(rows[1]).getByText(/Jean Martin/)).toBeInTheDocument();
  });

  it("filtre dynamiquement par recherche (nom, email, rôle)", async () => {
    mockAbsences();
    const user = userEvent.setup();
    renderPage();
    await screen.findByText(/Jean Martin/);

    await user.type(screen.getByPlaceholderText("Rechercher (nom, email, rôle)…"), "diane");

    expect(screen.queryByText(/Jean Martin/)).not.toBeInTheDocument();
    expect(screen.getByText(/Diane Lefebvre/)).toBeInTheDocument();
  });

  it("filtre rapide Instrumentistes ne montre que les instrumentistes", async () => {
    mockAbsences();
    const user = userEvent.setup();
    renderPage();
    await screen.findByText(/Jean Martin/);

    await user.click(screen.getByText(/Instrumentistes \(1\)/));

    expect(screen.queryByText(/Jean Martin/)).not.toBeInTheDocument();
    expect(screen.getByText(/Diane Lefebvre/)).toBeInTheDocument();
  });

  it("le bouton « Demander les congés » ouvre le dialogue correspondant", async () => {
    mockAbsences();
    const user = userEvent.setup();
    renderPage();
    await screen.findByText(/Jean Martin/);

    await user.click(screen.getByRole("button", { name: "Demander les congés" }));

    expect(await screen.findByRole("heading", { name: "Demander les congés" })).toBeInTheDocument();
  });

  it("le bouton « Confirmer les congés encodés » ouvre le dialogue correspondant", async () => {
    mockAbsences();
    const user = userEvent.setup();
    renderPage();
    await screen.findByText(/Jean Martin/);

    await user.click(screen.getByRole("button", { name: "Confirmer les congés encodés" }));

    expect(await screen.findByRole("heading", { name: "Confirmer les congés encodés" })).toBeInTheDocument();
  });
});

describe("AbsencesPage — historique (absences passées masquées par défaut)", () => {
  it("appelle getAbsences avec from=aujourd'hui par défaut (historique décoché)", async () => {
    renderPage();
    await waitFor(() => expect(planningApi.getAbsences).toHaveBeenCalled());

    const call = vi.mocked(planningApi.getAbsences).mock.calls[0][0];
    expect(call).toEqual({ from: expect.any(String) });
  });

  it("recharge sans filtre `from` quand on coche « Afficher l'historique »", async () => {
    const user = userEvent.setup();
    renderPage();
    await waitFor(() => expect(planningApi.getAbsences).toHaveBeenCalled());

    await user.click(screen.getByRole("checkbox", { name: /Afficher l'historique/i }));

    await waitFor(() => {
      const lastCall = vi.mocked(planningApi.getAbsences).mock.calls.at(-1)![0];
      expect(lastCall).toBeUndefined();
    });
  });
});

describe("AbsencesPage — mise à jour optimiste", () => {
  it("la nouvelle absence (période) apparaît immédiatement, avant la résolution de l'appel réseau", async () => {
    mockSearchablePerson();
    let resolveCreate!: (v: Absence) => void;
    vi.mocked(planningApi.createAbsence).mockImplementation(() => new Promise((resolve) => { resolveCreate = resolve; }));

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    // Visible immediately, before the network promise resolves.
    expect(await screen.findByText(/Jean Martin/)).toBeInTheDocument();

    resolveCreate(makeAbsence({ id: 99 }));
    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalledTimes(1));
  });

  it("rollback et toast d'erreur si la création échoue", async () => {
    mockSearchablePerson();
    let rejectCreate!: (e: Error) => void;
    vi.mocked(planningApi.createAbsence).mockImplementation(() => new Promise((_, reject) => { rejectCreate = reject; }));

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(await screen.findByText(/Jean Martin/)).toBeInTheDocument(); // optimistic row shown

    rejectCreate(new Error("boom"));
    await waitFor(() => expect(screen.queryByText(/Jean Martin/)).not.toBeInTheDocument()); // rolled back
  });

  it("la ligne disparaît immédiatement à la suppression, avant la résolution de l'appel réseau", async () => {
    vi.mocked(planningApi.getAbsences).mockResolvedValue([makeAbsence({ id: 1 })]);
    let resolveDelete!: () => void;
    vi.mocked(planningApi.deleteAbsence).mockImplementation(() => new Promise((resolve) => { resolveDelete = resolve; }));

    const user = userEvent.setup();
    renderPage();
    await screen.findByText(/Jean Martin/);

    await user.click(screen.getByRole("button", { name: "Supprimer" }));

    await waitFor(() => expect(screen.queryByText(/Jean Martin/)).not.toBeInTheDocument());
    resolveDelete();
    await waitFor(() => expect(planningApi.deleteAbsence).toHaveBeenCalledTimes(1));
  });
});

describe("AbsencesPage — garde anti-double-soumission sur Enregistrer", () => {
  it("double-clic rapide en mode période → un seul appel createAbsence", async () => {
    mockSearchablePerson();
    let resolveCreate!: (v: Absence) => void;
    vi.mocked(planningApi.createAbsence).mockImplementation(() => new Promise((resolve) => { resolveCreate = resolve; }));

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);

    const submitBtn = screen.getByRole("button", { name: "Enregistrer" });
    fireEvent.click(submitBtn);
    fireEvent.click(submitBtn);
    fireEvent.click(submitBtn);

    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalled());
    resolveCreate(makeAbsence({ id: 99 }));
    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalledTimes(1));
  });

  it("double-clic rapide en mode jours isolés → un seul batch createIsolatedDayAbsences", async () => {
    mockSearchablePerson();
    let resolveCreate!: (v: Absence[]) => void;
    vi.mocked(planningApi.createIsolatedDayAbsences).mockImplementation(
      () => new Promise((resolve) => { resolveCreate = resolve; }),
    );

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Jours isolés" }));
    const dateInput = screen.getByLabelText("Ajouter une date");
    await user.clear(dateInput);
    await user.type(dateInput, "2026-07-04");

    const submitBtn = screen.getByRole("button", { name: "Enregistrer" });
    fireEvent.click(submitBtn);
    fireEvent.click(submitBtn);
    fireEvent.click(submitBtn);

    await waitFor(() => expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalled());
    resolveCreate([makeAbsence({ id: 99 })]);
    await waitFor(() => expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledTimes(1));
    expect(planningApi.createAbsence).not.toHaveBeenCalled();
  });

  it("la garde se libère après un succès — un nouveau Enregistrer fonctionne ensuite normalement", async () => {
    mockSearchablePerson();
    vi.mocked(planningApi.createAbsence).mockResolvedValue(makeAbsence({ id: 99 }));

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalledTimes(1));
    // Wait out MUI's Dialog exit transition (the rest of the page is aria-hidden until then).
    await waitFor(() => expect(screen.queryByText("Personne")).not.toBeInTheDocument());

    // Open a second create flow after the first one fully settled.
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalledTimes(2));
  });

  it("la garde se libère après une erreur — un nouveau Enregistrer fonctionne ensuite normalement", async () => {
    mockSearchablePerson();
    vi.mocked(planningApi.createAbsence).mockRejectedValueOnce(new Error("boom"));

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalledTimes(1));
    // Failed mutation: onMutate had already optimistically closed the dialog, but the row
    // gets rolled back — wait for that rollback before reopening.
    await waitFor(() => expect(screen.queryByText("Personne")).not.toBeInTheDocument());

    vi.mocked(planningApi.createAbsence).mockResolvedValueOnce(makeAbsence({ id: 99 }));
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalledTimes(2));
  });

});

describe("AbsencesPage — non-régression mode Période (Cas 1)", () => {
  it("garde le comportement Du/Au existant : un seul appel createAbsence", async () => {
    mockSearchablePerson();
    vi.mocked(planningApi.createAbsence).mockResolvedValue(makeAbsence());

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);

    // "Période" is the default mode — no extra interaction needed.
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => expect(planningApi.createAbsence).toHaveBeenCalledTimes(1));
    expect(planningApi.createIsolatedDayAbsences).not.toHaveBeenCalled();
    expect(planningApi.createAbsence).toHaveBeenCalledWith(
      expect.objectContaining({ userId: 1, dateStart: expect.any(String), dateEnd: expect.any(String) }),
    );
  });
});

describe("AbsencesPage — mode Jours isolés (Cas 3)", () => {
  it("désactive Enregistrer quand le champ est vide et aucun jour n'est ajouté", async () => {
    mockSearchablePerson();
    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Jours isolés" }));

    // The field is pre-filled with today's date by default — clear it to test true emptiness.
    await user.clear(screen.getByLabelText("Ajouter une date"));

    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeDisabled();
  });

  it("active Enregistrer dès qu'une date valide est dans le champ, même sans cliquer Ajouter (régression bug prod)", async () => {
    mockSearchablePerson();
    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Jours isolés" }));

    const dateInput = screen.getByLabelText("Ajouter une date");
    await user.clear(dateInput);
    await user.type(dateInput, "2026-07-04");

    // No click on "Ajouter" — the field has a date but no chip exists yet.
    expect(screen.queryByText("04/07/2026")).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeEnabled();
  });

  it("clic sur Ajouter puis Enregistrer envoie tous les jours en un seul appel groupé (flux existant)", async () => {
    mockSearchablePerson();
    vi.mocked(planningApi.createIsolatedDayAbsences).mockResolvedValue([
      makeAbsence({ id: 1, dateStart: "2026-07-04", dateEnd: "2026-07-04" }),
      makeAbsence({ id: 2, dateStart: "2026-07-09", dateEnd: "2026-07-09" }),
      makeAbsence({ id: 3, dateStart: "2026-07-18", dateEnd: "2026-07-18" }),
    ]);

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Jours isolés" }));

    const dateInput = screen.getByLabelText("Ajouter une date");
    for (const date of ["2026-07-04", "2026-07-09", "2026-07-18"]) {
      await user.clear(dateInput);
      await user.type(dateInput, date);
      await user.click(screen.getByRole("button", { name: "Ajouter" }));
    }

    expect(screen.getByText("04/07/2026")).toBeInTheDocument();
    expect(screen.getByText("09/07/2026")).toBeInTheDocument();
    expect(screen.getByText("18/07/2026")).toBeInTheDocument();

    const enregistrer = screen.getByRole("button", { name: "Enregistrer" });
    expect(enregistrer).toBeEnabled();
    await user.click(enregistrer);

    await waitFor(() => expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledTimes(1));
    expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledWith({
      userId: 1, dates: ["2026-07-04", "2026-07-09", "2026-07-18"], reason: undefined,
    });
    expect(planningApi.createAbsence).not.toHaveBeenCalled();
  });

  it("choisir une date puis cliquer directement Enregistrer sans cliquer Ajouter crée une absence dateStart=dateEnd", async () => {
    mockSearchablePerson();
    vi.mocked(planningApi.createIsolatedDayAbsences).mockResolvedValue([
      makeAbsence({ id: 1, dateStart: "2026-07-04", dateEnd: "2026-07-04" }),
    ]);

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Jours isolés" }));

    const dateInput = screen.getByLabelText("Ajouter une date");
    await user.clear(dateInput);
    await user.type(dateInput, "2026-07-04");

    // Directly click Enregistrer — never clicked "Ajouter", no chip was ever shown.
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledTimes(1));
    expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledWith({
      userId: 1, dates: ["2026-07-04"], reason: undefined,
    });
    expect(planningApi.createAbsence).not.toHaveBeenCalled();
  });

  it("date déjà en chip + même date dans le champ → une seule absence créée (pas de doublon)", async () => {
    mockSearchablePerson();
    vi.mocked(planningApi.createIsolatedDayAbsences).mockResolvedValue([
      makeAbsence({ id: 1, dateStart: "2026-07-04", dateEnd: "2026-07-04" }),
    ]);

    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Jours isolés" }));

    const dateInput = screen.getByLabelText("Ajouter une date");
    await user.clear(dateInput);
    await user.type(dateInput, "2026-07-04");
    await user.click(screen.getByRole("button", { name: "Ajouter" }));
    expect(screen.getByText("04/07/2026")).toBeInTheDocument();

    // The field still shows the same date that was just added as a chip — click Enregistrer directly.
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledTimes(1));
    expect(planningApi.createIsolatedDayAbsences).toHaveBeenCalledWith({
      userId: 1, dates: ["2026-07-04"], reason: undefined,
    });
  });

  it("permet de retirer un jour isolé avant validation (Cas 4)", async () => {
    mockSearchablePerson();
    const user = userEvent.setup();
    renderPage();
    await openCreateDialog(user);
    await user.click(screen.getByRole("button", { name: "Jours isolés" }));

    const dateInput = screen.getByLabelText("Ajouter une date");
    for (const date of ["2026-07-04", "2026-07-09", "2026-07-18"]) {
      await user.clear(dateInput);
      await user.type(dateInput, date);
      await user.click(screen.getByRole("button", { name: "Ajouter" }));
    }

    const chip09 = screen.getByText("09/07/2026").closest(".MuiChip-root")!;
    await user.click(within(chip09 as HTMLElement).getByTestId("CloseIcon"));

    expect(screen.queryByText("09/07/2026")).not.toBeInTheDocument();
    expect(screen.getByText("04/07/2026")).toBeInTheDocument();
    expect(screen.getByText("18/07/2026")).toBeInTheDocument();
  });
});
