import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import SelfAbsencesPage from "./SelfAbsencesPage";

/**
 * Self-service absences (Lot 3, D-097) — the ONE page/module consumed by both
 * /app/s/absences and /app/i/absences. This suite is role-agnostic by construction (the
 * component never branches on role); role-specific route wiring is covered separately in
 * AppRouter.test.tsx.
 */

const fetchMyAbsencesMock = vi.fn();
const createMyAbsenceMock = vi.fn();
const updateMyAbsenceMock = vi.fn();
const deleteMyAbsenceMock = vi.fn();
const fetchAbsenceImpactPreviewMock = vi.fn();
const fetchMyAbsenceDeletionInfoMock = vi.fn();

vi.mock("./api/selfAbsences.api", () => ({
  fetchMyAbsences: (...args: unknown[]) => fetchMyAbsencesMock(...args),
  createMyAbsence: (...args: unknown[]) => createMyAbsenceMock(...args),
  updateMyAbsence: (...args: unknown[]) => updateMyAbsenceMock(...args),
  deleteMyAbsence: (...args: unknown[]) => deleteMyAbsenceMock(...args),
  fetchAbsenceImpactPreview: (...args: unknown[]) => fetchAbsenceImpactPreviewMock(...args),
  fetchMyAbsenceDeletionInfo: (...args: unknown[]) => fetchMyAbsenceDeletionInfoMock(...args),
}));

const toastSuccess = vi.fn();
const toastError = vi.fn();
vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn(), info: vi.fn() }),
}));

function makeAbsence(overrides: Partial<any> = {}) {
  return {
    id: 1,
    dateStart: "2026-08-12",
    dateEnd: "2026-08-12",
    reason: "Congé",
    createdAt: "2026-08-01T10:00:00+02:00",
    editable: true,
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <SelfAbsencesPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchMyAbsencesMock.mockReset();
  createMyAbsenceMock.mockReset();
  updateMyAbsenceMock.mockReset();
  deleteMyAbsenceMock.mockReset();
  fetchAbsenceImpactPreviewMock.mockReset().mockResolvedValue([]);
  // Communication des absences chirurgiens — Lot B (D-114). Défaut "jamais notifié" pour ne
  // pas casser les tests de suppression existants (confirmation simple, sans choix Oui/Non) —
  // les tests Lot B dédiés ci-dessous surchargent explicitement cette valeur par défaut.
  fetchMyAbsenceDeletionInfoMock.mockReset().mockResolvedValue({ blockManagementAlreadyNotified: false, sites: [] });
  toastSuccess.mockReset();
  toastError.mockReset();
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(new Date("2026-08-06T12:00:00+02:00"));
});

afterEach(() => {
  vi.useRealTimers();
});

describe("SelfAbsencesPage — états UX", () => {
  it("erreur réseau → message d'erreur explicite", async () => {
    fetchMyAbsencesMock.mockRejectedValue(new Error("network error"));
    renderPage();

    expect(await screen.findByText("Impossible de charger vos absences.")).toBeInTheDocument();
  });

  it("aucune absence à venir → état vide avec CTA 'Ajouter une absence'", async () => {
    fetchMyAbsencesMock.mockResolvedValue([]);
    renderPage();

    expect(await screen.findByText("Aucune absence prévue")).toBeInTheDocument();
    expect(screen.getByText("Ajouter une absence")).toBeInTheDocument();
  });

  it("bascule vers 'Passées' → état vide sans CTA quand aucune absence passée", async () => {
    fetchMyAbsencesMock.mockResolvedValue([]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("Aucune absence prévue");
    await user.click(screen.getByRole("button", { name: "Passées" }));

    expect(await screen.findByText("Aucune absence passée")).toBeInTheDocument();
    expect(screen.queryByText("Ajouter une absence")).not.toBeInTheDocument();
  });
});

describe("SelfAbsencesPage — liste groupée par mois", () => {
  it("jour unique affiche une seule date ; période affiche début → fin et la durée", async () => {
    fetchMyAbsencesMock.mockResolvedValue([
      makeAbsence({ id: 1, dateStart: "2026-08-12", dateEnd: "2026-08-12", reason: "Congé" }),
      makeAbsence({ id: 2, dateStart: "2026-08-19", dateEnd: "2026-08-23", reason: "Congé" }),
    ]);
    renderPage();

    expect(await screen.findByText("12 août")).toBeInTheDocument();
    expect(screen.getByText("19 août → 23 août")).toBeInTheDocument();
    expect(screen.getByText("· 5 jours")).toBeInTheDocument();
  });

  it("groupe par mois avec un en-tête par mois", async () => {
    fetchMyAbsencesMock.mockResolvedValue([
      makeAbsence({ id: 1, dateStart: "2026-08-12", dateEnd: "2026-08-12" }),
      makeAbsence({ id: 2, dateStart: "2026-09-07", dateEnd: "2026-09-07" }),
    ]);
    renderPage();

    expect(await screen.findByText("AOÛT 2026")).toBeInTheDocument();
    expect(screen.getByText("SEPTEMBRE 2026")).toBeInTheDocument();
  });

  it("une absence non éditable (passée) affiche 'Passée' sans bouton modifier/supprimer", async () => {
    fetchMyAbsencesMock.mockResolvedValue([
      makeAbsence({ id: 1, dateStart: "2020-01-01", dateEnd: "2020-01-01", editable: false }),
    ]);
    renderPage();

    await screen.findByText("Passées"); // scope control present while list loads
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    await user.click(screen.getByRole("button", { name: "Passées" }));

    expect(await screen.findByText("Passée")).toBeInTheDocument();
    expect(screen.queryByLabelText("Modifier")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("Supprimer")).not.toBeInTheDocument();
  });
});

describe("SelfAbsencesPage — création", () => {
  it("crée une absence jour unique et affiche le toast de confirmation (aucun impact)", async () => {
    fetchMyAbsencesMock.mockResolvedValue([]);
    createMyAbsenceMock.mockResolvedValue({
      id: 9, dateStart: "2026-08-20", dateEnd: "2026-08-20", reason: null, createdAt: "2026-08-06T00:00:00Z",
      editable: true, missionsImpactedCount: 0,
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("Aucune absence prévue");
    await user.click(screen.getByText("+ Ajouter"));

    expect(await screen.findByText("Nouvelle absence")).toBeInTheDocument();
    const dateInput = screen.getByLabelText("Date");
    await user.clear(dateInput);
    await user.type(dateInput, "2026-08-20");

    await user.click(screen.getByRole("button", { name: "Créer l'absence" }));

    await waitFor(() => expect(createMyAbsenceMock).toHaveBeenCalledWith({
      dateStart: "2026-08-20", dateEnd: "2026-08-20", reason: null,
    }));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Absence enregistrée"));
  });

  it("crée une absence période et affiche le toast avec le nombre de missions concernées", async () => {
    fetchMyAbsencesMock.mockResolvedValue([]);
    createMyAbsenceMock.mockResolvedValue({
      id: 10, dateStart: "2026-08-19", dateEnd: "2026-08-23", reason: "Congé", createdAt: "2026-08-06T00:00:00Z",
      editable: true, missionsImpactedCount: 2,
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("Aucune absence prévue");
    await user.click(screen.getByText("+ Ajouter"));
    await screen.findByText("Nouvelle absence");

    await user.click(screen.getByRole("button", { name: "Période" }));
    const fromInput = screen.getByLabelText("Du");
    const toInput = screen.getByLabelText("Au");
    await user.clear(fromInput);
    await user.type(fromInput, "2026-08-19");
    await user.clear(toInput);
    await user.type(toInput, "2026-08-23");

    expect(await screen.findByText("5 jours")).toBeInTheDocument();

    await user.click(screen.getByText("Congé"));
    await user.click(screen.getByRole("button", { name: "Créer l'absence" }));

    await waitFor(() => expect(createMyAbsenceMock).toHaveBeenCalledWith({
      dateStart: "2026-08-19", dateEnd: "2026-08-23", reason: "Congé",
    }));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith(
      "Absence enregistrée — 2 missions du planning sont concernées, le manager en a été informé.",
    ));
  });

  it("période avec date de fin antérieure à la date de début → erreur inline, CTA désactivé", async () => {
    fetchMyAbsencesMock.mockResolvedValue([]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("Aucune absence prévue");
    await user.click(screen.getByText("+ Ajouter"));
    await screen.findByText("Nouvelle absence");
    await user.click(screen.getByRole("button", { name: "Période" }));

    const fromInput = screen.getByLabelText("Du");
    const toInput = screen.getByLabelText("Au");
    await user.clear(fromInput);
    await user.type(fromInput, "2026-08-20");
    await user.clear(toInput);
    await user.type(toInput, "2026-08-10");

    expect(await screen.findByText("La date de fin doit être postérieure ou égale à la date de début.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Créer l'absence" })).toBeDisabled();
    expect(createMyAbsenceMock).not.toHaveBeenCalled();
  });
});

describe("SelfAbsencesPage — impact planning (non bloquant)", () => {
  it("aucun impact → message de confirmation vert", async () => {
    fetchMyAbsencesMock.mockResolvedValue([]);
    fetchAbsenceImpactPreviewMock.mockResolvedValue([]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("Aucune absence prévue");
    await user.click(screen.getByText("+ Ajouter"));
    await screen.findByText("Nouvelle absence");

    expect(await screen.findByText("✓ Aucune mission prévue pendant cette absence.")).toBeInTheDocument();
    // Never blocks: CTA remains enabled even while impact preview is (already) resolved empty.
    expect(screen.getByRole("button", { name: "Créer l'absence" })).not.toBeDisabled();
  });

  it("impact détecté → liste compacte des missions concernées, jamais bloquant", async () => {
    fetchMyAbsencesMock.mockResolvedValue([]);
    fetchAbsenceImpactPreviewMock.mockResolvedValue([
      { missionId: 1, startAt: "2026-08-20T08:00:00+02:00", endAt: "2026-08-20T13:00:00+02:00", siteName: "Delta", counterpart: { id: 5, name: "Diane de Moor" } },
      { missionId: 2, startAt: "2026-08-20T13:00:00+02:00", endAt: "2026-08-20T18:00:00+02:00", siteName: "Parc Léopold", counterpart: null },
    ]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("Aucune absence prévue");
    await user.click(screen.getByText("+ Ajouter"));
    await screen.findByText("Nouvelle absence");

    expect(await screen.findByText(/2 missions sont déjà planifiées/)).toBeInTheDocument();
    expect(screen.getByText(/Diane de Moor/)).toBeInTheDocument();
    expect(screen.getByText(/À couvrir/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Créer l'absence" })).not.toBeDisabled();
  });
});

describe("SelfAbsencesPage — édition", () => {
  it("modifier une absence jour unique rouvre le formulaire en mode 'Jour unique'", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 3, dateStart: "2026-08-12", dateEnd: "2026-08-12", reason: "Congé" })]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Modifier"));

    expect(await screen.findByText("Modifier l'absence")).toBeInTheDocument();
    const dayModeButtons = screen.getAllByRole("button", { name: "Jour unique" });
    expect(dayModeButtons.length).toBeGreaterThan(0);
    expect(screen.getByLabelText("Date")).toHaveValue("2026-08-12");
  });

  it("modifier une absence période rouvre le formulaire en mode 'Période' préreempli", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 4, dateStart: "2026-08-19", dateEnd: "2026-08-23", reason: "Formation" })]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("19 août → 23 août");
    await user.click(screen.getByLabelText("Modifier"));

    await screen.findByText("Modifier l'absence");
    expect(screen.getByLabelText("Du")).toHaveValue("2026-08-19");
    expect(screen.getByLabelText("Au")).toHaveValue("2026-08-23");
  });

  it("soumet la modification via updateMyAbsence", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 5, dateStart: "2026-08-12", dateEnd: "2026-08-12", reason: "Congé" })]);
    updateMyAbsenceMock.mockResolvedValue({
      id: 5, dateStart: "2026-08-13", dateEnd: "2026-08-13", reason: "Congé", createdAt: "2026-08-01T00:00:00Z",
      editable: true, missionsImpactedCount: 0,
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Modifier"));
    await screen.findByText("Modifier l'absence");

    const dateInput = screen.getByLabelText("Date");
    await user.clear(dateInput);
    await user.type(dateInput, "2026-08-13");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    await waitFor(() => expect(updateMyAbsenceMock).toHaveBeenCalledWith(5, {
      dateStart: "2026-08-13", dateEnd: "2026-08-13", reason: "Congé",
    }));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Absence modifiée"));
  });
});

describe("SelfAbsencesPage — suppression", () => {
  it("affiche une confirmation courte avec la date avant de supprimer", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 6, dateStart: "2026-08-19", dateEnd: "2026-08-23" })]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("19 août → 23 août");
    await user.click(screen.getByLabelText("Supprimer"));

    expect(await screen.findByText("Supprimer cette absence ?")).toBeInTheDocument();
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText("19 août → 23 août")).toBeInTheDocument();
    expect(within(dialog).getByText("Cette absence sera retirée.")).toBeInTheDocument();
  });

  it("confirmer la suppression appelle deleteMyAbsence et affiche un toast", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 7, dateStart: "2026-08-12", dateEnd: "2026-08-12" })]);
    deleteMyAbsenceMock.mockResolvedValue(undefined);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Supprimer"));
    await screen.findByText("Supprimer cette absence ?");

    await user.click(screen.getByRole("button", { name: "Supprimer" }));

    await waitFor(() => expect(deleteMyAbsenceMock).toHaveBeenCalledWith(7, undefined));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Absence supprimée"));
  });

  it("annuler la suppression ne fait aucun appel", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 8, dateStart: "2026-08-12", dateEnd: "2026-08-12" })]);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Supprimer"));
    await screen.findByText("Supprimer cette absence ?");
    await user.click(screen.getByRole("button", { name: "Annuler" }));

    expect(screen.queryByText("Supprimer cette absence ?")).not.toBeInTheDocument();
    expect(deleteMyAbsenceMock).not.toHaveBeenCalled();
  });
});

describe("SelfAbsencesPage — suppression, communication des absences chirurgiens (Lot B, D-114)", () => {
  it("déjà notifiée : affiche le dialogue Oui/Non avec la liste des sites, jamais de déduction locale", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 9, dateStart: "2026-08-12", dateEnd: "2026-08-12" })]);
    fetchMyAbsenceDeletionInfoMock.mockResolvedValue({
      blockManagementAlreadyNotified: true,
      sites: [{ siteId: 5, siteName: "CHIREC - Hôpital Delta", notificationSentAt: "2026-07-01T10:00:00Z" }],
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Supprimer"));

    expect(await screen.findByText("Confirmer la suppression")).toBeInTheDocument();
    expect(screen.getByText(/CHIREC - Hôpital Delta/)).toBeInTheDocument();
    expect(screen.queryByText("Supprimer cette absence ?")).not.toBeInTheDocument();
    expect(deleteMyAbsenceMock).not.toHaveBeenCalled();
  });

  it("« Non, supprimer uniquement » supprime sans notifier le bloc", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 10, dateStart: "2026-08-12", dateEnd: "2026-08-12" })]);
    fetchMyAbsenceDeletionInfoMock.mockResolvedValue({
      blockManagementAlreadyNotified: true,
      sites: [{ siteId: 5, siteName: "Delta", notificationSentAt: "2026-07-01T10:00:00Z" }],
    });
    deleteMyAbsenceMock.mockResolvedValue(undefined);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Supprimer"));
    await screen.findByText("Confirmer la suppression");

    await user.click(screen.getByRole("button", { name: "Non, supprimer uniquement" }));

    await waitFor(() => expect(deleteMyAbsenceMock).toHaveBeenCalledWith(10, false));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Absence supprimée"));
  });

  it("« Oui, prévenir le bloc et supprimer » supprime en demandant la notification", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 11, dateStart: "2026-08-12", dateEnd: "2026-08-12" })]);
    fetchMyAbsenceDeletionInfoMock.mockResolvedValue({
      blockManagementAlreadyNotified: true,
      sites: [{ siteId: 5, siteName: "Delta", notificationSentAt: "2026-07-01T10:00:00Z" }],
    });
    deleteMyAbsenceMock.mockResolvedValue(undefined);
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Supprimer"));
    await screen.findByText("Confirmer la suppression");

    await user.click(screen.getByRole("button", { name: "Oui, prévenir le bloc et supprimer" }));

    await waitFor(() => expect(deleteMyAbsenceMock).toHaveBeenCalledWith(11, true));
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith("Absence supprimée"));
  });

  it("annuler le dialogue Oui/Non ne fait aucun appel", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 12, dateStart: "2026-08-12", dateEnd: "2026-08-12" })]);
    fetchMyAbsenceDeletionInfoMock.mockResolvedValue({
      blockManagementAlreadyNotified: true,
      sites: [{ siteId: 5, siteName: "Delta", notificationSentAt: "2026-07-01T10:00:00Z" }],
    });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Supprimer"));
    await screen.findByText("Confirmer la suppression");

    await user.click(screen.getByRole("button", { name: "Annuler" }));

    expect(screen.queryByText("Confirmer la suppression")).not.toBeInTheDocument();
    expect(deleteMyAbsenceMock).not.toHaveBeenCalled();
  });

  it("une erreur lors de la vérification affiche un toast, sans jamais supprimer ni ouvrir de dialogue", async () => {
    fetchMyAbsencesMock.mockResolvedValue([makeAbsence({ id: 13, dateStart: "2026-08-12", dateEnd: "2026-08-12" })]);
    fetchMyAbsenceDeletionInfoMock.mockRejectedValue(new Error("network error"));
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderPage();

    await screen.findByText("12 août");
    await user.click(screen.getByLabelText("Supprimer"));

    await waitFor(() => expect(fetchMyAbsenceDeletionInfoMock).toHaveBeenCalled());
    expect(screen.queryByText("Confirmer la suppression")).not.toBeInTheDocument();
    expect(screen.queryByText("Supprimer cette absence ?")).not.toBeInTheDocument();
    expect(deleteMyAbsenceMock).not.toHaveBeenCalled();
  });
});
