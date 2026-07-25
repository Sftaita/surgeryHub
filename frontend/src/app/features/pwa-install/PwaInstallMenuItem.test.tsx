import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PwaInstallMenuItem } from "./PwaInstallMenuItem";

let mockState: any;
vi.mock("./usePwaInstallMenuState", () => ({
  usePwaInstallMenuState: () => mockState,
}));

describe("PwaInstallMenuItem — page profil", () => {
  it("affiche 'Application installée' sans bouton quand déjà installée", () => {
    mockState = { label: "Application installée", actionLabel: null, onAction: null, disabled: true, variant: "installed" };
    render(<PwaInstallMenuItem />);
    expect(screen.getByText("Application installée")).toBeInTheDocument();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("reste toujours disponible (§7 : le point d'entrée manuel n'est jamais masqué par la politique de report)", async () => {
    const onAction = vi.fn();
    mockState = { label: "Installer l'application", actionLabel: "Voir comment faire", onAction, disabled: false, variant: "actionable" };
    render(<PwaInstallMenuItem />);
    await userEvent.click(screen.getByRole("button", { name: "Voir comment faire" }));
    expect(onAction).toHaveBeenCalledTimes(1);
  });
});
