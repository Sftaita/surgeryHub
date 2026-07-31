import { describe, it, expect, vi, beforeEach, afterEach, type Mock } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route, useNavigate } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useMediaQuery } from "@mui/material";

import { MobileLayout } from "./MobileLayout";
import { useAuth } from "../auth/AuthContext";
import { getEncodingBackTarget, resetScrollRestoration } from "./scrollRestoration";

// Contrairement à MobileLayout.test.tsx, useNavigate n'est PAS mocké ici : ces tests
// vérifient une vraie navigation interne (MemoryRouter) pour exercer l'effet de
// MobileLayout qui mémorise l'origine de l'encodage (voir scrollRestoration.ts).

vi.mock("@mui/material", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@mui/material")>();
  return { ...actual, useMediaQuery: vi.fn() };
});

vi.mock("../auth/AuthContext", () => ({
  useAuth: vi.fn(),
}));

vi.mock("../features/missions/api/missions.api", () => ({
  fetchMissions: vi.fn().mockResolvedValue({ items: [], total: 0 }),
  fetchInstrumentistOffersWithFallback: vi.fn().mockResolvedValue({ items: [], total: 0 }),
  fetchOffersUnreadCount: vi.fn().mockResolvedValue(0),
}));

vi.mock("../features/missions/sync/useInstrumentistMissionSync", () => ({
  useInstrumentistMissionSync: vi.fn(),
}));

vi.mock("../features/push/usePushNotifications", () => ({
  usePushNotifications: () => ({ pushState: "unsupported", requestPermission: vi.fn() }),
}));

vi.mock("../features/notifications/api/notifications.api", () => ({
  fetchUnreadNotificationsCount: vi.fn().mockResolvedValue(0),
}));

vi.mock("../features/pwa-install/PwaInstallBanner", () => ({
  PwaInstallBanner: () => null,
}));

vi.mock("../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}));

function StubToday() {
  const navigate = useNavigate();
  return (
    <div>
      <div>Today content</div>
      <button onClick={() => navigate("/app/i/missions/42/encoding")}>Aller à l'encodage</button>
    </div>
  );
}

function StubPlanning() {
  const navigate = useNavigate();
  return (
    <div>
      <div>Planning content</div>
      <button onClick={() => navigate("/app/i/missions/42/encoding")}>Aller à l'encodage</button>
    </div>
  );
}

// getEncodingBackTarget() n'est lu qu'au clic (dans le handler), jamais pendant le
// rendu — exactement comme MissionEncodingPage.tsx (handleBack). MobileLayout
// mémorise l'origine dans un effet qui s'exécute après le commit initial : le lire
// pendant le rendu de ce composant enfant renverrait une valeur pas encore à jour.
function StubEncoding() {
  const navigate = useNavigate();
  return (
    <div>
      <div>Encoding content</div>
      <button onClick={() => navigate(getEncodingBackTarget())}>Retour</button>
    </div>
  );
}

function renderApp(initialPath: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/app/i" element={<MobileLayout />}>
            <Route path="today" element={<StubToday />} />
            <Route path="planning" element={<StubPlanning />} />
            <Route path="missions/:id/encoding" element={<StubEncoding />} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("MobileLayout — origine de l'écran d'encodage (navigation réelle)", () => {
  beforeEach(() => {
    resetScrollRestoration();
    // jsdom n'implémente pas window.scrollTo() (log "Not implemented" bruyant sans
    // ça) — MobileLayout l'appelle via useRouteScrollRestoration à chaque changement
    // de route, sans rapport avec ce que ces tests vérifient (l'origine de l'encodage).
    vi.spyOn(window, "scrollTo").mockImplementation(() => {});
    (useMediaQuery as unknown as Mock).mockReturnValue(false);
    (useAuth as unknown as Mock).mockReturnValue({
      state: {
        status: "authenticated",
        user: { id: 1, role: "INSTRUMENTIST", sites: [], firstname: "Sophie", lastname: "Collette" },
      },
      logout: vi.fn(),
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("la flèche retour de l'encodage revient réellement vers la route d'origine (today)", async () => {
    const user = userEvent.setup();
    renderApp("/app/i/today");

    await user.click(await screen.findByRole("button", { name: "Aller à l'encodage" }));
    await screen.findByText("Encoding content");

    await user.click(screen.getByRole("button", { name: "Retour" }));

    expect(await screen.findByText("Today content")).toBeInTheDocument();
    expect(screen.queryByText("Encoding content")).not.toBeInTheDocument();
  });

  it("la flèche retour de l'encodage revient vers une origine différente (planning) — jamais today par défaut", async () => {
    const user = userEvent.setup();
    renderApp("/app/i/planning");

    await user.click(await screen.findByRole("button", { name: "Aller à l'encodage" }));
    await screen.findByText("Encoding content");

    await user.click(screen.getByRole("button", { name: "Retour" }));

    expect(await screen.findByText("Planning content")).toBeInTheDocument();
    expect(screen.queryByText("Today content")).not.toBeInTheDocument();
  });

  it("ouverture directe de l'encodage (lien profond, sans origine en historique) → route de secours today", async () => {
    const user = userEvent.setup();
    renderApp("/app/i/missions/42/encoding");
    await screen.findByText("Encoding content");

    await user.click(screen.getByRole("button", { name: "Retour" }));

    expect(await screen.findByText("Today content")).toBeInTheDocument();
  });

  it("une nouvelle entrée dans l'espace instrumentiste (remontage de MobileLayout) efface l'origine mémorisée d'une session précédente", async () => {
    const user = userEvent.setup();
    // Origine délibérément différente de la route de secours (/app/i/today), pour
    // qu'une éventuelle fuite d'état entre les deux montages soit observable : si le
    // reset au montage ne fonctionnait pas, le retour de la 2e phase atterrirait sur
    // "Planning content" au lieu du repli today attendu.
    const { unmount } = renderApp("/app/i/planning");

    await user.click(await screen.findByRole("button", { name: "Aller à l'encodage" }));
    await screen.findByText("Encoding content");

    // Déconnexion/reconnexion dans le même onglet : MobileLayout est démonté puis
    // remonté, sans rechargement complet de la page (l'état module-level survit).
    unmount();
    renderApp("/app/i/missions/42/encoding");
    await screen.findByText("Encoding content");

    await user.click(screen.getByRole("button", { name: "Retour" }));

    expect(await screen.findByText("Today content")).toBeInTheDocument();
    expect(screen.queryByText("Planning content")).not.toBeInTheDocument();
  });
});
