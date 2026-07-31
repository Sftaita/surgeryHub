import { describe, it, expect, vi, beforeEach, type Mock } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useMediaQuery } from "@mui/material";

import { MobileLayout } from "./MobileLayout";
import { useAuth } from "../auth/AuthContext";

const { mockNavigate } = vi.hoisted(() => ({ mockNavigate: vi.fn() }));

vi.mock("@mui/material", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@mui/material")>();
  return { ...actual, useMediaQuery: vi.fn() };
});

vi.mock("react-router-dom", async (importOriginal) => {
  const actual = await importOriginal<typeof import("react-router-dom")>();
  return { ...actual, useNavigate: () => mockNavigate };
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

const mockSubscribe = vi.fn();
let mockPushStatus: string = "unsupported";
vi.mock("../features/push/usePushNotifications", () => ({
  usePushNotifications: () => ({
    status: mockPushStatus,
    permission: "default",
    isSupported: true,
    isSubscribed: false,
    lastError: null,
    subscribe: mockSubscribe,
    unsubscribe: vi.fn(),
    refreshStatus: vi.fn(),
  }),
}));

vi.mock("../features/notifications/api/notifications.api", () => ({
  fetchUnreadNotificationsCount: vi.fn().mockResolvedValue(0),
}));

// Hors périmètre de ce fichier (refonte navigation) — voir MobileLayout.push.test.tsx
// pour la bannière push. Rendu marqué (plutôt que null) pour pouvoir vérifier le
// point de montage réel dans MobileLayout sans tester le composant lui-même
// (déjà couvert par PwaInstallBanner.test.tsx).
vi.mock("../features/pwa-install/PwaInstallBanner", () => ({
  PwaInstallBanner: () => <div data-testid="pwa-install-banner-mount" />,
}));

const mockLogout = vi.fn();

function mockDesktop(isDesktop: boolean) {
  (useMediaQuery as unknown as Mock).mockReturnValue(isDesktop);
}

function renderLayout(initialPath = "/app/i/today") {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const result = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/app/i" element={<MobileLayout />}>
            <Route path="today" element={<div>Today content</div>} />
            <Route path="planning" element={<div>Planning content</div>} />
            <Route path="offers" element={<div>Offers content</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return { ...result, queryClient };
}

describe("MobileLayout — nav instrumentiste (alignement handoff-instrumentiste-nav)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockPushStatus = "unsupported";
    // jsdom n'implémente pas window.scrollTo() — MobileLayout l'appelle désormais via
    // useRouteScrollRestoration (navigation et scroll, voir scrollRestoration.ts),
    // sans rapport avec ce que ces tests-ci vérifient.
    vi.spyOn(window, "scrollTo").mockImplementation(() => {});
    (useAuth as unknown as Mock).mockReturnValue({
      state: {
        status: "authenticated",
        user: { id: 1, role: "INSTRUMENTIST", sites: [], firstname: "Sophie", lastname: "Collette" },
      },
      logout: mockLogout,
    });
  });

  describe("desktop (>=900px)", () => {
    it("la sidebar affiche exactement les 3 items Aujourd'hui / Planning / Offres", async () => {
      mockDesktop(true);
      renderLayout();

      const aside = await screen.findByRole("complementary");
      expect(within(aside).getByRole("button", { name: "Aujourd'hui" })).toBeInTheDocument();
      expect(within(aside).getByRole("button", { name: "Planning" })).toBeInTheDocument();
      expect(within(aside).getByRole("button", { name: "Offres" })).toBeInTheDocument();

      // Messages/Notifications/Profil ne doivent plus jamais apparaître dans la sidebar.
      expect(within(aside).queryByText("Messages")).not.toBeInTheDocument();
      expect(within(aside).queryByText("Notifications")).not.toBeInTheDocument();
      expect(within(aside).queryByText("Profil")).not.toBeInTheDocument();
    });

    it("le bloc utilisateur est statique (pas de Popover) et expose un bouton de déconnexion direct", async () => {
      mockDesktop(true);
      renderLayout();

      const aside = await screen.findByRole("complementary");
      expect(within(aside).getByText("Sophie Collette")).toBeInTheDocument();
      expect(within(aside).getByText("Instrumentiste")).toBeInTheDocument();
      expect(within(aside).getByRole("button", { name: "Se déconnecter" })).toBeInTheDocument();

      // Exactement 4 boutons dans la sidebar : 3 onglets + 1 déconnexion — rien d'autre.
      expect(within(aside).getAllByRole("button")).toHaveLength(4);

      // Cliquer sur le nom/avatar (texte statique, plus un bouton) n'ouvre aucun menu.
      await userEvent.click(within(aside).getByText("Sophie Collette"));
      expect(screen.queryByText("Mon profil")).not.toBeInTheDocument();
      expect(screen.queryByRole("presentation")).not.toBeInTheDocument();
    });

    it("le bouton de déconnexion direct appelle logout() et redirige vers /login", async () => {
      mockDesktop(true);
      renderLayout();

      const aside = await screen.findByRole("complementary");
      await userEvent.click(within(aside).getByRole("button", { name: "Se déconnecter" }));

      expect(mockLogout).toHaveBeenCalledTimes(1);
      expect(mockNavigate).toHaveBeenCalledWith("/login", { replace: true });
    });

    it("cliquer un onglet de la sidebar navigue toujours correctement (Aujourd'hui, Planning, Offres)", async () => {
      mockDesktop(true);
      renderLayout();

      const aside = await screen.findByRole("complementary");
      await userEvent.click(within(aside).getByRole("button", { name: "Planning" }));
      expect(mockNavigate).toHaveBeenCalledWith("/app/i/planning");

      await userEvent.click(within(aside).getByRole("button", { name: "Offres" }));
      expect(mockNavigate).toHaveBeenCalledWith("/app/i/offers");

      await userEvent.click(within(aside).getByRole("button", { name: "Aujourd'hui" }));
      expect(mockNavigate).toHaveBeenCalledWith("/app/i/today");
    });

    it("l'onglet correspondant à la route courante porte aria-current=\"page\", pas les autres", async () => {
      mockDesktop(true);
      renderLayout("/app/i/planning");

      const aside = await screen.findByRole("complementary");
      expect(within(aside).getByRole("button", { name: "Planning" })).toHaveAttribute("aria-current", "page");
      expect(within(aside).getByRole("button", { name: "Aujourd'hui" })).not.toHaveAttribute("aria-current");
      expect(within(aside).getByRole("button", { name: "Offres" })).not.toHaveAttribute("aria-current");
    });
  });

  // Push activation banner: see MobileLayout.push.test.tsx (kept separate from this file
  // so the two can be staged/committed independently — pre-commit review, D-081).

  describe("mobile (<900px) — non-régression", () => {
    it("la bottom nav garde exactement les 3 mêmes onglets, sans sidebar desktop", async () => {
      mockDesktop(false);
      renderLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation instrumentiste" });
      expect(within(nav).getByRole("button", { name: "Aujourd'hui" })).toBeInTheDocument();
      expect(within(nav).getByRole("button", { name: "Planning" })).toBeInTheDocument();
      expect(within(nav).getByRole("button", { name: "Offres" })).toBeInTheDocument();
      expect(within(nav).getAllByRole("button")).toHaveLength(3);

      expect(screen.queryByRole("complementary")).not.toBeInTheDocument();
    });

    it("cliquer un onglet de la bottom nav navigue toujours correctement (Aujourd'hui, Planning, Offres)", async () => {
      mockDesktop(false);
      renderLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation instrumentiste" });
      await userEvent.click(within(nav).getByRole("button", { name: "Planning" }));
      expect(mockNavigate).toHaveBeenCalledWith("/app/i/planning");

      await userEvent.click(within(nav).getByRole("button", { name: "Offres" }));
      expect(mockNavigate).toHaveBeenCalledWith("/app/i/offers");

      await userEvent.click(within(nav).getByRole("button", { name: "Aujourd'hui" }));
      expect(mockNavigate).toHaveBeenCalledWith("/app/i/today");
    });

    it("l'onglet correspondant à la route courante porte aria-current=\"page\", pas les autres", async () => {
      mockDesktop(false);
      renderLayout("/app/i/offers");

      const nav = await screen.findByRole("navigation", { name: "Navigation instrumentiste" });
      expect(within(nav).getByRole("button", { name: "Offres" })).toHaveAttribute("aria-current", "page");
      expect(within(nav).getByRole("button", { name: "Aujourd'hui" })).not.toHaveAttribute("aria-current");
      expect(within(nav).getByRole("button", { name: "Planning" })).not.toHaveAttribute("aria-current");
    });

    it("affiche le badge sur Offres — nombre d'offres NON LUES (Lot 6), pas le total disponible", async () => {
      mockDesktop(false);
      const { fetchInstrumentistOffersWithFallback, fetchOffersUnreadCount } = await import("../features/missions/api/missions.api");
      // Volontairement différent du total (5) pour prouver que le badge ne dérive plus
      // de items.length (comportement pré-Lot 6) mais du compteur serveur dédié.
      (fetchInstrumentistOffersWithFallback as unknown as Mock).mockResolvedValue({
        items: [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }, { id: 5 }],
        total: 5,
      });
      (fetchOffersUnreadCount as unknown as Mock).mockResolvedValue(3);
      renderLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation instrumentiste" });
      expect(await within(nav).findByText("3")).toBeInTheDocument();
    });

    it("une erreur réseau sur un refetch en arrière-plan ne remet jamais le badge à zéro (revue post-rapport)", async () => {
      mockDesktop(false);
      const { fetchOffersUnreadCount } = await import("../features/missions/api/missions.api");
      (fetchOffersUnreadCount as unknown as Mock).mockResolvedValueOnce(4);
      const { queryClient } = renderLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation instrumentiste" });
      expect(await within(nav).findByText("4")).toBeInTheDocument();

      // Un refetch en arrière-plan échoue (ex: coupure réseau transitoire) — react-query
      // conserve la dernière valeur connue tant qu'un succès ne l'écrase pas ; le badge
      // ne doit jamais retomber silencieusement à 0/disparaître à cause de cet échec.
      (fetchOffersUnreadCount as unknown as Mock).mockRejectedValueOnce(new Error("network down"));
      await queryClient.refetchQueries({ queryKey: ["missions", "offers", "unread-count"] }).catch(() => {});

      expect(within(nav).getByText("4")).toBeInTheDocument();
    });
  });

  describe("BrandBand — Notifications et menu compte (les deux breakpoints)", () => {
    it("le bouton Notifications navigue vers /app/i/notifications", async () => {
      mockDesktop(false);
      renderLayout();

      await userEvent.click(await screen.findByRole("button", { name: "Notifications" }));
      expect(mockNavigate).toHaveBeenCalledWith("/app/i/notifications");
    });

    it("le badge de la cloche vient du compteur serveur (Lot 3, revue post-rapport) — plus de cache local", async () => {
      mockDesktop(false);
      const { fetchUnreadNotificationsCount } = await import("../features/notifications/api/notifications.api");
      (fetchUnreadNotificationsCount as unknown as Mock).mockResolvedValue(3);
      renderLayout();

      expect(await screen.findByRole("button", { name: "3 notifications non lues" })).toBeInTheDocument();
    });

    it("aucun libellé numéroté quand il n'y a aucune notification non lue", async () => {
      mockDesktop(false);
      const { fetchUnreadNotificationsCount } = await import("../features/notifications/api/notifications.api");
      (fetchUnreadNotificationsCount as unknown as Mock).mockResolvedValue(0);
      renderLayout();

      expect(await screen.findByRole("button", { name: "Notifications" })).toBeInTheDocument();
    });

    it("le bouton Compte ouvre le menu compte avec Mon profil et Se déconnecter", async () => {
      mockDesktop(false);
      renderLayout();

      await userEvent.click(await screen.findByRole("button", { name: "Compte" }));
      expect(screen.getByText("Mon profil")).toBeInTheDocument();
      expect(screen.getByText("Se déconnecter")).toBeInTheDocument();
    });

    it("Mon profil navigue vers /app/i/profile (accès Profil maintenu, hors barre principale)", async () => {
      mockDesktop(false);
      renderLayout();

      await userEvent.click(await screen.findByRole("button", { name: "Compte" }));
      await userEvent.click(screen.getByText("Mon profil"));

      expect(mockNavigate).toHaveBeenCalledWith("/app/i/profile");
    });

    it("Se déconnecter (menu compte) appelle logout() et redirige vers /login", async () => {
      mockDesktop(false);
      renderLayout();

      await userEvent.click(await screen.findByRole("button", { name: "Compte" }));
      await userEvent.click(screen.getByText("Se déconnecter"));

      expect(mockLogout).toHaveBeenCalledTimes(1);
      expect(mockNavigate).toHaveBeenCalledWith("/login", { replace: true });
    });

    it("le bouton Compte reste disponible aussi sur desktop (>=900px)", async () => {
      mockDesktop(true);
      renderLayout();

      await userEvent.click(await screen.findByRole("button", { name: "Compte" }));
      expect(screen.getByText("Mon profil")).toBeInTheDocument();
    });
  });

  describe("bannière d'installation PWA — point de montage", () => {
    it("PwaInstallBanner est bien monté dans le contenu (comportement propre déjà couvert par PwaInstallBanner.test.tsx)", async () => {
      mockDesktop(false);
      renderLayout();

      expect(await screen.findByTestId("pwa-install-banner-mount")).toBeInTheDocument();
    });
  });

  describe("utilisateur non chargé", () => {
    it("ne plante pas et affiche le nom de repli quand l'utilisateur n'est pas authentifié", async () => {
      (useAuth as unknown as Mock).mockReturnValue({ state: { status: "anonymous" }, logout: mockLogout });
      mockDesktop(true);
      renderLayout();

      const aside = await screen.findByRole("complementary");
      // "Instrumentiste" apparaît deux fois sans utilisateur chargé : nom de repli et rôle.
      expect(within(aside).getAllByText("Instrumentiste").length).toBe(2);
    });
  });

  describe("rôle non concerné par ce layout", () => {
    it("hors de l'espace instrumentiste (/app/i), MobileLayout ne rend aucune navigation — simple passthrough", () => {
      mockDesktop(false);
      const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      render(
        <QueryClientProvider client={queryClient}>
          <MemoryRouter initialEntries={["/app/s"]}>
            <Routes>
              <Route path="/app/s" element={<MobileLayout />}>
                <Route index element={<div>Surgeon Home</div>} />
              </Route>
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>,
      );

      expect(screen.getByText("Surgeon Home")).toBeInTheDocument();
      expect(screen.queryByRole("navigation", { name: "Navigation instrumentiste" })).not.toBeInTheDocument();
      expect(screen.queryByRole("complementary")).not.toBeInTheDocument();
      expect(screen.queryByRole("button", { name: "Compte" })).not.toBeInTheDocument();
    });
  });

  describe("iOS safe-area (Lot 4, audit PWA/mobile/admin 2026-07-29)", () => {
    it("le header mobile compense l'encoche/Dynamic Island via env(safe-area-inset-top)", () => {
      mockDesktop(false);
      renderLayout();

      const emittedCss = Array.from(document.querySelectorAll("style"))
        .map((el) => el.textContent ?? "")
        .join("\n");
      expect(emittedCss).toMatch(/env\(safe-area-inset-top\)/);
    });
  });
});
