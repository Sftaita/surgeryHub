import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, act } from "@testing-library/react";
import { ProfilePhotoPromptGate } from "./ProfilePhotoPromptGate";

let authState: any = { status: "authenticated", user: { id: 1, role: "INSTRUMENTIST", sites: [], profilePictureUrl: null } };
let reminderIsDue = true;
const dismissMock = vi.fn();
let justActivated = false;

vi.mock("../../auth/AuthContext", () => ({
  useAuth: () => ({ state: authState }),
}));

vi.mock("./useProfilePhotoReminder", () => ({
  useProfilePhotoReminder: () => ({ isDue: reminderIsDue, dismiss: dismissMock }),
}));

vi.mock("./justActivatedAccountFlag", () => ({
  wasAccountJustActivated: () => justActivated,
}));

vi.mock("./ProfilePhotoPromptModal", () => ({
  ProfilePhotoPromptModal: ({ open, onDismiss }: { open: boolean; onDismiss: () => void }) =>
    open ? (
      <div>
        <p>prompt-modal-open</p>
        <button onClick={onDismiss}>Plus tard</button>
      </div>
    ) : null,
}));

beforeEach(() => {
  vi.useFakeTimers();
  authState = { status: "authenticated", user: { id: 1, role: "INSTRUMENTIST", sites: [], profilePictureUrl: null } };
  reminderIsDue = true;
  justActivated = false;
  dismissMock.mockClear();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("ProfilePhotoPromptGate", () => {
  it("n'affiche rien immédiatement — attend un délai avant de proposer la modale", () => {
    render(<ProfilePhotoPromptGate />);

    expect(screen.queryByText("prompt-modal-open")).not.toBeInTheDocument();
  });

  it("affiche la modale après le délai quand une photo manque et que le rappel est dû", () => {
    render(<ProfilePhotoPromptGate />);

    act(() => vi.advanceTimersByTime(2500));

    expect(screen.getByText("prompt-modal-open")).toBeInTheDocument();
  });

  it("n'affiche jamais la modale si l'utilisateur a déjà une photo", () => {
    authState = {
      status: "authenticated",
      user: { id: 1, role: "INSTRUMENTIST", sites: [], profilePictureUrl: "https://cdn.test/photo.jpg" },
    };
    render(<ProfilePhotoPromptGate />);

    act(() => vi.advanceTimersByTime(2500));

    expect(screen.queryByText("prompt-modal-open")).not.toBeInTheDocument();
  });

  it("n'affiche pas la modale si le rappel n'est pas dû (cadence)", () => {
    reminderIsDue = false;
    render(<ProfilePhotoPromptGate />);

    act(() => vi.advanceTimersByTime(2500));

    expect(screen.queryByText("prompt-modal-open")).not.toBeInTheDocument();
  });

  it("n'affiche pas la modale juste après l'activation du compte, dans la même session", () => {
    justActivated = true;
    render(<ProfilePhotoPromptGate />);

    act(() => vi.advanceTimersByTime(2500));

    expect(screen.queryByText("prompt-modal-open")).not.toBeInTheDocument();
  });

  it("le clic sur Plus tard appelle dismiss() du hook de rappel", () => {
    render(<ProfilePhotoPromptGate />);
    act(() => vi.advanceTimersByTime(2500));

    act(() => screen.getByText("Plus tard").click());

    expect(dismissMock).toHaveBeenCalledTimes(1);
  });

  it("ne rend rien quand l'utilisateur n'est pas authentifié", () => {
    authState = { status: "anonymous" };
    const { container } = render(<ProfilePhotoPromptGate />);

    act(() => vi.advanceTimersByTime(2500));

    expect(container).toBeEmptyDOMElement();
  });
});
