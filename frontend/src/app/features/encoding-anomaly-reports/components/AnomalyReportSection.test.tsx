import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AnomalyReportSection } from "./AnomalyReportSection";
import type { EncodingAnomalyReport } from "../api/encodingAnomalyReports.types";

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();

vi.mock("../../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: (...args: unknown[]) => apiPostMock(...args),
  },
}));

vi.mock("../../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

const MISSION_ID = 88;

function report(overrides: Partial<EncodingAnomalyReport> = {}): EncodingAnomalyReport {
  return {
    id: 1,
    missionId: MISSION_ID,
    reporter: { id: 2, displayName: "Dr Test" },
    type: "MATERIAL_INCORRECT",
    comment: "Matériel manquant",
    status: "OPEN",
    createdAt: "2026-08-01T10:00:00Z",
    resolvedBy: null,
    resolvedAt: null,
    resolutionComment: null,
    ...overrides,
  };
}

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <AnomalyReportSection missionId={MISSION_ID} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
  apiPostMock.mockReset();
});

describe("AnomalyReportSection — aucun signalement", () => {
  it("affiche uniquement le CTA 'Signaler un problème'", async () => {
    apiGetMock.mockResolvedValue({ data: [] });
    renderSection();

    expect(await screen.findByText("Signaler un problème")).toBeInTheDocument();
    expect(screen.queryByText("Problème signalé — en attente de traitement")).not.toBeInTheDocument();
    expect(screen.queryByText("Problème traité")).not.toBeInTheDocument();
  });
});

describe("AnomalyReportSection — signalement OPEN", () => {
  it("affiche le bandeau 'en attente' et masque le CTA (évite un doublon accidentel)", async () => {
    apiGetMock.mockResolvedValue({ data: [report({ status: "OPEN" })] });
    renderSection();

    expect(await screen.findByText("Problème signalé — en attente de traitement")).toBeInTheDocument();
    expect(screen.getByText(/Matériel incorrect/)).toBeInTheDocument();
    expect(screen.queryByText("Signaler un problème")).not.toBeInTheDocument();
  });
});

describe("AnomalyReportSection — signalement RESOLVED", () => {
  it("affiche 'Problème traité' avec la réponse, et réaffiche le CTA (nouveau signalement possible)", async () => {
    apiGetMock.mockResolvedValue({
      data: [report({ status: "RESOLVED", resolutionComment: "Corrigé côté encodage.", resolvedBy: { id: 9, displayName: "Manager Test" }, resolvedAt: "2026-08-02T10:00:00Z" })],
    });
    renderSection();

    expect(await screen.findByText("Problème traité")).toBeInTheDocument();
    expect(screen.getByText("« Corrigé côté encodage. »")).toBeInTheDocument();
    expect(screen.getByText("Signaler un problème")).toBeInTheDocument();
  });
});

describe("AnomalyReportSection — ouverture du formulaire", () => {
  it("clique sur 'Signaler un problème' ouvre la feuille de signalement", async () => {
    apiGetMock.mockResolvedValue({ data: [] });
    const user = userEvent.setup();
    renderSection();

    await screen.findByText("Signaler un problème");
    await user.click(screen.getByText("Signaler un problème"));

    expect(await screen.findByRole("dialog")).toBeInTheDocument();
  });

  it("après un signalement réussi, la query est invalidée et le bandeau apparaît sans rechargement manuel", async () => {
    apiGetMock.mockResolvedValue({ data: [] });
    apiPostMock.mockResolvedValue({ data: report({ status: "OPEN" }) });
    const user = userEvent.setup();
    renderSection();

    await screen.findByText("Signaler un problème");
    await user.click(screen.getByText("Signaler un problème"));
    await screen.findByRole("dialog");

    await user.type(screen.getByLabelText(/Description/), "Un problème constaté.");

    apiGetMock.mockResolvedValue({ data: [report({ status: "OPEN" })] });
    await user.click(screen.getByText("Envoyer le signalement"));

    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(await screen.findByText("Problème signalé — en attente de traitement")).toBeInTheDocument();
  });
});
