import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import MissionEncodingPage from "./MissionEncodingPage";

const apiGetMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: vi.fn().mockResolvedValue({ data: {} }),
    patch: vi.fn().mockResolvedValue({ data: {} }),
    delete: vi.fn().mockResolvedValue({ data: undefined }),
  },
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

const CATALOG = { firms: [], items: [] };

function baseMission(overrides: Record<string, any> = {}) {
  return {
    id: 529,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startAt: "2026-07-05T08:00:00Z",
    endAt: "2026-07-05T12:00:00Z",
    status: "ASSIGNED",
    site: { id: 1, name: "CHU Brugmann — Site Victor Horta", address: "Rue de la Clinique 1" },
    surgeon: { id: 2, firstname: "Jérôme", lastname: "De Muylder", email: "jdm@surgicalhub.test", specialties: [] },
    instrumentist: { id: 3, firstname: "Jane", lastname: "Doe", email: "jane@surgicalhub.test" },
    allowedActions: ["encoding", "submit", "edit_hours"],
    service: { hours: null },
    ...overrides,
  };
}

function mockRoutes(mission: any, encoding: any) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}/encoding`) {
      return Promise.resolve({ data: encoding });
    }
    if (url === `/api/missions/${mission.id}`) {
      return Promise.resolve({ data: mission });
    }
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

function baseEncoding(overrides: Record<string, any> = {}) {
  return {
    mission: { id: 529, type: "BLOCK", status: "ASSIGNED", allowedActions: ["encoding", "submit"] },
    interventions: [],
    entries: [],
    catalog: CATALOG,
    ...overrides,
  };
}

function renderPage(missionId = 529) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[`/app/i/missions/${missionId}/encoding`]}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/app/i/missions/:id/encoding" element={<MissionEncodingPage />} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
});

describe("MissionEncodingPage — header et carte mission (MissionReadOnlyCard)", () => {
  it("affiche le nom du site et son adresse dans la carte mission", async () => {
    const mission = baseMission();
    mockRoutes(mission, baseEncoding());
    renderPage();

    expect(await screen.findByText("CHU Brugmann — Site Victor Horta")).toBeInTheDocument();
    expect(screen.getByText(/Rue de la Clinique 1/)).toBeInTheDocument();
  });

  it("affiche la spécialité du chirurgien quand elle est disponible", async () => {
    const mission = baseMission({ surgeon: { id: 2, firstname: "Jérôme", lastname: "De Muylder", email: "x@x.test", specialties: ["GENOU"] } });
    mockRoutes(mission, baseEncoding());
    renderPage();

    expect(await screen.findByText("Dr. Jérôme De Muylder")).toBeInTheDocument();
    expect(screen.getByText("Genou")).toBeInTheDocument();
  });

  it("n'affiche aucun suffixe quand le chirurgien n'a pas de spécialité (jamais 'undefined')", async () => {
    const mission = baseMission({ surgeon: { id: 2, firstname: "Jérôme", lastname: "De Muylder", email: "x@x.test", specialties: [] } });
    mockRoutes(mission, baseEncoding());
    renderPage();

    expect(await screen.findByText("Dr. Jérôme De Muylder")).toBeInTheDocument();
    expect(screen.queryByText(/undefined/)).not.toBeInTheDocument();
  });

  it("n'affiche aucun suffixe quand le champ specialties est absent", async () => {
    const surgeon: any = { id: 2, firstname: "Jérôme", lastname: "De Muylder", email: "x@x.test" };
    delete surgeon.specialties;
    const mission = baseMission({ surgeon });
    mockRoutes(mission, baseEncoding());
    renderPage();

    expect(await screen.findByText("Dr. Jérôme De Muylder")).toBeInTheDocument();
    expect(screen.queryByText(/undefined/)).not.toBeInTheDocument();
  });

  it("affiche la photo du chirurgien quand profilePicturePath est renseigné", async () => {
    const mission = baseMission({
      surgeon: { id: 2, firstname: "Jérôme", lastname: "De Muylder", email: "x@x.test", specialties: [], profilePicturePath: "/uploads/profile-pictures/surgeon-2.jpg" },
    });
    mockRoutes(mission, baseEncoding());
    renderPage();

    const img = await screen.findByAltText("Dr. Jérôme De Muylder");
    expect(img.tagName).toBe("IMG");
    expect((img as HTMLImageElement).src).toContain("/uploads/profile-pictures/surgeon-2.jpg");
  });

  it("affiche les initiales quand le chirurgien n'a pas de photo", async () => {
    const mission = baseMission({
      surgeon: { id: 2, firstname: "Jérôme", lastname: "De Muylder", email: "x@x.test", specialties: [], profilePicturePath: null },
    });
    mockRoutes(mission, baseEncoding());
    renderPage();

    await screen.findByText("Dr. Jérôme De Muylder");
    expect(screen.queryByAltText("Dr. Jérôme De Muylder")).not.toBeInTheDocument();
    expect(screen.getByText("DM")).toBeInTheDocument();
  });
});

describe("MissionEncodingPage — chargement et erreurs", () => {
  it("affiche un indicateur de chargement pendant la requête", async () => {
    apiGetMock.mockImplementation(() => new Promise(() => {})); // never resolves
    renderPage();

    expect(await screen.findByRole("progressbar")).toBeInTheDocument();
  });

  it("affiche un message d'erreur en cas d'échec réseau", async () => {
    apiGetMock.mockRejectedValue(new Error("Network Error"));
    renderPage();

    expect(await screen.findByText("Network Error")).toBeInTheDocument();
  });
});

describe("MissionEncodingPage — brouillon et interventions", () => {
  it("mission vide : 0 intervention · 0 matériel, sans données simulées", async () => {
    const mission = baseMission();
    mockRoutes(mission, baseEncoding({ interventions: [] }));
    renderPage();

    expect(await screen.findByText("0 intervention · 0 matériel")).toBeInTheDocument();
    expect(screen.getByText("Aucune intervention encodée")).toBeInTheDocument();
  });

  it("plusieurs interventions et matériels : compteurs réels dérivés des données", async () => {
    const mission = baseMission();
    const materialLineA1 = { id: 1, missionInterventionId: 1, interventionDraftId: null, item: { id: 1, label: "Vis", referenceCode: "V1", unit: "u", isImplant: false, firm: { id: 1, name: "Arthrex" } }, quantity: "1.00", comment: "" };
    const materialLineA2 = { id: 2, missionInterventionId: 1, interventionDraftId: null, item: { id: 2, label: "Fil", referenceCode: "F1", unit: "u", isImplant: false, firm: { id: 1, name: "Arthrex" } }, quantity: "2.00", comment: "" };
    mockRoutes(
      mission,
      baseEncoding({
        // `interventions` (legacy) reste transmis pour l'enrichissement Lot 6 — `entries`
        // (EPIC Revue instrumentiste, Lot 3, commit 8) est la source de vérité du rendu
        // et des compteurs sur cette page.
        interventions: [
          { id: 1, code: "A", label: "Intervention A", orderIndex: 0, materialLines: [materialLineA1, materialLineA2], materialItemRequests: [] },
          { id: 2, code: "B", label: "Intervention B", orderIndex: 1, materialLines: [], materialItemRequests: [] },
        ],
        entries: [
          { kind: "INTERVENTION", id: 1, requestId: null, orderIndex: 0, label: "Intervention A", interventionType: null, firm: null, requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false, materialLines: [materialLineA1, materialLineA2], materialItemRequests: [] },
          { kind: "INTERVENTION", id: 2, requestId: null, orderIndex: 1, label: "Intervention B", interventionType: null, firm: null, requestedFirmNameSnapshot: null, status: "CATALOGUED", readOnly: false, materialLines: [], materialItemRequests: [] },
        ],
      }),
    );
    renderPage();

    expect(await screen.findByText("2 interventions · 2 matériels")).toBeInTheDocument();
    expect(screen.getByText("Intervention A")).toBeInTheDocument();
    expect(screen.getByText("Intervention B")).toBeInTheDocument();
  });

  it("affiche les heures prestées quand elles sont renseignées", async () => {
    const mission = baseMission({ service: { hours: "6" } });
    mockRoutes(mission, baseEncoding());
    renderPage();

    expect(await screen.findByText("6 h")).toBeInTheDocument();
  });

  it("affiche 'Non renseigné' pour les heures quand absentes", async () => {
    const mission = baseMission({ service: { hours: null } });
    mockRoutes(mission, baseEncoding());
    renderPage();

    expect(await screen.findByText("Non renseigné")).toBeInTheDocument();
  });

  it("le bouton Terminer l'encodage est présent quand l'action submit est autorisée", async () => {
    const mission = baseMission({ allowedActions: ["encoding", "submit"] });
    mockRoutes(mission, baseEncoding());
    renderPage();

    expect(await screen.findByRole("button", { name: "Terminer l'encodage" })).toBeInTheDocument();
  });

  it("le bouton Terminer l'encodage est absent quand submit n'est pas autorisé", async () => {
    const mission = baseMission({ allowedActions: ["encoding"] });
    mockRoutes(mission, baseEncoding());
    renderPage();

    await screen.findByText("0 intervention · 0 matériel");
    expect(screen.queryByRole("button", { name: "Terminer l'encodage" })).not.toBeInTheDocument();
  });

  it("le bouton 'Démarrer l'encodage' n'est jamais affiché (revue UX lot 1), même si le backend l'autorise encore", async () => {
    const mission = baseMission({ allowedActions: ["encoding", "submit", "start_encoding"] });
    mockRoutes(mission, baseEncoding());
    renderPage();

    await screen.findByText("0 intervention · 0 matériel");
    expect(screen.queryByRole("button", { name: /démarrer l'encodage/i })).not.toBeInTheDocument();
  });
});
