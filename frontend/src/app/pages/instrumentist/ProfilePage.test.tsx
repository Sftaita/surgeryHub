import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import ProfilePage from "./ProfilePage";

const apiGetMock = vi.fn();
const apiPatchMock = vi.fn();
const uploadProfilePictureMock = vi.fn();
const refreshUserMock = vi.fn().mockResolvedValue(undefined);
const toastSuccessMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    patch: (...args: unknown[]) => apiPatchMock(...args),
  },
}));

vi.mock("../../features/me/api/me.api", () => ({
  uploadProfilePicture: (...args: unknown[]) => uploadProfilePictureMock(...args),
}));

vi.mock("../../auth/AuthContext", () => ({
  useAuth: () => ({
    state: { status: "authenticated", user: { id: 42, firstname: "Jane" } },
    refreshUser: refreshUserMock,
  }),
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccessMock, error: vi.fn(), warning: vi.fn() }),
}));

// AvatarCropDialog needs canvas/Image APIs jsdom doesn't implement.
vi.mock("../../ui/avatar/AvatarCropDialog", () => ({
  AvatarCropDialog: ({ open, onConfirm }: any) =>
    open ? (
      <button onClick={() => onConfirm(new File(["cropped"], "cropped.png", { type: "image/png" }))}>
        confirm-crop
      </button>
    ) : null,
}));

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ProfilePage />
    </QueryClientProvider>,
  );
}

const baseProfile = {
  instrumentistProfile: {
    id: 42,
    email: "jane@example.com",
    firstname: "Jane",
    lastname: "Doe",
    displayName: "Jane Doe",
    active: true,
    employmentType: "EMPLOYEE",
    defaultCurrency: "EUR",
    hourlyRate: null,
    consultationFee: null,
    profilePicturePath: "/uploads/profile-pictures/jane.jpg",
    siteMemberships: [],
    specialties: ["GENOU"],
  },
};

beforeEach(() => {
  apiGetMock.mockReset();
  apiPatchMock.mockReset();
  uploadProfilePictureMock.mockReset();
  refreshUserMock.mockClear();
  toastSuccessMock.mockClear();
  apiGetMock.mockResolvedValue({ data: baseProfile });
  vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("ProfilePage — photo de profil", () => {
  it("résout l'URL de la photo via buildProfilePictureUrl (régression du bug d'URL relative)", async () => {
    renderPage();

    const avatarImg = await screen.findByRole("img", { name: "Jane Doe" });
    expect(avatarImg).toHaveAttribute(
      "src",
      "https://api.surgicalhub.test/uploads/profile-pictures/jane.jpg",
    );
  });

  it("uploade la photo cropée, invalide le cache et rafraîchit l'utilisateur", async () => {
    uploadProfilePictureMock.mockResolvedValue({ profilePictureUrl: "https://cdn.test/new.jpg" });
    const user = userEvent.setup();
    renderPage();

    await screen.findByRole("img", { name: "Jane Doe" });

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(fileInput, new File(["x"], "photo.png", { type: "image/png" }));
    await user.click(await screen.findByText("confirm-crop"));

    await waitFor(() => expect(uploadProfilePictureMock).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(toastSuccessMock).toHaveBeenCalledWith("Photo de profil mise à jour"));
    expect(refreshUserMock).toHaveBeenCalledTimes(1);
  });

  it("affiche les spécialités et permet de les basculer", async () => {
    apiPatchMock.mockResolvedValue({ data: {} });
    const user = userEvent.setup();
    renderPage();

    const genouChip = await screen.findByText("Genou");
    await user.click(genouChip);

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith("/api/users/42/specialties", { specialties: [] });
    });
  });
});
