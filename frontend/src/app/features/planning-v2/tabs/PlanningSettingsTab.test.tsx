import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { PlanningSettingsTab } from "./PlanningSettingsTab";

vi.mock("../components/ShiftPeriodSettings", () => ({ ShiftPeriodSettings: () => <div>ShiftPeriodSettings</div> }));
vi.mock("../components/SiteGroupSettings", () => ({ SiteGroupSettings: () => <div>SiteGroupSettings</div> }));
vi.mock("../components/AbsenceCommunicationSettings", () => ({ AbsenceCommunicationSettings: () => <div>AbsenceCommunicationSettings-stub</div> }));
vi.mock("../components/AbsenceCommunicationJournal", () => ({ AbsenceCommunicationJournal: () => <div>AbsenceCommunicationJournal-stub</div> }));

function renderTab() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <PlanningSettingsTab />
    </QueryClientProvider>,
  );
}

describe("PlanningSettingsTab — sous-onglets Communication des absences (Lot C, D-114)", () => {
  it("affiche Configuration par défaut", async () => {
    renderTab();

    await userEvent.click(screen.getByText("Communication des absences"));

    expect(await screen.findByText("AbsenceCommunicationSettings-stub")).toBeInTheDocument();
    expect(screen.queryByText("AbsenceCommunicationJournal-stub")).not.toBeInTheDocument();
  });

  it("bascule vers Journal et inversement", async () => {
    renderTab();
    await userEvent.click(screen.getByText("Communication des absences"));
    await screen.findByText("AbsenceCommunicationSettings-stub");

    await userEvent.click(screen.getByText("Journal"));
    expect(await screen.findByText("AbsenceCommunicationJournal-stub")).toBeInTheDocument();
    expect(screen.queryByText("AbsenceCommunicationSettings-stub")).not.toBeInTheDocument();

    await userEvent.click(screen.getByText("Configuration"));
    expect(await screen.findByText("AbsenceCommunicationSettings-stub")).toBeInTheDocument();
  });
});
