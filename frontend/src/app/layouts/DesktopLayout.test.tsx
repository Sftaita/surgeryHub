import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { QueryClientProvider, QueryClient } from "@tanstack/react-query";

import { DesktopLayout } from "./DesktopLayout";

/**
 * Pré-déploiement Notifications §2 — l'entrée ADMIN "Historique des notifications"
 * (route déjà existante depuis D-084, voir AppRouter.tsx) est câblée ici de façon
 * isolée du reste de la refonte de navigation en cours (non liée à ce lot).
 */

let authRole: "ADMIN" | "MANAGER" | "INSTRUMENTIST" | "SURGEON" = "ADMIN";

vi.mock("../auth/AuthContext", () => ({
  useAuth: () => ({
    state: { status: "authenticated", user: { role: authRole, email: "user@test.com" } },
    logout: vi.fn(),
  }),
}));

vi.mock("../features/push/usePushNotifications", () => ({
  usePushNotifications: () => ({
    status: "permission-default",
    permission: "default",
    isSupported: true,
    isSubscribed: false,
    lastError: null,
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
    refreshStatus: vi.fn(),
  }),
}));

vi.mock("../features/pwa-install/usePwaInstallMenuState", () => ({
  usePwaInstallMenuState: () => ({
    label: "Installation non proposée automatiquement sur ce navigateur",
    actionLabel: null,
    onAction: null,
    disabled: true,
    variant: "unavailable",
  }),
}));

vi.mock("../features/manager-catalogue/api/catalogue.api", () => ({
  getMaterialRequests: vi.fn().mockResolvedValue({ items: [] }),
}));

vi.mock("../features/manager-catalogue/api/interventionTypeRequests.api", () => ({
  getInterventionTypeRequests: vi.fn().mockResolvedValue({ items: [] }),
}));

function renderLayout(initialPath = "/app/m/missions") {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route element={<DesktopLayout />}>
            <Route path="/app/m/missions" element={<div>Missions stub</div>} />
            <Route path="/app/admin/outbound-notifications" element={<div>Outbound notifications stub</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

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
