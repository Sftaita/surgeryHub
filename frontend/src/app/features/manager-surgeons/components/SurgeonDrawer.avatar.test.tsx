import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { SurgeonDrawer } from "./SurgeonDrawer";
import { ToastProvider } from "../../../ui/toast/ToastProvider";

vi.stubEnv("VITE_API_BASE_URL", "https://api.surgicalhub.test");

const baseSurgeon = {
  id: 9,
  email: "jean.martin@example.com",
  firstname: "Jean",
  lastname: "Martin",
  displayName: "Jean Martin",
  active: true,
  profilePicturePath: "/uploads/profile-pictures/jean.jpg",
  siteMemberships: [],
};

vi.mock("../hooks/useSurgeonDrawer", () => ({
  useSurgeonDrawer: () => ({
    surgeon: baseSurgeon,
    isLoading: false,
    isError: false,
    refetch: vi.fn(),
    headerDisplayName: "Jean Martin",
    activeSection: "information",
    scrollToSection: vi.fn(),
    addSiteOpen: false,
    setAddSiteOpen: vi.fn(),
    membershipToDelete: null,
    setMembershipToDelete: vi.fn(),
    displayedMemberships: [],
    handleSetDisplayedMemberships: vi.fn(),
    deleteMembershipMutation: { isPending: false },
    addMembershipMutation: { isPending: false, mutate: vi.fn() },
    refreshSurgeonDetail: vi.fn(),
    informationSectionRef: { current: null },
    sitesSectionRef: { current: null },
    planningSectionRef: { current: null },
  }),
}));

function renderDrawer() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <ToastProvider>
        <SurgeonDrawer open surgeonId={9} onClose={() => {}} />
      </ToastProvider>
    </QueryClientProvider>,
  );
}

describe("SurgeonDrawer — avatar + email", () => {
  it("affiche l'avatar avec la photo de profil dans le header", () => {
    renderDrawer();

    const avatarImg = screen.getAllByRole("img", { name: "Jean Martin" })[0];
    expect(avatarImg).toHaveAttribute(
      "src",
      "https://api.surgicalhub.test/uploads/profile-pictures/jean.jpg",
    );
  });

  it("affiche le bouton Modifier de l'éditeur d'email", () => {
    renderDrawer();

    expect(screen.getByRole("button", { name: "Modifier" })).toBeInTheDocument();
  });
});
