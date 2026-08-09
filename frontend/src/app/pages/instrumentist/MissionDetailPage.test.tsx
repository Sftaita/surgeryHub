import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import { MissionDetailContent } from "./MissionDetailPage";

const apiGetMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: vi.fn().mockResolvedValue({ data: {} }),
    patch: vi.fn().mockResolvedValue({ data: {} }),
  },
}));

vi.mock("../../ui/toast/useToast", () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}));

const MISSION_ID = 690;

function baseMission(overrides: Record<string, any> = {}) {
  return {
    id: MISSION_ID,
    type: "BLOCK",
    schedulePrecision: "EXACT",
    startAt: "2026-07-20T08:00:00Z",
    endAt: "2026-07-20T11:00:00Z",
    status: "ASSIGNED",
    site: { id: 1, name: "CHU Test" },
    surgeon: { id: 2, firstname: "Jean", lastname: "Dupont", email: "jd@test.be" },
    allowedActions: ["encoding", "edit_hours"],
    ...overrides,
  };
}

function executionInfo(overrides: Record<string, any> = {}) {
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

function mockRoutes(mission: any, execution: any) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}/execution`) return Promise.resolve({ data: execution });
    if (url === `/api/missions/${mission.id}`) return Promise.resolve({ data: mission });
    return Promise.reject(new Error(`unexpected GET ${url}`));
  });
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <MissionDetailContent missionId={MISSION_ID} />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  apiGetMock.mockReset();
});

/**
 * Anomalie écran d'encodage (commit dédié) — cette page lisait aussi
 * mission.service?.hours (toujours undefined depuis D-071). Corrigée pour lire
 * ["mission-execution", missionId], la même source que MissionEncodingPage.tsx et
 * SubmitDialog.tsx (formatage centralisé, formatExecutionHours).
 */
describe("MissionDetailPage (instrumentiste) — heures prestées (source de vérité mission-execution)", () => {
  it("affiche 'Non renseigné' quand aucune saisie n'existe réellement", async () => {
    mockRoutes(baseMission(), executionInfo({ hasExecutionRecord: false }));
    renderPage();

    expect(await screen.findByText("Non renseigné")).toBeInTheDocument();
  });

  it("affiche les heures réellement enregistrées, au même format que l'écran d'encodage", async () => {
    mockRoutes(baseMission(), executionInfo({ hasExecutionRecord: true, actualDurationMinutes: 270 }));
    renderPage();

    expect(await screen.findByText("4.5 h")).toBeInTheDocument();
    expect(screen.queryByText("Non renseigné")).not.toBeInTheDocument();
  });
});

/**
 * Socle chirurgien (Lot 2, D-095, §8/§9) — MissionDetailContent est le même composant
 * pour l'instrumentiste et le chirurgien, entièrement piloté par allowedActions (jamais
 * de branchement par rôle dans ce composant). Pour un chirurgien, le backend
 * (MissionActionsService) ne accorde jamais encoding/edit_hours/submit — ces tests
 * verrouillent le rendu obtenu avec un allowedActions vide : aucun bouton d'action
 * instrumentiste, aucune donnée financière ou patient, mais l'instrumentiste assigné
 * (ou "À couvrir") reste visible.
 */
describe("MissionDetailPage — rendu chirurgien (allowedActions vide, aucune action instrumentiste)", () => {
  it("affiche les infos utiles (site, horaire, chirurgien, instrumentiste, type, statut) sans aucun bouton d'action", async () => {
    mockRoutes(
      baseMission({
        instrumentist: { id: 5, firstname: "Salve", lastname: "Decorte", email: "sd@test.be" },
        allowedActions: [],
      }),
      executionInfo(),
    );
    renderPage();

    expect(await screen.findByText("CHU Test")).toBeInTheDocument();
    expect(screen.getByText("Dr. Jean Dupont")).toBeInTheDocument();
    expect(screen.getByText("Salve Decorte")).toBeInTheDocument();
    expect(screen.getByText("Bloc opératoire")).toBeInTheDocument();
    expect(screen.getByText("Encodage non disponible pour cette mission.")).toBeInTheDocument();

    expect(screen.queryByText("Encoder la mission")).not.toBeInTheDocument();
    expect(screen.queryByText("Terminer l'encodage")).not.toBeInTheDocument();
    expect(screen.queryByText("Modifier")).not.toBeInTheDocument();
    expect(screen.queryByText("Gérer")).not.toBeInTheDocument();
  });

  it("mission non couverte : affiche 'À couvrir' plutôt qu'un instrumentiste, toujours sans bouton d'action", async () => {
    mockRoutes(
      baseMission({ status: "OPEN", instrumentist: null, allowedActions: [] }),
      executionInfo(),
    );
    renderPage();

    expect(await screen.findByText("À couvrir")).toBeInTheDocument();
    expect(screen.queryByText("Encoder la mission")).not.toBeInTheDocument();
    expect(screen.queryByText("Terminer l'encodage")).not.toBeInTheDocument();
  });

  it("aucune donnée financière ou patient n'apparaît jamais dans le rendu (montant, tarif, prix, patient)", async () => {
    mockRoutes(
      baseMission({
        instrumentist: { id: 5, firstname: "Salve", lastname: "Decorte", email: "sd@test.be" },
        allowedActions: [],
      }),
      executionInfo(),
    );
    const { container } = renderPage();

    await screen.findByText("CHU Test");
    const text = container.textContent ?? "";
    expect(text).not.toMatch(/€|tarif|montant|prix|patient/i);
  });
});

/**
 * Lot 6 (D-100) — allowedActions inclut désormais "view_encoding" pour un chirurgien
 * consultant sa propre mission. MissionDetailContent reste le même composant que
 * l'instrumentiste (pas de branchement par rôle) : le CTA "Encodage de l'intervention"
 * apparaît uniquement piloté par ce flag, et navigue vers /app/s/... (jamais /app/i/...,
 * réservé à l'édition instrumentiste).
 */
describe("MissionDetailPage — rendu chirurgien avec view_encoding (Lot 6, D-100)", () => {
  it("affiche le CTA 'Encodage de l'intervention' et le lien 'Voir' quand view_encoding est présent", async () => {
    mockRoutes(
      baseMission({
        instrumentist: { id: 5, firstname: "Salve", lastname: "Decorte", email: "sd@test.be" },
        allowedActions: ["view_encoding"],
      }),
      executionInfo(),
    );
    renderPage();

    expect(await screen.findByText("Encodage de l'intervention")).toBeInTheDocument();
    expect(screen.getByText("Voir")).toBeInTheDocument();
    expect(screen.getByText("Consultez les interventions et le matériel encodés par l'instrumentiste.")).toBeInTheDocument();

    // Jamais les CTA d'édition instrumentiste en même temps.
    expect(screen.queryByText("Encoder la mission")).not.toBeInTheDocument();
    expect(screen.queryByText("Gérer")).not.toBeInTheDocument();
  });

  it("le CTA 'Voir' navigue vers /app/s/missions/:id/encoding (jamais /app/i/...)", async () => {
    mockRoutes(
      baseMission({
        instrumentist: { id: 5, firstname: "Salve", lastname: "Decorte", email: "sd@test.be" },
        allowedActions: ["view_encoding"],
      }),
      executionInfo(),
    );
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
      <MemoryRouter initialEntries={["/app/s/missions/690"]}>
        <QueryClientProvider client={client}>
          <Routes>
            <Route path="/app/s/missions/:id" element={<MissionDetailContent missionId={MISSION_ID} />} />
            <Route path="/app/s/missions/:id/encoding" element={<div>Surgeon encoding screen</div>} />
          </Routes>
        </QueryClientProvider>
      </MemoryRouter>,
    );

    const user = userEvent.setup();
    await screen.findByText("Encodage de l'intervention");
    await user.click(screen.getByText("Encodage de l'intervention"));

    expect(await screen.findByText("Surgeon encoding screen")).toBeInTheDocument();
  });

  it("view_encoding absent (statut non éligible) : ni CTA ni lien, comme avant ce lot", async () => {
    mockRoutes(
      baseMission({
        instrumentist: { id: 5, firstname: "Salve", lastname: "Decorte", email: "sd@test.be" },
        allowedActions: [],
      }),
      executionInfo(),
    );
    renderPage();

    await screen.findByText("CHU Test");
    expect(screen.queryByText("Encodage de l'intervention")).not.toBeInTheDocument();
    expect(screen.queryByText("Voir")).not.toBeInTheDocument();
  });
});

/**
 * Régression instrumentiste — canEncoding doit rester strictement prioritaire et
 * exclusif : même si un jour view_encoding apparaissait à tort aux côtés de
 * encoding/edit_encoding, le CTA instrumentiste reste seul affiché (jamais les deux).
 */
describe("MissionDetailPage — régression instrumentiste (canEncoding prioritaire sur canViewEncoding)", () => {
  it("encoding + view_encoding ensemble : seul le CTA instrumentiste 'Encoder la mission' est affiché", async () => {
    mockRoutes(
      baseMission({
        instrumentist: { id: 5, firstname: "Salve", lastname: "Decorte", email: "sd@test.be" },
        allowedActions: ["encoding", "view_encoding"],
      }),
      executionInfo(),
    );
    renderPage();

    expect(await screen.findByText("Encoder la mission")).toBeInTheDocument();
    expect(screen.getByText("Gérer")).toBeInTheDocument();
    expect(screen.queryByText("Encodage de l'intervention")).not.toBeInTheDocument();
    expect(screen.queryByText("Voir")).not.toBeInTheDocument();
  });
});
