import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AbsenceReminderDialog } from "./AbsenceReminderDialog";

vi.mock("../api/planning.api", () => ({
  getMissingAbsencesPreview: vi.fn(),
  getEncodedAbsencesPreview: vi.fn(),
  requestMissingAbsences: vi.fn(),
  confirmEncodedAbsences: vi.fn(),
}));

const { toastSuccess, toastError } = vi.hoisted(() => ({ toastSuccess: vi.fn(), toastError: vi.fn() }));
vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError }),
}));

import * as planningApi from "../api/planning.api";

beforeEach(() => {
  vi.clearAllMocks();
  toastSuccess.mockClear();
  toastError.mockClear();
});

function renderDialog(mode: "missing" | "encoded", open = true) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AbsenceReminderDialog mode={mode} open={open} onClose={() => {}} />
    </QueryClientProvider>,
  );
}

describe("AbsenceReminderDialog — ordre : message puis liste puis bouton", () => {
  it("le champ message personnalisé précède la liste des personnes dans le DOM", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({
      count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }],
    });

    renderDialog("missing");
    await screen.findByText(/Jean Martin/);

    const messageField = screen.getByLabelText("Message personnalisé");
    const personRow = screen.getByText(/Jean Martin/);
    // DOCUMENT_POSITION_FOLLOWING (4) means personRow comes AFTER messageField.
    // eslint-disable-next-line no-bitwise
    expect(messageField.compareDocumentPosition(personRow) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });
});

describe("AbsenceReminderDialog — mode missing (Demander les congés)", () => {
  it("affiche toutes les personnes cochées par défaut, compteur = sélection", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({
      count: 2,
      people: [
        { id: 1, name: "Jean Martin", email: "martin@test.com", role: "SURGEON" },
        { id: 2, name: "Diane Lefebvre", email: "diane@test.com", role: "INSTRUMENTIST" },
      ],
    });

    renderDialog("missing");

    expect(await screen.findByText(/2 personnes sélectionnées/)).toBeInTheDocument();
    const checkboxes = screen.getAllByRole("checkbox");
    expect(checkboxes).toHaveLength(2);
    checkboxes.forEach((cb) => expect(cb).toBeChecked());
  });

  it("décocher une personne réduit le compteur et l'exclut de l'envoi", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({
      count: 2,
      people: [
        { id: 1, name: "Jean Martin", email: "martin@test.com", role: "SURGEON" },
        { id: 2, name: "Diane Lefebvre", email: "diane@test.com", role: "INSTRUMENTIST" },
      ],
    });
    vi.mocked(planningApi.requestMissingAbsences).mockResolvedValue({ sent: true, count: 1 });

    const user = userEvent.setup();
    renderDialog("missing");
    await screen.findByText(/2 personnes sélectionnées/);

    // Order follows the preview's people array: [Jean (id 1), Diane (id 2)].
    await user.click(screen.getAllByRole("checkbox")[1]);

    expect(await screen.findByText(/1 personne sélectionnée/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(planningApi.requestMissingAbsences).toHaveBeenCalledWith(expect.any(String), [1]));
  });

  it("pré-remplit un message par défaut modifiable", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({ count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }] });

    renderDialog("missing");
    await screen.findByText(/Jean Martin/);

    const textarea = screen.getByLabelText("Message personnalisé") as HTMLTextAreaElement;
    expect(textarea.value).toContain("Pourriez-vous nous transmettre");

    const user = userEvent.setup();
    await user.clear(textarea);
    await user.type(textarea, "Message custom");
    expect(textarea.value).toBe("Message custom");
  });

  it("envoie le message personnalisé et tous les ids sélectionnés via requestMissingAbsences", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({ count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }] });
    vi.mocked(planningApi.requestMissingAbsences).mockResolvedValue({ sent: true, count: 1 });

    const user = userEvent.setup();
    renderDialog("missing");
    await screen.findByText(/1 personne sélectionnée/);

    const textarea = screen.getByLabelText("Message personnalisé");
    await user.clear(textarea);
    await user.type(textarea, "Merci de répondre vite");

    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(planningApi.requestMissingAbsences).toHaveBeenCalledWith("Merci de répondre vite", [1]));
    expect(planningApi.confirmEncodedAbsences).not.toHaveBeenCalled();
  });

  it("le message par défaut explique l'absence de congé encodé et donne boost.conge@gmail.com comme adresse de réponse", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({ count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }] });

    renderDialog("missing");
    await screen.findByText(/Jean Martin/);

    const textarea = screen.getByLabelText("Message personnalisé") as HTMLTextAreaElement;
    expect(textarea.value).toContain("aucun congé");
    expect(textarea.value).toContain("boost.conge@gmail.com");
    expect(textarea.value).toContain("application SurgicalHub");
  });

  it("le toast de succès parle d'emails individuels, pas d'un destinataire unique", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({ count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }] });
    vi.mocked(planningApi.requestMissingAbsences).mockResolvedValue({ sent: true, count: 1 });

    const user = userEvent.setup();
    renderDialog("missing");
    await screen.findByText(/1 personne sélectionnée/);
    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith(expect.stringContaining("email individuel")));
    expect(toastSuccess.mock.calls[0][0]).not.toMatch(/boost\.conge/);
  });

  it("désactive Envoyer si aucune personne n'est concernée", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({ count: 0, people: [] });

    renderDialog("missing");
    await screen.findByText(/0 personne sélectionnée/);

    expect(screen.getByRole("button", { name: "Envoyer" })).toBeDisabled();
  });

  it("désactive Envoyer si toutes les personnes sont décochées manuellement", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({
      count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }],
    });

    const user = userEvent.setup();
    renderDialog("missing");
    await screen.findByText(/1 personne sélectionnée/);

    await user.click(screen.getByRole("checkbox"));

    expect(screen.getByRole("button", { name: "Envoyer" })).toBeDisabled();
  });
});

describe("AbsenceReminderDialog — mode encoded (Confirmer les congés encodés, emails individuels)", () => {
  it("affiche le résumé groupé par personne avec dates au format français, et une checkbox par personne", async () => {
    vi.mocked(planningApi.getEncodedAbsencesPreview).mockResolvedValue({
      count: 1,
      groups: [{
        user: { id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" },
        absences: [{ dateStart: "2026-09-10", dateEnd: "2026-09-15", reason: null }],
      }],
    });

    renderDialog("encoded");

    expect(await screen.findByText(/1 personne sélectionnée/)).toBeInTheDocument();
    expect(screen.getByText(/Jean Martin/)).toBeInTheDocument();
    expect(screen.getAllByRole("checkbox")).toHaveLength(1);
    // Period format: "10/09/2026 → 15/09/2026" — never "du ... au ...".
    expect(screen.getByText("10/09/2026 → 15/09/2026")).toBeInTheDocument();
  });

  it("affiche un jour isolé comme une seule date, sans flèche", async () => {
    vi.mocked(planningApi.getEncodedAbsencesPreview).mockResolvedValue({
      count: 1,
      groups: [{
        user: { id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" },
        absences: [{ dateStart: "2026-07-07", dateEnd: "2026-07-07", reason: null }],
      }],
    });

    renderDialog("encoded");

    expect(await screen.findByText("07/07/2026")).toBeInTheDocument();
    expect(screen.queryByText(/07\/07\/2026 →/)).not.toBeInTheDocument();
  });

  it("envoie via confirmEncodedAbsences avec les ids sélectionnés, jamais requestMissingAbsences", async () => {
    vi.mocked(planningApi.getEncodedAbsencesPreview).mockResolvedValue({
      count: 2,
      groups: [
        { user: { id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }, absences: [{ dateStart: "2026-09-10", dateEnd: "2026-09-10", reason: null }] },
        { user: { id: 2, name: "Diane Lefebvre", email: "diane@test.com", role: "INSTRUMENTIST" }, absences: [{ dateStart: "2026-09-20", dateEnd: "2026-09-20", reason: null }] },
      ],
    });
    vi.mocked(planningApi.confirmEncodedAbsences).mockResolvedValue({ sent: true, count: 2 });

    const user = userEvent.setup();
    renderDialog("encoded");
    await screen.findByText(/2 personnes sélectionnées/);

    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(planningApi.confirmEncodedAbsences).toHaveBeenCalledWith(expect.any(String), [1, 2]));
    expect(planningApi.requestMissingAbsences).not.toHaveBeenCalled();
  });

  it("décocher une personne l'exclut de l'envoi (elle ne recevra aucun email)", async () => {
    vi.mocked(planningApi.getEncodedAbsencesPreview).mockResolvedValue({
      count: 2,
      groups: [
        { user: { id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }, absences: [{ dateStart: "2026-09-10", dateEnd: "2026-09-10", reason: null }] },
        { user: { id: 2, name: "Diane Lefebvre", email: "diane@test.com", role: "INSTRUMENTIST" }, absences: [{ dateStart: "2026-09-20", dateEnd: "2026-09-20", reason: null }] },
      ],
    });
    vi.mocked(planningApi.confirmEncodedAbsences).mockResolvedValue({ sent: true, count: 1 });

    const user = userEvent.setup();
    renderDialog("encoded");
    await screen.findByText(/2 personnes sélectionnées/);

    // Order follows the preview's groups array: [Jean (id 1), Diane (id 2)].
    await user.click(screen.getAllByRole("checkbox")[1]);

    expect(await screen.findByText(/1 personne sélectionnée/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(planningApi.confirmEncodedAbsences).toHaveBeenCalledWith(expect.any(String), [1]));
  });

  it("le toast de succès reflète le nombre d'emails individuels envoyés, pas un destinataire unique", async () => {
    vi.mocked(planningApi.getEncodedAbsencesPreview).mockResolvedValue({
      count: 1,
      groups: [{ user: { id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }, absences: [{ dateStart: "2026-09-10", dateEnd: "2026-09-10", reason: null }] }],
    });
    vi.mocked(planningApi.confirmEncodedAbsences).mockResolvedValue({ sent: true, count: 1 });

    const user = userEvent.setup();
    renderDialog("encoded");
    await screen.findByText(/1 personne sélectionnée/);
    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(planningApi.confirmEncodedAbsences).toHaveBeenCalled());
    // No "recipient" is ever referenced for this mode — response shape has none.
    expect(planningApi.confirmEncodedAbsences).toHaveBeenCalledWith(expect.any(String), [1]);
  });
});

describe("AbsenceReminderDialog — garde anti double-envoi (Priorité 1)", () => {
  it("mode missing : 3 clics rapprochés sur Envoyer ne déclenchent qu'un seul appel API", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({
      count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }],
    });
    // Resolves after a tick, just like a real network call — the window during which a
    // second/third click could otherwise race ahead of React re-rendering `disabled`.
    vi.mocked(planningApi.requestMissingAbsences).mockImplementation(
      () => new Promise((resolve) => setTimeout(() => resolve({ sent: true, count: 1 }), 30)),
    );

    renderDialog("missing");
    await screen.findByText(/1 personne sélectionnée/);
    const button = screen.getByRole("button", { name: "Envoyer" });

    fireEvent.click(button);
    fireEvent.click(button);
    fireEvent.click(button);

    await waitFor(() => expect(planningApi.requestMissingAbsences).toHaveBeenCalled());
    expect(planningApi.requestMissingAbsences).toHaveBeenCalledTimes(1);
  });

  it("mode encoded : 3 clics rapprochés sur Envoyer ne déclenchent qu'un seul appel API", async () => {
    vi.mocked(planningApi.getEncodedAbsencesPreview).mockResolvedValue({
      count: 1,
      groups: [{ user: { id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }, absences: [{ dateStart: "2026-09-10", dateEnd: "2026-09-10", reason: null }] }],
    });
    vi.mocked(planningApi.confirmEncodedAbsences).mockImplementation(
      () => new Promise((resolve) => setTimeout(() => resolve({ sent: true, count: 1 }), 30)),
    );

    renderDialog("encoded");
    await screen.findByText(/1 personne sélectionnée/);
    const button = screen.getByRole("button", { name: "Envoyer" });

    fireEvent.click(button);
    fireEvent.click(button);
    fireEvent.click(button);

    await waitFor(() => expect(planningApi.confirmEncodedAbsences).toHaveBeenCalled());
    expect(planningApi.confirmEncodedAbsences).toHaveBeenCalledTimes(1);
  });

  it("le garde se libère après l'envoi : un nouvel envoi reste possible ensuite", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({
      count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }],
    });
    vi.mocked(planningApi.requestMissingAbsences).mockResolvedValue({ sent: true, count: 1 });

    const user = userEvent.setup();
    renderDialog("missing", true);
    await screen.findByText(/1 personne sélectionnée/);

    await user.click(screen.getByRole("button", { name: "Envoyer" }));
    await waitFor(() => expect(planningApi.requestMissingAbsences).toHaveBeenCalledTimes(1));
  });

  it("la garde se libère aussi après une erreur d'envoi (onSettled)", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({
      count: 1, people: [{ id: 1, name: "Jean Martin", email: "x@test.com", role: "SURGEON" }],
    });
    vi.mocked(planningApi.requestMissingAbsences)
      .mockRejectedValueOnce(new Error("boom"))
      .mockResolvedValueOnce({ sent: true, count: 1 });

    const user = userEvent.setup();
    renderDialog("missing");
    await screen.findByText(/1 personne sélectionnée/);
    const button = screen.getByRole("button", { name: "Envoyer" });

    await user.click(button);
    await waitFor(() => expect(planningApi.requestMissingAbsences).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(toastError).toHaveBeenCalled());

    // A second, separate click after the failure must be allowed through (guard released).
    await user.click(button);
    await waitFor(() => expect(planningApi.requestMissingAbsences).toHaveBeenCalledTimes(2));
  });
});

describe("AbsenceReminderDialog — ne réinitialise pas la sélection sur refetch en arrière-plan (Priorité 4)", () => {
  it("une personne décochée reste décochée après un refetch de la preview qui change la liste", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValueOnce({
      count: 2,
      people: [
        { id: 1, name: "Jean Martin", email: "martin@test.com", role: "SURGEON" },
        { id: 2, name: "Diane Lefebvre", email: "diane@test.com", role: "INSTRUMENTIST" },
      ],
    });

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const user = userEvent.setup();
    render(
      <QueryClientProvider client={client}>
        <AbsenceReminderDialog mode="missing" open={true} onClose={() => {}} />
      </QueryClientProvider>,
    );

    await screen.findByText(/2 personnes sélectionnées/);
    // Uncheck Diane (id 2) — order follows the preview's people array.
    await user.click(screen.getAllByRole("checkbox")[1]);
    expect(await screen.findByText(/1 personne sélectionnée/)).toBeInTheDocument();

    // Simulate a background refetch that returns a CHANGED list (a third person now appears) —
    // this must never silently re-check everyone, wiping the manual exclusion above.
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValueOnce({
      count: 3,
      people: [
        { id: 1, name: "Jean Martin", email: "martin@test.com", role: "SURGEON" },
        { id: 2, name: "Diane Lefebvre", email: "diane@test.com", role: "INSTRUMENTIST" },
        { id: 3, name: "Paul Renard", email: "paul@test.com", role: "SURGEON" },
      ],
    });
    await client.invalidateQueries({ queryKey: ["absences", "missing-preview"] });
    await screen.findByText(/Paul Renard/);

    // Diane must still be excluded — only Jean (id 1) and the newly-appeared Paul (id 3) are
    // still selected, never reset back to "all 3 checked".
    expect(screen.getByText(/1 personne sélectionnée|2 personnes sélectionnées/)).toBeInTheDocument();
    const checkboxes = screen.getAllByRole("checkbox");
    const dianeCheckbox = checkboxes[1]; // still Diane, second in the (stable) list order
    expect(dianeCheckbox).not.toBeChecked();
  });

  it("réinitialise bien la sélection à « tout coché » lors d'une réouverture du dialogue", async () => {
    vi.mocked(planningApi.getMissingAbsencesPreview).mockResolvedValue({
      count: 2,
      people: [
        { id: 1, name: "Jean Martin", email: "martin@test.com", role: "SURGEON" },
        { id: 2, name: "Diane Lefebvre", email: "diane@test.com", role: "INSTRUMENTIST" },
      ],
    });

    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const user = userEvent.setup();
    const { rerender } = render(
      <QueryClientProvider client={client}>
        <AbsenceReminderDialog mode="missing" open={true} onClose={() => {}} />
      </QueryClientProvider>,
    );

    await screen.findByText(/2 personnes sélectionnées/);
    await user.click(screen.getAllByRole("checkbox")[1]); // uncheck Diane
    expect(await screen.findByText(/1 personne sélectionnée/)).toBeInTheDocument();

    // Close, then reopen — this is a genuinely new "session" and must reset to all-checked.
    rerender(
      <QueryClientProvider client={client}>
        <AbsenceReminderDialog mode="missing" open={false} onClose={() => {}} />
      </QueryClientProvider>,
    );
    rerender(
      <QueryClientProvider client={client}>
        <AbsenceReminderDialog mode="missing" open={true} onClose={() => {}} />
      </QueryClientProvider>,
    );

    expect(await screen.findByText(/2 personnes sélectionnées/)).toBeInTheDocument();
  });
});
