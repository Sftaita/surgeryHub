import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import ProfilePage from "./ProfilePage";

const fetchMeMock = vi.fn();
const uploadProfilePictureMock = vi.fn();
const refreshUserMock = vi.fn().mockResolvedValue(undefined);
const toastSuccessMock = vi.fn();
const toastErrorMock = vi.fn();
let pushStatus: "permission-default" | "subscribed" | "permission-denied" | "unsupported" = "permission-default";
const subscribeToPushMock = vi.fn();

vi.mock("../../features/me/api/me.api", () => ({
  fetchMe: (...args: unknown[]) => fetchMeMock(...args),
  uploadProfilePicture: (...args: unknown[]) => uploadProfilePictureMock(...args),
}));

vi.mock("../../auth/AuthContext", () => ({
  useAuth: () => ({
    state: { status: "authenticated", user: { id: 7, firstname: "Ada" } },
    refreshUser: refreshUserMock,
  }),
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccessMock, error: toastErrorMock, warning: vi.fn() }),
}));

vi.mock("../../features/push/usePushNotifications", () => ({
  usePushNotifications: () => ({ status: pushStatus, subscribe: subscribeToPushMock }),
}));

// AvatarCropDialog needs canvas/Image APIs jsdom doesn't implement — same bypass
// pattern as pages/instrumentist/ProfilePage.test.tsx.
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

function baseMe(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 7,
    email: "ada@example.com",
    firstname: "Ada",
    lastname: "Lovelace",
    profilePictureUrl: "/uploads/profile-pictures/ada.jpg",
    role: "MANAGER",
    instrumentistProfile: null,
    sites: [],
    activeSiteId: null,
    ...overrides,
  };
}

beforeEach(() => {
  fetchMeMock.mockReset().mockResolvedValue(baseMe());
  uploadProfilePictureMock.mockReset();
  refreshUserMock.mockClear();
  toastSuccessMock.mockClear();
  toastErrorMock.mockClear();
  subscribeToPushMock.mockReset();
  pushStatus = "permission-default";
  vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("ProfilePage (manager/admin) — identité", () => {
  it("affiche le titre de la page", async () => {
    renderPage();
    expect(await screen.findByRole("heading", { name: "Mon profil" })).toBeInTheDocument();
  });

  it("affiche un indicateur de chargement avant résolution", () => {
    renderPage();
    expect(document.querySelector(".MuiCircularProgress-root")).toBeInTheDocument();
  });

  it("affiche le nom complet", async () => {
    renderPage();
    expect(await screen.findByText("Ada Lovelace")).toBeInTheDocument();
  });

  it("affiche le rôle MANAGER", async () => {
    fetchMeMock.mockResolvedValue(baseMe({ role: "MANAGER" }));
    renderPage();
    expect(await screen.findAllByText("Manager")).not.toHaveLength(0);
  });

  it("affiche le rôle ADMIN", async () => {
    fetchMeMock.mockResolvedValue(baseMe({ role: "ADMIN" }));
    renderPage();
    expect(await screen.findAllByText("Administrateur")).not.toHaveLength(0);
  });

  it("affiche l'e-mail", async () => {
    renderPage();
    expect(await screen.findByText("ada@example.com")).toBeInTheDocument();
  });
});

describe("ProfilePage — photo de profil", () => {
  it("affiche la photo existante via resolveApiAssetUrl", async () => {
    renderPage();
    const avatarImg = await screen.findByRole("img", { name: "Ada Lovelace" });
    expect(avatarImg).toHaveAttribute("src", "https://api.surgicalhub.test/uploads/profile-pictures/ada.jpg");
  });

  it("affiche les initiales en repli si aucune photo n'existe", async () => {
    fetchMeMock.mockResolvedValue(baseMe({ profilePictureUrl: null }));
    renderPage();
    expect(await screen.findByText("AL")).toBeInTheDocument();
    expect(screen.queryByRole("img", { name: "Ada Lovelace" })).toBeNull();
  });

  it("uploade la photo cropée, invalide le cache et rafraîchit le contexte utilisateur", async () => {
    uploadProfilePictureMock.mockResolvedValue(baseMe({ profilePictureUrl: "https://cdn.test/new.jpg" }));
    const user = userEvent.setup();
    renderPage();

    await screen.findByRole("img", { name: "Ada Lovelace" });

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(fileInput, new File(["x"], "photo.png", { type: "image/png" }));
    await user.click(await screen.findByText("confirm-crop"));

    await waitFor(() => expect(uploadProfilePictureMock).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(toastSuccessMock).toHaveBeenCalledWith("Photo de profil mise à jour"));
    expect(refreshUserMock).toHaveBeenCalledTimes(1);
  });

  it("affiche une erreur lisible si l'upload échoue, sans planter la page", async () => {
    uploadProfilePictureMock.mockRejectedValue(new Error("network"));
    const user = userEvent.setup();
    renderPage();

    await screen.findByRole("img", { name: "Ada Lovelace" });

    const fileInput = document.querySelector('input[type="file"]') as HTMLInputElement;
    await user.upload(fileInput, new File(["x"], "photo.png", { type: "image/png" }));
    await user.click(await screen.findByText("confirm-crop"));

    await waitFor(() => expect(toastErrorMock).toHaveBeenCalledWith("Impossible de mettre à jour la photo de profil"));
    expect(await screen.findByRole("heading", { name: "Mon profil" })).toBeInTheDocument();
  });

  it("ne propose aucune suppression de photo (capacité inexistante côté backend)", async () => {
    renderPage();
    await screen.findByRole("img", { name: "Ada Lovelace" });
    expect(screen.queryByRole("button", { name: "Supprimer la photo de profil" })).toBeNull();
  });
});

describe("ProfilePage — informations personnelles en lecture seule", () => {
  it("n'affiche aucun champ éditable pour prénom, nom ou e-mail", async () => {
    renderPage();
    await screen.findByText("Ada Lovelace");
    expect(screen.queryByRole("textbox")).toBeNull();
  });

  it("n'affiche aucun bouton d'enregistrement des informations personnelles", async () => {
    renderPage();
    await screen.findByText("Ada Lovelace");
    expect(screen.queryByRole("button", { name: /enregistrer/i })).toBeNull();
  });

  it("n'affiche aucun champ mot de passe", async () => {
    renderPage();
    await screen.findByText("Ada Lovelace");
    expect(document.querySelector('input[type="password"]')).toBeNull();
  });

  it("indique que la modification passe par un administrateur", async () => {
    renderPage();
    expect(await screen.findByText(/doit être réalisée par un administrateur/i)).toBeInTheDocument();
  });
});

describe("ProfilePage — notifications (préférences push existantes)", () => {
  it("propose d'activer les notifications quand la permission n'a jamais été demandée", async () => {
    pushStatus = "permission-default";
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Activer" }));
    expect(subscribeToPushMock).toHaveBeenCalledTimes(1);
  });

  it("indique que les notifications sont déjà activées", async () => {
    pushStatus = "subscribed";
    renderPage();
    expect(await screen.findByText("Notifications activées sur cet appareil.")).toBeInTheDocument();
  });
});

describe("ProfilePage — états d'erreur", () => {
  it("affiche un état d'erreur exploitable si le chargement du profil échoue (pas de page blanche)", async () => {
    fetchMeMock.mockRejectedValue(new Error("network"));
    renderPage();

    expect(await screen.findByText("Impossible de charger votre profil")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Réessayer" })).toBeInTheDocument();
  });
});
