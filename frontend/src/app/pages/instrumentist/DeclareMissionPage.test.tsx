import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import dayjs from "dayjs";
import DeclareMissionPage from "./DeclareMissionPage";

const fetchSitesMock = vi.fn();
const fetchSurgeonsMock = vi.fn();
const declareMissionMock = vi.fn();
const toastSuccessMock = vi.fn();
const toastErrorMock = vi.fn();
const navigateMock = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchSites: (...args: unknown[]) => fetchSitesMock(...args),
  fetchSurgeons: (...args: unknown[]) => fetchSurgeonsMock(...args),
  declareMission: (...args: unknown[]) => declareMissionMock(...args),
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccessMock, error: toastErrorMock }),
}));

vi.mock("../../features/missions/sync/missionSyncBus", () => ({
  requestMissionSync: vi.fn(),
}));

vi.mock("react-router-dom", async () => {
  const actual = await vi.importActual<typeof import("react-router-dom")>("react-router-dom");
  return { ...actual, useNavigate: () => navigateMock };
});

const SITES = [
  { id: 1, name: "CHIREC - Hôpital Delta" },
  { id: 2, name: "Clinique Saint-Jean" },
];
const SURGEONS = {
  items: [
    { id: 8, email: "arnauddeltour@hotmail.com", firstname: "Arnaud", lastname: "Deltour" },
  ],
  total: 1,
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <DeclareMissionPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

async function selectSiteAndSurgeon(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole("combobox", { name: "Site *" }));
  await user.click(screen.getByText("CHIREC - Hôpital Delta"));
  await user.click(screen.getByRole("combobox", { name: "Chirurgien *" }));
  await user.click(screen.getByText("Arnaud Deltour"));
}

beforeEach(() => {
  fetchSitesMock.mockReset().mockResolvedValue(SITES);
  fetchSurgeonsMock.mockReset().mockResolvedValue(SURGEONS);
  declareMissionMock.mockReset();
  toastSuccessMock.mockClear();
  toastErrorMock.mockClear();
  navigateMock.mockClear();
});

describe("DeclareMissionPage — steppers + SelectField", () => {
  it("se rend comme un sheet modal (role=dialog) avec le bon titre", async () => {
    renderPage();
    const dialog = await screen.findByRole("dialog", { name: "Déclarer une mission" });
    expect(dialog).toBeInTheDocument();
  });

  it("la flèche retour du header navigue en arrière après l'anim de sortie", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ delay: null });
    renderPage();

    await user.click(await screen.findByRole("button", { name: "Retour" }));
    expect(navigateMock).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(300);
    expect(navigateMock).toHaveBeenCalledWith(-1);
    vi.useRealTimers();
  });

  it("le bouton de fermeture du header est une flèche retour ('Retour'), pas une croix ('Fermer'), et il n'y a pas de bouton Annuler séparé", async () => {
    renderPage();
    await screen.findByRole("dialog", { name: "Déclarer une mission" });
    expect(screen.getByRole("button", { name: "Retour" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Fermer" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Annuler" })).not.toBeInTheDocument();
  });

  it("affiche la date du jour et une durée par défaut de 1h00", async () => {
    renderPage();
    const today = dayjs().format("ddd D MMMM").replace(/^\w/, (c) => c.toUpperCase());
    expect(await screen.findByText(today)).toBeInTheDocument();
    expect(screen.getByText("1h00")).toBeInTheDocument();
  });

  it("le bouton + de la date est désactivé (jamais dans le futur)", async () => {
    renderPage();
    await waitFor(() => expect(screen.getByRole("combobox", { name: "Site *" })).toBeEnabled());
    expect(screen.getByRole("button", { name: "Jour suivant" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Jour précédent" })).toBeEnabled();
  });

  it("avancer l'heure de début (+15min) recalcule la durée en direct", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("1h00");
    await user.click(screen.getByRole("button", { name: "Avancer l'heure de début" }));
    expect(screen.getByText("0h45")).toBeInTheDocument();
  });

  it("coche 'Se termine le lendemain' ajoute (+1j) au libellé de fin et met à jour la durée", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("1h00");

    await user.click(screen.getByRole("button", { name: "Se termine le lendemain" }));

    expect(screen.getByText(/\(\+1j\)/)).toBeInTheDocument();
    // 24h de plus qu'avant (la fin reste au même minutage d'horloge mais bascule le lendemain)
    expect(screen.getByText("25h00")).toBeInTheDocument();
  });

  it("le bouton Déclarer est désactivé tant que site et chirurgien ne sont pas choisis", async () => {
    renderPage();
    await screen.findByRole("combobox", { name: "Site *" });
    expect(screen.getByRole("button", { name: "Déclarer la mission" })).toBeDisabled();
  });

  it("soumission envoie le payload attendu au backend (siteId, surgeonUserId, type, dates ISO, comment)", async () => {
    const user = userEvent.setup();
    declareMissionMock.mockResolvedValue({ id: 999, status: "DECLARED" });
    renderPage();

    await selectSiteAndSurgeon(user);
    await user.type(screen.getByLabelText("Commentaire (optionnel)"), "Urgence fin de journée");

    expect(screen.getByRole("button", { name: "Déclarer la mission" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Déclarer la mission" }));

    await waitFor(() => expect(declareMissionMock).toHaveBeenCalledTimes(1));
    const payload = declareMissionMock.mock.calls[0][0];
    expect(payload.siteId).toBe(1);
    expect(payload.surgeonUserId).toBe(8);
    expect(payload.type).toBe("BLOCK");
    expect(payload.comment).toBe("Urgence fin de journée");
    expect(payload.startAt).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
    expect(payload.endAt).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
    expect(dayjs(payload.endAt).isAfter(dayjs(payload.startAt))).toBe(true);

    await waitFor(() => expect(toastSuccessMock).toHaveBeenCalled());
    // La navigation est différée le temps de l'anim de sortie du sheet (voir closeAndNavigate).
    await waitFor(() =>
      expect(navigateMock).toHaveBeenCalledWith("/app/i/missions/999", { replace: true }),
    );
  });

  it("le succès joue l'anim de sortie avant de naviguer, pas de coupure brutale", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ delay: null });
    declareMissionMock.mockResolvedValue({ id: 999, status: "DECLARED" });
    renderPage();

    await selectSiteAndSurgeon(user);
    await user.click(screen.getByRole("button", { name: "Déclarer la mission" }));

    await vi.waitFor(() => expect(declareMissionMock).toHaveBeenCalledTimes(1));
    // Le sheet doit encore être là juste après le succès (l'anim de sortie n'a pas fini).
    expect(navigateMock).not.toHaveBeenCalled();
    expect(screen.getByRole("dialog", { name: "Déclarer une mission" })).toBeInTheDocument();

    await vi.advanceTimersByTimeAsync(300);
    expect(navigateMock).toHaveBeenCalledWith("/app/i/missions/999", { replace: true });
    vi.useRealTimers();
  });

  it("le toast de succès n'apparaît qu'une fois le sheet fermé, jamais pendant sa sortie (évite le chevauchement d'animations)", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ delay: null });
    declareMissionMock.mockResolvedValue({ id: 999, status: "DECLARED" });
    renderPage();

    await selectSiteAndSurgeon(user);
    await user.click(screen.getByRole("button", { name: "Déclarer la mission" }));

    await vi.waitFor(() => expect(declareMissionMock).toHaveBeenCalledTimes(1));
    // Le sheet a commencé sa sortie (mission acceptée) mais le toast ne doit PAS
    // encore être affiché : le sheet occupe presque tout l'écran (mobileMaxHeight),
    // un toast affiché maintenant tomberait visuellement dessus.
    expect(toastSuccessMock).not.toHaveBeenCalled();

    // Le toast et la navigation se déclenchent ensemble, seulement une fois le sheet
    // réellement retiré du DOM.
    await vi.advanceTimersByTimeAsync(300);
    expect(toastSuccessMock).toHaveBeenCalledWith("Mission déclarée. En cours de validation.");
    expect(navigateMock).toHaveBeenCalledWith("/app/i/missions/999", { replace: true });
    vi.useRealTimers();
  });

  it("affiche l'erreur backend via toast uniquement (pas de bannière inline) sans planter", async () => {
    const user = userEvent.setup();
    declareMissionMock.mockRejectedValue(new Error("Instrumentiste non autorisé sur site"));
    renderPage();

    await selectSiteAndSurgeon(user);
    await user.click(screen.getByRole("button", { name: "Déclarer la mission" }));

    await waitFor(() =>
      expect(toastErrorMock).toHaveBeenCalledWith("Instrumentiste non autorisé sur site"),
    );
    // docs/design/prototypes — declSave n'affiche jamais de bannière inline, seulement un toast.
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
    expect(screen.queryByText("Instrumentiste non autorisé sur site")).not.toBeInTheDocument();
  });
});
