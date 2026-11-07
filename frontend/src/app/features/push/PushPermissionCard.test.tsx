import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PushPermissionCard } from "./PushPermissionCard";

let status: "unsupported" | "permission-default" | "permission-denied" | "subscribing" | "subscribed" | "error" =
  "permission-default";
const subscribeMock = vi.fn();
const unsubscribeMock = vi.fn();
const repairMock = vi.fn();
let health: Record<string, unknown> | null = null;

vi.mock("./usePushNotifications", () => ({
  usePushNotifications: () => ({ status, subscribe: subscribeMock, unsubscribe: unsubscribeMock, repair: repairMock, health }),
}));

const device = (over: Record<string, unknown> = {}) => ({
  id: 28, provider: "FCM", registeredAt: null, lastAcceptedAt: null, lastReceiptAt: null,
  receiptsCapable: true, unacknowledged: 0, suspected: false, otherDevices: 0, ...over,
});
const healthOf = (over: Record<string, unknown> = {}) => ({
  state: "OK", reason: null, renewed: false, renewalPending: null, device: device(), checkedAt: "2026-10-30T09:00:00Z", ...over,
});

let platform: "ios" | "android" | "other" = "other";
vi.mock("../pwa-install/pwaInstallDetection", () => ({
  detectPlatform: () => platform,
}));

beforeEach(() => {
  status = "permission-default";
  platform = "other";
  subscribeMock.mockReset();
  unsubscribeMock.mockReset();
  repairMock.mockReset();
  health = null;
});

describe("PushPermissionCard — état réel de l'appareil (D-175)", () => {
  it("appareil reconnu : aucune action proposée, dernière confirmation et autres appareils affichés", () => {
    status = "subscribed";
    health = healthOf({ device: device({ lastReceiptAt: "2026-10-30T08:15:00Z", otherDevices: 1 }) });
    render(<PushPermissionCard />);

    expect(screen.queryByRole("button", { name: "Réparer" })).not.toBeInTheDocument();
    expect(screen.getByText(/Dernière notification confirmée par le navigateur/)).toBeInTheDocument();
    expect(screen.getByText("Notifications aussi actives sur 1 autre appareil.")).toBeInTheDocument();
  });

  it("renouvellement automatique effectué : l'utilisateur en est informé, rien à faire", () => {
    status = "subscribed";
    health = healthOf({ state: "RENEWED", renewed: true });
    render(<PushPermissionCard />);

    expect(screen.getByText("L'abonnement de cet appareil a été renouvelé automatiquement.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Réparer" })).not.toBeInTheDocument();
  });

  it("abonnement invalidé non réparé automatiquement : explication et bouton « Réparer » (sans désactiver/réactiver)", async () => {
    status = "subscribed";
    health = healthOf({ state: "RENEW_REQUIRED", reason: "INVALIDATED", renewalPending: "manual", device: null });
    const user = userEvent.setup();
    render(<PushPermissionCard />);

    expect(screen.getByText(/n'est plus valide/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Réparer" }));
    expect(repairMock).toHaveBeenCalledTimes(1);
    expect(unsubscribeMock).not.toHaveBeenCalled();
  });

  it("envois non confirmés (suspicion) : signalés avec leur nombre, réparation proposée", () => {
    status = "subscribed";
    health = healthOf({ device: device({ suspected: true, unacknowledged: 3 }) });
    render(<PushPermissionCard />);

    expect(screen.getByText("Les 3 dernières notifications envoyées à cet appareil n'ont pas été confirmées par le navigateur.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Réparer" })).toBeInTheDocument();
  });

  it("réparation en échec : message d'erreur et nouvelle tentative possible", () => {
    status = "subscribed";
    health = healthOf({ state: "RENEW_REQUIRED", reason: "INVALIDATED", renewalPending: "failed" });
    render(<PushPermissionCard />);

    expect(screen.getByText("La réparation de l'abonnement n'a pas abouti.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Réparer" })).toBeInTheDocument();
  });
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
