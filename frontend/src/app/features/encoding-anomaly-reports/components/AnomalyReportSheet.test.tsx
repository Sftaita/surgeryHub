import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AnomalyReportSheet } from "./AnomalyReportSheet";

const apiPostMock = vi.fn();
const toastSuccess = vi.fn();
const toastError = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    post: (...args: unknown[]) => apiPostMock(...args),
  },
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

const MISSION_ID = 55;

function renderSheet(onClose = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return { onClose, ...render(
    <QueryClientProvider client={client}>
      <AnomalyReportSheet open missionId={MISSION_ID} onClose={onClose} />
    </QueryClientProvider>,
  ) };
}

beforeEach(() => {
  apiPostMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("AnomalyReportSheet — sélection du type", () => {
  it("propose les 5 types en boutons radio (pas un menu déroulant)", () => {
    renderSheet();
    for (const label of ["Intervention manquante", "Intervention incorrecte", "Matériel incorrect", "Heures incorrectes", "Autre"]) {
      const radio = screen.getByRole("radio", { name: label });
      expect(radio).toBeInTheDocument();
    }
    expect(screen.getByRole("radio", { name: "Intervention manquante" })).toBeChecked();
  });
});

describe("AnomalyReportSheet — validation", () => {
  it("le bouton d'envoi est désactivé tant qu'aucun commentaire n'est saisi", () => {
    renderSheet();
    expect(screen.getByText("Envoyer le signalement")).toBeDisabled();
  });

  it("un commentaire non vide active l'envoi", async () => {
    const user = userEvent.setup();
    renderSheet();
    await user.type(screen.getByLabelText(/Description/), "Matériel manquant sur la fiche.");
    expect(screen.getByText("Envoyer le signalement")).toBeEnabled();
  });
});

describe("AnomalyReportSheet — soumission", () => {
  it("envoie le type sélectionné et le commentaire, ferme et notifie au succès", async () => {
    apiPostMock.mockResolvedValue({ data: { id: 1, missionId: MISSION_ID, type: "MATERIAL_INCORRECT", comment: "x", status: "OPEN" } });
    const user = userEvent.setup();
    const { onClose } = renderSheet();

    await user.click(screen.getByRole("radio", { name: "Matériel incorrect" }));
    await user.type(screen.getByLabelText(/Description/), "Il manque une plaque.");
    await user.click(screen.getByText("Envoyer le signalement"));

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(
      `/api/missions/${MISSION_ID}/encoding-anomaly-reports`,
      { type: "MATERIAL_INCORRECT", comment: "Il manque une plaque." },
    ));
    await waitFor(() => expect(onClose).toHaveBeenCalled());
    expect(toastSuccess).toHaveBeenCalled();
  });

  it("409 (signalement déjà en attente) → message explicite, jamais un message générique", async () => {
    apiPostMock.mockRejectedValue({ response: { status: 409 } });
    const user = userEvent.setup();
    renderSheet();

    await user.type(screen.getByLabelText(/Description/), "Un autre problème.");
    await user.click(screen.getByText("Envoyer le signalement"));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Un signalement est déjà en attente pour cette mission."));
  });

  it("erreur réseau générique → message d'erreur affiché dans la feuille", async () => {
    apiPostMock.mockRejectedValue(new Error("network error"));
    const user = userEvent.setup();
    renderSheet();

    await user.type(screen.getByLabelText(/Description/), "Un problème.");
    await user.click(screen.getByText("Envoyer le signalement"));

    expect(await screen.findByText("Impossible d'envoyer le signalement. Réessayez.")).toBeInTheDocument();
  });
});
