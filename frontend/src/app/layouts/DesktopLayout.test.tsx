import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { QueryClientProvider, QueryClient } from "@tanstack/react-query";

import { DesktopLayout } from "./DesktopLayout";

/**
 * Pré-déploiement Notifications §2 — l'entrée ADMIN "Historique des notifications"
 * (route déjà existante depuis D-084, voir AppRouter.tsx) est câblée ici de façon
 * isolée du reste de la refonte de navigation en cours (non liée à ce lot).
 *
 * Lot 9 (D-079) — étend cette suite avec la nouvelle structure de navigation
 * groupée (Dashboard/Missions/.../Catalogue/Planning/Facturation/Administration)
 * et les badges de demandes en attente (useNavBadgeCount).
 *
 * Lot 10 — étend cette suite avec le menu compte (avatar, préférences push,
 * installation PWA, déconnexion). Aucune action "Profil" : aucune route/écran
 * profil manager/admin n'existe dans le code actuel (seule /app/i/profile existe,
 * réservée aux instrumentistes) — voir le rapport du Lot 10.
 */

let authStatus: "authenticated" | "anonymous" = "authenticated";
let authRole: "ADMIN" | "MANAGER" | "INSTRUMENTIST" | "SURGEON" = "ADMIN";
let authUser: { firstname?: string | null; lastname?: string | null; profilePictureUrl?: string | null } = {
  firstname: "Ada", lastname: "Lovelace", profilePictureUrl: null,
};
const logoutMock = vi.fn();

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({
    state: authStatus === "authenticated"
      ? { status: "authenticated", user: { role: authRole, email: "user@test.com", ...authUser } }
      : { status: "anonymous" },
    logout: logoutMock,
  }),
}));

let pushStatus: "permission-default" | "subscribed" | "unsupported" | "permission-denied" = "permission-default";
const subscribeToPushMock = vi.fn();
vi.mock("../features/push/usePushNotifications", () => ({
  usePushNotifications: () => ({ status: pushStatus, subscribe: subscribeToPushMock }),
}));

let pwaVariant: "installed" | "actionable" | "unavailable" = "actionable";
const pwaOnActionMock = vi.fn();
vi.mock("../features/pwa-install/usePwaInstallMenuState", () => ({
  usePwaInstallMenuState: () => {
    if (pwaVariant === "unavailable") {
      return { label: "Installation non proposée automatiquement sur ce navigateur", actionLabel: null, onAction: null, disabled: true, variant: "unavailable" as const };
    }
    if (pwaVariant === "installed") {
      return { label: "Application installée", actionLabel: null, onAction: null, disabled: true, variant: "installed" as const };
    }
    return { label: "Installer l'application", actionLabel: "Installer", onAction: pwaOnActionMock, disabled: false, variant: "actionable" as const };
  },
}));

const getMaterialRequestsMock = vi.fn();
vi.mock("../features/manager-catalogue/api/catalogue.api", () => ({
  getMaterialRequests: (...args: unknown[]) => getMaterialRequestsMock(...args),
}));

const getInterventionTypeRequestsMock = vi.fn();
vi.mock("../features/manager-catalogue/api/interventionTypeRequests.api", () => ({
  getInterventionTypeRequests: (...args: unknown[]) => getInterventionTypeRequestsMock(...args),
}));

function renderLayout(initialPath = "/app/m/missions") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route element={<DesktopLayout />}>
            <Route path="/app/m/dashboard" element={<div>Dashboard stub</div>} />
            <Route path="/app/m/missions" element={<div>Missions stub</div>} />
            <Route path="/app/m/catalogue/requests" element={<div>Requests stub</div>} />
            <Route path="/app/admin/outbound-notifications" element={<div>Outbound notifications stub</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  authStatus = "authenticated";
  authUser = { firstname: "Ada", lastname: "Lovelace", profilePictureUrl: null };
  logoutMock.mockReset();
  pushStatus = "permission-default";
  subscribeToPushMock.mockReset();
  pwaVariant = "actionable";
  pwaOnActionMock.mockReset();
  getMaterialRequestsMock.mockReset().mockResolvedValue({ items: [] });
  getInterventionTypeRequestsMock.mockReset().mockResolvedValue({ items: [] });
});

describe("DesktopLayout — entrée ADMIN Historique des notifications", () => {
  it("ADMIN voit l'entrée", () => {
    authRole = "ADMIN";
    renderLayout();
    const link = screen.getByRole("link", { name: "Historique des notifications" });
    expect(link).toHaveAttribute("href", "/app/admin/outbound-notifications");
  });

  it("MANAGER ne voit pas l'entrée", () => {
    authRole = "MANAGER";
    renderLayout();
    expect(screen.queryByRole("link", { name: "Historique des notifications" })).toBeNull();
  });

  it("INSTRUMENTIST ne voit pas l'entrée", () => {
    authRole = "INSTRUMENTIST";
    renderLayout();
    expect(screen.queryByRole("link", { name: "Historique des notifications" })).toBeNull();
  });

  it("SURGEON ne voit pas l'entrée", () => {
    authRole = "SURGEON";
    renderLayout();
    expect(screen.queryByRole("link", { name: "Historique des notifications" })).toBeNull();
  });

  it("le clic ouvre /app/admin/outbound-notifications", async () => {
    authRole = "ADMIN";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("link", { name: "Historique des notifications" }));

    expect(await screen.findByText("Outbound notifications stub")).toBeInTheDocument();
  });

  it("aucun doublon de l'entrée", () => {
    authRole = "ADMIN";
    renderLayout();
    expect(screen.getAllByRole("link", { name: "Historique des notifications" })).toHaveLength(1);
  });

  it("les autres entrées Administration restent inchangées", () => {
    authRole = "ADMIN";
    renderLayout();
    expect(screen.getByRole("link", { name: "Utilisateurs" })).toHaveAttribute("href", "/app/admin/users");
    expect(screen.getByRole("link", { name: "Sites" })).toHaveAttribute("href", "/app/admin/sites");
    expect(screen.getByRole("link", { name: "Invitations" })).toHaveAttribute("href", "/app/admin/invitations");
    expect(screen.getByRole("link", { name: "Audit" })).toHaveAttribute("href", "/app/admin/audit");
  });
});

describe("DesktopLayout — navigation groupée (D-079)", () => {
  it("affiche le groupe principal pour MANAGER : Dashboard, Missions, Instrumentistes, Chirurgiens, Établissements", () => {
    authRole = "MANAGER";
    renderLayout();
    expect(screen.getByRole("link", { name: "Dashboard" })).toHaveAttribute("href", "/app/m/dashboard");
    expect(screen.getByRole("link", { name: "Missions" })).toHaveAttribute("href", "/app/m/missions");
    expect(screen.getByRole("link", { name: "Instrumentistes" })).toHaveAttribute("href", "/app/m/instrumentists");
    expect(screen.getByRole("link", { name: "Chirurgiens" })).toHaveAttribute("href", "/app/m/surgeons");
    expect(screen.getByRole("link", { name: "Établissements" })).toHaveAttribute("href", "/app/m/hospitals");
  });

  it("affiche le groupe Catalogue : Firmes, Prestations, Demandes", () => {
    authRole = "MANAGER";
    renderLayout();
    expect(screen.getByText("Catalogue")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Firmes" })).toHaveAttribute("href", "/app/m/firms");
    expect(screen.getByRole("link", { name: "Prestations" })).toHaveAttribute("href", "/app/m/catalogue/prestations");
    expect(screen.getByRole("link", { name: "Demandes" })).toHaveAttribute("href", "/app/m/catalogue/requests");
  });

  it("affiche le groupe Planning : Construire, Absences", () => {
    authRole = "MANAGER";
    renderLayout();
    expect(screen.getByText("Planning")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Construire" })).toHaveAttribute("href", "/app/m/planning/v2");
    expect(screen.getByRole("link", { name: "Absences" })).toHaveAttribute("href", "/app/m/planning/absences");
  });

  it("affiche le groupe Facturation : Factures Firmes, Décomptes, Statistiques", () => {
    authRole = "MANAGER";
    renderLayout();
    expect(screen.getByText("Facturation")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Factures Firmes" })).toHaveAttribute("href", "/app/m/billing/firm-invoices");
    expect(screen.getByRole("link", { name: "Décomptes" })).toHaveAttribute("href", "/app/m/billing/statements");
    expect(screen.getByRole("link", { name: "Statistiques" })).toHaveAttribute("href", "/app/m/finance/statistics");
  });

  it("le groupe Administration n'apparaît que pour ADMIN", () => {
    authRole = "MANAGER";
    const { unmount } = renderLayout();
    expect(screen.queryByText("Administration")).toBeNull();
    unmount();

    authRole = "ADMIN";
    renderLayout();
    expect(screen.getByText("Administration")).toBeInTheDocument();
  });

  it("le clic sur Demandes navigue vers /app/m/catalogue/requests", async () => {
    authRole = "MANAGER";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("link", { name: "Demandes" }));

    expect(await screen.findByText("Requests stub")).toBeInTheDocument();
  });

  it("le lien de la route active porte l'état actif MUI (selected)", () => {
    authRole = "MANAGER";
    renderLayout("/app/m/missions");
    const missionsButton = screen.getByRole("link", { name: "Missions" }).querySelector(".MuiListItemButton-root");
    expect(missionsButton?.className).toMatch(/Mui-selected/);
    const dashboardButton = screen.getByRole("link", { name: "Dashboard" }).querySelector(".MuiListItemButton-root");
    expect(dashboardButton?.className).not.toMatch(/Mui-selected/);
  });

  it("les anciennes entrées non regroupées de l'ancienne sidebar ont disparu (Configuration, Planning publié, Matériel en item racine)", () => {
    authRole = "MANAGER";
    renderLayout();
    expect(screen.queryByRole("link", { name: "Configuration" })).toBeNull();
    expect(screen.queryByRole("link", { name: "Planning publié" })).toBeNull();
    expect(screen.queryByRole("link", { name: "Matériel" })).toBeNull();
  });
});

describe("DesktopLayout — badges de demandes en attente (D-079)", () => {
  it("affiche le badge sur Demandes quand des demandes matériel sont en attente", async () => {
    authRole = "MANAGER";
    getMaterialRequestsMock.mockResolvedValue({ items: [{ id: 1 }, { id: 2 }, { id: 3 }] });
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [] });
    renderLayout();

    expect(await screen.findByText("3")).toBeInTheDocument();
  });

  it("affiche le badge sur Demandes quand des demandes de type d'intervention sont en attente", async () => {
    authRole = "MANAGER";
    getMaterialRequestsMock.mockResolvedValue({ items: [] });
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [{ id: 1 }, { id: 2 }] });
    renderLayout();

    expect(await screen.findByText("2")).toBeInTheDocument();
  });

  it("cumule les deux sources dans un badge unique", async () => {
    authRole = "MANAGER";
    getMaterialRequestsMock.mockResolvedValue({ items: [{ id: 1 }] });
    getInterventionTypeRequestsMock.mockResolvedValue({ items: [{ id: 1 }, { id: 2 }] });
    renderLayout();

    expect(await screen.findByText("3")).toBeInTheDocument();
  });

  it("n'affiche aucun badge quand aucune demande n'est en attente", () => {
    authRole = "MANAGER";
    renderLayout();
    const requestsLink = screen.getByRole("link", { name: "Demandes" });
    expect(requestsLink.textContent).toBe("Demandes");
  });
});

describe("DesktopLayout — menu compte (Lot 10)", () => {
  it("affiche le nom du compte connecté", () => {
    renderLayout();
    expect(screen.getAllByText("Ada Lovelace").length).toBeGreaterThan(0);
  });

  it("sans photo de profil, affiche les initiales en repli", () => {
    authUser = { firstname: "Ada", lastname: "Lovelace", profilePictureUrl: null };
    renderLayout();
    expect(screen.getByText("AL")).toBeInTheDocument();
    expect(screen.queryByRole("img", { name: "Ada Lovelace" })).toBeNull();
  });

  it("avec une photo de profil, affiche l'image plutôt que les initiales", () => {
    authUser = { firstname: "Ada", lastname: "Lovelace", profilePictureUrl: "/uploads/profile-pictures/ada.jpg" };
    renderLayout();
    const img = screen.getByRole("img", { name: "Ada Lovelace" });
    expect(img).toHaveAttribute("src", expect.stringContaining("/uploads/profile-pictures/ada.jpg"));
    expect(screen.queryByText("AL")).toBeNull();
  });

  it("le déclencheur porte les attributs d'accessibilité attendus, menu fermé initialement", () => {
    renderLayout();
    const trigger = screen.getByRole("button", { name: /Ada Lovelace/ });
    expect(trigger).toHaveAttribute("aria-haspopup", "menu");
    expect(trigger).not.toHaveAttribute("aria-expanded");
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("ouvre le menu au clic sur le déclencheur", async () => {
    const user = userEvent.setup();
    renderLayout();

    const trigger = screen.getByRole("button", { name: /Ada Lovelace/ });
    await user.click(trigger);

    expect(screen.getByRole("menu")).toBeInTheDocument();
    // Le menu MUI ouvert applique aria-hidden au reste de l'arbre (dont le déclencheur) —
    // on relit donc l'attribut sur la même référence DOM plutôt que de requêter par rôle.
    expect(trigger).toHaveAttribute("aria-expanded", "true");
  });

  it("ferme le menu à la touche Échap", async () => {
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    expect(screen.getByRole("menu")).toBeInTheDocument();

    await user.keyboard("{Escape}");
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("affiche l'action Préférences de notifications quand elles sont désactivées, et appelle l'abonnement au clic", async () => {
    pushStatus = "permission-default";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    const item = screen.getByRole("menuitem", { name: "Activer les notifications" });
    await user.click(item);

    expect(subscribeToPushMock).toHaveBeenCalledTimes(1);
    // Ferme le menu après sélection.
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("n'affiche pas l'action Préférences de notifications si déjà abonné", async () => {
    pushStatus = "subscribed";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    expect(screen.queryByRole("menuitem", { name: "Activer les notifications" })).toBeNull();
  });

  it("l'ouverture du menu ne déclenche jamais elle-même une demande de permission navigateur", async () => {
    pushStatus = "permission-default";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));

    expect(subscribeToPushMock).not.toHaveBeenCalled();
  });

  it("affiche l'action Installation quand disponible, et appelle le mécanisme PWA existant au clic", async () => {
    pwaVariant = "actionable";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    const item = screen.getByRole("menuitem", { name: "Installer l'application" });
    await user.click(item);

    expect(pwaOnActionMock).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("masque l'action Installation quand le point d'entrée n'est pas disponible (variant unavailable)", async () => {
    pwaVariant = "unavailable";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    expect(screen.queryByRole("menuitem", { name: /Installation/ })).toBeNull();
    expect(screen.queryByRole("menuitem", { name: "Application installée" })).toBeNull();
  });

  it("affiche « Application installée » désactivé quand l'application est déjà installée", async () => {
    pwaVariant = "installed";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    const item = screen.getByRole("menuitem", { name: "Application installée" });
    expect(item).toHaveAttribute("aria-disabled", "true");
  });

  it("affiche toujours l'action Déconnexion et appelle logout() au clic", async () => {
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    const item = screen.getByRole("menuitem", { name: "Se déconnecter" });
    await user.click(item);

    expect(logoutMock).toHaveBeenCalledTimes(1);
  });

  it("n'affiche aucune action représentée uniquement par une icône (chaque item porte un intitulé textuel)", async () => {
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    const items = screen.getAllByRole("menuitem");
    expect(items.length).toBeGreaterThan(0);
    for (const item of items) {
      expect(item.textContent?.trim().length).toBeGreaterThan(0);
    }
  });

  it("aucun menu compte affiché quand l'utilisateur n'est pas authentifié", () => {
    authStatus = "anonymous";
    renderLayout();
    expect(screen.queryByRole("button", { name: /Ada Lovelace/ })).toBeNull();
    expect(screen.queryByRole("button", { name: "Se déconnecter" })).toBeNull();
  });

  it("ne contient aucun lien vers les destinations de la navigation principale (Dashboard, Missions, Catalogue, Planning, Facturation, Administration)", async () => {
    authRole = "ADMIN";
    const user = userEvent.setup();
    renderLayout();

    await user.click(screen.getByRole("button", { name: /Ada Lovelace/ }));
    const menu = screen.getByRole("menu");
    expect(menu.querySelector("a")).toBeNull();
  });
});
