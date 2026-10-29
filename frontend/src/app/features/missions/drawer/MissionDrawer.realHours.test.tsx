import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { MissionDrawer, type DrawerCorrections } from "./MissionDrawer";
import { buildMissionDrawerView } from "./missionDrawerModel";
import type { Mission, MissionLock } from "../api/missions.types";
import type { MissionExecutionInfo } from "../api/missions.api";

/**
 * Bloc HEURES du tiroir mission — non-régression de l'affichage des heures réelles :
 * seules les valeurs déclarées sont montrées, jamais une borne reconstruite depuis le planning
 * (mission planifiée 08h00–13h00, Europe/Brussels).
 */
const mission = (overrides: Partial<Mission> = {}) => ({
  id: 1218,
  type: "BLOCK",
  schedulePrecision: "EXACT",
  startAt: "2026-10-02T08:00:00+02:00",
  endAt: "2026-10-02T13:00:00+02:00",
  site: { id: 1, name: "CHIREC — Hôpital Delta" },
  status: "SUBMITTED",
  surgeon: { id: 5, firstname: "Yorick", lastname: "Berger", email: "y@x.be" },
  instrumentist: { id: 9, firstname: "Salve", lastname: "Decorte", email: "s@x.be" },
  allowedActions: ["view"],
  ...overrides,
}) as Mission;

const execution = (overrides: Partial<MissionExecutionInfo> = {}): MissionExecutionInfo => ({
  missionId: 1218, hasExecutionRecord: true, actualStartAt: null, actualEndAt: null, actualDurationMinutes: null,
  hoursSource: "INSTRUMENTIST", effectiveDurationMinutes: 300, effectiveDurationSource: "PLANNED", disputes: [],
  ...overrides,
} as MissionExecutionInfo);

const TIMES = execution({ actualStartAt: "2026-10-02T07:00:00+02:00", actualEndAt: "2026-10-02T15:30:00+02:00", effectiveDurationMinutes: 510, effectiveDurationSource: "ACTUAL_TIMES" });
const DURATION_ONLY = execution({ actualDurationMinutes: 510, effectiveDurationMinutes: 510, effectiveDurationSource: "ACTUAL_EXPLICIT" });
const PARTIAL = execution({ actualStartAt: "2026-10-02T07:00:00+02:00" });
const NONE = execution({ hasExecutionRecord: false, hoursSource: null });

const EDIT: DrawerCorrections = { hours: { label: "Corriger les heures", testId: "manager-edit-hours", onClick: vi.fn() } };
const LOCK: MissionLock = { level: "FINANCIAL_CALCULATION", label: "Calcul financier verrouillé", reason: "Calcul financier verrouillé." };
const ENCODING_LOCK: MissionLock = { level: "ENCODING", label: "Encodage verrouillé", reason: "Encodage validé." };
const FINANCIAL_BLOCK = "Verrou financier : le calcul de cette mission est intégré à un document financier. Son encodage ne peut plus être rouvert.";

function renderHours(m: Mission, e: MissionExecutionInfo | null, corrections?: DrawerCorrections) {
  const view = buildMissionDrawerView({ mission: m, execution: e ?? undefined, entries: [], encodingError: false, audit: [], calculation: undefined, calculationLoading: false });
  render(<MissionDrawer open missionId={m.id} view={view} footer={{ title: "", actions: [] }} corrections={corrections} onClose={vi.fn()} />);
  return screen.queryByTestId("drawer-hours");
}

/** [début, durée totale, fin] de la ligne « Réel ». */
function realRow(card: HTMLElement) {
  const row = within(card).getByText("Réel").parentElement!;
  return Array.from(row.querySelectorAll<HTMLElement>(".mdw-tl__val"));
}

describe("MissionDrawer — heures réelles", () => {
  it("1 — réel 07h00–15h30 : les deux bornes et la durée totale 8h30", () => {
    const card = renderHours(mission(), TIMES)!;
    const [start, total, end] = realRow(card);
    expect(start).toHaveTextContent("07h00");
    expect(total).toHaveTextContent("8h30");
    expect(end).toHaveTextContent("15h30");
    expect(screen.queryByTestId("drawer-hours-note")).toBeNull();
  });

  it("2 — durée seule (cas #1218) : durée déclarée, bornes « Non renseigné », explication", () => {
    const card = renderHours(mission({ allowedActions: ["view", "edit_hours"] }), DURATION_ONLY, EDIT)!;
    const [start, total, end] = realRow(card);
    expect(start).toHaveTextContent("Non renseigné");
    expect(total).toHaveTextContent("8h30 · déclarée");
    expect(end).toHaveTextContent("Non renseigné");
    expect(start.style.color).toBe("var(--gray-500)");
    expect(end.style.color).toBe("var(--gray-500)");
    expect(card).toHaveTextContent("Durée déclarée : dépasse le planifié de 3h30");
    expect(screen.getByTestId("drawer-hours-note")).toHaveTextContent(
      "Une durée réelle de 8h30 a été déclarée, mais les heures de début et de fin n'ont pas été renseignées. Utilisez « Corriger les heures » pour renseigner les horaires.",
    );
    // Aucune borne reconstruite depuis le planifié 08h00–13h00.
    expect(within(card).getByText("Réel").parentElement).not.toHaveTextContent(/08h00|13h00|16h30/);
  });

  it("3 — aucune heure : rien d'inventé, ni borne ni durée ni comparaison", () => {
    const card = renderHours(mission(), NONE)!;
    expect(screen.getByTestId("drawer-hours-none")).toHaveTextContent("Heures réelles non déclarées");
    expect(realRow(card)).toHaveLength(0);
    expect(card).not.toHaveTextContent(/Conforme|Dépasse/);
    expect(screen.queryByTestId("drawer-hours-note")).toBeNull();
  });

  it("4 — début seul : « Déclaration incomplète », la fin et la durée restent vides", () => {
    const card = renderHours(mission(), PARTIAL)!;
    const [start, total, end] = realRow(card);
    expect(start).toHaveTextContent("07h00");
    expect(total).toHaveTextContent("—");
    expect(end).toHaveTextContent("Non renseigné");
    expect(card).toHaveTextContent("Déclaration incomplète");
    // Le planifié de repli servi par le backend (300 min) n'est jamais affiché comme réel.
    expect(card).not.toHaveTextContent(/Conforme au planifié/);
    expect(total).not.toHaveTextContent("5h00");
    expect(screen.getByTestId("drawer-hours-note")).toHaveTextContent("Déclaration incomplète : l'heure de fin n'a pas été renseignée.");
  });

  it("5 — dépassement de 3h30 : texte et couleur ambre sur ce qui dépasse", () => {
    const card = renderHours(mission(), TIMES)!;
    expect(card).toHaveTextContent("Dépasse le planifié de 3h30");
    const [start, total, end] = realRow(card);
    expect(start.style.color).toBe("var(--amber-700)");
    expect(total.style.color).toBe("var(--amber-700)");
    expect(end.style.color).toBe("var(--amber-700)");
    expect(card).toHaveTextContent("Planning : horaire confirmé");
  });

  it("6a — validée, réouverture servie : explication renvoyant à la réouverture, aucune correction directe", () => {
    const m = mission({ status: "VALIDATED", allowedActions: ["view", "reopen"], encoding: { locks: [ENCODING_LOCK] } as unknown as Mission["encoding"] });
    const card = renderHours(m, DURATION_ONLY)!;
    expect(within(card).queryByRole("button", { name: "Corriger les heures" })).toBeNull();
    expect(screen.getByTestId("drawer-hours-note")).toHaveTextContent("Les heures sont verrouillées avec l'encodage : une correction passe par sa réouverture.");
    expect(screen.getByTestId("drawer-hours-note")).not.toHaveTextContent("Utilisez « Corriger les heures »");
  });

  it("6b — verrou financier : motif servi par le backend, jamais une réouverture impossible", () => {
    const m = mission({
      status: "VALIDATED", allowedActions: ["view"], encodingCorrectionBlockedReason: FINANCIAL_BLOCK,
      encoding: { locks: [ENCODING_LOCK, LOCK] } as unknown as Mission["encoding"],
    });
    const card = renderHours(m, DURATION_ONLY)!;
    expect(within(card).queryByRole("button", { name: "Corriger les heures" })).toBeNull();
    const note = screen.getByTestId("drawer-hours-note");
    expect(note).toHaveTextContent(`Les heures ne sont plus modifiables. ${FINANCIAL_BLOCK}`);
    expect(note).not.toHaveTextContent("une correction passe par sa réouverture");
    expect(note).not.toHaveTextContent("Corriger les heures");
  });

  it("6c — verrou sans droit ni motif servi : aucune action proposée", () => {
    const m = mission({ status: "VALIDATED", allowedActions: ["view"], encoding: { locks: [ENCODING_LOCK] } as unknown as Mission["encoding"] });
    renderHours(m, DURATION_ONLY);
    expect(screen.getByTestId("drawer-hours-note")).toHaveTextContent(/n'ont pas été renseignées\. Les heures ne sont plus modifiables\.$/);
  });

  it("7 — mission sans instrumentiste : « Activité opératoire » inchangée, aucun bloc HEURES", () => {
    const m = mission({
      instrumentist: null,
      executedWithoutInstrumentist: { declaredAt: "2026-10-02T12:00:00+02:00", declaredByName: "Jean Dupont" },
      encoding: {
        state: "SUBMITTED", stateLabel: "Soumis", executedWithoutInstrumentist: true, interventionCount: 2, materialLineCount: 3,
        finalizedBy: { name: "Dr Paul Martin", role: "SURGEON", at: "2026-10-02T14:00:00+02:00" }, validatedBy: null, locks: [],
      },
    } as unknown as Partial<Mission>);
    expect(renderHours(m, NONE)).toBeNull();
    const card = screen.getByTestId("drawer-operative-activity");
    expect(card).toHaveTextContent("Encodage terminé par le chirurgien — Dr Paul Martin");
    expect(card).toHaveTextContent("Aucune prestation d'instrumentiste");
    expect(screen.queryByTestId("drawer-hours-note")).toBeNull();
    expect(screen.queryByText("Heures réelles non déclarées")).toBeNull();
  });
});
