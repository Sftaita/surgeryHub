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
 */

let authRole: "ADMIN" | "MANAGER" | "INSTRUMENTIST" | "SURGEON" = "ADMIN";

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({
    state: { status: "authenticated", user: { role: authRole, email: "user@test.com" } },
    logout: vi.fn(),
  }),
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
