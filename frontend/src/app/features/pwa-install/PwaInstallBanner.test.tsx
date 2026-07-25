import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PwaInstallBanner } from "./PwaInstallBanner";

const dismissBannerMock = vi.fn();
const openIosGuideMock = vi.fn();
const promptAndroidInstallMock = vi.fn().mockResolvedValue("accepted");

let mockValue: any = {
  status: "not-installable",
  showBanner: false,
  dismissBanner: dismissBannerMock,
  openIosGuide: openIosGuideMock,
  promptAndroidInstall: promptAndroidInstallMock,
};

vi.mock("./PwaInstallProvider", () => ({
  usePwaInstall: () => mockValue,
}));

beforeEach(() => {
  vi.clearAllMocks();
  mockValue = {
    status: "not-installable",
    showBanner: false,
    dismissBanner: dismissBannerMock,
    openIosGuide: openIosGuideMock,
    promptAndroidInstall: promptAndroidInstallMock,
  };
});

describe("PwaInstallBanner", () => {
  it("ne rend rien si showBanner est faux", () => {
    mockValue.showBanner = false;
    const { container } = render(<PwaInstallBanner />);
    expect(container).toBeEmptyDOMElement();
  });

  it("variante Android : bouton 'Installer' déclenche promptAndroidInstall", async () => {
    mockValue = { ...mockValue, status: "android-installable", showBanner: true };
    render(<PwaInstallBanner />);

    expect(screen.getByText("Installez SurgicalHub")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Installer" }));
    expect(promptAndroidInstallMock).toHaveBeenCalledTimes(1);
  });

  it("variante iOS : bouton 'Voir comment faire' ouvre le guide, jamais de bouton Installer", async () => {
    mockValue = { ...mockValue, status: "ios-installable", showBanner: true };
    render(<PwaInstallBanner />);

    expect(screen.queryByRole("button", { name: "Installer" })).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Voir comment faire" }));
    expect(openIosGuideMock).toHaveBeenCalledTimes(1);
  });

  it("« Plus tard » appelle dismissBanner sur les deux variantes", async () => {
    mockValue = { ...mockValue, status: "android-installable", showBanner: true };
    render(<PwaInstallBanner />);
    await userEvent.click(screen.getByRole("button", { name: "Plus tard" }));
    expect(dismissBannerMock).toHaveBeenCalledTimes(1);
  });
});
