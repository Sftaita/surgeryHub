import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getSurgeon,
  addSurgeonSiteMembership,
  deleteSurgeonSiteMembership,
} from "../api/surgeons.api";
import type {
  SurgeonProfileDTO,
  SurgeonSiteMembershipDTO,
} from "../api/surgeons.types";
import { useToast } from "../../../ui/toast/useToast";

export type SurgeonSectionKey = "information" | "sites" | "planning";

function mergeSurgeonMembershipsBySiteId(
  memberships: SurgeonSiteMembershipDTO[],
): SurgeonSiteMembershipDTO[] {
  const map = new Map<number, SurgeonSiteMembershipDTO>();

  for (const membership of memberships) {
    const existing = map.get(membership.site.id);

    if (!existing) {
      map.set(membership.site.id, membership);
      continue;
    }

    const existingIsOptimistic = existing.id < 0;
    const currentIsConfirmed = membership.id > 0;

    if (existingIsOptimistic && currentIsConfirmed) {
      map.set(membership.site.id, membership);
    }
  }

  return Array.from(map.values()).sort((a, b) =>
    a.site.name.localeCompare(b.site.name, "fr", { sensitivity: "base" }),
  );
}

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.error?.message ??
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    "Une erreur est survenue."
  );
}

export function useSurgeonDrawer(
  surgeonId: number | null,
  drawerOpen: boolean,
) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [activeSection, setActiveSection] =
    useState<SurgeonSectionKey>("information");
  const [addSiteOpen, setAddSiteOpen] = useState(false);
  const [membershipToDelete, setMembershipToDelete] =
    useState<SurgeonSiteMembershipDTO | null>(null);
  const [displayedMemberships, setDisplayedMemberships] = useState<
    SurgeonSiteMembershipDTO[]
  >([]);

  const informationSectionRef = useRef<HTMLDivElement | null>(null);
  const sitesSectionRef = useRef<HTMLDivElement | null>(null);
  const planningSectionRef = useRef<HTMLDivElement | null>(null);

  const {
    data: surgeon,
    isLoading,
    isError,
    refetch,
  } = useQuery<SurgeonProfileDTO>({
    queryKey: ["surgeon-detail", surgeonId],
    queryFn: () => getSurgeon(surgeonId as number),
    enabled: drawerOpen && surgeonId !== null,
  });

  useEffect(() => {
    setDisplayedMemberships([]);
    setMembershipToDelete(null);
  }, [surgeonId]);

  useEffect(() => {
    const backendMemberships = surgeon?.siteMemberships ?? [];

    setDisplayedMemberships((current) =>
      mergeSurgeonMembershipsBySiteId([...current, ...backendMemberships]),
    );
  }, [surgeon?.siteMemberships]);

  useEffect(() => {
    if (!drawerOpen) {
      return;
    }

    setActiveSection("information");
  }, [drawerOpen, surgeonId]);

  const refreshSurgeonDetail = async () => {
    if (surgeonId === null) {
      return;
    }

    await queryClient.invalidateQueries({
      queryKey: ["surgeon-detail", surgeonId],
    });

    await queryClient.invalidateQueries({
      queryKey: ["surgeons"],
    });
  };

  const handleSetDisplayedMemberships = (
    nextMemberships: SurgeonSiteMembershipDTO[],
  ) => {
    setDisplayedMemberships(mergeSurgeonMembershipsBySiteId(nextMemberships));
  };

  const scrollToSection = (
    sectionKey: SurgeonSectionKey,
    section: HTMLDivElement | null,
  ) => {
    if (!section) {
      return;
    }

    setActiveSection(sectionKey);

    section.scrollIntoView({
      behavior: "smooth",
      block: "start",
    });
  };

  const deleteMembershipMutation = useMutation({
    mutationFn: async (membership: SurgeonSiteMembershipDTO) => {
      if (surgeonId === null) {
        throw new Error("Chirurgien introuvable.");
      }

      if (membership.id < 0) {
        return;
      }

      return deleteSurgeonSiteMembership(surgeonId, membership.id);
    },
    onMutate: async (membership) => {
      setMembershipToDelete(null);

      const previousMemberships = displayedMemberships;

      setDisplayedMemberships((current) =>
        current.filter((item) => item.site.id !== membership.site.id),
      );

      return {
        previousMemberships,
        wasOptimistic: membership.id < 0,
      };
    },
    onSuccess: async (_response, _membership, context) => {
      toast.success("Affiliation retirée.");

      if (!context?.wasOptimistic) {
        await refreshSurgeonDetail();
      }
    },
    onError: (err: any, _membership, context) => {
      if (context?.previousMemberships) {
        setDisplayedMemberships(context.previousMemberships);
      }

      toast.error(extractErrorMessage(err));
    },
  });

  const addMembershipMutation = useMutation({
    mutationFn: async ({
      siteId,
      siteName,
    }: {
      siteId: number;
      siteName: string;
    }) => {
      if (surgeonId === null) {
        throw new Error("Chirurgien introuvable.");
      }

      const optimistic: SurgeonSiteMembershipDTO = {
        id: -Date.now(),
        site: { id: siteId, name: siteName },
        siteRole: "SURGEON",
      };

      const previousMemberships = displayedMemberships;

      setDisplayedMemberships((current) =>
        mergeSurgeonMembershipsBySiteId([...current, optimistic]),
      );

      try {
        const created = await addSurgeonSiteMembership(surgeonId, siteId);
        return { created, optimistic, previousMemberships };
      } catch (err) {
        throw { originalError: err, previousMemberships };
      }
    },
    onSuccess: async ({ created, optimistic, previousMemberships }) => {
      setDisplayedMemberships(
        mergeSurgeonMembershipsBySiteId([
          ...previousMemberships.filter((m) => m.id !== optimistic.id),
          created,
        ]),
      );

      toast.success("Site ajouté.");
      await refreshSurgeonDetail();
    },
    onError: (wrappedErr: any, _vars, _ctx) => {
      const err = wrappedErr?.originalError ?? wrappedErr;
      const previousMemberships: SurgeonSiteMembershipDTO[] =
        wrappedErr?.previousMemberships ?? displayedMemberships;

      setDisplayedMemberships(previousMemberships);
      toast.error(extractErrorMessage(err));
    },
  });

  const headerDisplayName = surgeon?.displayName?.trim()
    ? surgeon.displayName
    : [surgeon?.firstname, surgeon?.lastname]
        .filter((v): v is string => Boolean(v && v.trim()))
        .join(" ") || "—";

  return {
    surgeon: surgeon ?? null,
    isLoading,
    isError,
    refetch,
    headerDisplayName,

    activeSection,
    scrollToSection,

    addSiteOpen,
    setAddSiteOpen,

    membershipToDelete,
    setMembershipToDelete,

    displayedMemberships,
    handleSetDisplayedMemberships,

    deleteMembershipMutation,
    addMembershipMutation,
    refreshSurgeonDetail,

    informationSectionRef,
    sitesSectionRef,
    planningSectionRef,
  };
}
