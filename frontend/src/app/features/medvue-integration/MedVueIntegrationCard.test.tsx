import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MedVueIntegrationCard } from "./MedVueIntegrationCard";

vi.mock("./api/medvueLink.api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("./api/medvueLink.api")>();
  return {
    ...actual,
    fetchMedVueLink: vi.fn(),
    linkMedVueAccount: vi.fn(),
    revokeMedVueLink: vi.fn(),
  };
});

import { fetchMedVueLink, linkMedVueAccount, revokeMedVueLink } from "./api/medvueLink.api";

const unlinked = { configured: true, linked: false, linkedAt: null, linkedBy: null };
const linked = {
  configured: true,
  linked: true,
  linkedAt: "2026-10-10T08:00:00+02:00",
  linkedBy: { id: 7, displayName: "Alice Admin" },
};

function renderCard(props: Partial<React.ComponentProps<typeof MedVueIntegrationCard>> = {}) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MedVueIntegrationCard userId={42} {...props} />
    </QueryClientProvider>,
  );
}

function apiError(message: string) {
  return Object.assign(new Error("x"), { response: { data: { error: { code: "invalid_code", message } } } });
}

describe("MedVueIntegrationCard", () => {
  beforeEach(() => {
    vi.mocked(fetchMedVueLink).mockReset();
    vi.mocked(linkMedVueAccount).mockReset();
    vi.mocked(revokeMedVueLink).mockReset();
  });

  it("links the account with the typed code and shows the server state", async () => {
    vi.mocked(fetchMedVueLink).mockResolvedValue(unlinked);
    vi.mocked(linkMedVueAccount).mockResolvedValue(linked);
    renderCard();

    await userEvent.type(await screen.findByLabelText("Code MedVue"), "k7qm-2xpa-9drt");
    await userEvent.click(screen.getByRole("button", { name: "Associer" }));

    expect(linkMedVueAccount).toHaveBeenCalledWith(42, "k7qm-2xpa-9drt");
    expect(await screen.findByText(/Votre compte est associé à votre compte MedVue/)).toBeInTheDocument();
    expect(screen.getByText(/par Alice Admin/)).toBeInTheDocument();
  });

  it("shows the server message on refusal and clears the single-use code", async () => {
    vi.mocked(fetchMedVueLink).mockResolvedValue(unlinked);
    vi.mocked(linkMedVueAccount).mockRejectedValue(apiError("Code invalide ou expiré. Générez un nouveau code dans MedVue."));
    renderCard();

    const input = await screen.findByLabelText("Code MedVue");
    await userEvent.type(input, "ZZZZZZZZZZZZ");
    await userEvent.click(screen.getByRole("button", { name: "Associer" }));

    expect(await screen.findByText("Code invalide ou expiré. Générez un nouveau code dans MedVue.")).toBeInTheDocument();
    expect(input).toHaveValue("");
  });

  it("disables the submit button while the code is empty", async () => {
    vi.mocked(fetchMedVueLink).mockResolvedValue(unlinked);
    renderCard();

    expect(await screen.findByRole("button", { name: "Associer" })).toBeDisabled();
  });

  it("asks for confirmation before revoking", async () => {
    vi.mocked(fetchMedVueLink).mockResolvedValueOnce(linked).mockResolvedValue(unlinked);
    vi.mocked(revokeMedVueLink).mockResolvedValue(undefined);
    renderCard();

    await userEvent.click(await screen.findByRole("button", { name: "Dissocier" }));
    expect(revokeMedVueLink).not.toHaveBeenCalled();
    expect(screen.getByText(/MedVue ne pourra plus lire ces congés/)).toBeInTheDocument();

    const buttons = screen.getAllByRole("button", { name: "Dissocier" });
    await userEvent.click(buttons[buttons.length - 1]);

    expect(revokeMedVueLink).toHaveBeenCalledWith(42);
    await waitFor(() => expect(screen.getByLabelText("Code MedVue")).toBeInTheDocument());
  });

  it("hides the form when the server is not configured", async () => {
    vi.mocked(fetchMedVueLink).mockResolvedValue({ ...unlinked, configured: false });
    renderCard();

    expect(await screen.findByText(/n'est pas disponible sur ce serveur/)).toBeInTheDocument();
    expect(screen.queryByLabelText("Code MedVue")).not.toBeInTheDocument();
  });

  it("uses third-person wording on another user's record", async () => {
    vi.mocked(fetchMedVueLink).mockResolvedValue(unlinked);
    renderCard({ forOtherUser: true, bare: true });

    expect(await screen.findByText(/généré par cette personne dans MedVue/)).toBeInTheDocument();
  });
});
