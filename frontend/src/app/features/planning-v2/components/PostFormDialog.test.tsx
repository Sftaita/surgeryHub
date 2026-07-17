import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { PostFormDialog } from "./PostFormDialog";
import type { Site } from "../../sites/api/sites.api";
import type { SearchableOption } from "./SearchableSelect";

const sites: Site[] = [
  { id: 1, name: "Alpha" },
  { id: 2, name: "Beta (matin seul)" },
  { id: 3, name: "Gamma (aucune période)" },
];
const surgeons: SearchableOption[] = [{ id: 1, label: "Dr Martin" }];
const instrumentists: SearchableOption[] = [];

function periodConfig(siteId: number, period: string) {
  return { id: siteId * 10 + period.length, site: { id: siteId, name: "" }, period, startTime: "08:00", endTime: "18:00", active: true };
}

// This dialog filters the period options by the selected site's active shift periods
// (GET /api/planning/shift-periods) — stub it per-site: Alpha has all three configured
// (matches the Batch 14C tests below, unrelated to period filtering), Beta only has
// MATIN, Gamma has none at all.
const getShiftPeriodsMock = vi.fn((siteId: number) => {
  if (siteId === 1) {
    return Promise.resolve({ items: ["MATIN", "APRES_MIDI", "JOURNEE"].map((p) => periodConfig(1, p)) });
  }
  if (siteId === 2) {
    return Promise.resolve({ items: [periodConfig(2, "MATIN")] });
  }
  return Promise.resolve({ items: [] });
});

vi.mock("../api/planningV2.api", () => ({
  getShiftPeriods: (siteId: number) => getShiftPeriodsMock(siteId),
}));

function renderDialog(onSubmit = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <PostFormDialog
        open
        onClose={() => {}}
        onSubmit={onSubmit}
        submitting={false}
        sites={sites}
        surgeons={surgeons}
        instrumentists={instrumentists}
      />
    </QueryClientProvider>
  );
  return { onSubmit };
}

// SearchableSelect's label is a plain <label> with no `for` attribute (MUI Autocomplete
// generates its own input id), so getByLabelText can't associate them — find the visible
// label text instead and look up its sibling combobox within the same wrapper Box.
async function pickAutocomplete(user: ReturnType<typeof userEvent.setup>, labelText: string, optionText: string) {
  const label = screen.getByText(labelText);
  const container = label.closest("div");
  if (!container) throw new Error(`No container found for label "${labelText}"`);
  const input = within(container).getByRole("combobox");
  await user.click(input);
  await user.click(await screen.findByText(optionText));
}

describe("PostFormDialog — récurrence mensuelle (Batch 14C)", () => {
  // Same reasoning as the third test below (see its comment for the profiling detail):
  // 3 Autocomplete picks + 1 toggle click, each carrying a real ~90-175ms Popper/
  // accessible-name cost in this dialog's DOM under CPU contention. Confirmed failing
  // under full-suite load (5000ms default) even though this is the lightest of the
  // three monthly-recurrence tests — full-suite contention here was heavier than the
  // 6-10-busy-process scenario originally profiled (this run coincided with a ~3+
  // minute backend PHPUnit suite on the same host). Same fix: an explicit, generous,
  // justified timeout on this one test — not a global Vitest setting.
  it("désactive l'enregistrement tant qu'aucun jour de la semaine n'est sélectionné en mode mensuel", async () => {
    const user = userEvent.setup();
    renderDialog();

    await pickAutocomplete(user, "Chirurgien", "Dr Martin");
    await pickAutocomplete(user, "Site", "Alpha");
    await pickAutocomplete(user, "Récurrence", "Certains jours du mois");

    // Deselect the default weekday (Lundi) without picking another one.
    await user.click(screen.getByRole("button", { name: "Lundi" }));

    expect(screen.getByRole("button", { name: /Enregistrer le poste/i })).toBeDisabled();
  }, 15000);

  it("désactive l'enregistrement tant qu'aucune occurrence (1er..5e) n'est sélectionnée en mode mensuel", async () => {
    const user = userEvent.setup();
    renderDialog();

    await pickAutocomplete(user, "Chirurgien", "Dr Martin");
    await pickAutocomplete(user, "Site", "Alpha");
    await pickAutocomplete(user, "Récurrence", "Certains jours du mois");

    // Deselect the default occurrence (1er) without picking another one.
    await user.click(screen.getByRole("button", { name: "1er" }));
    await user.click(screen.getByRole("button", { name: "Jeudi" }));

    expect(screen.getByRole("button", { name: /Enregistrer le poste/i })).toBeDisabled();
  }, 15000);

  // RC1-F: this test does 9 real userEvent interactions (3 MUI Autocomplete picks + 5 toggle
  // clicks + 1 submit) — roughly double test 1 (4) and test 2 (5) above. Profiling showed each
  // getByRole('button', {name}) lookup costs ~120-175ms in this dialog's DOM (Testing Library's
  // accessible-name computation over MUI's verbose markup) and each Autocomplete open/select
  // costs ~90-175ms (Popper/Portal mount) — a real, measured cost, not a hang or leaked
  // promise: the test consistently completes in 1.8-2.8s on a quiet machine. Under directly-
  // induced CPU contention (6-10 busy processes) the same run reproducibly took 3.6-4.6s, and
  // it was observed at 5.24s in an earlier CI-style run — this test's legitimate workload sits
  // too close to Vitest's 5000ms default for this environment's realistic background load
  // (this repo regularly runs alongside a multi-container Docker stack + local MySQL). Given
  // 15s, it passed 100% of repeated runs including under artificial contention.
  it("envoie weekdays + monthWeeks, sans monthlyNthWeekday, pour '2e et 3e jeudi du mois'", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderDialog();

    await pickAutocomplete(user, "Chirurgien", "Dr Martin");
    await pickAutocomplete(user, "Site", "Alpha");
    await pickAutocomplete(user, "Récurrence", "Certains jours du mois");

    // Default occurrence is [1er] — deselect it, then pick 2e and 3e.
    await user.click(screen.getByRole("button", { name: "1er" }));
    await user.click(screen.getByRole("button", { name: "2e" }));
    await user.click(screen.getByRole("button", { name: "3e" }));

    // Default weekday is [Lundi] — deselect it, then pick Jeudi.
    await user.click(screen.getByRole("button", { name: "Lundi" }));
    await user.click(screen.getByRole("button", { name: "Jeudi" }));

    const submit = screen.getByRole("button", { name: /Enregistrer le poste/i });
    expect(submit).toBeEnabled();
    await user.click(submit);

    expect(onSubmit).toHaveBeenCalledTimes(1);
    const payload = onSubmit.mock.calls[0][0];
    expect(payload.recurrence).toEqual({
      frequency: "MONTHLY",
      interval: 1,
      weekdays: [4],
      monthWeeks: [2, 3],
      anchorDate: payload.startDate,
    });
    expect(payload.recurrence).not.toHaveProperty("monthlyNthWeekday");
  }, 15000);
});

describe("PostFormDialog — périodes filtrées par site", () => {
  it("ne propose que les périodes configurées et actives pour le site sélectionné", async () => {
    const user = userEvent.setup();
    renderDialog();

    await pickAutocomplete(user, "Site", "Beta (matin seul)");

    expect(await screen.findByRole("button", { name: /Matin/ })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Après-midi/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Journée/ })).not.toBeInTheDocument();
  });

  it("bascule automatiquement sur une période disponible si celle sélectionnée ne l'est plus", async () => {
    const user = userEvent.setup();
    renderDialog();

    // Default period is MATIN, which Beta *does* have — switch to a site where it doesn't.
    await pickAutocomplete(user, "Site", "Beta (matin seul)");
    expect(await screen.findByRole("button", { name: /Matin/, pressed: true })).toBeInTheDocument();
  });

  it("affiche un avertissement et désactive l'enregistrement quand le site n'a aucune période active", async () => {
    const user = userEvent.setup();
    renderDialog();

    await pickAutocomplete(user, "Chirurgien", "Dr Martin");
    await pickAutocomplete(user, "Site", "Gamma (aucune période)");

    expect(
      await screen.findByText(/Aucune période active configurée pour Gamma/),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Enregistrer le poste/i })).toBeDisabled();
  });
});
