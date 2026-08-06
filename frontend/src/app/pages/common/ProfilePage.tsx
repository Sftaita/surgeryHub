import {
  Box, Chip, CircularProgress, Divider,
  Paper, Stack, Typography,
} from "@mui/material";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "../../api/apiClient";
import { fetchMe, uploadProfilePicture } from "../../features/me/api/me.api";
import { useAuth } from "../../auth/AuthContext";
import { useToast } from "../../ui/toast/useToast";
import { AvatarUploader } from "../../ui/avatar/AvatarUploader";
import { resolveApiAssetUrl } from "../../api/apiAssetUrl";
import { PwaInstallMenuItem } from "../../features/pwa-install/PwaInstallMenuItem";
import { PushPermissionCard } from "../../features/push/PushPermissionCard";
import { NotificationPreferencesSection } from "../../features/notifications/NotificationPreferencesSection";
import { EmptyState } from "../../ui/EmptyState";
import { useInstrumentistOnboardingReplay } from "../../features/instrumentist-onboarding/InstrumentistOnboardingReplayContext";

const ROLE_LABEL: Record<string, string> = {
  INSTRUMENTIST: "Instrumentiste",
  SURGEON: "Chirurgien",
  MANAGER: "Manager",
  ADMIN: "Administrateur",
};

const ORTHO_SPECIALTIES = [
  { value: "EPAULE", label: "Épaule" },
  { value: "GENOU",  label: "Genou" },
  { value: "HANCHE", label: "Hanche" },
  { value: "RACHIS", label: "Colonne" },
  { value: "MAIN",   label: "Main" },
  { value: "PIED",   label: "Pied" },
];

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <Stack direction="row" justifyContent="space-between" alignItems="center">
      <Typography variant="body2" color="text.secondary">
        {label}
      </Typography>
      <Typography variant="body2" fontWeight={600}>
        {value}
      </Typography>
    </Stack>
  );
}

async function patchSpecialties(userId: number, specialties: string[]) {
  await apiClient.patch(`/api/users/${userId}/specialties`, { specialties });
}

/**
 * Page profil partagée — instrumentiste ET chirurgien (socle mobile Lot 1,
 * 2026-08-05). Lit exclusivement les champs racine de `MeResponse` (déjà
 * communs à tous les rôles : id/email/firstname/lastname/phone/
 * profilePictureUrl/sites), jamais `instrumentistProfile` — voir
 * docs/decisions.md pour le principe. La seule section réellement spécifique
 * (compétences orthopédiques + replay onboarding) reste conditionnelle au
 * rôle, jamais un `SurgeonProfilePage` séparé.
 */
export default function ProfilePage() {
  const { state, refreshUser } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const { requestReplay } = useInstrumentistOnboardingReplay();

  const userId = state.status === "authenticated" ? state.user.id : null;

  const meQuery = useQuery({ queryKey: ["me"], queryFn: fetchMe });

  const specialties: string[] = meQuery.data?.instrumentistProfile?.specialties ?? [];

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
    onError: () => toast.error("Impossible de mettre à jour la photo de profil"),
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

  if (meQuery.isError || !meQuery.data) {
    return (
      <EmptyState
        title="Impossible de charger votre profil"
        description="Une erreur est survenue lors du chargement de vos informations."
        action={{ label: "Réessayer", onClick: () => meQuery.refetch() }}
      />
    );
  }

  const user = meQuery.data;
  const isInstrumentist = user.role === "INSTRUMENTIST";
  const displayName = [user.firstname, user.lastname].filter(Boolean).join(" ") || user.email;
  const roleLabel = ROLE_LABEL[user.role] ?? user.role;

  return (
    <Stack spacing={2.5} sx={{ maxWidth: 480, mx: "auto" }}>
      {/* Carte identité */}
      <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
        <Stack direction="row" spacing={2} alignItems="center">
          <AvatarUploader
            name={displayName}
            photoUrl={resolveApiAssetUrl(user.profilePictureUrl)}
            size="lg"
            onFileReady={async (file) => { await photoMutation.mutateAsync(file); }}
          />
          <Box>
            <Typography variant="h6" fontWeight={700} sx={{ lineHeight: 1.2 }}>
              {displayName}
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {user.email}
            </Typography>
            <Chip size="small" label={roleLabel} sx={{ mt: 0.5, fontSize: "0.7rem" }} />
          </Box>
        </Stack>
      </Paper>

      <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
        <Typography variant="subtitle1" fontWeight={700} mb={1.5}>
          Informations personnelles
        </Typography>
        <Stack spacing={1.25}>
          <InfoRow label="Prénom" value={user.firstname ?? "—"} />
          <InfoRow label="Nom" value={user.lastname ?? "—"} />
          <InfoRow label="E-mail" value={user.email} />
          <InfoRow label="Téléphone" value={user.phone ?? "—"} />
          {user.sites.length > 0 && (
            <InfoRow label="Site(s)" value={user.sites.map((s) => s.name).join(", ")} />
          )}
        </Stack>
      </Paper>

      <Divider />

      <PushPermissionCard />
      <NotificationPreferencesSection />

      <PwaInstallMenuItem />

      {isInstrumentist && (
        <>
          <Paper variant="outlined" sx={{ p: 2, borderRadius: 3 }}>
            <Stack direction="row" alignItems="center" spacing={2}>
              <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography variant="subtitle2" fontWeight={700}>
                  Revoir la présentation de SurgicalHub
                </Typography>
              </Box>
              <Box
                component="button"
                type="button"
                onClick={requestReplay}
                sx={{
                  border: "none", borderRadius: "999px", background: "#2C7D5F", color: "#fff",
                  fontFamily: "inherit", fontWeight: 700, fontSize: 13, cursor: "pointer",
                  height: 36, padding: "0 16px", flexShrink: 0,
                }}
              >
                Revoir
              </Box>
            </Stack>
          </Paper>

          <Divider />

          {/* Compétences — spécifique instrumentiste, jamais affiché au chirurgien */}
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
        </>
      )}
    </Stack>
  );
}
