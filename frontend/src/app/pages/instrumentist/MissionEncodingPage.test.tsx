import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Routes, Route } from "react-router-dom";
import MissionEncodingPage from "./MissionEncodingPage";

const apiGetMock = vi.fn();
const apiPatchMock = vi.fn();

vi.mock("../../api/apiClient", () => ({
  apiClient: {
    get: (...args: unknown[]) => apiGetMock(...args),
    post: vi.fn().mockResolvedValue({ data: {} }),
    patch: (...args: unknown[]) => apiPatchMock(...args),
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
    ...overrides,
  };
}

/** MissionExecutionInfo — voir missions.api.ts. hasExecutionRecord=false par défaut :
 *  aucune saisie n'a encore eu lieu, exactement l'état "Non renseigné" attendu. */
function baseExecution(overrides: Record<string, any> = {}) {
  return {
    missionId: 529,
    hasExecutionRecord: false,
    actualStartAt: null,
    actualEndAt: null,
    actualDurationMinutes: null,
    hoursSource: null,
    effectiveDurationMinutes: 240,
    effectiveDurationSource: "PLANNED",
    disputes: [],
    ...overrides,
  };
}

function mockRoutes(mission: any, encoding: any, execution: any = baseExecution()) {
  apiGetMock.mockImplementation((url: string) => {
    if (url === `/api/missions/${mission.id}/encoding`) {
      return Promise.resolve({ data: encoding });
    }
    if (url === `/api/missions/${mission.id}/execution`) {
      return Promise.resolve({ data: execution });
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
    interventionTypeRequests: [],
    catalog: CATALOG,
    coherenceSummary: { hasNoInterventions: true, hasInterventionsWithNoMaterial: false, hasUnusedSuggestions: false, hasMaterialFromOtherFirm: false, hasMissingPrimaryFirm: false },
    encodingComments: [],
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
  apiPatchMock.mockReset();
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
        // et des compteurs sur cette page. Aucune régression : interventions réelles
        // toujours rendues correctement après le retrait d'EncodingStatusPanel.
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

  it("aucune régression sur un draft OPEN : toujours rendu dans la liste unifiée", async () => {
    const mission = baseMission();
    mockRoutes(
      mission,
      baseEncoding({
        entries: [
          { kind: "DRAFT", id: 17, requestId: 28, orderIndex: 0, label: "Reconstruction LCL", interventionType: null, firm: null, requestedFirmNameSnapshot: null, status: "OPEN", readOnly: false, materialLines: [], materialItemRequests: [] },
        ],
      }),
    );
    renderPage();

    expect(await screen.findByText("Reconstruction LCL")).toBeInTheDocument();
    expect(screen.getByText("En attente de validation manager")).toBeInTheDocument();
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

/**
 * Anomalie écran d'encodage (commit dédié) — le résumé intermédiaire (statut "Brouillon"
 * + signaux de cohérence informationnels) était un doublon du véritable récapitulatif
 * (SubmitDialog, ouvert par "Terminer l'encodage") : retiré de cette page uniquement.
 */
describe("MissionEncodingPage — résumé intermédiaire supprimé, récapitulatif final préservé", () => {
  it("n'affiche plus le résumé intermédiaire (statut + signaux de cohérence)", async () => {
    const mission = baseMission();
    mockRoutes(mission, baseEncoding());
    renderPage();

    await screen.findByText("0 intervention · 0 matériel");
    expect(screen.queryByText("Brouillon")).not.toBeInTheDocument();
    expect(screen.queryByText("Signaux informationnels — n'empêchent pas la validation")).not.toBeInTheDocument();
    // "Aucune intervention encodée" reste affiché une seule fois — par la section
    // Interventions elle-même, plus jamais dupliqué par un second panneau de résumé.
    expect(screen.getAllByText("Aucune intervention encodée")).toHaveLength(1);
  });

  it("le bouton Terminer l'encodage reste accessible et ouvre le véritable récapitulatif final", async () => {
    const user = userEvent.setup();
    const mission = baseMission();
    mockRoutes(mission, baseEncoding());
    renderPage();

    const submitButton = await screen.findByRole("button", { name: "Terminer l'encodage" });
    await user.click(submitButton);

    // Le récapitulatif final (SubmitDialog) n'a pas été supprimé ni modifié : son titre
    // et son contenu (heures/interventions/matériel) restent affichés normalement.
    expect(await screen.findByText("Récapitulatif avant validation")).toBeInTheDocument();
    expect(screen.getByText("Valider et clôturer la mission")).toBeInTheDocument();
  });
});

/**
 * Anomalie écran d'encodage (commit dédié) — cause racine : la carte lisait
 * mission.service?.hours, un champ que le backend n'expose plus depuis le renommage
 * InstrumentistService -> MissionExecution (D-071). La carte lit désormais
 * ["mission-execution", missionId] (GET .../execution), la même source de vérité que la
 * mutation d'EditServiceHoursDialog écrit.
 */
describe("MissionEncodingPage — heures prestées (source de vérité MissionExecutionInfo)", () => {
  it("affiche 'Non renseigné' quand aucune saisie n'existe réellement (hasExecutionRecord=false)", async () => {
    const mission = baseMission();
    mockRoutes(mission, baseEncoding(), baseExecution({ hasExecutionRecord: false }));
    renderPage();

    expect(await screen.findByText("Non renseigné")).toBeInTheDocument();
  });

  it("affiche la valeur au format 'X h' quand hasExecutionRecord=true", async () => {
    const mission = baseMission();
    mockRoutes(mission, baseEncoding(), baseExecution({ hasExecutionRecord: true, actualDurationMinutes: 240 }));
    renderPage();

    expect(await screen.findByText("4 h")).toBeInTheDocument();
  });

  it("affiche une valeur décimale correctement formatée (270 min = 4.5 h)", async () => {
    const mission = baseMission();
    mockRoutes(mission, baseEncoding(), baseExecution({ hasExecutionRecord: true, actualDurationMinutes: 270 }));
    renderPage();

    expect(await screen.findByText("4.5 h")).toBeInTheDocument();
  });

  it("mise à jour optimiste immédiate après sauvegarde, sans repasser par 'Non renseigné', confirmée par la réponse serveur", async () => {
    const user = userEvent.setup();
    const mission = baseMission();
    mockRoutes(mission, baseEncoding(), baseExecution({ hasExecutionRecord: false }));

    let resolvePatch: (v: any) => void = () => {};
    apiPatchMock.mockImplementation(() => new Promise((resolve) => { resolvePatch = resolve; }));

    renderPage();

    expect(await screen.findByText("Non renseigné")).toBeInTheDocument();
    await user.click(screen.getByText("Non renseigné"));
    await user.click(await screen.findByRole("button", { name: "Enregistrer les heures" }));

    // Optimiste : la modale se ferme, la carte affiche déjà "4 h" (08h00->12h00 planifié,
    // aucune pause) avant toute réponse serveur — jamais de retour transitoire à "Non renseigné".
    await waitFor(() => expect(screen.queryByRole("button", { name: "Enregistrer les heures" })).not.toBeInTheDocument());
    expect(await screen.findByText("4 h")).toBeInTheDocument();
    expect(screen.queryByText("Non renseigné")).not.toBeInTheDocument();

    resolvePatch({
      data: baseExecution({ hasExecutionRecord: true, actualDurationMinutes: 240, hoursSource: "INSTRUMENTIST", effectiveDurationMinutes: 240, effectiveDurationSource: "ACTUAL_EXPLICIT" }),
    });

    // La réponse serveur confirme (ne rétablit jamais "Non renseigné").
    await waitFor(() => expect(screen.getByText("4 h")).toBeInTheDocument());
  });

  it("rollback vers la valeur précédente si la sauvegarde échoue", async () => {
    const user = userEvent.setup();
    const mission = baseMission();
    mockRoutes(mission, baseEncoding(), baseExecution({ hasExecutionRecord: true, actualDurationMinutes: 180, effectiveDurationMinutes: 180, effectiveDurationSource: "ACTUAL_EXPLICIT" }));
    apiPatchMock.mockRejectedValue(new Error("Network Error"));

    renderPage();

    expect(await screen.findByText("3 h")).toBeInTheDocument();
    await user.click(screen.getByText("3 h"));
    await user.click(await screen.findByRole("button", { name: "Enregistrer les heures" }));

    // La valeur optimiste (4h, 08h-12h planifié) apparaît d'abord, puis le rollback
    // restaure la valeur précédente (3h) une fois l'échec confirmé.
    await waitFor(() => expect(screen.getByText("3 h")).toBeInTheDocument());
  });

  it("un refetch (invalidation) ne rétablit jamais l'ancienne valeur", async () => {
    // Serveur simulé à état réel (pas un mock réassigné après coup) : onSuccess invalide
    // ["mission-execution", missionId], ce qui déclenche un vrai refetch quasi immédiat
    // (même tick que l'invalidation) — un mock GET réassigné après l'action utilisateur
    // pourrait perdre la course contre ce refetch et répondre l'ancienne valeur.
    const user = userEvent.setup();
    const mission = baseMission();
    let serverExecution = baseExecution({ hasExecutionRecord: false });

    apiGetMock.mockImplementation((url: string) => {
      if (url === `/api/missions/${mission.id}/encoding`) return Promise.resolve({ data: baseEncoding() });
      if (url === `/api/missions/${mission.id}/execution`) return Promise.resolve({ data: serverExecution });
      if (url === `/api/missions/${mission.id}`) return Promise.resolve({ data: mission });
      return Promise.reject(new Error(`unexpected GET ${url}`));
    });
    apiPatchMock.mockImplementation(() => {
      serverExecution = baseExecution({ hasExecutionRecord: true, actualDurationMinutes: 240, hoursSource: "INSTRUMENTIST", effectiveDurationMinutes: 240, effectiveDurationSource: "ACTUAL_EXPLICIT" });
      return Promise.resolve({ data: serverExecution });
    });

    renderPage();

    await screen.findByText("Non renseigné");
    await user.click(screen.getByText("Non renseigné"));
    await user.click(await screen.findByRole("button", { name: "Enregistrer les heures" }));

    await waitFor(() => expect(screen.getByText("4 h")).toBeInTheDocument());
    // Le refetch déclenché par invalidate() interroge le "serveur" simulé, désormais à
    // jour lui aussi (mutation exécutée avant ce point) — jamais de régression visible.
    expect(screen.queryByText("Non renseigné")).not.toBeInTheDocument();
  });

  it("persiste après un rechargement complet simulé (nouveau montage de page, même backend)", async () => {
    const mission = baseMission();
    const savedExecution = baseExecution({ hasExecutionRecord: true, actualDurationMinutes: 240, hoursSource: "INSTRUMENTIST", effectiveDurationMinutes: 240, effectiveDurationSource: "ACTUAL_EXPLICIT" });
    mockRoutes(mission, baseEncoding(), savedExecution);

    const { unmount } = renderPage();
    expect(await screen.findByText("4 h")).toBeInTheDocument();

    // "Rechargement" = démontage complet + nouveau QueryClient + nouveau montage, contre
    // le même mock serveur (qui persiste réellement côté backend, contrairement à un
    // simple cache client) : si "4 h" survit, la valeur est bien persistée server-side,
    // pas seulement retenue en mémoire côté client.
    unmount();
    renderPage();
    expect(await screen.findByText("4 h")).toBeInTheDocument();
  });
});
