import { useEffect, useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  activateInstrumentist,
  deleteSiteMembership,
  getInstrumentist,
  suspendInstrumentist,
} from "../api/instrumentists.api";
import type {
  InstrumentistDetailDTO,
  SiteMembershipDTO,
} from "../api/instrumentists.types";
import { useToast } from "../../../ui/toast/useToast";
import {
  buildDisplayName,
  extractErrorMessage,
  mergeMembershipsBySiteId,
} from "../utils/instrumentists.utils";

export type SectionKey =
  | "information"
  | "sites"
  | "rates"
  | "status"
  | "competences"
  | "planning";

export function useInstrumentistDrawer(
  instrumentistId: number | null,
  drawerOpen: boolean,
) {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [activeSection, setActiveSection] = useState<SectionKey>("information");
  const [addSiteOpen, setAddSiteOpen] = useState(false);
  const [membershipToDelete, setMembershipToDelete] =
    useState<SiteMembershipDTO | null>(null);
  const [displayedMemberships, setDisplayedMemberships] = useState<
    SiteMembershipDTO[]
  >([]);

  const informationSectionRef = useRef<HTMLDivElement | null>(null);
  const sitesSectionRef = useRef<HTMLDivElement | null>(null);
  const ratesSectionRef = useRef<HTMLDivElement | null>(null);
  const statusSectionRef = useRef<HTMLDivElement | null>(null);
  const competencesSectionRef = useRef<HTMLDivElement | null>(null);
  const planningSectionRef = useRef<HTMLDivElement | null>(null);

  const {
    data: instrumentist,
    isLoading,
    isError,
    refetch,
  } = useQuery<InstrumentistDetailDTO>({
    queryKey: ["instrumentist-detail", instrumentistId],
    queryFn: () => getInstrumentist(instrumentistId as number),
    enabled: drawerOpen && instrumentistId !== null,
  });

  useEffect(() => {
    setDisplayedMemberships([]);
    setMembershipToDelete(null);
  }, [instrumentistId]);

  useEffect(() => {
    const backendMemberships = instrumentist?.siteMemberships ?? [];

    setDisplayedMemberships((current) =>
      mergeMembershipsBySiteId([...current, ...backendMemberships]),
    );
  }, [instrumentist?.siteMemberships]);

  useEffect(() => {
    if (!drawerOpen) {
      return;
    }

    setActiveSection("information");
  }, [drawerOpen, instrumentistId]);

  const refreshInstrumentistDetail = async () => {
    if (instrumentistId === null) {
      return;
    }

    await queryClient.invalidateQueries({
      queryKey: ["instrumentist-detail", instrumentistId],
    });

    await queryClient.invalidateQueries({
      queryKey: ["instrumentists"],
    });
  };

  const handleSetDisplayedMemberships = (
    nextMemberships: SiteMembershipDTO[],
  ) => {
    setDisplayedMemberships(mergeMembershipsBySiteId(nextMemberships));
  };

  const scrollToSection = (
    sectionKey: SectionKey,
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
    mutationFn: async (membership: SiteMembershipDTO) => {
      if (instrumentistId === null) {
        throw new Error("Instrumentiste introuvable.");
      }

      if (membership.id < 0) {
        return { id: membership.id, deleted: true as const };
      }

      return deleteSiteMembership(instrumentistId, membership.id);
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
        await refreshInstrumentistDetail();
      }
    },
    onError: (err: any, _membership, context) => {
      if (context?.previousMemberships) {
        setDisplayedMemberships(context.previousMemberships);
      }

      toast.error(extractErrorMessage(err));
    },
  });

  const statusMutation = useMutation({
    mutationFn: async (currentlyActive: boolean) => {
      if (instrumentistId === null) {
        throw new Error("Instrumentiste introuvable.");
      }

      return currentlyActive
        ? suspendInstrumentist(instrumentistId)
        : activateInstrumentist(instrumentistId);
    },
    onSuccess: (result) => {
      queryClient.setQueryData<InstrumentistDetailDTO | undefined>(
        ["instrumentist-detail", instrumentistId],
        (current) => (current ? { ...current, active: result.active } : current),
      );

      queryClient.invalidateQueries({ queryKey: ["instrumentists"] });

      toast.success(result.active ? "Instrumentiste réactivé." : "Instrumentiste suspendu.");
    },
    onError: (err: any) => {
      toast.error(extractErrorMessage(err));
    },
  });

  const headerDisplayName = useMemo(
    () => buildDisplayName(instrumentist),
    [instrumentist],
  );

  return {
    instrumentist,
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
    refreshInstrumentistDetail,

    statusMutation,

    informationSectionRef,
    sitesSectionRef,
    ratesSectionRef,
    statusSectionRef,
    competencesSectionRef,
    planningSectionRef,
  };
}
