import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ToastProvider } from "./ToastProvider";
import { useToast } from "./useToast";

function Trigger() {
  const toast = useToast();
  return (
    <>
      <button onClick={() => toast.success("Enregistré")}>fire-success</button>
      <button onClick={() => toast.error("Erreur serveur")}>fire-error</button>
    </>
  );
}

function renderWithProvider() {
  return render(
    <ToastProvider>
      <Trigger />
    </ToastProvider>,
  );
}

describe("ToastProvider", () => {
  it("affiche le message dans une pilule unique, sans variation de couleur par sévérité", async () => {
    const user = userEvent.setup();
    renderWithProvider();

    await user.click(screen.getByText("fire-success"));
    const successToast = await screen.findByText("Enregistré");
    const successColor = (successToast.parentElement as HTMLElement).style.background;

    await user.click(screen.getByText("fire-error"));
    const errorToast = await screen.findByText("Erreur serveur");
    const errorColor = (errorToast.parentElement as HTMLElement)?.style.background || (errorToast as HTMLElement).style.background;

    // Le toast lui-même porte les styles (c'est le même élément qui contient le texte).
    expect(successToast).toHaveStyle({ background: "#16202B", color: "rgb(255, 255, 255)" });
    expect(errorToast).toHaveStyle({ background: "#16202B", color: "rgb(255, 255, 255)" });
  });

  it("se ferme automatiquement après le délai configuré", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ delay: null, advanceTimers: vi.advanceTimersByTime });
    renderWithProvider();

    await user.click(screen.getByText("fire-success"));
    expect(await screen.findByText("Enregistré")).toBeInTheDocument();

    vi.advanceTimersByTime(2900);
    await waitFor(() => expect(screen.queryByText("Enregistré")).not.toBeInTheDocument());

    vi.useRealTimers();
  });

  it("expose un role=status pour l'accessibilité", async () => {
    const user = userEvent.setup();
    renderWithProvider();

    await user.click(screen.getByText("fire-success"));
    expect(await screen.findByRole("status")).toHaveTextContent("Enregistré");
  });
});
