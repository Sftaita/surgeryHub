import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ProfilePhotoPromptModal } from "./ProfilePhotoPromptModal";

const refreshUserMock = vi.fn().mockResolvedValue(undefined);
const uploadProfilePictureMock = vi.fn();
const toastSuccessMock = vi.fn();
let onFileReadyCapture: ((file: File) => Promise<void> | void) | null = null;

const authState = {
  status: "authenticated" as const,
  user: { id: 1, role: "INSTRUMENTIST", sites: [], firstname: "Jane", lastname: "Doe", profilePictureUrl: null },
};

vi.mock("../../auth/AuthContext", () => ({
  useAuth: () => ({ state: authState, refreshUser: refreshUserMock }),
}));

vi.mock("./api/me.api", () => ({
  uploadProfilePicture: (...args: unknown[]) => uploadProfilePictureMock(...args),
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccessMock, error: vi.fn(), warning: vi.fn() }),
}));

vi.mock("../../ui/avatar/AvatarUploader", () => ({
  AvatarUploader: ({ name, onFileReady }: any) => {
    onFileReadyCapture = onFileReady;
    return <div data-testid="avatar-uploader">{name}</div>;
  },
}));

beforeEach(() => {
  refreshUserMock.mockClear();
  uploadProfilePictureMock.mockReset();
  toastSuccessMock.mockClear();
  onFileReadyCapture = null;
});

describe("ProfilePhotoPromptModal", () => {
  it("passe le nom complet de l'utilisateur à AvatarUploader", () => {
    render(<ProfilePhotoPromptModal open onDismiss={vi.fn()} />);
    expect(screen.getByTestId("avatar-uploader")).toHaveTextContent("Jane Doe");
  });

  it("upload -> toast, rafraîchit l'utilisateur puis ferme la modale", async () => {
    uploadProfilePictureMock.mockResolvedValue({ profilePictureUrl: "https://cdn.test/new.jpg" });
    const onDismiss = vi.fn();
    render(<ProfilePhotoPromptModal open onDismiss={onDismiss} />);

    const file = new File(["x"], "photo.png", { type: "image/png" });
    await onFileReadyCapture!(file);

    expect(uploadProfilePictureMock).toHaveBeenCalledWith(file);
    await waitFor(() => expect(toastSuccessMock).toHaveBeenCalled());
    expect(refreshUserMock).toHaveBeenCalledTimes(1);
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("propage l'échec de l'upload sans fermer la modale (AvatarUploader affichera l'erreur)", async () => {
    uploadProfilePictureMock.mockRejectedValue(new Error("network"));
    const onDismiss = vi.fn();
    render(<ProfilePhotoPromptModal open onDismiss={onDismiss} />);

    const file = new File(["x"], "photo.png", { type: "image/png" });
    await expect(onFileReadyCapture!(file)).rejects.toThrow("network");

    expect(onDismiss).not.toHaveBeenCalled();
  });

  it("le clic sur Plus tard appelle onDismiss", async () => {
    const onDismiss = vi.fn();
    render(<ProfilePhotoPromptModal open onDismiss={onDismiss} />);
    const user = userEvent.setup();

    await user.click(screen.getByRole("button", { name: "Plus tard" }));

    expect(onDismiss).toHaveBeenCalledTimes(1);
  });
});
