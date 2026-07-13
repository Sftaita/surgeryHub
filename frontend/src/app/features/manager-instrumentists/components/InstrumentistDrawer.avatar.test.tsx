import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { InstrumentistDrawer } from "./InstrumentistDrawer";
import { ToastProvider } from "../../../ui/toast/ToastProvider";

vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");

const baseInstrumentist = {
  id: 7,
  email: "carla@example.com",
  firstname: "Carla",
  lastname: "Silva",
  displayName: "Carla Silva",
  active: true,
  employmentType: null,
  defaultCurrency: "EUR",
  hourlyRate: null,
  consultationFee: null,
  profilePicturePath: "/uploads/profile-pictures/carla.jpg",
  siteMemberships: [],
  specialties: [],
};

vi.mock("../hooks/useInstrumentistDrawer", () => ({
  useInstrumentistDrawer: () => ({
    instrumentist: baseInstrumentist,
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
    headerDisplayName: "Carla Silva",
    activeSection: "information",
    scrollToSection: vi.fn(),
    addSiteOpen: false,
    setAddSiteOpen: vi.fn(),
    membershipToDelete: null,
    setMembershipToDelete: vi.fn(),
    displayedMemberships: [],
    handleSetDisplayedMemberships: vi.fn(),
    hourlyRateInput: "",
    setHourlyRateInput: vi.fn(),
    consultationFeeInput: "",
    setConsultationFeeInput: vi.fn(),
    ratesFeedback: { type: "idle", message: "" },
    setRatesFeedback: vi.fn(),
    ratesMutation: { isPending: false },
    handleSaveRates: vi.fn(),
    deleteMembershipMutation: { isPending: false },
    refreshInstrumentistDetail: vi.fn(),
    statusMutation: { isPending: false, mutate: vi.fn() },
    informationSectionRef: { current: null },
    sitesSectionRef: { current: null },
    ratesSectionRef: { current: null },
    statusSectionRef: { current: null },
    planningSectionRef: { current: null },
    competencesSectionRef: { current: null },
  }),
}));

function renderDrawer() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <InstrumentistDrawer open instrumentistId={7} onClose={() => {}} />
      </ToastProvider>
    </QueryClientProvider>,
  );
}

describe("InstrumentistDrawer — avatar + email", () => {
  it("affiche l'avatar avec la photo de profil dans le header", () => {
    renderDrawer();

    const avatarImg = screen.getAllByRole("img", { name: "Carla Silva" })[0];
    expect(avatarImg).toHaveAttribute(
      "src",
      "https://api.surgicalhub.test/uploads/profile-pictures/carla.jpg",
    );
  });

  it("affiche le bouton Modifier de l'éditeur d'email", () => {
    renderDrawer();

    expect(screen.getByRole("button", { name: "Modifier" })).toBeInTheDocument();
  });
});
