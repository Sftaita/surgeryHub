import { describe, it, expect, vi, beforeEach } from "vitest";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PushInvitationBanner } from "./PushInvitationBanner";

let status: "unsupported" | "permission-default" | "permission-denied" | "subscribing" | "subscribed" | "error" = "permission-default";
const subscribeMock = vi.fn();
vi.mock("./usePushNotifications", () => ({
  usePushNotifications: () => ({ status, subscribe: subscribeMock }),
}));
vi.mock("../pwa-install/pwaInstallDetection", () => ({ detectPlatform: () => "other" }));

beforeEach(() => {
  status = "permission-default";
  subscribeMock.mockReset();
  window.localStorage.clear();
});

describe("PushInvitationBanner — invitation volontaire et non bloquante (D-143, D-161)", () => {
  it("chirurgien : explique l'intérêt (salles disponibles) et ne demande la permission qu'au clic", async () => {
    const user = userEvent.setup();
    render(<PushInvitationBanner scope="surgeon" />);

    expect(screen.getByText(/salle opératoire se libère/)).toBeInTheDocument();
    expect(subscribeMock).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "Activer les notifications" }));
    expect(subscribeMock).toHaveBeenCalledTimes(1);
  });

  it("« Plus tard » masque l'invitation et elle ne réapparaît pas au prochain affichage", async () => {
    const user = userEvent.setup();
    const { unmount } = render(<PushInvitationBanner scope="surgeon" />);
    await user.click(screen.getByRole("button", { name: "Plus tard" }));
    expect(screen.queryByTestId("push-invitation")).not.toBeInTheDocument();
    unmount();

    render(<PushInvitationBanner scope="surgeon" />);
    expect(screen.queryByTestId("push-invitation")).not.toBeInTheDocument();
  });

  it.each(["permission-denied", "subscribed", "unsupported"] as const)("rien n'est proposé quand le push est %s", (s) => {
    status = s;
    render(<PushInvitationBanner scope="surgeon" />);
    expect(screen.queryByTestId("push-invitation")).not.toBeInTheDocument();
  });

  it("refus du navigateur côté chirurgien : jamais la promesse d'un e-mail de secours", async () => {
    const user = userEvent.setup();
    const { rerender } = render(<PushInvitationBanner scope="surgeon" />);
    await user.click(screen.getByRole("button", { name: "Activer les notifications" }));
    status = "permission-denied";
    act(() => rerender(<PushInvitationBanner scope="surgeon" />));

    const denied = screen.getByTestId("push-invitation-denied");
    expect(denied).toHaveTextContent("centre de notifications");
    expect(denied).not.toHaveTextContent(/e-mail/);
  });

  it("D-175 — désactivées volontairement sur cet appareil : l'invitation n'est plus jamais proposée", () => {
    window.localStorage.setItem("surgicalhub.push.optedOut", "1");
    render(<PushInvitationBanner scope="instrumentist" />);
    expect(screen.queryByTestId("push-invitation")).not.toBeInTheDocument();
  });

  it("instrumentiste : texte inchangé (rappels de mission)", () => {
    render(<PushInvitationBanner scope="instrumentist" />);
    expect(screen.getByText(/rappels de mission/)).toBeInTheDocument();
  });
});
