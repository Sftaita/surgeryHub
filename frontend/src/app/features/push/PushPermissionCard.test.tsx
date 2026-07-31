import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PushPermissionCard } from "./PushPermissionCard";

let status: "unsupported" | "permission-default" | "permission-denied" | "subscribing" | "subscribed" | "error" =
  "permission-default";
const subscribeMock = vi.fn();
const unsubscribeMock = vi.fn();

vi.mock("./usePushNotifications", () => ({
  usePushNotifications: () => ({ status, subscribe: subscribeMock, unsubscribe: unsubscribeMock }),
}));

let platform: "ios" | "android" | "other" = "other";
vi.mock("../pwa-install/pwaInstallDetection", () => ({
  detectPlatform: () => platform,
}));

beforeEach(() => {
  status = "permission-default";
  platform = "other";
  subscribeMock.mockReset();
  unsubscribeMock.mockReset();
});

describe("PushPermissionCard — les 4 états de permission (Lot 3)", () => {
  it("propose d'activer quand la permission n'a jamais été demandée", async () => {
    status = "permission-default";
    const user = userEvent.setup();
    render(<PushPermissionCard />);

    await user.click(screen.getByRole("button", { name: "Activer" }));
    expect(subscribeMock).toHaveBeenCalledTimes(1);
  });

  it("indique que les notifications sont activées et permet de les désactiver", async () => {
    status = "subscribed";
    const user = userEvent.setup();
    render(<PushPermissionCard />);

    expect(screen.getByText("Notifications activées sur cet appareil.")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Désactiver" }));
    expect(unsubscribeMock).toHaveBeenCalledTimes(1);
  });

  it("explique la marche à suivre iOS quand la permission est refusée", () => {
    status = "permission-denied";
    platform = "ios";
    render(<PushPermissionCard />);

    expect(screen.getByText("Notifications bloquées par le navigateur.")).toBeInTheDocument();
    expect(screen.getByText(/Réglages iOS/)).toBeInTheDocument();
  });

  it("explique la marche à suivre Android quand la permission est refusée", () => {
    status = "permission-denied";
    platform = "android";
    render(<PushPermissionCard />);

    expect(screen.getByText(/paramètres de votre navigateur/)).toBeInTheDocument();
  });

  it("indique l'indisponibilité sur ce navigateur", () => {
    status = "unsupported";
    render(<PushPermissionCard />);

    expect(screen.getByText("Les notifications ne sont pas prises en charge par ce navigateur.")).toBeInTheDocument();
  });

  it("n'affiche jamais de bouton prétendant réactiver automatiquement une permission bloquée", () => {
    status = "permission-denied";
    render(<PushPermissionCard />);

    expect(screen.queryByRole("button")).toBeNull();
  });
});
