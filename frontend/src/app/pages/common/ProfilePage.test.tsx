import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import ProfilePage from "./ProfilePage";

const fetchMeMock = vi.fn();
const apiPatchMock = vi.fn();
const uploadProfilePictureMock = vi.fn();
const refreshUserMock = vi.fn().mockResolvedValue(undefined);
const toastSuccessMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    patch: (...args: unknown[]) => apiPatchMock(...args),
  },
}));

vi.mock("../../features/me/api/me.api", () => ({
  fetchMe: (...args: unknown[]) => fetchMeMock(...args),
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

// Hors périmètre de ce fichier (identité/spécialités) — voir leur propre couverture.
vi.mock("../../features/pwa-install/PwaInstallMenuItem", () => ({
  PwaInstallMenuItem: () => null,
}));
vi.mock("../../features/push/PushPermissionCard", () => ({
  PushPermissionCard: () => null,
}));
vi.mock("../../features/notifications/NotificationPreferencesSection", () => ({
  NotificationPreferencesSection: () => null,
}));

const requestReplayMock = vi.fn();
vi.mock("../../features/instrumentist-onboarding/InstrumentistOnboardingReplayContext", () => ({
  useInstrumentistOnboardingReplay: () => ({ requestReplay: requestReplayMock }),
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

function meResponse(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    id: 42,
    email: "jane@example.com",
    firstname: "Jane",
    lastname: "Doe",
    phone: null,
    profilePictureUrl: "/uploads/profile-pictures/jane.jpg",
    role: "INSTRUMENTIST",
    instrumentistProfile: null,
    sites: [],
    activeSiteId: null,
    instrumentistOnboardingCompleted: false,
    ...overrides,
  };
}

beforeEach(() => {
  fetchMeMock.mockReset();
  apiPatchMock.mockReset();
  uploadProfilePictureMock.mockReset();
  refreshUserMock.mockClear();
  toastSuccessMock.mockClear();
  requestReplayMock.mockClear();
  vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("ProfilePage (partagée instrumentiste/chirurgien) — identité", () => {
  it("lit le nom/email/téléphone depuis les champs racine de MeResponse, jamais instrumentistProfile", async () => {
    fetchMeMock.mockResolvedValue(meResponse({
      role: "SURGEON",
      phone: "+32 475 00 00 00",
      instrumentistProfile: null,
    }));
    renderPage();

    expect(await screen.findByText("Jane Doe")).toBeInTheDocument();
    // "jane@example.com" apparaît deux fois par conception (carte identité + ligne
    // "E-mail" des informations personnelles) — jamais un doublon accidentel.
    expect(screen.getAllByText("jane@example.com").length).toBe(2);
    expect(screen.getByText("+32 475 00 00 00")).toBeInTheDocument();
    expect(screen.getByText("Chirurgien")).toBeInTheDocument();
  });

  it("affiche « — » quand le téléphone n'est pas renseigné", async () => {
    fetchMeMock.mockResolvedValue(meResponse({ phone: null }));
    renderPage();

    await screen.findByText("Jane Doe");
    const phoneRow = screen.getByText("Téléphone").closest("div")?.parentElement;
    expect(phoneRow).toHaveTextContent("—");
  });

  it("affiche les sites quand présents", async () => {
    fetchMeMock.mockResolvedValue(meResponse({
      sites: [{ id: 2, name: "Delta", timezone: "Europe/Brussels" }],
    }));
    renderPage();

    await screen.findByText("Jane Doe");
    expect(screen.getByText("Delta")).toBeInTheDocument();
  });

  it("résout l'URL de la photo via resolveApiAssetUrl (régression du bug d'URL relative)", async () => {
    fetchMeMock.mockResolvedValue(meResponse());
    renderPage();

    const avatarImg = await screen.findByRole("img", { name: "Jane Doe" });
    expect(avatarImg).toHaveAttribute(
      "src",
      "https://api.surgicalhub.test/uploads/profile-pictures/jane.jpg",
    );
  });

  it("uploade la photo cropée, invalide le cache et rafraîchit l'utilisateur", async () => {
    fetchMeMock.mockResolvedValue(meResponse());
    uploadProfilePictureMock.mockResolvedValue(meResponse());
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
});

describe("ProfilePage — section instrumentiste conditionnelle", () => {
  it("INSTRUMENTIST : affiche les compétences orthopédiques et le bouton Revoir", async () => {
    fetchMeMock.mockResolvedValue(meResponse({
      role: "INSTRUMENTIST",
      instrumentistProfile: { specialties: ["GENOU"] },
    }));
    renderPage();

    expect(await screen.findByText("Mes compétences orthopédiques")).toBeInTheDocument();
    expect(screen.getByText("Revoir")).toBeInTheDocument();
  });

  it("INSTRUMENTIST : bascule une spécialité", async () => {
    fetchMeMock.mockResolvedValue(meResponse({
      role: "INSTRUMENTIST",
      instrumentistProfile: { specialties: ["GENOU"] },
    }));
    apiPatchMock.mockResolvedValue({ data: {} });
    const user = userEvent.setup();
    renderPage();

    const genouChip = await screen.findByText("Genou");
    await user.click(genouChip);

    await waitFor(() => {
      expect(apiPatchMock).toHaveBeenCalledWith("/api/users/42/specialties", { specialties: [] });
    });
  });

  it("le bouton « Revoir » appelle requestReplay() sans mutation serveur", async () => {
    fetchMeMock.mockResolvedValue(meResponse({ role: "INSTRUMENTIST", instrumentistProfile: { specialties: [] } }));
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByText("Revoir"));

    expect(requestReplayMock).toHaveBeenCalledTimes(1);
    expect(apiPatchMock).not.toHaveBeenCalled();
  });

  it("SURGEON : n'affiche jamais les compétences orthopédiques ni le bouton Revoir", async () => {
    fetchMeMock.mockResolvedValue(meResponse({ role: "SURGEON", instrumentistProfile: null }));
    renderPage();

    await screen.findByText("Jane Doe");
    expect(screen.queryByText("Mes compétences orthopédiques")).not.toBeInTheDocument();
    expect(screen.queryByText("Revoir")).not.toBeInTheDocument();
  });

  it("MANAGER : n'affiche pas non plus la section instrumentiste (même garde que SURGEON)", async () => {
    fetchMeMock.mockResolvedValue(meResponse({ role: "MANAGER", instrumentistProfile: null }));
    renderPage();

    await screen.findByText("Jane Doe");
    expect(screen.queryByText("Mes compétences orthopédiques")).not.toBeInTheDocument();
  });
});
