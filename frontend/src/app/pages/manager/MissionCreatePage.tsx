import { useMemo } from "react";
import { useNavigate } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Box,
  Button,
  CircularProgress,
  Stack,
  Typography,
} from "@mui/material";

import MissionCreateWizard from "../../features/missions/components/MissionCreateWizard";
import {
  fetchSites,
  fetchSurgeons,
} from "../../features/missions/api/missions.api";
import type {
  SiteListItem,
  UserListItem,
} from "../../features/missions/api/missions.types";

import { useToast } from "../../ui/toast/useToast";

type SiteOption = { id: number; name: string };
type UserOption = { id: number; label: string };

function toUserLabel(u: UserListItem): string {
  const dn = (u.displayName ?? "").trim();
  if (dn) return dn;

  const fn = (u.firstname ?? "").trim();
  const ln = (u.lastname ?? "").trim();
  const full = `${fn} ${ln}`.trim();
  return full || u.email || `User #${u.id}`;
}

export default function MissionCreatePage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const toast = useToast();

  const sitesQ = useQuery({
    queryKey: ["sites"],
    queryFn: () => fetchSites(),
  });

  const surgeonsQ = useQuery({
    queryKey: ["surgeons", { page: 1, limit: 200 }],
    queryFn: () => fetchSurgeons(1, 200),
  });

  const loading = sitesQ.isLoading || surgeonsQ.isLoading;
  const isError = sitesQ.isError || surgeonsQ.isError;

  const sites: SiteOption[] = useMemo(() => {
    const raw: SiteListItem[] = sitesQ.data ?? [];
    return raw
      .map((s) => ({
        id: Number(s.id),
        name: String(s.name ?? `Site #${s.id}`),
      }))
      .filter((s) => Number.isFinite(s.id));
  }, [sitesQ.data]);

  const surgeons: UserOption[] = useMemo(() => {
    const raw = surgeonsQ.data?.items ?? [];
    return raw
      .map((u) => ({ id: Number(u.id), label: toUserLabel(u) }))
      .filter((u) => Number.isFinite(u.id));
  }, [surgeonsQ.data]);

  return (
    <Box sx={{ p: 2, maxWidth: 900 }}>
      <Stack
        direction="row"
        justifyContent="space-between"
        alignItems="center"
        gap={2}
      >
        <Typography variant="h6">Créer une mission</Typography>
        <Button variant="outlined" onClick={() => navigate("/app/m/missions")}>
          Retour
        </Button>
      </Stack>

      {loading ? (
        <Stack direction="row" alignItems="center" spacing={1.5} sx={{ mt: 3 }}>
          <CircularProgress size={18} />
          <Typography>Chargement…</Typography>
        </Stack>
      ) : null}

      {isError ? (
        <Box sx={{ mt: 3 }}>
          <Typography color="error">
            Impossible de charger les listes (sites / chirurgiens).
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
            Vérifie que les endpoints /api/sites et /api/surgeons sont
            accessibles avec le token MANAGER/ADMIN.
          </Typography>
        </Box>
      ) : null}

      {!loading && !isError ? (
        <MissionCreateWizard
          sites={sites}
          surgeons={surgeons}
          onCancel={() => navigate("/app/m/missions")}
          onDone={({ mode }) => {
            // Rafraîchir toute la liste (incluant les variations de queryKey)
            queryClient.invalidateQueries({
              queryKey: ["missions"],
              exact: false,
            });

            // Toast après succès (le ToastProvider étant global, il survit à la navigation)
            toast.success(
              mode === "DRAFT"
                ? "Mission enregistrée en brouillon."
                : "Mission créée et publiée."
            );

            // Lot 2b: retour liste dans tous les cas
            navigate("/app/m/missions");
          }}
        />
      ) : null}
    </Box>
  );
}
