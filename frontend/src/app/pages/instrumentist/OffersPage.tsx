import * as React from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CircularProgress, Stack, Typography } from "@mui/material";
import { Navigate, useNavigate } from "react-router-dom";

import {
  fetchInstrumentistOffersWithFallback,
  claimMission,
} from "../../features/missions/api/missions.api";
import type { Mission } from "../../features/missions/api/missions.types";
import MissionCardMobile from "../../features/missions/components/MissionCardMobile";
import { useToast } from "../../ui/toast/useToast";
import { useAuth } from "../../auth/AuthContext";
import { isMobileRole } from "../../auth/roles";

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
  const navigate = useNavigate();
  const { state } = useAuth();

  // ✅ Failsafe : cette page est instrumentiste only
  if (state.status !== "authenticated") {
    return <Navigate to="/login" replace />;
  }
  const role = state.user.role;
  if (!isMobileRole(role) || role !== "INSTRUMENTIST") {
    return <Navigate to="/app/m/missions" replace />;
  }

  const [loadingClaimId, setLoadingClaimId] = React.useState<number | null>(
    null,
  );

  const { data, isLoading, isFetching, error } = useQuery({
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
      } else if (status === 403) {
        // ✅ Si jamais ça arrive (token/rôle incohérent), on sort proprement
        toast.error("Accès refusé");
        navigate("/app/m/missions", { replace: true });
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
