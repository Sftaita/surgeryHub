import { useEffect, useMemo, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  activateInstrumentist,
  deleteSiteMembership,
  getInstrumentist,
  suspendInstrumentist,
  updateInstrumentistRates,
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
  normalizeRateValue,
  parseRateInput,
} from "../utils/instrumentists.utils";

export type SectionKey =
  | "information"
  | "sites"
  | "rates"
  | "status"
  | "planning";

export type RatesFeedback =
  | { type: "idle"; message: string }
  | { type: "info"; message: string }
  | { type: "success"; message: string }
  | { type: "error"; message: string };

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
  const [hourlyRateInput, setHourlyRateInput] = useState("");
  const [consultationFeeInput, setConsultationFeeInput] = useState("");
  const [ratesFeedback, setRatesFeedback] = useState<RatesFeedback>({
    type: "idle",
    message: "",
  });

  const informationSectionRef = useRef<HTMLDivElement | null>(null);
  const sitesSectionRef = useRef<HTMLDivElement | null>(null);
  const ratesSectionRef = useRef<HTMLDivElement | null>(null);
  const statusSectionRef = useRef<HTMLDivElement | null>(null);
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
    setHourlyRateInput(normalizeRateValue(instrumentist?.hourlyRate));
    setConsultationFeeInput(normalizeRateValue(instrumentist?.consultationFee));
    setRatesFeedback({ type: "idle", message: "" });
  }, [
    instrumentist?.id,
    instrumentist?.hourlyRate,
    instrumentist?.consultationFee,
  ]);

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

  const ratesMutation = useMutation({
    mutationFn: async (payload: {
      hourlyRate?: number;
      consultationFee?: number;
    }) => {
      if (instrumentistId === null) {
        throw new Error("Instrumentiste introuvable.");
      }

      return updateInstrumentistRates(instrumentistId, payload);
    },
    onMutate: () => {
      setRatesFeedback({
        type: "info",
        message: "Enregistrement en cours…",
      });
    },
    onSuccess: async (updatedRates) => {
      if (instrumentistId === null) {
        return;
      }

      queryClient.setQueryData<InstrumentistDetailDTO | undefined>(
        ["instrumentist-detail", instrumentistId],
        (current) => {
          if (!current) {
            return current;
          }

          return {
            ...current,
            hourlyRate: updatedRates.hourlyRate,
            consultationFee: updatedRates.consultationFee,
          };
        },
      );

      setHourlyRateInput(normalizeRateValue(updatedRates.hourlyRate));
      setConsultationFeeInput(normalizeRateValue(updatedRates.consultationFee));
      setRatesFeedback({
        type: "success",
        message: "Tarifs enregistrés.",
      });

      await queryClient.invalidateQueries({
        queryKey: ["instrumentist-detail", instrumentistId],
      });
    },
    onError: (err: any) => {
      setRatesFeedback({
        type: "error",
        message: extractErrorMessage(err),
      });
    },
  });

  const handleSaveRates = async () => {
    if (!instrumentist || instrumentistId === null) {
      setRatesFeedback({
        type: "error",
        message: "Instrumentiste introuvable.",
      });
      return;
    }

    const initialHourlyRate = normalizeRateValue(instrumentist.hourlyRate);
    const initialConsultationFee = normalizeRateValue(
      instrumentist.consultationFee,
    );

    const nextHourlyRate = hourlyRateInput.trim();
    const nextConsultationFee = consultationFeeInput.trim();

    const payload: {
      hourlyRate?: number;
      consultationFee?: number;
    } = {};

    if (nextHourlyRate !== initialHourlyRate.trim()) {
      const parsedHourlyRate = parseRateInput(hourlyRateInput);

      if (parsedHourlyRate === null) {
        setRatesFeedback({
          type: "error",
          message: "Le tarif bloc doit être un nombre valide.",
        });
        return;
      }

      payload.hourlyRate = parsedHourlyRate;
    }

    if (nextConsultationFee !== initialConsultationFee.trim()) {
      const parsedConsultationFee = parseRateInput(consultationFeeInput);

      if (parsedConsultationFee === null) {
        setRatesFeedback({
          type: "error",
          message: "Le tarif consultation doit être un nombre valide.",
        });
        return;
      }

      payload.consultationFee = parsedConsultationFee;
    }

    if (Object.keys(payload).length === 0) {
      setRatesFeedback({
        type: "info",
        message: "Aucune modification à enregistrer.",
      });
      return;
    }

    await ratesMutation.mutateAsync(payload);
  };

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

    hourlyRateInput,
    setHourlyRateInput,
    consultationFeeInput,
    setConsultationFeeInput,
    ratesFeedback,
    setRatesFeedback,
    ratesMutation,
    handleSaveRates,

    deleteMembershipMutation,
    refreshInstrumentistDetail,

    statusMutation,

    informationSectionRef,
    sitesSectionRef,
    ratesSectionRef,
    statusSectionRef,
    planningSectionRef,
  };
}
