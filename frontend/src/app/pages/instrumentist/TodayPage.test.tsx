import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";
import TodayPage from "./TodayPage";

const fetchMissionsMock = vi.fn();
const fetchOffersMock = vi.fn();

vi.mock("../../features/missions/api/missions.api", () => ({
  fetchMissions: (...args: unknown[]) => fetchMissionsMock(...args),
  fetchInstrumentistOffersWithFallback: (...args: unknown[]) => fetchOffersMock(...args),
}));

/**
 * `background: url(...)` on a plain Box has no load-error handling — TodayPage
 * preloads the photo itself via a real `Image()` before trusting it. jsdom's
 * HTMLImageElement never actually fetches anything, so onload/onerror never fire on
 * their own; this fake makes them fire deterministically based on the URL, so both the
 * success and failure paths are actually exercised.
 */
class FakeImage {
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  private _src = "";
  set src(value: string) {
    this._src = value;
    queueMicrotask(() => {
      if (value.includes("broken")) this.onerror?.();
      else this.onload?.();
    });
  }
  get src() {
    return this._src;
  }
}

/**
 * Emotion (MUI's sx) keeps a single shared, ever-growing set of <style> tags across
 * tests/renders — never purged, exactly like in a real running app. A blind substring
 * search across all of them is prone to false positives from an unrelated previous
 * test's leftover rule. Scoping to the specific element's own emotion-generated class
 * (and only that rule's body) avoids that cross-test bleed entirely, without having to
 * (wrongly) purge emotion's cache between tests.
 */
function ruleBodyForElement(el: Element): string {
  const emotionClass = Array.from(el.classList).find((c) => c.startsWith("css-"));
  if (!emotionClass) return "";
  const sheet = Array.from(document.querySelectorAll("style")).map((s) => s.textContent ?? "").join("\n");
  const selector = `.${emotionClass}`;
  const idx = sheet.indexOf(selector);
  if (idx === -1) return "";
  const braceStart = sheet.indexOf("{", idx);
  const braceEnd = sheet.indexOf("}", braceStart);
  return sheet.slice(braceStart, braceEnd);
}

function makeMission(overrides: Record<string, any> = {}) {
  const now = new Date();
  return {
    id: 1,
    type: "BLOCK",
    startAt: now.toISOString(),
    endAt: new Date(now.getTime() + 4 * 3600_000).toISOString(),
    status: "ASSIGNED",
    site: { id: 1, name: "CHU Brugmann", address: "Site Victor Horta" },
    surgeon: { id: 2, firstname: "Anouk", lastname: "Peeters" },
    allowedActions: ["encoding"],
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <TodayPage />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.stubGlobal("Image", FakeImage as unknown as typeof Image);
  vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");
  fetchMissionsMock.mockReset();
  fetchOffersMock.mockReset();
  fetchOffersMock.mockResolvedValue({ items: [] });
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
});

describe("TodayPage — image de l'hôpital", () => {
  it("résout et affiche la photo réelle de l'hôpital via resolveApiAssetUrl", async () => {
    fetchMissionsMock.mockImplementation((_p: number, _l: number, filters: any) =>
      filters?.status === "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED"
        ? Promise.resolve({ items: [makeMission({ site: { id: 1, name: "CHU Brugmann", photoPath: "/uploads/hospital-photos/photo1.jpg" } })] })
        : Promise.resolve({ items: [] }),
    );
    renderPage();

    await screen.findByText("CHU Brugmann");
    const heroPhoto = await screen.findByTestId("mission-hero-photo");
    await waitFor(() => {
      expect(ruleBodyForElement(heroPhoto)).toContain("https://api.surgicalhub.test/uploads/hospital-photos/photo1.jpg");
    });
  });

  it("affiche le fallback design (dégradé) quand aucune photo n'existe", async () => {
    fetchMissionsMock.mockImplementation((_p: number, _l: number, filters: any) =>
      filters?.status === "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED"
        ? Promise.resolve({ items: [makeMission({ site: { id: 1, name: "CHU Brugmann", photoPath: null } })] })
        : Promise.resolve({ items: [] }),
    );
    renderPage();

    await screen.findByText("CHU Brugmann");
    const heroPhoto = await screen.findByTestId("mission-hero-photo");
    await waitFor(() => {
      expect(ruleBodyForElement(heroPhoto)).toContain("linear-gradient(150deg");
    });
    expect(ruleBodyForElement(heroPhoto)).not.toContain("uploads/hospital-photos");
  });

  it("retombe sur le fallback design quand l'image échoue réellement au chargement", async () => {
    fetchMissionsMock.mockImplementation((_p: number, _l: number, filters: any) =>
      filters?.status === "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED"
        ? Promise.resolve({ items: [makeMission({ site: { id: 1, name: "CHU Brugmann", photoPath: "/uploads/hospital-photos/broken.jpg" } })] })
        : Promise.resolve({ items: [] }),
    );
    renderPage();

    await screen.findByText("CHU Brugmann");
    const heroPhoto = await screen.findByTestId("mission-hero-photo");
    await waitFor(() => {
      expect(ruleBodyForElement(heroPhoto)).toContain("linear-gradient(150deg");
    });
    expect(ruleBodyForElement(heroPhoto)).not.toContain("broken.jpg");
  });

  it("affiche l'état 'aucune mission' avec un vrai CTA vers les offres", async () => {
    fetchMissionsMock.mockResolvedValue({ items: [] });
    renderPage();

    expect(await screen.findByText("Aucune mission aujourd'hui")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Voir les offres disponibles" })).toBeInTheDocument();
  });
});

describe("TodayPage — pastille « En cours » (dérivée du temps réel, pas du cron)", () => {
  function mockTodayMission(mission: Record<string, any>) {
    fetchMissionsMock.mockImplementation((_p: number, _l: number, filters: any) =>
      filters?.status === "ASSIGNED,DECLARED,IN_PROGRESS,SUBMITTED,VALIDATED"
        ? Promise.resolve({ items: [mission] })
        : Promise.resolve({ items: [] }),
    );
  }

  it("statut ASSIGNED + maintenant dans le créneau → « En cours » affiché (pas besoin d'attendre le cron)", async () => {
    mockTodayMission(makeMission({ status: "ASSIGNED" })); // fixture: startAt=now, endAt=now+4h
    renderPage();

    await screen.findByText("CHU Brugmann");
    expect(await screen.findByText("En cours")).toBeInTheDocument();
  });

  it("statut IN_PROGRESS + maintenant dans le créneau → « En cours » affiché", async () => {
    mockTodayMission(makeMission({ status: "IN_PROGRESS" }));
    renderPage();

    await screen.findByText("CHU Brugmann");
    expect(await screen.findByText("En cours")).toBeInTheDocument();
  });

  it("statut ASSIGNED mais avant le créneau → pas de « En cours »", async () => {
    const now = new Date();
    mockTodayMission(makeMission({
      status: "ASSIGNED",
      startAt: new Date(now.getTime() + 2 * 3600_000).toISOString(),
      endAt: new Date(now.getTime() + 6 * 3600_000).toISOString(),
    }));
    renderPage();

    await screen.findByText("CHU Brugmann");
    expect(screen.queryByText("En cours")).not.toBeInTheDocument();
  });

  it("statut IN_PROGRESS mais après le créneau (cron pas encore repassé pour le submit) → pas de « En cours »", async () => {
    const now = new Date();
    mockTodayMission(makeMission({
      status: "IN_PROGRESS",
      startAt: new Date(now.getTime() - 6 * 3600_000).toISOString(),
      endAt: new Date(now.getTime() - 2 * 3600_000).toISOString(),
    }));
    renderPage();

    await screen.findByText("CHU Brugmann");
    expect(screen.queryByText("En cours")).not.toBeInTheDocument();
  });

  it.each(["SUBMITTED", "VALIDATED", "DECLARED"])(
    "statut %s dans le créneau horaire → jamais « En cours », quel que soit l'horaire",
    async (status) => {
      mockTodayMission(makeMission({ status })); // startAt=now, endAt=now+4h
      renderPage();

      await screen.findByText("CHU Brugmann");
      expect(screen.queryByText("En cours")).not.toBeInTheDocument();
    },
  );
});

describe("TodayPage — aperçu « À venir »", () => {
  it("limite l'aperçu aux 3 missions les plus proches dans le temps, triées, et 'Voir tout' mène aux offres", async () => {
    fetchMissionsMock.mockImplementation((_p: number, _l: number, filters: any) => {
      if (filters?.status === "ASSIGNED,DECLARED") {
        return Promise.resolve({
          items: [
            makeMission({ id: 20, status: "ASSIGNED", site: { id: 1, name: "Site D (+4j)" }, startAt: "2026-07-18T09:00:00Z" }),
            makeMission({ id: 21, status: "ASSIGNED", site: { id: 2, name: "Site A (+1j)" }, startAt: "2026-07-15T09:00:00Z" }),
            makeMission({ id: 22, status: "ASSIGNED", site: { id: 3, name: "Site C (+3j)" }, startAt: "2026-07-17T09:00:00Z" }),
            makeMission({ id: 23, status: "ASSIGNED", site: { id: 4, name: "Site B (+2j)" }, startAt: "2026-07-16T09:00:00Z" }),
          ],
        });
      }
      return Promise.resolve({ items: [] });
    });
    renderPage();

    await screen.findByText("Site A (+1j)");
    expect(screen.getByText("Site B (+2j)")).toBeInTheDocument();
    expect(screen.getByText("Site C (+3j)")).toBeInTheDocument();
    expect(screen.queryByText("Site D (+4j)")).not.toBeInTheDocument();

    const link = screen.getByRole("button", { name: "Voir tout" });
    expect(link).toBeInTheDocument();
  });
});

describe("TodayPage — pastille de statut « À venir »", () => {
  it("affiche 'Confirmée' pour une mission assignée et 'En attente' pour une mission déclarée, jamais l'inverse figé", async () => {
    fetchMissionsMock.mockImplementation((_p: number, _l: number, filters: any) => {
      if (filters?.status === "ASSIGNED,DECLARED") {
        return Promise.resolve({
          items: [
            makeMission({ id: 10, status: "ASSIGNED", site: { id: 1, name: "St-Luc UCL" } }),
            makeMission({ id: 11, status: "DECLARED", site: { id: 2, name: "Cliniques de l'Europe" } }),
          ],
        });
      }
      return Promise.resolve({ items: [] });
    });
    renderPage();

    expect(await screen.findByText("St-Luc UCL")).toBeInTheDocument();
    expect(screen.getByText("Confirmée")).toBeInTheDocument();
    expect(screen.getByText("En attente")).toBeInTheDocument();
  });
});
