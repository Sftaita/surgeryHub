import {
  Box, Chip, CircularProgress, Divider,
  Paper, Stack, Typography,
} from "@mui/material";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "../../api/apiClient";
import { uploadProfilePicture } from "../../features/me/api/me.api";
import { useAuth } from "../../auth/AuthContext";
import { useToast } from "../../ui/toast/useToast";
import { AvatarUploader } from "../../ui/avatar/AvatarUploader";
import { resolveApiAssetUrl } from "../../api/apiAssetUrl";

const ORTHO_SPECIALTIES = [
  { value: "EPAULE", label: "Épaule" },
  { value: "GENOU",  label: "Genou" },
  { value: "HANCHE", label: "Hanche" },
  { value: "RACHIS", label: "Colonne" },
  { value: "MAIN",   label: "Main" },
  { value: "PIED",   label: "Pied" },
];

async function fetchMe() {
  const res = await apiClient.get("/api/me");
  return res.data;
}

async function patchSpecialties(userId: number, specialties: string[]) {
  await apiClient.patch(`/api/users/${userId}/specialties`, { specialties });
}

export default function ProfilePage() {
  const { state, refreshUser } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();

  const userId = state.status === "authenticated" ? state.user.id : null;

  const meQuery = useQuery({ queryKey: ["me"], queryFn: fetchMe });
  const profile = meQuery.data?.instrumentistProfile ?? null;
  const specialties: string[] = profile?.specialties ?? [];

  const mutation = useMutation({
    mutationFn: (next: string[]) => patchSpecialties(userId!, next),
    onSuccess: () => {
      toast.success("Compétences mises à jour");
      qc.invalidateQueries({ queryKey: ["me"] });
    },
    onError: () => toast.error("Erreur lors de la sauvegarde"),
  });

  const photoMutation = useMutation({
    mutationFn: (file: File) => uploadProfilePicture(file),
    onSuccess: async () => {
      toast.success("Photo de profil mise à jour");
      qc.invalidateQueries({ queryKey: ["me"] });
      await refreshUser();
    },
  });

  function toggle(value: string) {
    const next = specialties.includes(value)
      ? specialties.filter((s) => s !== value)
      : [...specialties, value];
    mutation.mutate(next);
  }

  if (meQuery.isLoading) {
    return (
      <Box sx={{ display: "flex", justifyContent: "center", pt: 6 }}>
        <CircularProgress size={28} />
      </Box>
    );
  }

  const displayName = profile
    ? [profile.firstname, profile.lastname].filter(Boolean).join(" ") || profile.email
    : (state.status === "authenticated" ? state.user.firstname ?? "Profil" : "Profil");

  return (
    <Stack spacing={2.5} sx={{ maxWidth: 480, mx: "auto" }}>
      {/* Carte identité */}
      <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
        <Stack direction="row" spacing={2} alignItems="center">
          <AvatarUploader
            name={displayName}
            photoUrl={resolveApiAssetUrl(profile?.profilePicturePath)}
            size="lg"
            onFileReady={async (file) => { await photoMutation.mutateAsync(file); }}
          />
          <Box>
            <Typography variant="h6" fontWeight={700} sx={{ lineHeight: 1.2 }}>
              {displayName}
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {profile?.email ?? ""}
            </Typography>
            <Chip
              size="small"
              label={profile?.active ? "Actif" : "Suspendu"}
              color={profile?.active ? "success" : "default"}
              variant="filled"
              sx={{ mt: 0.5, fontSize: "0.7rem" }}
            />
          </Box>
        </Stack>
      </Paper>

      <Divider />

      {/* Compétences */}
      <Box>
        <Typography variant="subtitle1" fontWeight={700} mb={0.5}>
          Mes compétences orthopédiques
        </Typography>
        <Typography variant="body2" color="text.secondary" mb={2}>
          Sélectionnez les spécialités que vous maîtrisez. Ces informations sont utilisées pour les suggestions de planning.
        </Typography>

        <Stack direction="row" flexWrap="wrap" gap={1.25}>
          {ORTHO_SPECIALTIES.map(({ value, label }) => {
            const active = specialties.includes(value);
            return (
              <Chip
                key={value}
                label={label}
                size="medium"
                color={active ? "primary" : "default"}
                variant={active ? "filled" : "outlined"}
                onClick={() => toggle(value)}
                disabled={mutation.isPending}
                sx={{ cursor: "pointer", fontWeight: active ? 600 : 400 }}
              />
            );
          })}
        </Stack>

        {mutation.isPending && (
          <Stack direction="row" spacing={1} alignItems="center" mt={1.5}>
            <CircularProgress size={14} />
            <Typography variant="caption" color="text.secondary">Enregistrement…</Typography>
          </Stack>
        )}
      </Box>
    </Stack>
  );
}
