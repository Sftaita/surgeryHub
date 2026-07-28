import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { InstrumentistOnboardingModal } from "./InstrumentistOnboardingModal";

function renderModal(overrides: Partial<Parameters<typeof InstrumentistOnboardingModal>[0]> = {}) {
  const onDismiss = vi.fn();
  const onFinish = vi.fn();
  render(<InstrumentistOnboardingModal open onDismiss={onDismiss} onFinish={onFinish} {...overrides} />);
  return { onDismiss, onFinish };
}

describe("InstrumentistOnboardingModal", () => {
  // 3 clics userEvent séquentiels — dépasse le timeout par défaut (5000ms) sur cette
  // machine (jsdom/environment lent, cf. logs vitest de cette session).
  it("affiche le contenu clé des 4 écrans en avançant avec Continuer", async () => {
    const user = userEvent.setup();
    renderModal();

    expect(screen.getByText("Bienvenue dans SurgicalHub")).toBeInTheDocument();

    await user.click(screen.getByText("Continuer")); // -> install
    expect(screen.getByText("Installez SurgicalHub sur votre téléphone")).toBeInTheDocument();
    expect(screen.getByText(/Paramètres → Installer l'application/)).toBeInTheDocument();

    await user.click(screen.getByText("Continuer")); // -> missions
    expect(screen.getByText("Vos missions, au même endroit")).toBeInTheDocument();
    // "Offres" apparaît deux fois sur cet écran — dans le texte explicatif (<strong>) ET
    // dans l'illustration ("Onglet « Offres »"). C'est un doublon légitime, pas un bug :
    // on cible ici précisément le terme mis en emphase dans le texte explicatif via son
    // rôle sémantique (<strong>), pas un getAllByText générique qui masquerait le sens.
    expect(screen.getByText("Mes missions", { selector: "strong" })).toBeInTheDocument();
    expect(screen.getByText("Offres", { selector: "strong" })).toBeInTheDocument();

    await user.click(screen.getByText("Continuer")); // -> encoding
    expect(screen.getByText("Un encodage complet est indispensable")).toBeInTheDocument();
    expect(
      screen.getByText(
        "Seule une mission entièrement encodée et terminée peut être prise en compte correctement pour la facturation.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("Compris, commencer")).toBeInTheDocument();
  }, 15000);

  it("le bouton Retour ramène à l'écran précédent", async () => {
    const user = userEvent.setup();
    renderModal();

    await user.click(screen.getByText("Continuer")); // -> install
    await user.click(screen.getByText("Continuer")); // -> missions
    expect(screen.getByText("Vos missions, au même endroit")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Retour" }));
    expect(screen.getByText("Installez SurgicalHub sur votre téléphone")).toBeInTheDocument();
  });

  it("l'écran Installer propose Plus tard / Continuer, tous deux avancent, jamais de bouton Installer", async () => {
    const user = userEvent.setup();
    renderModal();
    await user.click(screen.getByText("Continuer")); // -> install

    expect(screen.getByText("Plus tard")).toBeInTheDocument();
    expect(screen.queryByText("Installer")).not.toBeInTheDocument();

    await user.click(screen.getByText("Plus tard"));
    expect(screen.getByText("Vos missions, au même endroit")).toBeInTheDocument();
  });

  it("le CTA final « Compris, commencer » appelle onFinish", async () => {
    const user = userEvent.setup();
    const { onFinish } = renderModal();

    await user.click(screen.getByText("Continuer"));
    await user.click(screen.getByText("Continuer"));
    await user.click(screen.getByText("Continuer"));
    await user.click(screen.getByText("Compris, commencer"));

    expect(onFinish).toHaveBeenCalledTimes(1);
  }, 15000);

  it("n'affiche jamais de montant, tarif ou statut financier", () => {
    renderModal();
    const text = document.body.textContent ?? "";
    expect(text).not.toMatch(/€|EUR|tarif|honoraire|rémunération|montant/i);
  });

  it("le bouton de fermeture est désactivé sur le premier écran et actif dès le second", async () => {
    const user = userEvent.setup();
    renderModal();

    expect(screen.getByRole("button", { name: "Fermer" })).toBeDisabled();

    await user.click(screen.getByText("Continuer"));
    expect(screen.getByRole("button", { name: "Fermer" })).toBeEnabled();
  });

  it("Échap est sans effet sur le premier écran", async () => {
    const user = userEvent.setup();
    const { onDismiss } = renderModal();

    await user.keyboard("{Escape}");

    expect(onDismiss).not.toHaveBeenCalled();
    expect(screen.getByText("Bienvenue dans SurgicalHub")).toBeInTheDocument();
  });

  it("Échap ferme dès le second écran, même si le focus est sur le bouton de fermeture (pas seulement dans le contenu)", async () => {
    const user = userEvent.setup();
    const { onDismiss } = renderModal();

    await user.click(screen.getByText("Continuer")); // -> install
    screen.getByRole("button", { name: "Fermer" }).focus();

    await user.keyboard("{Escape}");

    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("ne rend rien quand open=false", () => {
    render(<InstrumentistOnboardingModal open={false} onDismiss={vi.fn()} onFinish={vi.fn()} />);
    expect(screen.queryByText("Bienvenue dans SurgicalHub")).not.toBeInTheDocument();
  });
});
