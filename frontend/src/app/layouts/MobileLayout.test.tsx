import { describe, it, expect, vi, beforeEach, type Mock } from "vitest";
import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route, RouterProvider, createMemoryRouter } from "react-router-dom";
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

// Socle mobile chirurgien (Lot 1, 2026-08-05) — MobileLayout appelle désormais
// usePwaInstallMenuState() directement (menu "Plus" chirurgien), pas seulement via
// PwaInstallBanner (déjà mocké ci-dessus). Nécessite PwaInstallProvider, non monté
// dans cet arbre de test réduit — hors périmètre de ce fichier.
vi.mock("../features/pwa-install/usePwaInstallMenuState", () => ({
  usePwaInstallMenuState: vi.fn(() => ({
    label: "Installation non proposée automatiquement sur ce navigateur",
    actionLabel: null,
    onAction: null,
    disabled: true,
    variant: "unavailable",
  })),
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
    // /app/s est devenu un scope reconnu (chirurgien, Lot 1 socle mobile partagé,
    // 2026-08-05) — ce test ciblait auparavant /app/s précisément pour vérifier le
    // passthrough "route non reconnue" ; /app/m (jamais routé sous MobileLayout dans
    // la vraie appli, voir AppRouter.tsx) sert maintenant de repli pour la même
    // intention : une route dont ni /app/i ni /app/s ne sont un préfixe.
    it("hors des espaces mobiles (/app/i, /app/s), MobileLayout ne rend aucune navigation — simple passthrough", () => {
      mockDesktop(false);
      const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      render(
        <QueryClientProvider client={queryClient}>
          <MemoryRouter initialEntries={["/app/m"]}>
            <Routes>
              <Route path="/app/m" element={<MobileLayout />}>
                <Route index element={<div>Manager Home</div>} />
              </Route>
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>,
      );

      expect(screen.getByText("Manager Home")).toBeInTheDocument();
      expect(screen.queryByRole("navigation", { name: "Navigation instrumentiste" })).not.toBeInTheDocument();
      expect(screen.queryByRole("navigation", { name: "Navigation chirurgien" })).not.toBeInTheDocument();
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

  describe("Vagues du bandeau de marque — repli Safari (propriété CSS d non supportée)", () => {
    it("chaque <path> du BandWaves porte un attribut d HTML statique, pas seulement la propriété CSS d", () => {
      mockDesktop(false);
      const { container } = renderLayout();

      const wavePaths = container.querySelectorAll("path");
      expect(wavePaths.length).toBeGreaterThanOrEqual(3);
      wavePaths.forEach((path) => {
        expect(path.getAttribute("d")).toBeTruthy();
      });
    });
  });

  describe("Vagues du bandeau — redémarrage à chaque navigation (audit iOS, 2026-08-05)", () => {
    // useNavigate() est mocké plus haut (mockNavigate, simple recorder) pour les tests
    // de clic — insuffisant ici, où on doit prouver qu'un VRAI changement de
    // location.pathname (jamais mocké) redéclenche l'animation. createMemoryRouter +
    // router.navigate() pilote la navigation en dehors du hook mocké.
    function renderLayoutWithRouter(initialPath = "/app/i/today") {
      const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      const router = createMemoryRouter(
        [
          {
            path: "/app/i",
            element: <MobileLayout />,
            children: [
              { path: "today", element: <div>Today content</div> },
              { path: "planning", element: <div>Planning content</div> },
            ],
          },
        ],
        { initialEntries: [initialPath] },
      );
      const result = render(
        <QueryClientProvider client={queryClient}>
          <RouterProvider router={router} />
        </QueryClientProvider>,
      );
      return { ...result, router, queryClient };
    }

    function bandWavesSvg(container: HTMLElement) {
      return container.querySelector('svg[viewBox="0 0 400 190"]');
    }

    it("remonte uniquement le composant des vagues (pas tout le layout) sur un vrai changement de route", async () => {
      mockDesktop(false);
      const { container, router } = renderLayoutWithRouter("/app/i/today");

      // Forme réelle de l'onglet "today", une fois le kick d'arrivée retombé.
      let todayW1 = "";
      await waitFor(() => {
        todayW1 = bandWavesSvg(container)?.querySelector("path")?.getAttribute("d") ?? "";
        expect(todayW1).toBeTruthy();
      });

      const wavesBefore = bandWavesSvg(container);
      const navBefore = screen.getByRole("navigation", { name: "Navigation instrumentiste" });

      await act(async () => {
        await router.navigate("/app/i/planning");
      });
      // Attend que la navigation soit réellement rendue (le contenu de la nouvelle
      // route est monté) avant de comparer les nœuds — router.navigate() se résout
      // dès que l'état interne du routeur change, pas forcément après le commit React.
      await screen.findByText("Planning content");

      const wavesAfter = bandWavesSvg(container);
      const navAfter = screen.getByRole("navigation", { name: "Navigation instrumentiste" });

      // Le composant des vagues a été détruit/recréé (nouveau nœud DOM)...
      expect(wavesAfter).toBeTruthy();
      expect(wavesAfter).not.toBe(wavesBefore);
      // ...alors que le reste du layout (nav du bas, etc.) n'a jamais été remonté.
      expect(navAfter).toBe(navBefore);

      // Passé le court "kick" (70ms), la forme reflète bien le nouvel onglet — jamais
      // restée figée sur la forme de la page précédente (le bug rapporté sur iOS).
      await waitFor(() => {
        const planningW1 = bandWavesSvg(container)?.querySelector("path")?.getAttribute("d");
        expect(planningW1).toBeTruthy();
        expect(planningW1).not.toBe(todayW1);
      });
    });

    it("ne remonte pas le composant des vagues quand la route ne change pas", async () => {
      mockDesktop(false);
      const { container, rerender, queryClient, router } = renderLayoutWithRouter("/app/i/today");
      void router;

      const wavesBefore = bandWavesSvg(container);

      rerender(
        <QueryClientProvider client={queryClient}>
          <RouterProvider router={router} />
        </QueryClientProvider>,
      );

      const wavesAfter = bandWavesSvg(container);
      expect(wavesAfter).toBe(wavesBefore);
    });
  });
});

// ── Socle mobile chirurgien (Lot 1, 2026-08-05) ──────────────────────────────
// Describe séparé (pas nesté dans le describe instrumentiste ci-dessus) : son
// beforeEach fixe role: "SURGEON", incompatible avec le beforeEach INSTRUMENTIST
// de la suite au-dessus. Le socle réellement partagé (BrandBand, vagues, PWA,
// notifications) est déjà verrouillé par les tests instrumentiste ; ici on
// verrouille uniquement ce qui doit différer par rôle (tabs, libellés, menu Plus).
function renderSurgeonLayout(initialPath = "/app/s") {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const result = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/app/s" element={<MobileLayout />}>
            <Route index element={<div>Home content</div>} />
            <Route path="planning" element={<div>Planning content</div>} />
            <Route path="activity" element={<div>Activity content</div>} />
            <Route path="profile" element={<div>Profile content</div>} />
            <Route path="notifications" element={<div>Notifications content</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return { ...result, queryClient };
}

describe("MobileLayout — chirurgien (socle mobile partagé, Lot 1, 2026-08-05)", () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    mockPushStatus = "unsupported";
    vi.spyOn(window, "scrollTo").mockImplementation(() => {});
    (useAuth as unknown as Mock).mockReturnValue({
      state: {
        status: "authenticated",
        user: { id: 7, role: "SURGEON", sites: [], firstname: "Etienne", lastname: "Lejeune" },
      },
      logout: mockLogout,
    });
    // clearAllMocks() ne restaure pas une implémentation remplacée par
    // mockReturnValue() (seul mockReset()/mockRestore() le ferait, et
    // resetAllMocks() casserait aussi la factory de vi.mock ci-dessus) — repli
    // explicite au variant "unavailable" avant chaque test pour empêcher toute
    // fuite de l'override du test "variant actionable" vers les suivants.
    const { usePwaInstallMenuState } = await import("../features/pwa-install/usePwaInstallMenuState");
    (usePwaInstallMenuState as unknown as Mock).mockReturnValue({
      label: "Installation non proposée automatiquement sur ce navigateur",
      actionLabel: null,
      onAction: null,
      disabled: true,
      variant: "unavailable",
    });
  });

  describe("mobile (<900px)", () => {
    it("la bottom nav affiche exactement Accueil / Planning / Activité + Plus — jamais les onglets instrumentiste", async () => {
      mockDesktop(false);
      renderSurgeonLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation chirurgien" });
      expect(within(nav).getByRole("button", { name: /Accueil/ })).toBeInTheDocument();
      expect(within(nav).getByRole("button", { name: /Planning/ })).toBeInTheDocument();
      expect(within(nav).getByRole("button", { name: /Activité/ })).toBeInTheDocument();
      expect(within(nav).getByRole("button", { name: /Plus/ })).toBeInTheDocument();

      expect(within(nav).queryByRole("button", { name: /Aujourd'hui/ })).not.toBeInTheDocument();
      expect(within(nav).queryByRole("button", { name: /Offres/ })).not.toBeInTheDocument();
    });

    it("l'onglet correspondant à la route courante porte aria-current=\"page\"", async () => {
      mockDesktop(false);
      renderSurgeonLayout("/app/s/planning");

      const nav = await screen.findByRole("navigation", { name: "Navigation chirurgien" });
      expect(within(nav).getByRole("button", { name: /Planning/ })).toHaveAttribute("aria-current", "page");
      expect(within(nav).getByRole("button", { name: /Accueil/ })).not.toHaveAttribute("aria-current");
    });

    it("le bouton Plus porte aria-current=\"page\" sur une route du menu (ex. profil)", async () => {
      mockDesktop(false);
      renderSurgeonLayout("/app/s/profile");

      const nav = await screen.findByRole("navigation", { name: "Navigation chirurgien" });
      expect(within(nav).getByRole("button", { name: /Plus/ })).toHaveAttribute("aria-current", "page");
    });

    it("cliquer Plus ouvre le menu compte avec Mes demandes / Mes indisponibilités / Notifications, en plus de Mon profil / Se déconnecter", async () => {
      mockDesktop(false);
      const user = userEvent.setup();
      renderSurgeonLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation chirurgien" });
      await user.click(within(nav).getByRole("button", { name: /Plus/ }));

      expect(await screen.findByText("Mes demandes")).toBeInTheDocument();
      expect(screen.getByText("Mes indisponibilités")).toBeInTheDocument();
      expect(screen.getByText("Notifications")).toBeInTheDocument();
      expect(screen.getByText("Mon profil")).toBeInTheDocument();
      expect(screen.getByText("Se déconnecter")).toBeInTheDocument();
      expect(screen.getByText("Chirurgien")).toBeInTheDocument();
    });

    it("Mon profil depuis le menu Plus navigue vers /app/s/profile", async () => {
      mockDesktop(false);
      const user = userEvent.setup();
      renderSurgeonLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation chirurgien" });
      await user.click(within(nav).getByRole("button", { name: /Plus/ }));
      await user.click(await screen.findByText("Mon profil"));

      expect(mockNavigate).toHaveBeenCalledWith("/app/s/profile");
    });

    it("Mes demandes / Mes indisponibilités depuis le menu Plus naviguent vers /app/s/requests et /app/s/absences", async () => {
      mockDesktop(false);
      const user = userEvent.setup();
      renderSurgeonLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation chirurgien" });
      await user.click(within(nav).getByRole("button", { name: /Plus/ }));
      await user.click(await screen.findByText("Mes demandes"));
      expect(mockNavigate).toHaveBeenCalledWith("/app/s/requests");

      await user.click(within(nav).getByRole("button", { name: /Plus/ }));
      await user.click(await screen.findByText("Mes indisponibilités"));
      expect(mockNavigate).toHaveBeenCalledWith("/app/s/absences");
    });

    it("la cloche de notifications est présente (même mécanisme partagé que l'instrumentiste)", async () => {
      mockDesktop(false);
      renderSurgeonLayout();

      expect(await screen.findByRole("button", { name: "Notifications" })).toBeInTheDocument();
    });

    it("le bouton Notifications de la cloche navigue vers /app/s/notifications", async () => {
      mockDesktop(false);
      const user = userEvent.setup();
      renderSurgeonLayout();

      await user.click(await screen.findByRole("button", { name: "Notifications" }));
      expect(mockNavigate).toHaveBeenCalledWith("/app/s/notifications");
    });
  });

  describe("desktop (>=900px)", () => {
    it("le rail affiche Accueil / Planning / Activité + Plus, jamais Aujourd'hui/Offres", async () => {
      mockDesktop(true);
      renderSurgeonLayout();

      const aside = await screen.findByRole("complementary");
      expect(within(aside).getByRole("button", { name: "Accueil" })).toBeInTheDocument();
      expect(within(aside).getByRole("button", { name: "Planning" })).toBeInTheDocument();
      expect(within(aside).getByRole("button", { name: "Activité" })).toBeInTheDocument();
      expect(within(aside).getByRole("button", { name: "Plus" })).toBeInTheDocument();
      expect(within(aside).queryByText("Aujourd'hui")).not.toBeInTheDocument();
      expect(within(aside).queryByText("Offres")).not.toBeInTheDocument();
      expect(within(aside).getByText("Chirurgien")).toBeInTheDocument();
    });
  });

  describe("menu Plus — installation PWA (variant disponible)", () => {
    it("affiche l'entrée d'installation quand usePwaInstallMenuState signale une installation actionnable", async () => {
      const { usePwaInstallMenuState } = await import("../features/pwa-install/usePwaInstallMenuState");
      (usePwaInstallMenuState as unknown as Mock).mockReturnValue({
        label: "Installer l'application",
        actionLabel: "Installer",
        onAction: vi.fn(),
        disabled: false,
        variant: "actionable",
      });

      mockDesktop(false);
      const user = userEvent.setup();
      renderSurgeonLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation chirurgien" });
      await user.click(within(nav).getByRole("button", { name: /Plus/ }));

      expect(await screen.findByText("Installer l'application")).toBeInTheDocument();
    });

    it("n'affiche pas l'entrée d'installation quand variant est \"unavailable\" (repli par défaut de ce fichier)", async () => {
      mockDesktop(false);
      const user = userEvent.setup();
      renderSurgeonLayout();

      const nav = await screen.findByRole("navigation", { name: "Navigation chirurgien" });
      await user.click(within(nav).getByRole("button", { name: /Plus/ }));

      await screen.findByText("Mon profil");
      expect(screen.queryByText("Installation non proposée automatiquement sur ce navigateur")).not.toBeInTheDocument();
    });
  });
});
