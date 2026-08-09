import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import SurgeonEncodingPage from "./SurgeonEncodingPage";

const apiGetMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: vi.fn().mockResolvedValue({ data: {} }),
  },
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

const MISSION_ID = 741;

function baseMission(overrides: Record<string, any> = {}) {
  return {
    id: MISSION_ID,
    type: "BLOCK",
    status: "VALIDATED",
    allowedActions: ["view_encoding"],
    ...overrides,
  };
}

function baseExecution(overrides: Record<string, any> = {}) {
  return {
    missionId: MISSION_ID,
    hasExecutionRecord: false,
    actualStartAt: null,
    actualEndAt: null,
    actualDurationMinutes: null,
    hoursSource: null,
    effectiveDurationMinutes: 180,
    effectiveDurationSource: "PLANNED",
    disputes: [],
    ...overrides,
  };
}

function baseEncoding(overrides: Record<string, any> = {}) {
  return {
    mission: { id: MISSION_ID, type: "BLOCK", status: "VALIDATED", allowedActions: ["view_encoding"] },
    interventions: [],
    entries: [],
    interventionTypeRequests: [],
    coherenceSummary: { hasNoInterventions: true, hasInterventionsWithNoMaterial: false, hasUnusedSuggestions: false, hasMaterialFromOtherFirm: false, hasMissingPrimaryFirm: false },
    encodingComments: [],
    ...overrides,
  };
}

function interventionEntry(overrides: Record<string, any> = {}) {
  return {
    kind: "INTERVENTION",
    id: 1,
    requestId: null,
    orderIndex: 0,
    label: "PTG genou droit",
    interventionType: { id: 10, code: "PTG", label: "PTG genou droit" },
    firm: { id: 20, name: "Zimmer" },
    requestedFirmNameSnapshot: null,
    status: "CATALOGUED",
    readOnly: false,
    materialLines: [
      {
        id: 100,
        missionInterventionId: 1,
        interventionDraftId: null,
        item: { id: 200, label: "Plateau tibial", referenceCode: "REF-001", unit: "pièce", isImplant: true, firm: { id: 20, name: "Zimmer" } },
        quantity: "2.00",
        comment: "Taille M",
      },
    ],
    materialItemRequests: [],
    representativePresent: true,
    ...overrides,
  };
}

function mockRoutes(mission: any, encoding: any, execution: any = baseExecution()) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}/encoding`) return Promise.resolve({ data: encoding });
    if (url === `/api/missions/${mission.id}/execution`) return Promise.resolve({ data: execution });
    if (url === `/api/missions/${mission.id}`) return Promise.resolve({ data: mission });
    if (url === `/api/missions/${mission.id}/encoding-anomaly-reports`) return Promise.resolve({ data: [] });
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

function renderPage(missionId = MISSION_ID) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter initialEntries={[`/app/s/missions/${missionId}/encoding`]}>
      <QueryClientProvider client={client}>
        <Routes>
          <Route path="/app/s/missions/:id/encoding" element={<SurgeonEncodingPage />} />
          <Route path="/app/s/missions/:id" element={<div>Mission detail screen</div>} />
        </Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
});

describe("SurgeonEncodingPage — chargement", () => {
  it("affiche un indicateur de chargement pendant la requête", async () => {
    let resolveMission: (v: any) => void = () => {};
    apiGetMock.mockImplementation((url: string) => {
      if (url === `/api/missions/${MISSION_ID}`) return new Promise((r) => { resolveMission = r; });
      if (url === `/api/missions/${MISSION_ID}/encoding-anomaly-reports`) return Promise.resolve({ data: [] });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    renderPage();

    expect(screen.getByRole("progressbar")).toBeInTheDocument();
    resolveMission({ data: baseMission() });
    await waitFor(() => expect(screen.queryByRole("progressbar")).not.toBeInTheDocument());
  });
});

describe("SurgeonEncodingPage — interventions et matériel (lecture seule)", () => {
  it("affiche l'intervention, la firme, le matériel (libellé, référence, quantité+unité, commentaire)", async () => {
    mockRoutes(baseMission(), baseEncoding({ entries: [interventionEntry()] }));
    renderPage();

    expect(await screen.findByText("PTG genou droit")).toBeInTheDocument();
    expect(screen.getByText("Zimmer")).toBeInTheDocument();
    expect(screen.getByText("Plateau tibial")).toBeInTheDocument();
    expect(screen.getByText(/Réf\. REF-001/)).toBeInTheDocument();
    expect(screen.getByText("2 pièce")).toBeInTheDocument();
    expect(screen.getByText("Taille M")).toBeInTheDocument();
    expect(screen.getByText("Délégué présent")).toBeInTheDocument();
  });

  it("affiche une entrée DRAFT avec le badge 'Demande en cours', jamais confondue avec une intervention réelle", async () => {
    mockRoutes(
      baseMission(),
      baseEncoding({
        entries: [
          {
            kind: "DRAFT",
            id: 5,
            requestId: 9,
            orderIndex: 0,
            label: "Nouveau type demandé",
            interventionType: null,
            firm: null,
            requestedFirmNameSnapshot: "Stryker",
            status: "OPEN",
            readOnly: false,
            materialLines: [],
            materialItemRequests: [],
          },
        ],
      }),
    );
    renderPage();

    expect(await screen.findByText("Nouveau type demandé")).toBeInTheDocument();
    expect(screen.getByText("Demande en cours")).toBeInTheDocument();
    expect(screen.getByText("Stryker")).toBeInTheDocument();
    expect(screen.getByText("Aucun matériel encodé pour cette intervention.")).toBeInTheDocument();
  });

  it("aucun bouton d'édition/suppression n'est jamais rendu (présentation strictement lecture)", async () => {
    mockRoutes(baseMission(), baseEncoding({ entries: [interventionEntry()] }));
    renderPage();

    await screen.findByText("PTG genou droit");
    for (const forbidden of ["Ajouter", "Modifier", "Supprimer", "Gérer", "Encoder"]) {
      expect(screen.queryByText(forbidden)).not.toBeInTheDocument();
    }
  });

  it("aucune donnée financière, tarifaire ou de billing n'apparaît jamais, même si le backend en renvoyait par erreur", async () => {
    mockRoutes(
      baseMission(),
      baseEncoding({
        entries: [interventionEntry({
          materialLines: [{
            id: 100, missionInterventionId: 1, interventionDraftId: null,
            item: { id: 200, label: "Plateau tibial", referenceCode: "REF-001", unit: "pièce", isImplant: true, firm: { id: 20, name: "Zimmer" }, billingStatus: "BILLABLE" } as any,
            quantity: "2.00", comment: "",
          }],
        })],
      }),
    );
    const { container } = renderPage();

    await screen.findByText("PTG genou droit");
    const text = container.textContent ?? "";
    expect(text).not.toMatch(/€|tarif|montant|prix|patient|billing/i);
  });
});

describe("SurgeonEncodingPage — état vide", () => {
  it("aucune entrée → message clair, jamais une erreur", async () => {
    mockRoutes(baseMission(), baseEncoding({ entries: [] }));
    renderPage();

    expect(await screen.findByText("Aucun encodage disponible pour l'instant")).toBeInTheDocument();
    expect(screen.queryByText(/Impossible de charger/)).not.toBeInTheDocument();
  });
});

describe("SurgeonEncodingPage — heures prestées", () => {
  it("affiche 'Non renseigné' quand aucune saisie n'existe", async () => {
    mockRoutes(baseMission(), baseEncoding({ entries: [] }), baseExecution({ hasExecutionRecord: false }));
    renderPage();

    expect(await screen.findByText("Non renseigné")).toBeInTheDocument();
  });

  it("affiche les heures réellement enregistrées, même format que les autres écrans", async () => {
    mockRoutes(baseMission(), baseEncoding({ entries: [] }), baseExecution({ hasExecutionRecord: true, actualDurationMinutes: 270 }));
    renderPage();

    expect(await screen.findByText("4.5 h")).toBeInTheDocument();
  });
});

describe("SurgeonEncodingPage — accès refusé", () => {
  it("view_encoding absent → message d'indisponibilité, jamais une erreur générique", async () => {
    mockRoutes(baseMission({ allowedActions: [] }), baseEncoding());
    renderPage();

    expect(await screen.findByText("La consultation de l'encodage n'est pas disponible pour cette mission.")).toBeInTheDocument();
  });
});

describe("SurgeonEncodingPage — erreur réseau", () => {
  it("erreur au chargement de l'encodage → message explicite avec Réessayer", async () => {
    apiGetMock.mockImplementation((url: string) => {
      if (url === `/api/missions/${MISSION_ID}`) return Promise.resolve({ data: baseMission() });
      if (url === `/api/missions/${MISSION_ID}/execution`) return Promise.resolve({ data: baseExecution() });
      if (url === `/api/missions/${MISSION_ID}/encoding`) return Promise.reject(new Error("network error"));
      if (url === `/api/missions/${MISSION_ID}/encoding-anomaly-reports`) return Promise.resolve({ data: [] });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    renderPage();

    expect(await screen.findByText("Impossible de charger l'encodage de cette mission.")).toBeInTheDocument();
    expect(screen.getByText("Réessayer")).toBeInTheDocument();
  });
});

describe("SurgeonEncodingPage — navigation retour", () => {
  it("le bouton retour navigue vers /app/s/missions/:id (jamais navigate(-1))", async () => {
    mockRoutes(baseMission(), baseEncoding({ entries: [] }));
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Aucun encodage disponible pour l'instant");
    await user.click(screen.getByLabelText("Retour"));

    expect(await screen.findByText("Mission detail screen")).toBeInTheDocument();
  });
});
