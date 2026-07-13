import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { UserEmailEditor } from "./UserEmailEditor";

const toastSuccess = vi.fn();
const toastWarning = vi.fn();
const toastError = vi.fn();

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({
    success: toastSuccess,
    warning: toastWarning,
    error: toastError,
  }),
}));

vi.mock("../api/userEmail.api", () => ({
  patchUserEmail: vi.fn(),
}));

import { patchUserEmail } from "../api/userEmail.api";

beforeEach(() => {
  vi.mocked(patchUserEmail).mockReset();
  toastSuccess.mockClear();
  toastWarning.mockClear();
  toastError.mockClear();
});

function renderEditor(onChanged = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <UserEmailEditor
        userId={42}
        currentEmail="ancienne@example.com"
        onChanged={onChanged}
      />
    </QueryClientProvider>,
  );
  return { onChanged };
}

describe("UserEmailEditor", () => {
  it("affiche le bouton Modifier et l'adresse actuelle", () => {
    renderEditor();
    expect(screen.getByText("ancienne@example.com")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Modifier" })).toBeInTheDocument();
  });

  it("passe en mode édition et affiche une confirmation avec ancienne et nouvelle adresses", async () => {
    const user = userEvent.setup();
    renderEditor();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    const input = screen.getByRole("textbox");
    await user.clear(input);
    await user.type(input, "nouvelle@example.com");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(await screen.findByText("Modifier l'adresse email ?")).toBeInTheDocument();
    expect(screen.getAllByText("ancienne@example.com").length).toBeGreaterThan(0);
    expect(screen.getAllByText("nouvelle@example.com").length).toBeGreaterThan(0);
    expect(patchUserEmail).not.toHaveBeenCalled();
  });

  it("n'effectue aucune mutation si l'édition est annulée", async () => {
    const user = userEvent.setup();
    renderEditor();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    const input = screen.getByRole("textbox");
    await user.clear(input);
    await user.type(input, "nouvelle@example.com");
    await user.click(screen.getByRole("button", { name: "Annuler" }));

    expect(screen.getByRole("button", { name: "Modifier" })).toBeInTheDocument();
    expect(patchUserEmail).not.toHaveBeenCalled();
  });

  it("n'effectue aucune mutation si la confirmation est annulée", async () => {
    const user = userEvent.setup();
    renderEditor();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    const input = screen.getByRole("textbox");
    await user.clear(input);
    await user.type(input, "nouvelle@example.com");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    await screen.findByText("Modifier l'adresse email ?");
    await user.click(screen.getAllByRole("button", { name: "Annuler" })[0]);

    expect(patchUserEmail).not.toHaveBeenCalled();
  });

  it("met à jour le drawer et affiche un succès après confirmation", async () => {
    vi.mocked(patchUserEmail).mockResolvedValue({
      user: {
        id: 42,
        email: "nouvelle@example.com",
        firstname: "Jean",
        lastname: "Martin",
        displayName: "Jean Martin",
        profilePicturePath: null,
      },
      warnings: [],
    });

    const user = userEvent.setup();
    const { onChanged } = renderEditor();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    const input = screen.getByRole("textbox");
    await user.clear(input);
    await user.type(input, "nouvelle@example.com");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    await screen.findByText("Modifier l'adresse email ?");
    await user.click(screen.getByRole("button", { name: "Confirmer la modification" }));

    await waitFor(() => expect(patchUserEmail).toHaveBeenCalledWith(42, "nouvelle@example.com"));
    await waitFor(() => expect(onChanged).toHaveBeenCalledWith(
      expect.objectContaining({ email: "nouvelle@example.com" }),
    ));
    expect(toastSuccess).toHaveBeenCalledWith("Adresse email modifiée");
  });

  it("affiche un avertissement si une notification n'a pas pu être envoyée", async () => {
    vi.mocked(patchUserEmail).mockResolvedValue({
      user: {
        id: 42,
        email: "nouvelle@example.com",
        firstname: null,
        lastname: null,
        displayName: "nouvelle@example.com",
        profilePicturePath: null,
      },
      warnings: [
        {
          code: "EMAIL_CHANGE_NOTIFICATION_NOT_QUEUED",
          recipient: "old",
          message: "not queued",
        },
      ],
    });

    const user = userEvent.setup();
    renderEditor();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    const input = screen.getByRole("textbox");
    await user.clear(input);
    await user.type(input, "nouvelle@example.com");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    await screen.findByText("Modifier l'adresse email ?");
    await user.click(screen.getByRole("button", { name: "Confirmer la modification" }));

    await waitFor(() =>
      expect(toastWarning).toHaveBeenCalledWith(
        "Adresse email modifiée, mais une notification n'a pas pu être envoyée.",
      ),
    );
  });

  it("conserve la valeur saisie et affiche l'erreur en cas d'échec", async () => {
    vi.mocked(patchUserEmail).mockRejectedValue({
      response: { data: { error: { message: "This email address is already used by another account." } } },
    });

    const user = userEvent.setup();
    renderEditor();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    const input = screen.getByRole("textbox");
    await user.clear(input);
    await user.type(input, "prise@example.com");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    await screen.findByText("Modifier l'adresse email ?");
    await user.click(screen.getByRole("button", { name: "Confirmer la modification" }));

    await waitFor(() =>
      expect(toastError).toHaveBeenCalledWith(
        "This email address is already used by another account.",
      ),
    );
    // Le formulaire reste ouvert avec la valeur saisie conservée, pas de retour au mode lecture
    // (attendre la fermeture animée de la Dialog de confirmation avant de requêter le textbox).
    await waitFor(() => expect(screen.getByRole("textbox")).toHaveValue("prise@example.com"));
  });

  it("empêche le double clic pendant la mutation", async () => {
    let resolvePromise: (value: any) => void = () => {};
    vi.mocked(patchUserEmail).mockImplementation(
      () => new Promise((resolve) => { resolvePromise = resolve; }),
    );

    const user = userEvent.setup();
    renderEditor();

    await user.click(screen.getByRole("button", { name: "Modifier" }));
    const input = screen.getByRole("textbox");
    await user.clear(input);
    await user.type(input, "nouvelle@example.com");
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));
    await screen.findByText("Modifier l'adresse email ?");

    const confirmButton = screen.getByRole("button", { name: "Confirmer la modification" });
    await user.click(confirmButton);

    expect(screen.getByRole("button", { name: "Enregistrement…" })).toBeDisabled();
    expect(patchUserEmail).toHaveBeenCalledTimes(1);

    resolvePromise({
      user: {
        id: 42,
        email: "nouvelle@example.com",
        firstname: null,
        lastname: null,
        displayName: "nouvelle@example.com",
        profilePicturePath: null,
      },
      warnings: [],
    });
  });
});
