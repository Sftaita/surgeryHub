import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { IosInstallGuideModal } from "./IosInstallGuideModal";

function setReducedMotion(reduced: boolean) {
  Object.defineProperty(window, "matchMedia", {
    value: (q: string) => ({
      matches: reduced && q === "(prefers-reduced-motion: reduce)",
      addEventListener: () => {},
      removeEventListener: () => {},
    }),
    configurable: true,
  });
}

beforeEach(() => {
  setReducedMotion(false);
});

afterEach(() => {
  vi.useRealTimers();
});

describe("IosInstallGuideModal — contenu", () => {
  it("ne rend rien si open est faux", () => {
    const { container } = render(<IosInstallGuideModal open={false} onClose={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("affiche le titre, le sous-titre et les 3 étapes textuelles fixes", () => {
    render(<IosInstallGuideModal open onClose={vi.fn()} />);
    expect(screen.getByText("Installez SurgicalHub")).toBeInTheDocument();
    expect(screen.getByText(/Accédez plus rapidement à votre planning/)).toBeInTheDocument();
    expect(screen.getByText("Touchez Partager dans Safari")).toBeInTheDocument();
    expect(screen.getByText("Choisissez « Sur l'écran d'accueil »")).toBeInTheDocument();
    expect(screen.getByText("Appuyez sur « Ajouter »")).toBeInTheDocument();
  });

  it("n'affiche jamais de bouton 'Installer' (iOS ne peut pas déclencher l'installation)", () => {
    render(<IosInstallGuideModal open onClose={vi.fn()} />);
    expect(screen.queryByRole("button", { name: /^Installer$/ })).not.toBeInTheDocument();
  });

  it("l'animation est présente (role=img) et les étapes textuelles restent lisibles indépendamment", () => {
    render(<IosInstallGuideModal open onClose={vi.fn()} />);
    expect(screen.getByRole("img", { hidden: true })).toBeInTheDocument();
    // Les 3 étapes sont un <ol> statique, jamais conditionné par l'état de l'animation.
    expect(screen.getAllByRole("listitem")).toHaveLength(3);
  });
});

describe("IosInstallGuideModal — actions", () => {
  it("« Plus tard » ferme avec l'outcome 'later'", async () => {
    const onClose = vi.fn();
    render(<IosInstallGuideModal open onClose={onClose} />);
    await userEvent.click(screen.getByRole("button", { name: "Plus tard" }));
    expect(onClose).toHaveBeenCalledWith("later");
  });

  it("« J'ai compris » ferme avec l'outcome 'understood'", async () => {
    const onClose = vi.fn();
    render(<IosInstallGuideModal open onClose={onClose} />);
    await userEvent.click(screen.getByRole("button", { name: "J'ai compris" }));
    expect(onClose).toHaveBeenCalledWith("understood");
  });

  it("clic sur le fond ferme avec l'outcome 'later' (jamais un faux 'installé')", async () => {
    const onClose = vi.fn();
    render(<IosInstallGuideModal open onClose={onClose} />);
    await userEvent.click(screen.getByTestId("pwa-ios-guide-backdrop"));
    expect(onClose).toHaveBeenCalledWith("later");
  });
});

describe("IosInstallGuideModal — accessibilité", () => {
  it("expose role=dialog, aria-modal, aria-labelledby et aria-describedby", () => {
    render(<IosInstallGuideModal open onClose={vi.fn()} />);
    const dialog = screen.getByRole("dialog");
    expect(dialog).toHaveAttribute("aria-modal", "true");
    expect(dialog).toHaveAttribute("aria-labelledby");
    expect(dialog).toHaveAttribute("aria-describedby");
  });

  it("place le focus sur le premier élément focusable à l'ouverture", async () => {
    render(<IosInstallGuideModal open onClose={vi.fn()} />);
    await vi.waitFor(() => expect(document.activeElement).toHaveAttribute("type", "button"));
  });

  it("Échap ferme le modal avec l'outcome 'later'", async () => {
    const onClose = vi.fn();
    render(<IosInstallGuideModal open onClose={onClose} />);
    await userEvent.keyboard("{Escape}");
    expect(onClose).toHaveBeenCalledWith("later");
  });

  it("Tab boucle à l'intérieur du modal (focus trap)", async () => {
    render(<IosInstallGuideModal open onClose={vi.fn()} />);
    const dialog = screen.getByRole("dialog");
    const buttons = dialog.querySelectorAll("button");
    const last = buttons[buttons.length - 1] as HTMLElement;
    // Laisse le focus automatique à l'ouverture (RAF) se stabiliser avant de le
    // redéplacer manuellement, sinon il écrase notre `last.focus()` juste après.
    await vi.waitFor(() => expect(document.activeElement).toBe(buttons[0]));
    last.focus();
    await userEvent.tab();
    expect(document.activeElement).toBe(buttons[0]);
  });
});

describe("IosInstallGuideModal — prefers-reduced-motion", () => {
  it("respecte prefers-reduced-motion : reste sur la première frame, pas de cycle automatique", async () => {
    setReducedMotion(true);
    vi.useFakeTimers();
    render(<IosInstallGuideModal open onClose={vi.fn()} />);

    // Sans mouvement réduit, l'étape changerait après 2.4s — ici, rien ne doit bouger.
    await vi.advanceTimersByTimeAsync(10_000);

    // Les instructions textuelles restent présentes et complètes, seule l'animation est figée.
    expect(screen.getByText("Touchez Partager dans Safari")).toBeInTheDocument();
    expect(screen.getByText("Choisissez « Sur l'écran d'accueil »")).toBeInTheDocument();
    expect(screen.getByText("Appuyez sur « Ajouter »")).toBeInTheDocument();
  });
});
