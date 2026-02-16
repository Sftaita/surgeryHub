import * as React from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CircularProgress, Stack, Typography } from "@mui/material";

import {
  fetchInstrumentistOffersWithFallback,
  claimMission,
} from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import MissionCardMobile from "../../features/missions/components/MissionCardMobile";
import { useToast } from "../../ui/toast/useToast";

function extractErrorMessage(err: any): string {
  return (
    err?.response?.data?.message ??
    err?.response?.data?.detail ??
    err?.message ??
    String(err)
  );
}

export default function OffersPage() {
  const toast = useToast();
  const queryClient = useQueryClient();

  const [loadingClaimId, setLoadingClaimId] = React.useState<number | null>(
    null,
  );

  const { data, isLoading, isFetching } = useQuery({
    queryKey: ["missions", "offers"],
    queryFn: () => fetchInstrumentistOffersWithFallback(1, 100),
  });

  const missions = data?.items ?? [];

  const handleClaim = async (mission: Mission) => {
    if (loadingClaimId !== null) return;

    setLoadingClaimId(mission.id);
    try {
      await claimMission(mission.id);
      toast.success("Mission attribuée");
      queryClient.invalidateQueries({ queryKey: ["missions"] });
    } catch (err: any) {
      const status = err?.response?.status;

      if (status === 409) {
        toast.warning(extractErrorMessage(err));
      } else {
        toast.error(extractErrorMessage(err));
      }

      queryClient.invalidateQueries({ queryKey: ["missions"] });
    } finally {
      setLoadingClaimId(null);
    }
  };

  if (isLoading) return <CircularProgress />;

  return (
    <Stack spacing={2}>
      <Typography variant="h6">
        Offres {isFetching ? "(actualisation…)" : ""}
      </Typography>

      {missions.length === 0 && (
        <Typography>Aucune mission disponible</Typography>
      )}

      {missions.map((m) => {
        const canClaim = m.allowedActions?.includes("claim") ?? false;
        const loading = loadingClaimId === m.id;

        return (
          <MissionCardMobile
            key={m.id}
            mission={m}
            primaryAction={{
              label: "CLAIM",
              action: () => handleClaim(m),
              visible: canClaim,
              loading,
              disabled: loadingClaimId !== null && !loading,
            }}
          />
        );
      })}
    </Stack>
  );
}
