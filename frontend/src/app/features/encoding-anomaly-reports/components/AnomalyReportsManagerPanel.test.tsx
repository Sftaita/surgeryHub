import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AnomalyReportsManagerPanel } from "./AnomalyReportsManagerPanel";
import type { EncodingAnomalyReport } from "../api/encodingAnomalyReports.types";

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();
const toastSuccess = vi.fn();
const toastError = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
  },
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: toastSuccess, error: toastError, warning: vi.fn() }),
}));

const MISSION_ID = 321;

function report(overrides: Partial<EncodingAnomalyReport> = {}): EncodingAnomalyReport {
  return {
    id: 7,
    missionId: MISSION_ID,
    reporter: { id: 2, displayName: "Dr Martin" },
    type: "HOURS_INCORRECT",
    comment: "Les heures ne correspondent pas.",
    status: "OPEN",
    createdAt: "2026-08-01T09:00:00Z",
    resolvedBy: null,
    resolvedAt: null,
    resolutionComment: null,
    ...overrides,
  };
}

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AnomalyReportsManagerPanel missionId={MISSION_ID} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
  toastSuccess.mockReset();
  toastError.mockReset();
});

describe("AnomalyReportsManagerPanel — aucun signalement", () => {
  it("ne rend rien (jamais de section vide pour la majorité des missions)", async () => {
    apiGetMock.mockResolvedValue({ data: [] });
    const { container } = renderPanel();

    await waitFor(() => expect(apiGetMock).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });
});

describe("AnomalyReportsManagerPanel — signalement OPEN", () => {
  it("affiche le type, le commentaire, le chirurgien, et le bouton de résolution", async () => {
    apiGetMock.mockResolvedValue({ data: [report()] });
    renderPanel();

    expect(await screen.findByText("Heures incorrectes")).toBeInTheDocument();
    expect(screen.getByText("Les heures ne correspondent pas.")).toBeInTheDocument();
    expect(screen.getByText(/Dr Martin/)).toBeInTheDocument();
    expect(screen.getByText("En attente")).toBeInTheDocument();
    expect(screen.getByText("Marquer comme traité")).toBeInTheDocument();
  });

  it("résoudre nécessite un commentaire, l'envoie et invalide la liste au succès", async () => {
    apiGetMock.mockResolvedValue({ data: [report()] });
    apiPostMock.mockResolvedValue({ data: report({ status: "RESOLVED", resolutionComment: "Corrigé.", resolvedBy: { id: 9, displayName: "Manager Test" } }) });
    const user = userEvent.setup();
    renderPanel();

    await screen.findByText("Marquer comme traité");
    await user.click(screen.getByText("Marquer comme traité"));

    const dialog = await screen.findByRole("dialog");
    const confirmButton = screen.getByText("Confirmer");
    expect(confirmButton).toBeDisabled();

    await user.type(screen.getByLabelText(/Commentaire de résolution/), "Corrigé.");
    expect(confirmButton).toBeEnabled();

    apiGetMock.mockResolvedValue({ data: [report({ status: "RESOLVED", resolutionComment: "Corrigé." })] });
    await user.click(confirmButton);

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledWith(
      "/api/encoding-anomaly-reports/7/resolve",
      { resolutionComment: "Corrigé." },
    ));
    await waitFor(() => expect(dialog).not.toBeInTheDocument());
    expect(toastSuccess).toHaveBeenCalled();
  });

  it("409 (déjà résolu par ailleurs) → toast d'erreur explicite, ferme le dialogue et rafraîchit", async () => {
    apiGetMock.mockResolvedValue({ data: [report()] });
    apiPostMock.mockRejectedValue({ response: { status: 409 } });
    const user = userEvent.setup();
    renderPanel();

    await screen.findByText("Marquer comme traité");
    await user.click(screen.getByText("Marquer comme traité"));
    await screen.findByRole("dialog");

    await user.type(screen.getByLabelText(/Commentaire de résolution/), "Corrigé.");
    await user.click(screen.getByText("Confirmer"));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("Ce signalement a déjà été traité."));
  });
});

describe("AnomalyReportsManagerPanel — signalement RESOLVED", () => {
  it("affiche 'Traité', la réponse, et jamais le bouton de résolution", async () => {
    apiGetMock.mockResolvedValue({
      data: [report({ status: "RESOLVED", resolutionComment: "Déjà corrigé.", resolvedBy: { id: 9, displayName: "Manager Test" }, resolvedAt: "2026-08-02T09:00:00Z" })],
    });
    renderPanel();

    expect(await screen.findByText("Traité")).toBeInTheDocument();
    expect(screen.getByText(/Déjà corrigé\./)).toBeInTheDocument();
    expect(screen.queryByText("Marquer comme traité")).not.toBeInTheDocument();
  });
});
