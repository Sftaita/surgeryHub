import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import dayjs from "dayjs";
import SurgeonMissionRequestFormPage from "./SurgeonMissionRequestFormPage";

const fetchMeMock = vi.fn();
const createMyMissionRequestMock = vi.fn();
const toastSuccessMock = vi.fn();
const toastErrorMock = vi.fn();
const navigateMock = vi.fn();

vi.mock("../me/api/me.api", () => ({
  fetchMe: (...args: unknown[]) => fetchMeMock(...args),
}));

vi.mock("./api/surgeonMissionRequests.api", () => ({
  createMyMissionRequest: (...args: unknown[]) => createMyMissionRequestMock(...args),
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccessMock, error: toastErrorMock }),
}));

vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => navigateMock };
});

const ME = {
  id: 5,
  email: "arnauddeltour@hotmail.com",
  firstname: "Arnaud",
  lastname: "Deltour",
  phone: null,
  profilePictureUrl: null,
  role: "SURGEON",
  instrumentistProfile: null,
  sites: [
    { id: 1, name: "CHIREC - Hôpital Delta", timezone: "Europe/Brussels" },
    { id: 2, name: "Parc Léopold", timezone: "Europe/Brussels" },
  ],
  activeSiteId: null,
  instrumentistOnboardingCompleted: true,
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <SurgeonMissionRequestFormPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  fetchMeMock.mockReset().mockResolvedValue(ME);
  createMyMissionRequestMock.mockReset();
  toastSuccessMock.mockClear();
  toastErrorMock.mockClear();
  navigateMock.mockClear();
});

describe("SurgeonMissionRequestFormPage — ouverture", () => {
  it("se rend comme un sheet modal avec le bon titre", async () => {
    renderPage();
    expect(await screen.findByRole("dialog", { name: "Demander une mission" })).toBeInTheDocument();
  });

  it("préselectionne le premier site affilié une fois chargé", async () => {
    renderPage();
    await waitFor(() => expect(screen.getByRole("combobox", { name: "Site *" })).toHaveTextContent("CHIREC - Hôpital Delta"));
  });

  it("aucun site affilié → sélecteur vide et désactivé, jamais de site arbitraire", async () => {
    fetchMeMock.mockResolvedValue({ ...ME, sites: [] });
    renderPage();
    const select = await screen.findByRole("combobox", { name: "Site *" });
    await waitFor(() => expect(select).toBeDisabled());
  });

  it("la flèche retour ferme et navigue en arrière après l'anim de sortie", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ delay: null });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Retour" }));
    expect(navigateMock).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(300);
    expect(navigateMock).toHaveBeenCalledWith(-1);
    vi.useRealTimers();
  });
});

describe("SurgeonMissionRequestFormPage — date et heures", () => {
  it("date par défaut = demain, jamais aujourd'hui ou avant", async () => {
    renderPage();
    const tomorrow = dayjs().add(1, "day").format("ddd D MMMM").replace(/^\w/, (c) => c.toUpperCase());
    expect(await screen.findByText(tomorrow)).toBeInTheDocument();
  });

  it("le bouton Jour précédent reste actif jusqu'à aujourd'hui puis se désactive", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("combobox", { name: "Site *" });
    expect(screen.getByRole("button", { name: "Jour précédent" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Jour précédent" }));
    expect(screen.getByRole("button", { name: "Jour précédent" })).toBeDisabled();
  });

  it("avancer l'heure de début (+15min) recalcule la durée en direct", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("1h00");
    await user.click(screen.getByRole("button", { name: "Avancer l'heure de début" }));
    expect(screen.getByText("0h45")).toBeInTheDocument();
  });

  it("coche 'Se termine le lendemain' ajoute (+1j) au libellé de fin", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("1h00");
    await user.click(screen.getByRole("button", { name: "Se termine le lendemain" }));
    expect(screen.getByText(/\(\+1j\)/)).toBeInTheDocument();
  });
});

describe("SurgeonMissionRequestFormPage — soumission", () => {
  it("le bouton Envoyer est désactivé tant que le site n'est pas chargé/sélectionné", async () => {
    fetchMeMock.mockResolvedValue({ ...ME, sites: [] });
    renderPage();
    await screen.findByRole("combobox", { name: "Site *" });
    expect(screen.getByRole("button", { name: "Envoyer" })).toBeDisabled();
  });

  it("soumission envoie le payload attendu (siteId, type, dates ISO, comment)", async () => {
    const user = userEvent.setup();
    createMyMissionRequestMock.mockResolvedValue({ id: 42, status: "PENDING" });
    renderPage();

    await waitFor(() => expect(screen.getByRole("combobox", { name: "Site *" })).toHaveTextContent("CHIREC - Hôpital Delta"));
    await user.type(screen.getByLabelText("Commentaire (optionnel)"), "Bloc supplémentaire");
    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(createMyMissionRequestMock).toHaveBeenCalledTimes(1));
    const payload = createMyMissionRequestMock.mock.calls[0][0];
    expect(payload.siteId).toBe(1);
    expect(payload.type).toBe("BLOCK");
    expect(payload.comment).toBe("Bloc supplémentaire");
    expect(payload.startAt).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
    expect(dayjs(payload.endAt).isAfter(dayjs(payload.startAt))).toBe(true);
  });

  it("succès → toast puis navigation vers /app/s/requests", async () => {
    // Timers réels ici (contrairement aux tests DeclareMissionPage équivalents) : le
    // site est présélectionné via une requête async (fetchMe), et faker les timers
    // pendant qu'on attend cette résolution est une source connue de flakiness avec
    // waitFor — le délai de sortie du sheet (260ms) tient largement dans le timeout
    // par défaut de waitFor (1000ms).
    const user = userEvent.setup();
    createMyMissionRequestMock.mockResolvedValue({ id: 42, status: "PENDING" });
    renderPage();

    await waitFor(() => expect(screen.getByRole("combobox", { name: "Site *" })).toHaveTextContent("CHIREC - Hôpital Delta"));
    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(createMyMissionRequestMock).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(toastSuccessMock).toHaveBeenCalledWith("Demande envoyée. Le manager va l'examiner."));
    expect(navigateMock).toHaveBeenCalledWith("/app/s/requests", { replace: true });
  });

  it("erreur backend (site non affilié) → toast, jamais de bannière inline, jamais un crash", async () => {
    const user = userEvent.setup();
    createMyMissionRequestMock.mockRejectedValue({ response: { data: { error: { message: "Vous n'êtes pas affilié à ce site." } } } });
    renderPage();

    await waitFor(() => expect(screen.getByRole("combobox", { name: "Site *" })).toHaveTextContent("CHIREC - Hôpital Delta"));
    await user.click(screen.getByRole("button", { name: "Envoyer" }));

    await waitFor(() => expect(toastErrorMock).toHaveBeenCalledWith("Vous n'êtes pas affilié à ce site."));
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});
