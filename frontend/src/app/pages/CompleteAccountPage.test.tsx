import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import CompleteAccountPage from "./CompleteAccountPage";

const checkInvitationMock = vi.fn();
const completeInvitationMock = vi.fn();

vi.mock("../features/invitation/api/invitation.api", () => ({
  checkInvitation: (...args: unknown[]) => checkInvitationMock(...args),
  completeInvitation: (...args: unknown[]) => completeInvitationMock(...args),
}));

// AvatarCropDialog needs canvas/Image APIs jsdom doesn't implement — stub it to
// hand back a fixed file, same approach as AvatarUploader's own test suite.
vi.mock("../ui/avatar/AvatarCropDialog", () => ({
  AvatarCropDialog: ({ open, onConfirm }: any) =>
    open ? (
      <button onClick={() => onConfirm(new File(["cropped"], "cropped.png", { type: "image/png" }))}>
        confirm-crop
      </button>
    ) : null,
}));

beforeEach(() => {
  sessionStorage.clear();
  checkInvitationMock.mockReset();
  completeInvitationMock.mockReset();
  checkInvitationMock.mockResolvedValue({
    status: "valid",
    valid: true,
    invitation: {
      email: "user@example.com",
      firstname: "Jane",
      lastname: "Doe",
      displayName: "Jane Doe",
      expiresAt: new Date(Date.now() + 86_400_000).toISOString(),
    },
  });
});

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={["/complete-account?token=abc123"]}>
      <QueryClientProvider client={client}>
        <CompleteAccountPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

describe("CompleteAccountPage — photo de profil", () => {
  it("affiche la zone de prompt photo de profil", async () => {
    renderPage();

    await waitFor(() => {
      expect(screen.getByText("Ajoutez une photo de profil")).toBeInTheDocument();
    });
    expect(
      screen.getByText(/aide les managers, chirurgiens et instrumentistes/i),
    ).toBeInTheDocument();
    expect(screen.getByLabelText("Ajouter une photo de profil")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Continuer sans photo" })).toBeInTheDocument();
  });

  it("permet de finaliser le compte sans photo", async () => {
    const user = userEvent.setup();
    completeInvitationMock.mockResolvedValue({ status: "account_completed" });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText("Ajoutez une photo de profil")).toBeInTheDocument();
    });

    await user.click(screen.getByRole("button", { name: "Continuer sans photo" }));

    // Prénom/Nom are pre-filled from the invitation lookup — only téléphone/password are missing.
    await user.type(screen.getByLabelText(/Téléphone/), "0470000000");
    await user.type(screen.getByLabelText("Mot de passe *"), "password123");
    await user.type(screen.getByLabelText(/Confirmer le mot de passe/), "password123");

    await user.click(screen.getByRole("button", { name: /Activer mon compte/i }));

    await waitFor(() => {
      expect(completeInvitationMock).toHaveBeenCalledTimes(1);
    });

    const formData = completeInvitationMock.mock.calls[0][0] as FormData;
    expect(formData.get("profilePicture")).toBeNull();
    expect(formData.get("firstname")).toBe("Jane");

    await waitFor(() => {
      expect(screen.getByText(/compte est activé/i)).toBeInTheDocument();
    });
  });

  it("inclut la photo cropée dans le FormData quand une photo est choisie", async () => {
    const user = userEvent.setup();
    completeInvitationMock.mockResolvedValue({ status: "account_completed" });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText("Ajoutez une photo de profil")).toBeInTheDocument();
    });

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    const file = new File(["x"], "photo.png", { type: "image/png" });
    await user.upload(fileInput, file);
    await user.click(await screen.findByText("confirm-crop"));

    await user.type(screen.getByLabelText(/Téléphone/), "0470000000");
    await user.type(screen.getByLabelText("Mot de passe *"), "password123");
    await user.type(screen.getByLabelText(/Confirmer le mot de passe/), "password123");
    await user.click(screen.getByRole("button", { name: /Activer mon compte/i }));

    await waitFor(() => expect(completeInvitationMock).toHaveBeenCalledTimes(1));

    const formData = completeInvitationMock.mock.calls[0][0] as FormData;
    const submittedFile = formData.get("profilePicture") as File;
    expect(submittedFile).not.toBeNull();
    expect(submittedFile.name).toBe("cropped.png");
  });

  it("marque le compte comme juste activé après succès, pour ne pas relancer le rappel de photo", async () => {
    const user = userEvent.setup();
    completeInvitationMock.mockResolvedValue({ status: "account_completed" });

    renderPage();
    await waitFor(() => {
      expect(screen.getByText("Ajoutez une photo de profil")).toBeInTheDocument();
    });

    await user.click(screen.getByRole("button", { name: "Continuer sans photo" }));
    await user.type(screen.getByLabelText(/Téléphone/), "0470000000");
    await user.type(screen.getByLabelText("Mot de passe *"), "password123");
    await user.type(screen.getByLabelText(/Confirmer le mot de passe/), "password123");
    await user.click(screen.getByRole("button", { name: /Activer mon compte/i }));

    await waitFor(() => {
      expect(sessionStorage.getItem("surgicalhub.justActivatedAccount")).toBe("1");
    });
  });
});
